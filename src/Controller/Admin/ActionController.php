<?php

namespace Base\Forge\Controller\Admin;

use Base\Forge\Entity\Project;
use Base\Forge\Entity\Quote;
use Base\Forge\Entity\Release;
use Base\Forge\Enum\QuoteStatus;
use Base\Forge\Service\ProjectReviews;
use Base\Forge\Service\ReleaseSync;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Service\Checkout;
use Base\Database\Repository\Repositories;
use Base\Contract\UnitOfWork;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The back office's buttons that are not a form: rebuild a release's
 * archive, send a quote, ask a client for their review of a delivered
 * project, and mark an order paid when its bank transfer
 * arrives (the manual gateway leaves it pending; confirming it here is what
 * credits the hours and issues the licences).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/forge')]
class ActionController extends AbstractController
{
    public function __construct(private readonly UnitOfWork $unitOfWork, private readonly Repositories $repositories)
    {
    }

    #[Route('/release/{id}/build', name: 'forge_admin_release_build', requirements: ['id' => '\d+'])]
    public function build(Request $request, int $id, ReleaseSync $sync): RedirectResponse
    {
        $this->assertToken($request, 'forge_release_build', $id);
        $release = $this->repositories->of(Release::class)->find($id) ?? throw $this->createNotFoundException();
        try {
            $sync->rebuild($release);
            $this->addFlash('success', sprintf('%s: archive built.', $release));
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('%s: %s', $release, $e->getMessage()));
        }

        return $this->back($request);
    }

    #[Route('/quote/{id}/send', name: 'forge_admin_quote_send', requirements: ['id' => '\d+'])]
    public function send(Request $request, int $id, MailerInterface $mailer): RedirectResponse
    {
        $this->assertToken($request, 'forge_quote_send', $id);
        $quote = $this->repositories->of(Quote::class)->find($id) ?? throw $this->createNotFoundException();
        if ($quote->getTotalMinutes() <= 0) {
            $this->addFlash('error', sprintf('%s has no hours to sell: add its lines first.', $quote->getReference()));

            return $this->back($request);
        }

        $quote->setStatus(QuoteStatus::SENT);
        $quote->setValidUntil($quote->getValidUntil() ?? new \DateTimeImmutable('+30 days'));
        $this->unitOfWork->flush();

        $mailer->send((new TemplatedEmail())
            ->to($quote->getEmail())
            ->subject(sprintf('Votre devis %s — %s', $quote->getReference(), $quote->getTitle()))
            ->htmlTemplate('@Forge/email/quote_sent.html.twig')
            ->context(['quote' => $quote]));

        $this->addFlash('success', sprintf('%s sent to %s.', $quote->getReference(), $quote->getEmail()));

        return $this->back($request);
    }

    /** Closes the project - delivered - and asks its client for a review, by e-mail. */
    #[Route('/project/{id}/review', name: 'forge_admin_project_review', requirements: ['id' => '\d+'])]
    public function review(Request $request, int $id, ProjectReviews $reviews): RedirectResponse
    {
        $this->assertToken($request, 'forge_project_review', $id);
        $project = $this->repositories->of(Project::class)->find($id) ?? throw $this->createNotFoundException();
        try {
            $reviews->request($project);
            $this->addFlash('success', sprintf('« %s » est livré : la demande d\'avis est partie à %s.', $project->getName(), $project->getClient()?->getEmail()));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->back($request);
    }

    #[Route('/order/{reference}/paid', name: 'forge_admin_order_paid', requirements: ['reference' => '[A-Za-z0-9\-]+'], methods: ['POST'])]
    public function paid(Request $request, string $reference, Checkout $checkout): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('forge_order_paid', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }

        $order = $this->repositories->of(Order::class)->findOneBy(['reference' => $reference]) ?? throw $this->createNotFoundException();
        $transaction = null;
        foreach ($order->getTransactions() as $candidate) {
            $transaction = $candidate;
        }
        if (!$transaction) {
            $this->addFlash('error', sprintf('Order %s has no payment to confirm.', $reference));

            return $this->back($request);
        }

        $checkout->confirm($order, $transaction);
        $this->addFlash('success', sprintf('Order %s marked paid.', $reference));

        return $this->back($request);
    }

    private function back(Request $request): RedirectResponse
    {
        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('admin'));
    }

    /**
     * These act (a mail sent, an archive rebuilt, a project closed): the
     * token their link carries (the CRUD controllers' actionToken()) says the
     * click came from the back office - not a link from elsewhere, not a
     * prefetch, which sent the mail twice.
     */
    private function assertToken(Request $request, string $action, int $id): void
    {
        if (!$this->isCsrfTokenValid($action.'_'.$id, (string) $request->query->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
