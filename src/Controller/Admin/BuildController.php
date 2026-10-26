<?php

namespace Base\Forge\Controller\Admin;

use Base\Admin\Context\AdminContext;
use Base\Admin\Menu\MenuBuilder;
use Base\Forge\Entity\Artifact;
use Base\Forge\Entity\Release;
use Base\Forge\Enum\Platform;
use Base\Forge\Service\ArtifactBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A release's builds, one per platform (a game's, an application's): each
 * with its size and its sha256, and a form to upload one - it replaces the
 * platform's previous build (ArtifactBuilder::attach()). What
 * forge:artifact:attach does from a CI.
 */
#[IsGranted('ROLE_ADMIN')]
class BuildController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ArtifactBuilder $builder,
        private readonly AdminContext $adminContext,
        private readonly MenuBuilder $menuBuilder,
    ) {
    }

    #[Route('/admin/forge/release/{id}/builds', name: 'forge_admin_release_builds', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function builds(Request $request, int $id): Response
    {
        $release = $this->entityManager->find(Release::class, $id) ?? throw $this->createNotFoundException();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forge_release_builds_'.$id, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid token.');
            }
            $platform = Platform::tryFrom((string) $request->request->get('platform'));
            $file = $request->files->get('build');
            if ($request->request->has('remove')) {
                $this->remove($release, (string) $request->request->get('remove'));

                return $this->redirectToRoute('forge_admin_release_builds', ['id' => $id]);
            }
            if (!$platform || !$file instanceof UploadedFile || !$file->isValid()) {
                $this->addFlash('error', $file instanceof UploadedFile && !$file->isValid() ? $file->getErrorMessage() : 'Choose a platform and a file.');
            } else {
                try {
                    $artifact = $this->builder->attach($release, $platform, $file->getPathname(), $file->getClientOriginalName());
                    $this->entityManager->flush();
                    $this->addFlash('success', sprintf('%s: %s build attached (sha256 %s).', $release, $platform->label(), $artifact->getSha256()));

                    return $this->redirectToRoute('forge_admin_release_builds', ['id' => $id]);
                } catch (\InvalidArgumentException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        if ([] === $this->adminContext->getMainMenu()) {
            $this->adminContext->setMainMenu($this->menuBuilder->buildDefault());
        }
        if ([] === $this->adminContext->getUserMenu()) {
            $this->adminContext->setUserMenu($this->menuBuilder->buildUserMenuDefault($this->getUser()));
        }

        return $this->render('@Forge/admin/release/builds.html.twig', [
            'admin_context' => $this->adminContext,
            'release' => $release,
            'platforms' => Platform::cases(),
        ]);
    }

    private function remove(Release $release, string $format): void
    {
        $artifact = Platform::tryFrom($format) ? $release->getArtifact($format) : null;
        if (!$artifact instanceof Artifact) {
            return;
        }
        $path = $this->builder->absolutePath($artifact);
        $release->getArtifacts()->removeElement($artifact);
        $this->entityManager->flush();
        (new Filesystem())->remove(\dirname($path));
        $this->addFlash('success', sprintf('%s: %s build removed.', $release, $artifact->getPlatform()?->label()));
    }
}
