<?php

namespace Base\Forge\Repository;

use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Entity\User;
use Base\Forge\Entity\HourCredit;
use Base\Forge\Entity\TimeEntry;

/**
 * The time logged on the projects.
 *
 * @method TimeEntry|null find($id)
 * @method TimeEntry|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method list<TimeEntry> findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class TimeEntryRepository extends ServiceEntityRepository
{
    /**
     * What is left to invoice a client for over a period: the billable time logged on their projects invoiced on the
     * time spent, neither reserved nor invoiced yet, and never taken off their support hours (no HourCredit points at
     * it: an entry logged before its project got a rate keeps its debit, it is paid). Oldest first; the projects load
     * when read.
     *
     * @return list<TimeEntry>
     */
    public function invoiceable(User $client, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // A client never stored has no project: nothing to invoice (and a null id would read "no client").
        if (null === $client->getId()) {
            return [];
        }

        return $this->findBy([
            // The client by its identifier: a path's value is bound as such on every engine (no entity to bind).
            'project.client' => $client->getId(),
            'billable' => true,
            'hourInvoice' => null,
            Group::of(Parser::SEPARATOR_AND,
                Criterion::of('project.hourlyRate', null, Parser::OPTION_NOT_NULL),
                Criterion::of('minutes', 0, Parser::OPTION_GREATER),
                Criterion::of('spentOn', $from, Parser::OPTION_GREATER_EQUAL),
                Criterion::of('spentOn', $to, Parser::OPTION_LOWER_EQUAL),
            ),
            Criterion::notExists(HourCredit::class, 'timeEntry'),
        ], ['spentOn' => 'ASC', 'id' => 'ASC']);
    }
}
