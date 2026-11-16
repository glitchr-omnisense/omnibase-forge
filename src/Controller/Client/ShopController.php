<?php

namespace Base\Forge\Controller\Client;

use Base\Database\Repository\Repositories;
use Base\Entity\User;
use Base\Forge\Entity\Product\HourPack;
use Base\Forge\Entity\Quote;
use Base\Forge\Enum\QuoteStatus;
use Base\Marketplace\Form\QuoteRequestType;
use Base\Marketplace\Model\QuoteRequest;
use Base\Marketplace\Service\CompanyRegistry;
use Base\Forge\Repository\QuoteRepository;
use Base\Forge\Service\HourLedger;
use Base\Forge\Service\QuoteStatusGuard;
use Base\Forge\Service\QuoteToOrder;
use Base\Marketplace\Service\CartException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Support, sold by the hour: the packs of the support store, the special
 * offers (quotes) made to the signed-in client, and the form asking for one.
 * A quote is read and accepted through its own link (/devis/<token>).
 */
class ShopController extends AbstractController
{
    public function __construct(
        private readonly Repositories $repositories,
        private readonly QuoteRepository $quotes,
        private readonly HourLedger $ledger,
        #[Autowire('%forge.support_store%')] private readonly string $supportStore = 'support',
    ) {
    }

    #[Route('/support', name: 'forge_shop')]
    public function index(): Response
    {
        // The packs of the support store (a path: its store joined, compared on its slug).
        $packs = $this->repositories->of(HourPack::class)->findBy(['parent.slug' => $this->supportStore], ['unitPrice' => 'ASC']);

        $user = $this->getUser();

        return $this->render('@Forge/client/shop/index.html.twig', [
            'packs' => array_values(array_filter($packs, fn (HourPack $pack) => $pack->isListed() && $pack->isForSell())),
            'offers' => $user instanceof User ? array_values(array_filter(
                $this->quotes->findForClient($user),
                fn (Quote $quote) => $quote->isAcceptable() || QuoteStatusGuard::isAwaitingPayment($quote),
            )) : [],
            'balance' => $user instanceof User ? $this->ledger->balance($user) : null,
        ]);
    }

    #[Route('/support/devis', name: 'forge_quote_request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        MailerInterface $mailer,
        CompanyRegistry $registry,
        #[Autowire('%forge.contact_email%')] ?string $contact = null,
    ): Response {
        $data = new QuoteRequest();
        $user = $this->getUser();
        if ($user instanceof User) {
            $data->email = (string) $user->getEmail();
            $data->contactName = (string) $user;
        }

        // The marketplace's form, without the trade terms (a studio sells hours, not cases).
        $form = $this->createForm(QuoteRequestType::class, $data, ['trade' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $quote = new Quote();
            $quote->setClient($user instanceof User ? $user : null);
            $quote->setContactName($data->contactName);
            $quote->setEmail($data->email);
            $quote->setTitle($data->title);
            $quote->setRequest($data->request);
            // A business: its SIRET, and what the State's register says of it.
            if ($data->siret) {
                $quote->setSiret($data->siret)->setCompanyCheck($registry->lookup($data->siret));
            }
            $quote->setStatus(QuoteStatus::REQUESTED);
            $this->quotes->saveNumbered($quote);

            if ($contact) {
                $mailer->send((new TemplatedEmail())
                    ->to($contact)
                    ->replyTo(new Address($quote->getEmail(), $quote->getContactName()))
                    ->subject(sprintf('[Devis] %s — %s', $quote->getReference(), $quote->getTitle()))
                    ->htmlTemplate('@Forge/email/quote_requested.html.twig')
                    ->context(['quote' => $quote]));
            }

            return $this->render('@Forge/client/quote/requested.html.twig', ['quote' => $quote]);
        }

        return $this->render('@Forge/client/quote/request.html.twig', [
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/devis/{token}', name: 'forge_quote', requirements: ['token' => '[A-Za-z0-9_\-]{43}'])]
    public function quote(string $token): Response
    {
        $quote = $this->findQuote($token);

        return $this->render('@Forge/client/quote/show.html.twig', [
            'quote' => $quote,
            'awaiting_payment' => QuoteStatusGuard::isAwaitingPayment($quote),
        ]);
    }

    #[Route('/devis/{token}/accepter', name: 'forge_quote_accept', requirements: ['token' => '[A-Za-z0-9_\-]{43}'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function accept(Request $request, string $token, QuoteToOrder $quoteToOrder): Response
    {
        $quote = $this->findQuote($token);
        if (!$this->isCsrfTokenValid('forge_quote_'.$quote->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }

        /** @var User $user */
        $user = $this->getUser();
        if ($quote->getClient() && $quote->getClient()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('This quote was made for someone else.');
        }

        try {
            $order = $quoteToOrder->accept($quote, $user);
        } catch (CartException $e) {
            $this->addFlash('error', '@marketplace.'.$e->getMessage());

            return $this->redirectToRoute('forge_quote', ['token' => $token]);
        } catch (\DomainException $e) {
            $this->addFlash('error', '@forge.'.$e->getMessage());

            return $this->redirectToRoute('forge_quote', ['token' => $token]);
        }

        return $this->redirectToRoute('marketplace_checkout', ['order' => $order->getId()]);
    }

    private function findQuote(string $token): Quote
    {
        $quote = $this->quotes->findOneBy(['token' => $token]);
        if (!$quote instanceof Quote || \in_array($quote->getStatus(), [QuoteStatus::REQUESTED, QuoteStatus::DRAFT], true)) {
            throw $this->createNotFoundException('No such quote.');
        }

        return $quote;
    }
}
