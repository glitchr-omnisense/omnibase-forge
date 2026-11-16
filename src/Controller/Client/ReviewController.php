<?php

namespace Base\Forge\Controller\Client;

use Base\Forge\Entity\ProjectReview;
use Base\Forge\Form\ReviewAnswerType;
use Base\Forge\Model\ReviewAnswer;
use Base\Forge\Repository\ProjectReviewRepository;
use Base\Contract\UnitOfWork;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where the client's review link lands: their project, delivered and
 * closed, and the form - one to five stars, a few words. Their link is the
 * credential: no account needed. Answered once; then it thanks them.
 */
class ReviewController extends AbstractController
{
    #[Route('/avis/{token}', name: 'forge_review', requirements: ['token' => '[A-Za-z0-9_\-]{20,64}'], methods: ['GET', 'POST'])]
    public function review(string $token, Request $request, ProjectReviewRepository $reviews, UnitOfWork $unitOfWork): Response
    {
        $review = $reviews->findOneBy(['token' => $token]);
        if (!$review instanceof ProjectReview) {
            throw $this->createNotFoundException('This review link is not valid, or was replaced by a newer one.');
        }

        $answer = new ReviewAnswer();
        $answer->signature = $review->getProject()?->getClient()?->getUsername();
        $form = $this->createForm(ReviewAnswerType::class, $answer);
        $form->handleRequest($request);

        if (!$review->isSubmitted() && $form->isSubmitted() && $form->isValid()) {
            $review->submit((int) $answer->rating, $answer->comment, $answer->signature);
            $unitOfWork->flush();

            return $this->redirectToRoute('forge_review', ['token' => $token]);
        }

        $response = $this->render('@Forge/client/review/form.html.twig', [
            'review' => $review,
            'project' => $review->getProject(),
            'form' => $form,
        ], new Response(null, $form->isSubmitted() && !$form->isValid() ? 422 : 200));
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
