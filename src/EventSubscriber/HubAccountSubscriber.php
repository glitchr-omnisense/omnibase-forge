<?php

namespace Base\Forge\EventSubscriber;

use Base\Forge\Service\HubAccounts;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The hub clients' account API (HubPurchaseController: its routes say `_forge_hub_account`)
 * answers to the account's token only: checked as soon as the route is known, before the request's
 * payload is read, so that a client without a token is told 401 - not that its body is wrong. The
 * user found is the request's `_forge_hub_user`.
 */
final class HubAccountSubscriber implements EventSubscriberInterface
{
    public const USER = '_forge_hub_user';
    public const REALM = 'Bearer realm="Forge account"';

    public function __construct(private readonly HubAccounts $accounts)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // After the router (32), before anything reads the payload.
        return [KernelEvents::REQUEST => ['onRequest', 16]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || true !== $request->attributes->get('_forge_hub_account')) {
            return;
        }
        $user = $this->accounts->authenticate($request->headers->get('Authorization'));
        if (null === $user) {
            $event->setResponse(new JsonResponse(['error' => 'unauthorized'], 401, ['WWW-Authenticate' => self::REALM, 'Cache-Control' => 'no-store']));

            return;
        }
        $request->attributes->set(self::USER, $user);
    }
}
