<?php

namespace Base\Forge\Service;

use Base\Forge\Entity\HourInvoice;
use Base\Forge\Invoice\HourInvoiceComposer;
use Base\Forge\Repository\HourInvoiceRepository;
use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Service\Invoices;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The forge invoices its hours: a Partner's time on the projects invoiced on
 * the time spent (Project::$hourlyRate), over a period, as omnibase/
 * marketplace's own Invoice, issued through Invoices::issueFor() - the same
 * entity, the same sequence without a gap (under symfony/lock), the same
 * seller and mentions, the same PDF and Factur-X, sent the same way
 * (Invoices::send(): e-mail, or the approved platform when omnisense/omnibill
 * is configured).
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
        private readonly Invoices $invoices,
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
     * What the draft would issue now - its seller, buyer, lines, totals,
     * mentions and dates, as marketplace composes them - for the person
     * reviewing it; line_of: the line of each entry.
     *
     * @return array{seller: array<string, mixed>, buyer: array<string, mixed>, lines: list<array<string, mixed>>, totals: array<string, mixed>, mentions: list<string>, sold_at: ?\DateTimeImmutable, due_at: \DateTimeImmutable, line_of: array<int, string>}
     */
    public function preview(HourInvoice $draft, ?\DateTimeImmutable $at = null): array
    {
        self::marketplace();
        ['source' => $source, 'line_of' => $lineOf] = $this->composer->source($draft);

        return $this->invoices->compose($source, $at) + ['line_of' => $lineOf];
    }

    /**
     * The draft issued through marketplace's Invoices::issueFor(): numbered in
     * the invoices' series, its seller, buyer, lines and amounts frozen on a
     * marketplace Invoice, and each of its time entries told the line that
     * billed it - in one transaction, under the series' lock.
     *
     * @throws \LogicException a draft with nothing to invoice, or already issued
     */
    public function issue(HourInvoice $draft, ?\DateTimeImmutable $at = null): Invoice
    {
        self::marketplace();
        if (!$draft->isDraft()) {
            throw new \LogicException(sprintf('The invoice %s is issued already.', $draft));
        }
        if ($draft->getEntries()->isEmpty()) {
            throw new \LogicException('Nothing to invoice: no hours left in the period.');
        }
        ['source' => $source, 'line_of' => $lineOf] = $this->composer->source($draft);

        return $this->invoices->issueFor($source, $at, function (Invoice $invoice) use ($draft, $lineOf): void {
            foreach ($draft->getEntries() as $entry) {
                $entry->billedOn($lineOf[$entry->getId() ?? spl_object_id($entry)]);
            }
            $draft->issuedAs($invoice);
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
     * new draft. The credit note names the invoice it cancels, then the
     * reason as given (marketplace writes both). Returns the credit note.
     */
    public function credit(HourInvoice $hourInvoice, ?string $reason = null, ?\DateTimeImmutable $at = null): Invoice
    {
        self::marketplace();

        return $this->invoices->reissue($this->standing($hourInvoice), $reason, $at)['credit_note'];
    }

    /** Where it stands on its gateway (omnisense/omnibill), and what to do about it; null when it went through none. */
    public function lifecycle(HourInvoice $hourInvoice): ?string
    {
        $invoice = $hourInvoice->getInvoice();
        if (null === $invoice) {
            return null;
        }
        self::marketplace();

        return implode(' — ', array_filter([$this->invoices->lifecycle($invoice), $this->invoices->guidance($invoice)])) ?: null;
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
     * omnibase/marketplace as forge needs it: Invoices::issueFor() (a03428c),
     * and with it the credit note of an invoice without an order (92d9691) and
     * the billing period on the lines (2042c64). A site whose marketplace is
     * older is told to update the two together, never invoiced another way.
     */
    private static function marketplace(): void
    {
        if (!method_exists(Invoices::class, 'issueFor')) {
            throw new \LogicException('omnibase/forge invoices its hours through omnibase/marketplace\'s Invoices::issueFor(), which this marketplace has not: update omnibase/marketplace with omnibase/forge (composer update omnibase/forge omnibase/marketplace omnibase/admin).');
        }
    }
}
