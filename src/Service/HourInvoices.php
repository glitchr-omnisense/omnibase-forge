<?php

namespace Base\Forge\Service;

use Base\Forge\Entity\HourInvoice;
use Base\Forge\Invoice\HourInvoiceComposer;
use Base\Forge\Repository\HourInvoiceRepository;
use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Invoice\InvoiceNumbering;
use Base\Marketplace\Service\Invoices;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The forge invoices its hours: a Partner's time on the projects invoiced on
 * the time spent (Project::$hourlyRate), over a period, as omnibase/
 * marketplace's own Invoice - the same entity, the same sequence without a
 * gap (InvoiceNumbering, under symfony/lock), the same PDF and Factur-X,
 * sent the same way (Invoices::send(): e-mail, or the approved platform when
 * omnisense/omnibill is configured).
 *
 *     $draft = $hourInvoices->draft(new HourInvoice($partner, $from, $to)); // the hours reserved
 *     $invoice = $hourInvoices->issue($draft);                              // F-2026-000042, frozen
 *     $hourInvoices->send($draft);
 *     $hourInvoices->credit($draft, 'Taux erroné');                        // A-2026-000007, hours released
 *
 * An issued invoice is never edited: a credit note cancels it and releases
 * its hours (EventListener\HourInvoiceCancelListener, whichever screen made
 * the credit note), and a new draft invoices them again.
 */
