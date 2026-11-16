<?php

namespace Base\Forge\EventSubscriber;

use Base\Entity\User;
use Base\Forge\Entity\License;
use Base\Forge\Entity\Product\HourPack;
use Base\Forge\Entity\Product\LicenseOffer;
use Base\Forge\Entity\Quote;
use Base\Forge\Enum\CreditReason;
use Base\Forge\Enum\QuoteStatus;
use Base\Forge\Repository\HourCreditRepository;
use Base\Forge\Service\HourLedger;
use Base\Forge\Service\LicenseIssuer;
use Base\Marketplace\Event\OrderPaidEvent;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Repositories;
use Base\Contract\UnitOfWork;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * What a paid order delivers, since nothing here ships:
 *
 *   HourPack      its minutes × quantity on the buyer's ledger
 *   LicenseOffer  one licence per unit, for the offer's software
 *   a quote's     the quote marked paid (its pack is an HourPack: the
 *   own pack      hours come with it)
 *
 * Checkout::confirm() dispatches OrderPaidEvent once, but a payment
 * provider's webhook and its redirect may both get there: everything is
 * skipped when this order's reference was already delivered.
 */
final class OrderPaidSubscriber
{
    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        private readonly Repositories $repositories,
        private readonly HourLedger $ledger,
        private readonly HourCreditRepository $credits,
        private readonly LicenseIssuer $issuer,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[AsEventListener(event: OrderPaidEvent::class)]
    public function __invoke(OrderPaidEvent $event): void
    {
        $order = $event->order;
        $reference = (string) $order->getReference();
        $customer = $order->getCustomer();

        if (!$customer instanceof User || $this->delivered($reference)) {
            return;
        }

        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            $quantity = max(1, (int) $item->getQuantity());

            if ($product instanceof HourPack && $product->getMinutes() > 0) {
                $this->ledger->credit($customer, $product->getMinutes() * $quantity, CreditReason::PURCHASE, (string) $product, $reference);

                $quote = $this->repositories->of(Quote::class)->findOneBy(['product' => Key::of($product)]);
                if ($quote) {
                    $quote->setStatus(QuoteStatus::PAID);
                    $quote->setOrderReference($reference);
                }
            }

            if ($product instanceof LicenseOffer) {
                if (!$software = $product->getSoftware()) {
                    $this->logger?->error('Licence offer {offer} has no software: order {order} paid, nothing issued.', ['offer' => (string) $product, 'order' => $reference]);
                    continue;
                }
                for ($i = 0; $i < $quantity; ++$i) {
                    $this->issuer->issue($software, $customer, $product, $reference);
                }
            }
        }

        $this->unitOfWork->flush();
    }

    private function delivered(string $reference): bool
    {
        return '' !== $reference && (
            $this->credits->hasOrder($reference)
            || null !== $this->repositories->of(License::class)->findOneBy(['orderReference' => $reference])
        );
    }
}
