<?php

namespace Base\Forge\Controller\Client;

use Base\Forge\Model\ActivationRequest;
use Base\Forge\Model\ReleaseRequest;
use Base\Forge\Exception\LicenseActivationException;
use Base\Forge\Service\LicenseActivations;
use Base\Forge\Service\LicenseToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The licence API the studio's software calls (a Unity game, a desktop
 * application): activate a machine and get a signed token to run offline;
 * free the machine; the public key to check the tokens with. JSON, no
 * session: the seat's key is the credential. See docs/licensing.md.
 */
#[Route('/api/licenses')]
class LicenseApiController extends AbstractController
{
    public function __construct(private readonly LicenseActivations $activations)
    {
    }

    /**
     * Activates the machine, or refreshes it: 200 and a token; 401 a wrong
     * key; 403 another software's key, or a licence expired or revoked; 409
     * every place taken - with the machines that hold them.
     */
    #[Route('/activate', name: 'forge_api_license_activate', methods: ['POST'])]
    public function activate(#[MapRequestPayload] ActivationRequest $request): JsonResponse
    {
        try {
            $result = $this->activations->activate($request->key, $request->machine, $request->software, $request->name, $request->platform, $request->version);
        } catch (LicenseActivationException $e) {
            return $this->refusal($e);
        }

        return $this->json([
            'status' => 'active',
            'token' => $result['token'],
            'license' => $result['payload'],
            'machines_left' => $result['machines_left'],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /** Frees the machine's place: 200 whether it held one or not; 401 a wrong key. */
    #[Route('/release', name: 'forge_api_license_release', methods: ['POST'])]
    public function release(#[MapRequestPayload] ReleaseRequest $request): JsonResponse
    {
        try {
            $released = $this->activations->releaseMachine($request->key, $request->machine);
        } catch (LicenseActivationException $e) {
            return $this->refusal($e);
        }

        return $this->json(['status' => $released ? 'released' : 'not_activated'], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * The key to check the tokens with - to ship in the software, or to fetch once -, and after a
     * rotation the previous one, its signature of the current one and the end of its tokens.
     */
    #[Route('/public-key', name: 'forge_api_license_public_key', methods: ['GET'])]
    public function publicKey(LicenseToken $tokens): JsonResponse
    {
        $key = $tokens->publicKey();
        if (!$key) {
            return $this->json(['error' => LicenseActivationException::NOT_CONFIGURED], 503);
        }
        $rotation = $tokens->rotation();

        return $this->json(array_filter([
            'algorithm' => 'Ed25519',
            'public_key' => $key,
            'previous_key' => $rotation['previousKey'] ?? null,
            'rotation' => $rotation['rotation'] ?? null,
            'previous_key_valid_until' => $rotation['previousKeyValidUntil'] ?? null,
        ], fn ($value) => null !== $value));
    }

    private function refusal(LicenseActivationException $e): JsonResponse
    {
        $body = ['error' => $e->reason] + $e->details;
        if (LicenseActivationException::NO_MACHINE_LEFT === $e->reason) {
            $body['manage_url'] = $this->generateUrl('forge_account_licenses', [], 0);
        }

        return $this->json($body, $e->getStatus(), ['Cache-Control' => 'no-store']);
    }
}
