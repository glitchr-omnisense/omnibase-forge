<?php

namespace Base\Forge\Controller\Client;

use Base\Entity\User;
use Base\Forge\Entity\HubToken;
use Base\Forge\EventSubscriber\HubAccountSubscriber;
use Base\Forge\Service\HubAccounts;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The editors linked to an account (docs/hub.md, "Linked editors and signing out"): each device
 * flow approved is a token of the account's; a client lists them, signs itself out by revoking
 * its own, or another one. The account's token is the credential (HubAccountSubscriber, before
 * anything else); JSON, no session, under /upm as the purchases.
 */
#[Route('/upm/-/hub/account-tokens', defaults: ['_forge_hub_account' => true])]
class HubAccountController extends AbstractController
{
    public function __construct(private readonly HubAccounts $accounts)
    {
    }

    #[Route('', name: 'forge_hub_account_tokens', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        [$user, $current] = $this->caller($request);
        if (null === $user) {
            return $this->unauthorized();
        }

        return $this->document(['tokens' => array_map(static fn (HubToken $token) => HubAccounts::describe($token, $current), $this->accounts->tokensOf($user))]);
    }

    /** Signing this editor out: the token sent is revoked, 401 from then on. */
    #[Route('/current', name: 'forge_hub_account_token_sign_out', methods: ['DELETE'])]
    public function signOut(Request $request): JsonResponse
    {
        [$user, $current] = $this->caller($request);
        if (null === $user || null === $current) {
            return $this->unauthorized();
        }
        $this->accounts->revoke($user, (int) $current->getId());

        return $this->document(null, Response::HTTP_NO_CONTENT);
    }

    /** Another token of the account revoked: 404 unknown_account_token for one that is not the account's. */
    #[Route('/{id}', name: 'forge_hub_account_token_revoke', requirements: ['id' => '\d{1,18}'], methods: ['DELETE'])]
    public function revoke(Request $request, int $id): JsonResponse
    {
        [$user] = $this->caller($request);
        if (null === $user) {
            return $this->unauthorized();
        }
        if (!$this->accounts->revoke($user, $id)) {
            return $this->document(['error' => 'unknown_account_token'], Response::HTTP_NOT_FOUND);
        }

        return $this->document(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array{0: ?User, 1: ?HubToken} what HubAccountSubscriber found for the request's token */
    private function caller(Request $request): array
    {
        $user = $request->attributes->get(HubAccountSubscriber::USER);
        $token = $request->attributes->get(HubAccountSubscriber::TOKEN);

        return [$user instanceof User ? $user : null, $token instanceof HubToken ? $token : null];
    }

    private function unauthorized(): JsonResponse
    {
        return $this->document(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => HubAccountSubscriber::REALM]);
    }

    /** @param array<string, mixed>|null $data */
    private function document(?array $data, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($data, $status, $headers + ['Cache-Control' => 'no-store']);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (Response::HTTP_NO_CONTENT === $status) {
            $response->setContent('');
        }

        return $response;
    }
}
