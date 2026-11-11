<?php

namespace Base\Forge\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\IdField;
use Base\Field\NumberField;
use Base\Field\TextField;
use Base\Forge\Entity\HourInvoice;
use Base\Forge\Entity\TimeEntry;
use Base\Forge\Enum\HourInvoiceStatus;
use Base\Forge\Service\HourInvoices;
use Omnibridge\Persistence\UnitOfWork;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The invoices of hours: a new one is a draft - a Partner and a month - that
 * gathers the time of their projects invoiced on the time spent, left to
 * invoice; a person reviews its lines, then issues it (numbered, frozen: a
 * marketplace Invoice), sends it, marks it paid, or cancels it by a credit
 * note, which releases its hours. A draft may be edited (gathered again on
 * save) or deleted (its hours released); an issued invoice never.
 */
class HourInvoiceCrudController extends AbstractCrudController
{
    private HourInvoices $hourInvoices;
    private TranslatorInterface $translator;
    private float $vatRate = 20.0;

    #[Required]
    public function setHourInvoiceServices(HourInvoices $hourInvoices, TranslatorInterface $translator, #[Autowire('%forge.invoicing.vat_rate%')] float $vatRate = 20.0): void
    {
        $this->hourInvoices = $hourInvoices;
        $this->translator = $translator;
        $this->vatRate = $vatRate;
    }

    public static function getEntityFqcn(): string
    {
        return HourInvoice::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-file-invoice-dollar';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['id' => 'DESC']);
    }

    public function isDeletable(object $entity): bool
    {
        return $entity instanceof HourInvoice && $entity->isDraft();
    }

    public function configureActions(Actions $actions): Actions
    {
        $issue = Action::new('issue', '@forge.invoice.admin.issue', 'fa-solid fa-stamp')
            ->linkToCrudAction('issue')
            ->askConfirmation('@forge.invoice.admin.issue_confirm')
            ->displayIf(fn (HourInvoice $hours) => $hours->isDraft() && !$hours->getEntries()->isEmpty());
        $pdf = Action::new('pdf', '@forge.invoice.admin.pdf', 'fa-solid fa-file-pdf')
            ->linkToUrl(fn (HourInvoice $hours) => $hours->getInvoice() ? $this->generateUrl('marketplace_invoice', ['number' => $hours->getInvoice()->getNumber()]) : '#')
            ->displayIf(fn (HourInvoice $hours) => null !== $hours->getInvoice());
        $creditPdf = Action::new('creditPdf', '@forge.invoice.admin.credit_pdf', 'fa-solid fa-file-pdf')
            ->linkToUrl(fn (HourInvoice $hours) => $hours->getCreditNote() ? $this->generateUrl('marketplace_invoice', ['number' => $hours->getCreditNote()->getNumber()]) : '#')
            ->displayIf(fn (HourInvoice $hours) => null !== $hours->getCreditNote());
        $send = Action::new('send', '@forge.invoice.admin.send', 'fa-solid fa-paper-plane')
            ->linkToCrudAction('send')
            ->displayIf(fn (HourInvoice $hours) => HourInvoiceStatus::ISSUED === $hours->getStatus());
        $paid = Action::new('markPaid', '@forge.invoice.admin.paid', 'fa-solid fa-check')
            ->linkToCrudAction('markPaid')
            ->displayIf(fn (HourInvoice $hours) => HourInvoiceStatus::ISSUED === $hours->getStatus() && !$hours->getInvoice()?->isPaid());
        $credit = Action::new('credit', '@forge.invoice.admin.credit', 'fa-solid fa-rotate-left')
            ->linkToCrudAction('credit')
            ->askConfirmation('@forge.invoice.admin.credit_confirm')
            ->displayIf(fn (HourInvoice $hours) => HourInvoiceStatus::ISSUED === $hours->getStatus());

        $actions = parent::configureActions($actions);
        foreach ([Crud::PAGE_INDEX, Crud::PAGE_DETAIL] as $page) {
            $actions->add($page, $issue)->add($page, $pdf)->add($page, $send)->add($page, $paid)->add($page, $credit)->add($page, $creditPdf);
            $actions->update($page, Action::EDIT, fn (Action $action) => $action->displayIf(fn (HourInvoice $hours) => $hours->isDraft()));
        }

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('number', '@forge.invoice.admin.number')->hideOnForm()
            ->formatValue(fn ($value, HourInvoice $hours) => $hours->getInvoice()?->getNumber() ?? '—');
        yield TextField::new('status', '@forge.invoice.admin.status')->hideOnForm()
            ->formatValue(fn ($value, HourInvoice $hours) => $this->translator->trans('@forge.invoice.status.'.$hours->getStatus()->value)
                .($hours->getInvoice() ? ' · '.$hours->getInvoice()->getState() : ''));
        yield AssociationField::new('client', '@forge.invoice.admin.client')->setColumns(4);
        yield DateField::new('periodStart', '@forge.invoice.admin.period_start')->setColumns(2);
        yield DateField::new('periodEnd', '@forge.invoice.admin.period_end')->setColumns(2);
        yield NumberField::new('vatRate', '@forge.invoice.admin.vat_rate')->setColumns(2)->setHelp('@forge.invoice.admin.vat_rate_help');
        yield TextField::new('note', '@forge.invoice.admin.note')->setRequired(false)->setColumns(12)->hideOnIndex();
        yield TextField::new('minutes', '@forge.invoice.admin.time')->hideOnForm()
            ->formatValue(fn ($value, HourInvoice $hours) => TimeEntry::formatMinutes($hours->getMinutes() ?: (int) array_sum(array_column($hours->getInvoice()?->getLines() ?? [], 'minutes'))));
        yield TextField::new('total', '@forge.invoice.admin.total')->hideOnForm()
            ->formatValue(fn ($value, HourInvoice $hours) => self::money($hours->getInvoice()?->getTotal() ?? ($hours->getClient() && !$hours->getEntries()->isEmpty() ? $this->hourInvoices->preview($hours)['totals']['total'] : 0)));
        // Where it stands on its gateway (omnisense/omnibill: e-mail or an approved platform), and what to do about it.
        yield TextField::new('lifecycle', '@forge.invoice.admin.lifecycle')->onlyOnDetail()
            ->formatValue(fn ($value, HourInvoice $hours) => $this->hourInvoices->lifecycle($hours) ?? '—');
        // The lines it issues (a draft: as it would now), and the time entries each bills.
        yield TextField::new('lines', '@forge.invoice.admin.lines')->onlyOnDetail()
            // Rendered here (escaped by its template) and printed as it is: the admin's detail page includes
            // its own field templates only (@Admin/<templateName>), not a bundle's setTemplatePath().
            ->formatValue(fn ($value, HourInvoice $hours) => $this->renderView('@Forge/admin/field/hour_invoice_lines.html.twig', ['data' => $this->lines($hours)]))
            ->setCustomOption('renderAsHtml', true);
    }

    public function createEntity(string $entityFqcn): object
    {
        return (new HourInvoice())->setVatRate($this->vatRate);
    }

    /** The draft gathers its hours as it is saved. */
    public function persistRecord(UnitOfWork $unitOfWork, object $entity): void
    {
        if ($entity instanceof HourInvoice) {
            $this->hourInvoices->draft($entity);

            return;
        }
        parent::persistRecord($unitOfWork, $entity);
    }

    public function updateRecord(UnitOfWork $unitOfWork, object $entity): void
    {
        if ($entity instanceof HourInvoice) {
            $this->hourInvoices->draft($entity);

            return;
        }
        parent::updateRecord($unitOfWork, $entity);
    }

    public function deleteRecord(UnitOfWork $unitOfWork, object $entity): void
    {
        if ($entity instanceof HourInvoice) {
            $this->hourInvoices->discard($entity);

            return;
        }
        parent::deleteRecord($unitOfWork, $entity);
    }

    public function edit(Request $request, string $entityId): Response
    {
        $hours = $this->findEntity($entityId);
        if ($hours instanceof HourInvoice && !$hours->isDraft()) {
            throw $this->createNotFoundException('An issued invoice does not change: correct it with a credit note.');
        }

        return parent::edit($request, $entityId);
    }

    #[AdminAction('/{entityId}/issue')]
    public function issue(string $entityId): Response
    {
        /** @var HourInvoice $hours */
        $hours = $this->findEntity($entityId);

        return $this->attempt(fn () => $this->translator->trans('@forge.invoice.admin.issued', ['{number}' => $this->hourInvoices->issue($hours)->getNumber()]));
    }

    #[AdminAction('/{entityId}/send')]
    public function send(string $entityId): Response
    {
        /** @var HourInvoice $hours */
        $hours = $this->findEntity($entityId);

        return $this->attempt(function () use ($hours) {
            $this->hourInvoices->send($hours);

            return $this->translator->trans('@forge.invoice.admin.sent', ['{number}' => $hours->getInvoice()?->getNumber(), '{email}' => $hours->getInvoice()?->getBuyer()['email'] ?? '']);
        });
    }

    #[AdminAction('/{entityId}/paid')]
    public function markPaid(string $entityId): Response
    {
        /** @var HourInvoice $hours */
        $hours = $this->findEntity($entityId);

        return $this->attempt(function () use ($hours) {
            $this->hourInvoices->markPaid($hours);

            return $this->translator->trans('@forge.invoice.admin.marked_paid', ['{number}' => $hours->getInvoice()?->getNumber()]);
        });
    }

    #[AdminAction('/{entityId}/credit')]
    public function credit(string $entityId): Response
    {
        /** @var HourInvoice $hours */
        $hours = $this->findEntity($entityId);

        return $this->attempt(fn () => $this->translator->trans('@forge.invoice.admin.credited', [
            '{number}' => $this->hourInvoices->credit($hours)->getNumber(),
            '{invoice}' => $hours->getInvoice()?->getNumber(),
        ]));
    }

    /**
     * @return array{issued: bool, lines: list<array<string, mixed>>, totals: array<string, mixed>, mentions: list<string>, entries: array<string, list<TimeEntry>>}
     */
    private function lines(HourInvoice $hours): array
    {
        $entries = [];
        if (null !== $invoice = $hours->getInvoice()) {
            foreach ($hours->getEntries() as $entry) {
                $entries[(string) $entry->getInvoiceLine()][] = $entry;
            }

            return ['issued' => true, 'lines' => $invoice->getLines(), 'totals' => $invoice->getTotals(), 'mentions' => $invoice->getMentions(), 'entries' => $entries];
        }
        if (null === $hours->getClient() || $hours->getEntries()->isEmpty()) {
            return ['issued' => false, 'lines' => [], 'totals' => [], 'mentions' => [], 'entries' => []];
        }
        $preview = $this->hourInvoices->preview($hours);
        foreach ($hours->getEntries() as $entry) {
            $entries[(string) ($preview['line_of'][$entry->getId() ?? spl_object_id($entry)] ?? '')][] = $entry;
        }

        return ['issued' => false, 'lines' => $preview['lines'], 'totals' => $preview['totals'], 'mentions' => $preview['mentions'], 'entries' => $entries];
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').' €';
    }

    /** @param callable(): string $do the action, answering what to tell */
    private function attempt(callable $do): Response
    {
        try {
            $this->addFlash('success', $do());
        } catch (\LogicException $e) {
            $this->addFlash('danger', $this->translator->trans('@forge.invoice.admin.refused', ['{message}' => $e->getMessage()]));
        }

        return $this->redirectToIndex();
    }
}
