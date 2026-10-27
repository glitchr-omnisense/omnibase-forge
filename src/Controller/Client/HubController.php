<?php

namespace Base\Forge\Controller\Client;

use Base\Forge\Service\DeviceLinks;
use Base\Forge\Service\HubCatalog;
use Base\Forge\Service\UpmRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the hub clients read beside the Unity registry (docs/hub.md): the hub manifest, the
 * catalogue - its paid packages too, with their offers, and the games -, and the device flow that
 * links an editor to its user's licences once bought (DeviceLinks). Under /upm, whose firewall
 * the host leaves open: the licence key comes as the registry's Bearer token, the device code in
 * the body.
 */
#[Route('/upm/-')]
class HubController extends AbstractController
{
    public function __construct(
        private readonly HubCatalog $catalog,
        private readonly UpmRegistry $registry,
        private readonly DeviceLinks $links,
        #[Autowire('%forge.hub.checkout%')] private readonly bool $checkout = true,
    ) {
    }

    #[Route('/hub.json', name: 'forge_hub_manifest', methods: ['GET'])]
    public function manifest(): JsonResponse
    {
        return $this->document($this->catalog->manifest(), Response::HTTP_OK, ['Cache-Control' => 'public, max-age=300']);
    }

    #[Route('/hub/catalog.json', name: 'forge_hub_catalog', methods: ['GET'])]
    public function catalog(Request $request): JsonResponse
    {
        $caller = $this->registry->authenticate($request->headers->get('Authorization'));
        if (null === $caller) {
            return $this->document(['error' => 'Invalid licence key.'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer realm="Glitch Art packages"']);
        }

        return $this->document($this->catalog->catalog($caller[1]), Response::HTTP_OK, ['Cache-Control' => 'private, no-store']);
    }

    /** The key's licences and the machines of its seat (a hub client's Licences page). */
    #[Route('/hub/licenses', name: 'forge_hub_licenses', methods: ['GET'])]
    public function licenses(Request $request): JsonResponse
    {
        $caller = $this->registry->authenticate($request->headers->get('Authorization'));
        if (null === $caller || null === $caller[0]) {
            return $this->document(['error' => 'A licence key is needed.'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer realm="Glitch Art packages"']);
        }

        return $this->document($this->catalog->licenses($caller[1], $caller[0]), Response::HTTP_OK, ['Cache-Control' => 'private, no-store']);
    }

    /** The editor asks for a code to show its user; the link page takes it. */
    #[Route('/hub/device', name: 'forge_hub_device', methods: ['POST'])]
    public function device(Request $request): JsonResponse
    {
        if (!$this->checkout) {
            return $this->document(['error' => 'unsupported'], Response::HTTP_NOT_FOUND);
        }
        $item = json_decode((string) $request->getContent(), true)['item'] ?? $request->request->get('item');
        $item = \is_string($item) && preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/', $item) ? $item : null;
        $code = $this->links->start($item);
        $link = $this->generateUrl('forge_hub_link', [], 0);

        return $this->document($code + [
            'verification_uri' => $link,
            'verification_uri_complete' => $link.'?'.http_build_query(array_filter(['code' => $code['user_code'], 'item' => $item])),
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    /** The editor asks, every interval, whether its user linked it: the key once, else why not. */
    #[Route('/hub/token', name: 'forge_hub_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        $device = json_decode((string) $request->getContent(), true)['device_code'] ?? $request->request->get('device_code');
        if (!\is_string($device) || !preg_match('/^[0-9a-f]{40}$/', $device)) {
            return $this->document(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        }
        $answer = $this->links->poll($device);
        if (isset($answer['error'])) {
            return $this->document($answer, Response::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
        }

        return $this->document($answer + ['registry' => $this->catalog->manifest()['sources'][0]['url']], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private function document(array $data, int $status, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($data, $status, $headers);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $response;
    }
}
