<?php

namespace Base\Forge\Controller\Client;

use Base\Entity\User;
use Base\Forge\Repository\HourInvoiceRepository;
use Base\Marketplace\Service\Invoices;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Partner's invoices of hours: each issued one, where it stands (sent,
 * paid, cancelled by its credit note, and on the approved platform when it
 * went through one), its PDF and its credit note's - read on marketplace's
 * page, /factures/{number}, which HourInvoiceVoter opens to them. Drafts
 * are the studio's: never shown.
 */
#[IsGranted('ROLE_USER')]
class InvoiceController extends AbstractController
{
    #[Route('/compte/factures', name: 'forge_account_invoices', methods: ['GET'])]
    public function index(HourInvoiceRepository $hourInvoices, Invoices $invoices): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $rows = [];
        foreach ($hourInvoices->issuedTo($user) as $hours) {
            $rows[] = ['hours' => $hours, 'invoice' => $hours->getInvoice(), 'credit_note' => $hours->getCreditNote(), 'lifecycle' => $invoices->lifecycle($hours->getInvoice())];
        }

        return $this->render('@Forge/client/invoice/index.html.twig', ['rows' => $rows]);
    }
}
