<?php

namespace Base\Forge\Controller\Client;

use Base\Forge\Entity\Software;
use Base\Forge\Enum\Platform;
use Base\Forge\Repository\SoftwareRepository;
use Base\Forge\Service\WebglBuilds;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A game's WebGL build, played in its page (the software page frames
 * /play/<slug>/<version>/ once the visitor presses Play): the build's own
 * index.html, then its loader and its compressed files, with the headers
 * WebglBuilds gives them. A free game is played in the browser by anyone; a
 * paid one is downloaded once bought, and played here only when the studio
 * opened a playable preview of it (Software::$playablePreview) - then by
 * anyone too. The staff always, to check a build.
 */
class PlayController extends AbstractController
{
    public function __construct(
        private readonly SoftwareRepository $software,
        private readonly WebglBuilds $builds,
    ) {
    }

    #[Route('/play/{slug}/{version}/{path}', name: 'forge_play', requirements: ['slug' => '[a-z0-9\-]+', 'version' => '[0-9A-Za-z.\-]+', 'path' => '.*'], defaults: ['path' => ''], methods: ['GET', 'HEAD'])]
    public function play(Request $request, string $slug, string $version, string $path): Response
    {
        // The build's own page reads its files relative to its address: /play/<slug>/<version>/,
        // never without the slash (Build/... would then be looked for one folder up).
        if ('' === $path && !str_ends_with($request->getPathInfo(), '/')) {
            return $this->redirect($request->getBaseUrl().$request->getPathInfo().'/', Response::HTTP_MOVED_PERMANENTLY);
        }

        $software = $this->software->findOneBy(['slug' => $slug]);
        if (!$software instanceof Software || !$software->isVisible()) {
            throw $this->createNotFoundException(sprintf('No game "%s".', $slug));
        }
        $build = $software->findRelease($version)?->getArtifact(Platform::WEBGL->value)
            ?? throw $this->createNotFoundException(sprintf('No WebGL build of %s %s.', $software, $version));
        if (!$software->isPlayableInBrowser() && !$this->isGranted('ROLE_STAFF')) {
            throw new AccessDeniedHttpException(sprintf('%s is a paid game: it is downloaded once bought.', $software));
        }

        [$file, $headers] = $this->builds->file($build, $path) ?? throw $this->createNotFoundException(sprintf('No "%s" in the build.', $path));

        // A version's build is fixed (a new one is a new version): cached - by the
        // browser alone when only the staff may play it.
        $open = $software->isPlayableInBrowser();
        $response = new BinaryFileResponse($file, Response::HTTP_OK, $headers, $open);
        $response->setMaxAge(3600);
        if (!$open) {
            $response->setPrivate();
        }

        return $response;
    }
}
