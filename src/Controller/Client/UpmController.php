<?php

namespace Base\Forge\Controller\Client;

use Base\Forge\Entity\License;
use Base\Forge\Service\ArtifactFiles;
use Base\Forge\Service\UpmRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Unity registry (UpmRegistry), as Unity's Package Manager reads a
 * scoped registry: the search, the package documents, the tarballs.
 * Anonymous: the free packages. With the licence key in an Authorization
 * header (`token` in .upmconfig.toml: a Bearer; `_auth`: Basic e-mail:key),
 * the licensed packages the licences with a seat for that key's e-mail
 * cover too. A wrong key is a 401, so that the Package Manager says so; a
 * licensed package asked without a key is a 401 too, with a key that does
 * not cover it a 403.
 */
#[Route('/upm')]
class UpmController extends AbstractController
{
    public function __construct(
        private readonly UpmRegistry $registry,
        private readonly ArtifactFiles $files,
    ) {
    }

    #[Route('/-/v1/search', name: 'forge_upm_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        return $this->answer($request, fn (array $licenses) => $this->json(
            $this->registry->search((string) $request->query->get('text', ''), $request->query->getInt('from'), $request->query->getInt('size', 20), $licenses),
        ));
    }

    #[Route('/-/all', name: 'forge_upm_all', methods: ['GET'])]
    public function all(Request $request): Response
    {
        return $this->answer($request, fn (array $licenses) => $this->json($this->registry->all($licenses)));
    }

    #[Route('/{name}', name: 'forge_upm_package', requirements: ['name' => '[a-z0-9_-]+(?:\.[a-z0-9_-]+)+'], methods: ['GET', 'HEAD'])]
    public function package(Request $request, string $name): Response
    {
        return $this->answer($request, function (array $licenses, bool $authenticated) use ($name) {
            $software = $this->registry->find($name);
            if (!$software || !$this->registry->hasVersions($software)) {
                return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
            }
            $document = $this->registry->document($software, $licenses);
            if (!\count($document['versions'])) {
                return $this->refusal($authenticated);
            }

            return $this->json($document);
        });
    }

    #[Route('/{name}/-/{filename}', name: 'forge_upm_tarball', requirements: ['name' => '[a-z0-9_-]+(?:\.[a-z0-9_-]+)+', 'filename' => '[^/]+\.tgz'], methods: ['GET', 'HEAD'])]
    public function tarball(Request $request, string $name, string $filename): Response
    {
        return $this->answer($request, function (array $licenses, bool $authenticated) use ($request, $name, $filename) {
            $software = $this->registry->find($name);
            if (!$software || !$this->registry->hasVersions($software)) {
                return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
            }
            if (!$this->registry->release($software, $filename)) {
                return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
            }
            $found = $this->registry->tarball($software, $filename, $licenses);
            if (!$found) {
                return $this->refusal($authenticated);
            }
            [$artifact, $license] = $found;

            return $this->files->respond($request, $artifact, $license, null, 'upm');
        });
    }

    /**
     * @param callable(list<License>, bool): Response $then
     */
    private function answer(Request $request, callable $then): Response
    {
        $caller = $this->registry->authenticate($request->headers->get('Authorization'));
        if (null === $caller) {
            return $this->json(['error' => 'Invalid licence key.'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer realm="Glitch Art packages"']);
        }
        [$holder, $licenses] = $caller;

        $response = $then($licenses, null !== $holder);
        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    private function refusal(bool $authenticated): JsonResponse
    {
        return $authenticated
            ? $this->json(['error' => 'Your licence does not cover this package or version.'], Response::HTTP_FORBIDDEN)
            : $this->json(['error' => 'This package needs a licence key: set it as the token of this registry in ~/.upmconfig.toml.'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer realm="Glitch Art packages"']);
    }
}
