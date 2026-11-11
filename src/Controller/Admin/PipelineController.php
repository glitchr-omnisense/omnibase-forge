<?php

namespace Base\Forge\Controller\Admin;

use Base\Admin\Context\AdminContext;
use Base\Admin\Menu\MenuBuilder;
use Base\Forge\Entity\Pipeline;
use Base\Forge\Repository\PipelineRepository;
use Base\Forge\Repository\ProjectRepository;
use Base\Forge\Service\PipelineSync;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The pipelines in the back office: the latest runs of every project, and one of them as its
 * graph - its jobs by stage, in the colour of their status (@glitchr/graphjs, which the back
 * office's layout loads) - and the runs read again from the hosts on demand (Service\PipelineSync).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/forge/pipelines')]
class PipelineController extends AbstractController
{
    public function __construct(
        private readonly PipelineRepository $pipelines,
        private readonly AdminContext $adminContext,
        private readonly MenuBuilder $menuBuilder,
    ) {
    }

    #[Route('', name: 'forge_admin_pipelines', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page('@Forge/admin/pipeline/index.html.twig', ['pipelines' => $this->pipelines->findLatest()]);
    }

    /** The latest runs of every project read again from the CI of its host, now. */
    #[Route('/sync', name: 'forge_admin_pipelines_sync', methods: ['POST'])]
    public function sync(Request $request, ProjectRepository $projects, PipelineSync $sync, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('forge_pipelines_sync', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }

        $read = $failed = 0;
        foreach ($projects->findWithRepositoryUrl() as $project) {
            try {
                $read += \count($sync->sync($project));
            } catch (\RuntimeException) {
                ++$failed;
            }
        }
        $this->addFlash($failed ? 'warning' : 'success', $translator->trans('pipeline.synced', ['runs' => $read, 'failed' => $failed], 'forge'));

        return $this->redirectToRoute('forge_admin_pipelines');
    }

    #[Route('/{id}', name: 'forge_admin_pipeline', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $pipeline = $this->pipelines->find($id) ?? throw $this->createNotFoundException();

        return $this->page('@Forge/admin/pipeline/show.html.twig', [
            'pipeline' => $pipeline,
            'others' => $pipeline->getProject() ? array_filter($this->pipelines->findLatestFor($pipeline->getProject(), 8), fn (Pipeline $other) => $other !== $pipeline) : [],
        ]);
    }

    /** A page of the back office: its menus, as RefundController builds them. */
    private function page(string $template, array $parameters): Response
    {
        if ([] === $this->adminContext->getMainMenu()) {
            $this->adminContext->setMainMenu($this->menuBuilder->buildDefault());
        }
        if ([] === $this->adminContext->getUserMenu()) {
            $this->adminContext->setUserMenu($this->menuBuilder->buildUserMenuDefault($this->getUser()));
        }

        return $this->render($template, ['admin_context' => $this->adminContext] + $parameters);
    }
}
