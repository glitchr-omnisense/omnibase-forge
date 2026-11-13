<?php

namespace Base\Forge\EventListener;

use Base\Forge\Entity\HubPurchase;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Event\PaymentPreparingEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The orders HubPurchases is settling, and how the omnitrade bridge charges them: with the
 * user's saved method, off-session (marketplace's PaymentPreparingEvent::$savedMethod, for the
 * provider's customer), the bank's page sending its buyer back to the purchase's page rather
 * than to the shop's cart. Any other order is left as the shop prepares it.
 */
final class HubPurchaseCharges
{
    /** @var array<int, array{HubPurchase, string, string}> the purchase, method and customer, by the order's object id */
    private array $charging = [];

    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function charge(Order $order, HubPurchase $purchase, string $method, string $customer): void
    {
        $this->charging[spl_object_id($order)] = [$purchase, $method, $customer];
    }

    public function done(Order $order): void
    {
        unset($this->charging[spl_object_id($order)]);
    }

    #[AsEventListener(event: PaymentPreparingEvent::class)]
    public function onPreparing(PaymentPreparingEvent $event): void
    {
        [$purchase, $method, $customer] = $this->charging[spl_object_id($event->order)] ?? [null, null, null];
        if (null === $purchase) {
            return;
        }
        $event->savedMethod = $method;
        $event->customer = $customer;
        $event->returnUrl = $this->urls->generate('forge_hub_purchase_return', ['token' => $purchase->getToken()], UrlGeneratorInterface::ABSOLUTE_URL);
        $event->metadata['hub_purchase'] = $purchase->getToken();
        $software = $purchase->getOffer()?->getSoftware()?->getName();
        $event->description = null !== $software ? sprintf('%s - %s', $software, (string) $purchase->getOffer()) : (string) $purchase->getOffer();
    }
}
