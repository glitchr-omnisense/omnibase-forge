<?php

namespace Base\Forge\Controller\Client;

use Base\Forge\Entity\Card;
use Base\Forge\Entity\Project;
use Base\Forge\Model\CardMove;
use Base\Forge\Security\Voter\ProjectVoter;
use Base\Forge\Service\Board;
use Base\Database\Repository\Repositories;
use Base\Contract\UnitOfWork;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A project's board: the page that shows it, and the move of a card - what its script
 * (public/js/board.js) sends at each drop. Who sees a project and who moves its cards is
 * ProjectVoter's: its client and the staff; a delivered project no longer moves for its client.
 *
 * An application with a dashboard of its own includes the board there
 * (@Forge/client/project/_board.html.twig) and needs nothing else: the move comes here.
 */
#[IsGranted('ROLE_USER')]
class BoardController extends AbstractController
{
    /** The id of the CSRF token a board carries (data-token) and its moves send back (X-CSRF-Token). */
    public const TOKEN = 'forge_board';

    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        private readonly Repositories $repositories,
        private readonly Board $board,
    ) {
    }

    #[Route('/projets/{id}/tableau', name: 'forge_project_board', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $project = $this->repositories->of(Project::class)->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        return $this->render('@Forge/client/project/board.html.twig', ['project' => $project]);
    }

    #[Route('/projets/cartes/{id}', name: 'forge_board_card', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function move(int $id, Request $request, #[MapRequestPayload] CardMove $move): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['error' => 'Invalid token.'], Response::HTTP_FORBIDDEN);
        }

        $card = $this->repositories->of(Card::class)->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT_BOARD, $card->getProject());

        $this->board->move($card, $move->getColumn(), $move->position);
        $this->unitOfWork->flush();

        return new JsonResponse(['id' => $card->getId(), 'column' => $card->getColumn()->value, 'position' => $card->getPosition()]);
    }
}
