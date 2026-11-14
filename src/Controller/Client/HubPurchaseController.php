<?php

namespace Base\Forge\Controller\Client;

use Base\Entity\User;
use Base\Forge\Entity\HubPurchase;
use Base\Forge\EventSubscriber\HubAccountSubscriber;
use Base\Forge\Exception\HubPurchaseException;
use Base\Forge\Model\PurchaseRequest;
use Base\Forge\Model\QuoteQuery;
use Base\Forge\Service\HubCatalog;
use Base\Forge\Service\HubPayments;
use Base\Forge\Service\HubPurchases;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Buying from the editor or the desktop app (plan items 16.2 to 16.4, 17.3; docs/hub.md, "Buying
 * in one click"): the user's saved payment methods - listed, added on the provider's page,
 * removed -, the price an offer comes to for them, and the one-click purchase with its status.
 * JSON, no session: the account's token from the device flow is the credential
 * (`Authorization: Bearer gla_...`, checked by HubAccountSubscriber before anything else). Under
 * /upm, whose firewall the host leaves open.
 */
#[Route('/upm/-/hub', defaults: ['_forge_hub_account' => true])]
class HubPurchaseController extends AbstractController
{
    public function __construct(
        private readonly HubPayments $payments,
        private readonly HubPurchases $purchases,
        private readonly HubCatalog $catalog,
    ) {
    }

    /** The user's saved methods, as the provider has them now. */
    #[Route('/payment-methods', name: 'forge_hub_payment_methods', methods: ['GET'])]
    public function methods(Request $request): JsonResponse
    {
        return $this->guarded($request, fn (User $user) => $this->document([
            'gateway' => $this->payments->gatewayName(),
            'methods' => array_map(HubPayments::describe(...), $this->payments->methods($user)),
        ]));
    }

    /** A method to save: the provider's page to open (the system browser, Forge Hub's webview). */
    #[Route('/payment-methods', name: 'forge_hub_payment_method_add', methods: ['POST'])]
    public function addMethod(Request $request): JsonResponse
    {
        return $this->guarded($request, function (User $user) use ($request) {
            $setup = $this->payments->setup($user, $this->generateUrl('forge_hub_payment_method_saved', [], UrlGeneratorInterface::ABSOLUTE_URL), $request->getPreferredLanguage() ?: null);

            return $this->document(['id' => $setup->reference, 'url' => $setup->redirectUrl], Response::HTTP_CREATED);
        });
    }

    #[Route('/payment-methods/{id}', name: 'forge_hub_payment_method_remove', requirements: ['id' => '[A-Za-z0-9_-]{1,255}'], methods: ['DELETE'])]
    public function removeMethod(Request $request, string $id): Response
    {
        return $this->guarded($request, function (User $user) use ($id) {
            $this->payments->remove($user, $id);

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        });
    }

    /**
     * What buying the offer charges the user now - what the confirmation shows, with the
     * currency and the store's name (plan item 17.3) - and the method it would be charged to.
     */
    #[Route('/quote', name: 'forge_hub_quote', methods: ['GET'])]
    public function quote(Request $request, #[MapQueryString] QuoteQuery $query): JsonResponse
    {
        return $this->guarded($request, function (User $user) use ($query) {
            $offer = $this->purchases->offer($query->offer);
            $methods = array_values(array_filter($this->payments->methods($user), static fn ($method) => !$method->isExpired()));

            return $this->document([
                'offer' => $offer->getId(),
                'title' => (string) $offer,
                'software' => $offer->getSoftware()?->getSlug(),
                'package' => $offer->getSoftware()?->getUpmName(),
                'store' => $this->catalog->manifest()['name'],
            ] + $this->purchases->quote($user, $offer) + [
                'method' => [] !== $methods ? HubPayments::describe($methods[0]) : null,
                // What the confirmation shows its user to accept, and the purchase sends back (docs/hub.md).
                'consent' => $this->purchases->consent($user, $offer, $query->locale),
            ]);
        });
    }

    /**
     * The purchase, as its user confirmed it, sent with an Idempotency-Key: 201 paid or waiting
     * (requires_action: the bank's page in "url"), 402 refused, 409 price_changed (with the price
     * now), consent_changed (the statements to show again) or payment_method_required, 422
     * consent_required (a statement missing or not true) or the key sent before with another body; the same key
     * again answers the same purchase as it stands (200, Idempotent-Replayed: true), charging
     * nothing more.
     */
    #[Route('/purchases', name: 'forge_hub_purchase', methods: ['POST'])]
    public function buy(Request $request, #[MapRequestPayload] PurchaseRequest $purchase): JsonResponse
    {
        return $this->guarded($request, function (User $user) use ($request, $purchase) {
            $key = (string) $request->headers->get('Idempotency-Key', '');
            if (!preg_match('/^[A-Za-z0-9_.:-]{8,64}$/', $key)) {
                return $this->document(['error' => 'idempotency_key_required'], Response::HTTP_BAD_REQUEST);
            }
            $bought = $this->purchases->buy($user, $purchase, $key);
            $response = $this->answer($bought, $this->purchases->handKey($bought), $bought->isReplayed() ? Response::HTTP_OK : Response::HTTP_CREATED);
            if ($bought->isReplayed()) {
                $response->headers->set('Idempotent-Replayed', 'true');
            }

            return $response;
        });
    }

    /** Where it stands: polled while it waits; once paid, the licence key, handed over once. */
    #[Route('/purchases/{token}', name: 'forge_hub_purchase_status', requirements: ['token' => '[0-9a-f]{32}'], methods: ['GET'])]
    public function status(Request $request, string $token): JsonResponse
    {
        return $this->guarded($request, function (User $user) use ($token) {
            $purchase = $this->purchases->find($user, $token) ?? throw new HubPurchaseException(HubPurchaseException::UNKNOWN_PURCHASE, 404);
            $this->purchases->refresh($purchase);

            return $this->answer($purchase, $this->purchases->handKey($purchase), Response::HTTP_OK);
        });
    }

    /**
     * A paid purchase's key again, for a client that lost the answer carrying it: a new key of the
     * buyer's seat on its licence, the previous one ending (only keys' hashes are kept). Owner only;
     * 404 unknown_purchase, 409 purchase_not_paid or license_unavailable (docs/hub.md, "A purchase's
     * key again").
     */
    #[Route('/purchases/{token}/key', name: 'forge_hub_purchase_key', requirements: ['token' => '[0-9a-f]{32}'], methods: ['POST'])]
    public function key(Request $request, string $token): JsonResponse
    {
        return $this->guarded($request, function (User $user) use ($token) {
            $key = $this->purchases->reissueKey($user, $token);

            return $this->document($this->purchases->describe($this->purchases->find($user, $token) ?? throw new HubPurchaseException(HubPurchaseException::UNKNOWN_PURCHASE, 404), $key));
        });
    }

    /** @param array{key: string, email: string, registry: string}|null $key */
    private function answer(HubPurchase $purchase, ?array $key, int $status): JsonResponse
    {
        $body = $this->purchases->describe($purchase, $key);
        if (HubPurchase::REFUSED === $purchase->getStatus()) {
            return $this->document(['error' => 'payment_refused'] + $body, Response::HTTP_PAYMENT_REQUIRED);
        }

        return $this->document($body, $status);
    }

    /**
     * The user HubAccountSubscriber found for the request's token (it answered 401 without one),
     * then what $then answers - or the refusal it throws, as JSON.
     *
     * @param callable(User): Response $then
     */
    private function guarded(Request $request, callable $then): JsonResponse
    {
        $user = $request->attributes->get(HubAccountSubscriber::USER);
        if (!$user instanceof User) {
            return $this->document(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => HubAccountSubscriber::REALM]);
        }
        try {
            $response = $then($user);
        } catch (HubPurchaseException $e) {
            return $this->document($e->body(), $e->status);
        }

        return $response instanceof JsonResponse ? $response : new JsonResponse(null, $response->getStatusCode());
    }

    /** @param array<string, mixed> $data */
    private function document(array $data, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($data, $status, $headers + ['Cache-Control' => 'no-store']);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $response;
    }
}
