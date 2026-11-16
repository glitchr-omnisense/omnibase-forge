<?php

namespace Base\Forge\Repository;

use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Entity\User;
use Base\Forge\Entity\HourInvoice;
use Base\Forge\Enum\HourInvoiceStatus;
use Base\Marketplace\Entity\Invoice;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method HourInvoice|null find($id, $lockMode = null, $lockVersion = null)
 * @method HourInvoice|null findOneBy(array $criteria, array $orderBy = null)
 * @method HourInvoice[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class HourInvoiceRepository extends ServiceEntityRepository
{
    /** The invoice of hours an issued invoice stands for, if it is one. */
    public function ofInvoice(Invoice $invoice): ?HourInvoice
    {
        return $this->findOneBy(['invoice' => Key::of($invoice)]);
    }

    /** The invoice of hours a credit note, or an invoice, belongs to. */
    public function ofDocument(Invoice $document): ?HourInvoice
    {
        return $this->findOneBy([Group::of(
            Parser::SEPARATOR_OR,
            Criterion::of('invoice', Key::of($document)),
            Criterion::of('creditNote', Key::of($document)),
        )]);
    }

    /**
     * A Partner's invoices of hours, issued or cancelled (never their drafts), newest first.
     *
     * @return list<HourInvoice>
     */
    public function issuedTo(User $client): array
    {
        return $this->findBy([
            'client' => Key::of($client),
            Group::of(
                Parser::SEPARATOR_AND,
                Criterion::of('status', HourInvoiceStatus::DRAFT, Parser::OPTION_NOT_EQUAL),
                // What the inner join of the invoice said: one that reads (none hidden by the persistence).
                Criterion::of('invoice.id', null, Parser::OPTION_NOT_NULL),
            ),
        ], ['invoice.issuedAt' => 'DESC', 'id' => 'DESC']);
    }
}
