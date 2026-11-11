<?php

namespace Base\Forge\Security\Voter;

use Base\Entity\User;
use Base\Forge\Repository\HourInvoiceRepository;
use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Security\InvoiceVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The Partner reads the invoices of hours made out to them, and their credit
 * notes, through marketplace's own page (/factures/{number}): an invoice of
 * hours has no order, whose customer InvoiceVoter would know - so this voter
 * answers the same attribute for those. The back office's access stays
 * InvoiceVoter's.
 *
 * @extends Voter<string, Invoice>
 */
final class HourInvoiceVoter extends Voter
{
    public function __construct(private readonly HourInvoiceRepository $hourInvoices)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return InvoiceVoter::VIEW === $attribute && $subject instanceof Invoice && null === $subject->getOrder();
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $client = $this->hourInvoices->ofDocument($subject)?->getClient();

        return null !== $client && $client->getId() === $user->getId();
    }
}
