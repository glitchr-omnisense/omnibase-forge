<?php

namespace Base\Forge\Repository;

use Base\Entity\User;
use Base\Forge\Entity\HourCredit;
use Base\Forge\Entity\HourInvoice;
use Base\Forge\Entity\TimeEntry;
use Base\Forge\Enum\HourInvoiceStatus;
use Base\Marketplace\Entity\Invoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HourInvoice> */
class HourInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HourInvoice::class);
    }

    /**
     * What is left to invoice a client for over a period: the billable time
     * logged on their projects invoiced on the time spent, neither reserved
     * nor invoiced yet, and never taken off their support hours (an entry
     * logged before its project got a rate keeps its debit: it is paid).
     *
     * @return list<TimeEntry> oldest first
     */
    public function invoiceable(User $client, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('e', 'p')
            ->from(TimeEntry::class, 'e')
            ->join('e.project', 'p')
            ->andWhere('p.client = :client')->setParameter('client', $client)
            ->andWhere('p.hourlyRate IS NOT NULL')
            ->andWhere('e.billable = true')
            ->andWhere('e.minutes > 0')
            ->andWhere('e.hourInvoice IS NULL')
            ->andWhere('e.spentOn >= :from')->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->andWhere('e.spentOn <= :to')->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->andWhere(sprintf('NOT EXISTS (SELECT c.id FROM %s c WHERE c.timeEntry = e)', HourCredit::class))
            ->orderBy('e.spentOn', 'ASC')->addOrderBy('e.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** The invoice of hours an issued invoice stands for, if it is one. */
    public function ofInvoice(Invoice $invoice): ?HourInvoice
    {
        return $this->findOneBy(['invoice' => $invoice]);
    }

    /** The invoice of hours a credit note, or an invoice, belongs to. */
    public function ofDocument(Invoice $document): ?HourInvoice
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.invoice = :document OR h.creditNote = :document')->setParameter('document', $document)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * A Partner's invoices of hours, issued or cancelled (never their drafts), newest first.
     *
     * @return list<HourInvoice>
     */
    public function issuedTo(User $client): array
    {
        return $this->createQueryBuilder('h')
            ->addSelect('i', 'n')
            ->join('h.invoice', 'i')
            ->leftJoin('h.creditNote', 'n')
            ->andWhere('h.client = :client')->setParameter('client', $client)
            ->andWhere('h.status != :draft')->setParameter('draft', HourInvoiceStatus::DRAFT)
            ->orderBy('i.issuedAt', 'DESC')->addOrderBy('h.id', 'DESC')
            ->getQuery()->getResult();
    }
}
