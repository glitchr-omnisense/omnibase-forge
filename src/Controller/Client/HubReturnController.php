<?php

namespace Base\Forge\Controller\Client;

use Base\Forge\Entity\HubPurchase;
use Base\Forge\Service\HubPurchases;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where a payment provider's page sends a hub client's user back, in the browser the editor
 * opened - signed in or not: after saving a payment method, and after the bank's own check of a
 * one-click purchase (3-D Secure). Nothing to do there but go back to Unity: the client sees the
 * method, or the purchase paid, by asking the forge. The purchase's page asks the provider once
 * (HubPurchases::refresh(), as the shop's return page does), so a purchase is paid even before
 * the webhook says so; it is named by its token, which only its client knows.
 */
class HubReturnController extends AbstractController
{
    #[Route('/hub/payment-methods/saved', name: 'forge_hub_payment_method_saved', methods: ['GET'])]
    public function saved(): Response
    {
        return $this->render('@Forge/client/hub/returned.html.twig', ['state' => 'saved']);
    }

    #[Route('/hub/purchases/{token}/done', name: 'forge_hub_purchase_return', requirements: ['token' => '[0-9a-f]{32}'], methods: ['GET'])]
    public function done(string $token, HubPurchases $purchases): Response
    {
        $purchase = $purchases->findByToken($token);
        if (!$purchase instanceof HubPurchase) {
            throw $this->createNotFoundException('No such purchase.');
        }
        $purchases->refresh($purchase);

        return $this->render('@Forge/client/hub/returned.html.twig', ['state' => $purchase->getStatus()]);
    }
}
