<?php

namespace Base\Forge\Service;

use Base\Entity\User;
use Base\Forge\Entity\HourCredit;
use Base\Forge\Entity\TimeEntry;
use Base\Forge\Enum\CreditReason;
use Base\Forge\Repository\HourCreditRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A client's support hours: credited when they buy some, debited when time
 * is logged on their projects. The balance is always the sum of the lines.
 */
class HourLedger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HourCreditRepository $credits,
    ) {
    }

    public function balance(User $user): int
    {
        return $this->credits->balance($user);
    }

    /** @return list<HourCredit> */
    public function history(User $user, int $limit = 50): array
    {
        return $this->credits->history($user, $limit);
    }

    /** Persisted, not flushed. */
    public function credit(User $user, int $minutes, CreditReason $reason = CreditReason::PURCHASE, ?string $note = null, ?string $orderReference = null): HourCredit
    {
        $credit = new HourCredit($user, $minutes, $reason, $note);
        $credit->setOrderReference($orderReference);
        $this->entityManager->persist($credit);

        return $credit;
    }

    /**
     * The debit line a time entry should have: its minutes, negated, on the
     * project's client - or none, when it is not billable, or its project is
     * invoiced on the time spent. Existing lines are
     * updated in place (TimeEntryLedgerSubscriber calls this in onFlush).
     */
    public function debitFor(TimeEntry $entry, ?HourCredit $existing): ?HourCredit
    {
        $client = $entry->getProject()?->getClient();
        if (!$client || !$entry->isBillable() || $entry->getMinutes() <= 0) {
            return null;
        }
        // A project invoiced on the time spent: its hours are invoiced
        // (Service\HourInvoices), not taken off the prepaid ones - never both.
        // An entry already debited before the rate was set stays debited.
        if (null === $existing && $entry->getProject()?->isInvoicedOnTime()) {
            return null;
        }

        $credit = $existing ?? (new HourCredit($client, 0, CreditReason::TIME, null))->setTimeEntry($entry);

        return $credit->setMinutes(-$entry->getMinutes());
    }
}