class HourInvoices
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HourInvoiceRepository $repository,
        private readonly HourInvoiceComposer $composer,
        private readonly InvoiceNumbering $numbering,
        private readonly Invoices $invoices,
        #[Autowire('%marketplace.invoice.prefix%')] private readonly string $prefix = 'F',
        #[Autowire('%marketplace.invoice.payment_days%')] private readonly int $paymentDays = 30,
    ) {
    }

    public function repository(): HourInvoiceRepository
    {
        return $this->repository;
    }

    /** marketplace's invoices: the PDF, the signed link, the lifecycle on the gateway. */
    public function invoices(): Invoices
    {
        return $this->invoices;
    }

    /**
     * A draft's hours, gathered again: what it reserved, plus what was logged
     * since in its period and is left to invoice. Stored.
     */
    public function draft(HourInvoice $draft): HourInvoice
    {
        if (!$draft->isDraft()) {
            throw new \LogicException(sprintf('The invoice %s is issued: its hours do not change.', $draft));
        }
        $client = $draft->getClient() ?? throw new \LogicException('An invoice of hours is for a Partner.');
        foreach ($draft->getEntries()->toArray() as $entry) {
            // Out of the period now, or no longer invoiced by the hour: released.
            if ($entry->getSpentOn() < $draft->getPeriodStart() || $entry->getSpentOn() > $draft->getPeriodEnd() || !$entry->isBillable() || !$entry->getProject()?->isInvoicedOnTime() || $entry->getProject()?->getClient() !== $client) {
                $draft->removeEntry($entry);
            }
        }
        foreach ($this->repository->invoiceable($client, $draft->getPeriodStart(), $draft->getPeriodEnd()) as $entry) {
            $draft->addEntry($entry);
        }
        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }

    /** A draft dropped: its hours released (forge_time_entry.hourInvoice_id ON DELETE SET NULL, and in memory). */
    public function discard(HourInvoice $draft): void
    {
        if (!$draft->isDraft()) {
            throw new \LogicException(sprintf('The invoice %s is issued: a credit note cancels it.', $draft));
        }
        foreach ($draft->getEntries()->toArray() as $entry) {
            $draft->removeEntry($entry);
        }
        $this->entityManager->remove($draft);
        $this->entityManager->flush();
    }

    /**
     * What the draft would issue now - its lines, totals and mentions - for
     * the person reviewing it.
     *
     * @return array{seller: array<string, mixed>, buyer: array<string, mixed>, lines: list<array<string, mixed>>, totals: array<string, mixed>, mentions: list<string>, due_at: \DateTimeImmutable}
     */
    public function preview(HourInvoice $draft, ?\DateTimeImmutable $at = null): array
    {
        $at ??= new \DateTimeImmutable();
        $client = $draft->getClient() ?? throw new \LogicException('An invoice of hours is for a Partner.');
        $seller = $this->composer->seller();
        $buyer = $this->composer->buyer($client);
        $exemption = $this->composer->exemption($seller, $buyer);
        ['lines' => $lines, 'totals' => $totals, 'line_of' => $lineOf] = $this->composer->amounts($draft, array_values($draft->getEntries()->toArray()), $exemption);
        $dueAt = $at->modify(sprintf('+%d days', $this->paymentDays));

        return [
            'seller' => $seller,
            'buyer' => $buyer,
            'lines' => $lines,
            'line_of' => $lineOf,
            'totals' => $totals,
            'mentions' => $this->composer->mentions($draft, $buyer, $exemption, $dueAt),
            'due_at' => $dueAt,
        ];
    }

    /**
     * The draft issued: numbered in the invoices' series, its seller, buyer,
     * lines and amounts frozen on a marketplace Invoice, and each of its time
     * entries told the line that billed it - in one transaction, under the
     * series' lock.
     *
     * @throws \LogicException a draft with nothing to invoice, or already issued
     */
    public function issue(HourInvoice $draft, ?\DateTimeImmutable $at = null): Invoice
    {
        if (!$draft->isDraft()) {
            throw new \LogicException(sprintf('The invoice %s is issued already.', $draft));
        }
        if ($draft->getEntries()->isEmpty()) {
            throw new \LogicException('Nothing to invoice: no hours left in the period.');
        }
        $at ??= new \DateTimeImmutable();
        $preview = $this->preview($draft, $at);
        if ($preview['totals']['total'] <= 0) {
            throw new \LogicException('Nothing to invoice: the hours come to nothing.');
        }
        return $this->numbering->issue($this->prefix, $at, function (int $sequence, string $number, int $year) use ($draft, $preview, $at) {
            $invoice = new Invoice(
                Invoice::TYPE_INVOICE, $this->prefix, $year, $sequence, $number, $at, null, 'EUR',
                $preview['seller'], $preview['buyer'], $preview['lines'], $preview['totals'], $preview['mentions'],
                // The date the services were completed: the period's end (CGI ann. II art. 242 nonies A, 7°).
                $draft->getPeriodEnd(), $preview['due_at'],
            );
            foreach ($draft->getEntries() as $entry) {
                $entry->billedOn($preview['line_of'][$entry->getId() ?? spl_object_id($entry)]);
            }
            $draft->issuedAs($invoice);

            return $invoice;
        });
    }

    /** Sent to the Partner the way marketplace sends its invoices: omnibill's gateway when configured, its own e-mail otherwise. */
    public function send(HourInvoice $hourInvoice, ?string $to = null): void
    {
        $this->invoices->send($this->standing($hourInvoice), $to);
    }

    public function markPaid(HourInvoice $hourInvoice, ?\DateTimeImmutable $at = null): void
    {
        $this->invoices->markPaid($this->standing($hourInvoice), $at);
    }

    /**
     * Cancelled by a credit note - the only way an issued invoice changes -
     * through marketplace's reissue() rule: the credit note, and the invoice
     * made again from its source; an invoice of hours has no order to make it
     * again from, so its hours are released (HourInvoiceCancelListener) for a
     * new draft. Returns the credit note.
     */
    public function credit(HourInvoice $hourInvoice, ?string $reason = null, ?\DateTimeImmutable $at = null): Invoice
    {
        $invoice = $this->standing($hourInvoice);
        $reason = $this->reason($invoice, $reason);
        // marketplace's reissue() (since its omnibill emission); credit() on a marketplace that has it not.
        $creditNote = method_exists($this->invoices, 'reissue')
            ? $this->invoices->reissue($invoice, $reason, $at)['credit_note']
            : $this->invoices->credit($invoice, $reason, $at);
        return $creditNote;
    }

    /** Where it stands on its gateway (omnisense/omnibill), and what to do about it; null when it went through none. */
    public function lifecycle(HourInvoice $hourInvoice): ?string
    {
        $invoice = $hourInvoice->getInvoice();
        if (null === $invoice) {
            return null;
        }
        $guidance = method_exists($this->invoices, 'guidance') ? $this->invoices->guidance($invoice) : null;

        return implode(' — ', array_filter([$this->invoices->lifecycle($invoice), $guidance])) ?: null;
    }

    private function standing(HourInvoice $hourInvoice): Invoice
    {
        $invoice = $hourInvoice->getInvoice() ?? throw new \LogicException(sprintf('%s is a draft: issue it first.', $hourInvoice));
        if ($invoice->isCancelled()) {
            throw new \LogicException(sprintf('The invoice %s is cancelled by %s.', $invoice->getNumber(), $invoice->getCreditNote()?->getNumber()));
        }

        return $invoice;
    }

    /**
     * The credit note names the invoice it cancels in its mentions (marketplace
     * writes that mention from an order; an invoice of hours has none). The
     * Factur-X refers to it as well (BT-25, from Invoice::getCredited()).
     */
    private function reason(Invoice $invoice, ?string $reason): string
    {
        $mention = $this->composer->creditMention($invoice);

        return null !== $reason && '' !== trim($reason) ? $mention.' '.trim($reason) : $mention;
    }
}
