<?php

namespace Base\Forge\Service;

use Base\Entity\User;
use Base\Forge\Entity\HubPurchase;
use Base\Forge\Entity\License;
use Base\Forge\Entity\Product\LicenseOffer;
use Base\Forge\EventListener\HubPurchaseCharges;
use Base\Forge\Exception\HubPurchaseException;
use Base\Forge\Model\PurchaseRequest;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Enum\OrderState;
use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\Event\PaymentCancelledEvent;
use Base\Marketplace\Payment\PaymentResult;
use Base\Marketplace\Service\CartException;
use Base\Marketplace\Service\Checkout;
use Base\Marketplace\Service\Pricing;
use Base\Marketplace\Service\RegionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Omnitrade\Exception\OmnitradeException;
use Omnitrade\Model\SavedMethod;
use Omnitrade\Model\Status;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Lock\LockFactory;

/**
 * One-click purchases from Forge Store and Forge Hub (plan items 16.3 and 17.3).
 *
 * The client first asks what an offer costs this user (quote(): the order's net price as the
 * shop would charge it - VAT, promotions and exemptions included), shows it with the currency
 * and the store's name, and on its user's confirmation sends {offer, price, currency} with an
 * Idempotency-Key. buy() then, under a lock of the user's own (symfony/lock: one purchase of a
 * user at a time, whatever process answers; 409 purchase_in_progress after a wait):
 *
 *   - answers the purchase that key already made, if any - nothing charged again (the same key
 *     with another offer, price or currency is refused: idempotency_key_reused);
 *   - prices the order anew and refuses (409 price_changed, with the price now) when it is not
 *     the amount confirmed: the confirmation shown is always the amount charged;
 *   - charges a saved method of the user (HubPayments) through the marketplace's checkout - a new
 *     order, Checkout::settle(), and the omnitrade bridge, told by HubPurchaseCharges to charge
 *     that method off-session (marketplace's PaymentPreparingEvent::$savedMethod).
 *
 * Paid at once: the order is confirmed and OrderPaidSubscriber issues the licence in the same
 * transaction. The bank asking for its buyer (3-D Secure): requires_action, with the bank's page,
 * which the client opens; the provider's webhook (marketplace_payment_webhook) then confirms the
 * order and the licence is issued; refresh() asks the provider meanwhile, as the shop's return
 * page does. Once paid, the client receives the licence key once (handKey()), and installs.
 */
class HubPurchases
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HubPayments $payments,
        private readonly Checkout $checkout,
        private readonly Pricing $pricing,
        private readonly RegionResolver $regions,
        private readonly LicenseIssuer $issuer,
        private readonly UpmRegistry $registry,
        private readonly LockFactory $locks,
        private readonly HubPurchaseCharges $charges,
        private readonly ?LoggerInterface $logger = null,
        /** How long a purchase waits, in seconds, for the user's purchase before it to finish. */
        private readonly float $lockWait = 15.0,
    ) {
    }

    /**
     * An offer the clients may buy: listed for sale, of a software, in a store.
     *
     * @throws HubPurchaseException unknown_offer (404)
     */
    public function offer(int $id): LicenseOffer
    {
        $offer = $this->entityManager->find(LicenseOffer::class, $id);
        if (!$offer instanceof LicenseOffer || !$offer->isForSell() || null === $offer->getSoftware() || null === $offer->getStore()) {
            throw new HubPurchaseException(HubPurchaseException::UNKNOWN_OFFER, 404);
        }

        return $offer;
    }

    /**
     * What buying the offer charges this user now: the order's net price and currency, priced
     * as the shop prices a cart, in an order that is never stored.
     *
     * @return array{price: int, currency: string}
     */
    public function quote(User $user, LicenseOffer $offer): array
    {
        $order = $this->order($user, $offer);

        return ['price' => (int) $order->getNetPrice(), 'currency' => strtoupper((string) $order->getCurrency())];
    }

    /**
     * @throws HubPurchaseException
     */
    public function buy(User $user, PurchaseRequest $request, string $idempotencyKey): HubPurchase
    {
        // One purchase of a user at a time, across processes: a second one - the same key sent again
        // before the first answered, or another - waits for the first, then finds what it did.
        $lock = $this->locks->createLock('forge-hub-purchase-'.$user->getId(), 120);
        for ($waited = 0.0; !$lock->acquire(false); $waited += 0.1) {
            if ($waited >= $this->lockWait) {
                throw new HubPurchaseException(HubPurchaseException::PURCHASE_IN_PROGRESS, 409);
            }
            usleep(100000);
        }
        try {
            $done = $this->entityManager->getRepository(HubPurchase::class)->findOneBy(['user' => $user, 'idempotencyKey' => $idempotencyKey]);
            if ($done instanceof HubPurchase) {
                if (!$done->isSameRequest($request->offer, $request->price, $request->currency)) {
                    throw new HubPurchaseException(HubPurchaseException::IDEMPOTENCY_KEY_REUSED, 422);
                }

                return $this->refresh($done->markReplayed());
            }

            $offer = $this->offer($request->offer);
            $order = $this->order($user, $offer);
            $price = (int) $order->getNetPrice();
            $currency = strtoupper((string) $order->getCurrency());
            if ($price !== $request->price || $currency !== strtoupper($request->currency)) {
                throw new HubPurchaseException(HubPurchaseException::PRICE_CHANGED, 409, ['offer' => $offer->getId(), 'price' => $price, 'currency' => $currency]);
            }

            $method = $this->method($user, $request->method);
            $paymentMethod = $this->payments->paymentMethod();
            $customer = $this->payments->customer($user) ?? throw new HubPurchaseException(HubPurchaseException::PAYMENT_METHOD_REQUIRED, 409);

            $purchase = new HubPurchase($user, $idempotencyKey, $offer, $price, $currency, (string) $this->payments->gatewayName());
            $this->entityManager->persist($order);
            foreach ($order->getItems() as $item) {
                $this->entityManager->persist($item);
            }
            $this->entityManager->persist($purchase);
            $this->entityManager->flush(); // the order gets its reference
            $purchase->charging($method->reference, $order->getReference());
            $this->entityManager->flush();

            $this->charges->charge($order, $purchase, $method->reference, $customer->getReference());
            try {
                $result = $this->checkout->settle($order, $paymentMethod);
            } catch (CartException $e) {
                $result = PaymentResult::refused($e->getMessage(), $e->getParameters());
            } catch (OmnitradeException $e) {
                $this->logger?->warning('Hub purchase {purchase}: the payment failed: {error}', ['purchase' => $purchase->getToken(), 'error' => $e->getMessage()]);
                $result = PaymentResult::refused('payment.unavailable');
            } finally {
                $this->charges->done($order);
            }

            match (true) {
                $result->isPaid() => $purchase->markPaid(),
                $result->isRedirect() => $purchase->markRequiresAction((string) $result->redirectUrl),
                $result->isRefused() => $this->refused($purchase, $order, self::reason($result)),
                default => $purchase->markPending(),
            };
            $this->entityManager->flush();

            return $purchase;
        } finally {
            $lock->release();
        }
    }

    /**
     * Where the purchase stands now: paid once its order is (the webhook confirmed it); while it
     * waits, the provider is asked, as the shop's return page asks it, and the order confirmed or
     * given up on what it says.
     */
    public function refresh(HubPurchase $purchase): HubPurchase
    {
        if (HubPurchase::PAID === $purchase->getStatus() || null === $purchase->getOrderReference()) {
            return $purchase;
        }
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['reference' => $purchase->getOrderReference()]);
        if (!$order instanceof Order) {
            return $purchase;
        }
        // Paid is paid, whatever was said before: the money came in, the licence is issued.
        if (null !== $order->getPaidAt()) {
            $purchase->markPaid();
            $this->entityManager->flush();

            return $purchase;
        }
        if ($purchase->isOver()) {
            return $purchase;
        }
        $transaction = null;
        foreach (array_reverse($order->getTransactions()->toArray()) as $candidate) {
            if ($candidate instanceof Transaction && $candidate->getWebhook()) {
                $transaction = $candidate;
                break;
            }
        }
        if (null === $transaction) {
            return $purchase;
        }
        try {
            $paid = $this->payments->bridge()->fetch($transaction);
        } catch (OmnitradeException|HubPurchaseException) {
            return $purchase; // asked again next time
        }
        if (Status::PAID === $paid->status) {
            $this->checkout->confirm($order, $transaction);
            $purchase->markPaid();
        } elseif ($paid->status->isFinal()) {
            $this->checkout->cancel($order, $transaction);
            $this->refused($purchase, $order, $paid->message);
        }
        $this->entityManager->flush();

        return $purchase;
    }

    /**
     * The licence key, handed to the client once its purchase is paid and its licence issued: a
     * new key of the user's seat on it, as the device flow hands one. Null before, and after.
     *
     * @return array{key: string, email: string, registry: string}|null
     */
    public function handKey(HubPurchase $purchase): ?array
    {
        if (HubPurchase::PAID !== $purchase->getStatus() || null !== $purchase->getKeyHandedAt() || null === $purchase->getOrderReference()) {
            return null;
        }
        $license = $this->entityManager->getRepository(License::class)->findOneBy(['orderReference' => $purchase->getOrderReference()], ['id' => 'ASC']);
        $seat = $license instanceof License ? $license->findSeatOf($purchase->getUser()) : null;
        if (null === $seat) {
            return null;
        }
        $purchase->markKeyHanded(new \DateTimeImmutable());
        $key = $this->issuer->rekey($seat); // flushes

        return ['key' => $key, 'email' => $seat->getEmail(), 'registry' => $this->registry->url()];
    }

    /** A hub purchase's order paid (the webhook, the return page, or at once): the purchase is. */
    #[AsEventListener(event: OrderPaidEvent::class)]
    public function onPaid(OrderPaidEvent $event): void
    {
        $purchase = $this->of($event->order);
        if ($purchase && HubPurchase::PAID !== $purchase->getStatus()) {
            $purchase->markPaid();
        }
    }

    /**
     * A hub purchase's payment given up (refused after the bank's check, cancelled): the purchase
     * is refused, and its order abandoned rather than left as the user's cart in the shop.
     */
    #[AsEventListener(event: PaymentCancelledEvent::class)]
    public function onCancelled(PaymentCancelledEvent $event): void
    {
        $purchase = $this->of($event->order);
        if ($purchase && !$purchase->isOver() && null === $event->order->getPaidAt()) {
            $this->refused($purchase, $event->order, $event->transaction->getDetails()['message'] ?? null);
            $this->entityManager->flush();
        }
    }

    public function find(User $user, string $token): ?HubPurchase
    {
        $purchase = $this->entityManager->getRepository(HubPurchase::class)->findOneBy(['token' => $token]);

        return $purchase instanceof HubPurchase && $purchase->getUser()->getId() === $user->getId() ? $purchase : null;
    }

    public function findByToken(string $token): ?HubPurchase
    {
        $purchase = $this->entityManager->getRepository(HubPurchase::class)->findOneBy(['token' => $token]);

        return $purchase instanceof HubPurchase ? $purchase : null;
    }

    /**
     * The purchase as the clients read it.
     *
     * @param array{key: string, email: string, registry: string}|null $key
     *
     * @return array<string, mixed>
     */
    public function describe(HubPurchase $purchase, ?array $key = null): array
    {
        $software = $purchase->getOffer()?->getSoftware();

        return array_filter([
            'id' => $purchase->getToken(),
            'status' => $purchase->getStatus(),
            'offer' => $purchase->getOffer()?->getId(),
            'price' => $purchase->getPrice(),
            'currency' => $purchase->getCurrency(),
            'order' => $purchase->getOrderReference(),
            'software' => $software?->getSlug(),
            'package' => $software?->getUpmName(),
            'url' => HubPurchase::REQUIRES_ACTION === $purchase->getStatus() ? $purchase->getRedirectUrl() : null,
            'reason' => HubPurchase::REFUSED === $purchase->getStatus() ? $purchase->getReason() : null,
        ], static fn ($value) => null !== $value) + ($key ?? []);
    }

    /** The order buying one unit of the offer, for the user, priced - not stored. */
    private function order(User $user, LicenseOffer $offer): Order
    {
        $store = $offer->getStore();
        $order = new Order($store);
        $order->setCustomer($user);
        $order->setRegion($this->regions->for($store));
        $order->addItem(new OrderItem($offer, 1));
        $this->pricing->reprice($order);

        return $order;
    }

    /**
     * The saved method to charge: the one named, when it is the user's, else their first one that
     * has not expired.
     *
     * @throws HubPurchaseException payment_method_required (409), unknown_payment_method (422)
     */
    private function method(User $user, ?string $named): SavedMethod
    {
        $methods = $this->payments->methods($user);
        foreach ($methods as $method) {
            if (null !== $named ? $method->reference === $named : !$method->isExpired()) {
                return $method;
            }
        }
        if (null !== $named && [] !== $methods) {
            throw new HubPurchaseException(HubPurchaseException::UNKNOWN_PAYMENT_METHOD, 422);
        }

        throw new HubPurchaseException(HubPurchaseException::PAYMENT_METHOD_REQUIRED, 409);
    }

    private function refused(HubPurchase $purchase, Order $order, ?string $reason): void
    {
        $purchase->markRefused($reason);
        // Not the user's cart in the shop: it was the editor's purchase.
        if (null === $order->getPaidAt() && OrderState::CART === $order->getState()) {
            $order->markAsAbandoned();
        }
    }

    private static function reason(PaymentResult $result): string
    {
        $reason = $result->parameters['{reason}'] ?? null;

        return \is_string($reason) && '' !== $reason ? $reason : (string) $result->reason;
    }

    private function of(Order $order): ?HubPurchase
    {
        $reference = $order->getReference();
        if (null === $reference || '' === $reference) {
            return null;
        }
        $purchase = $this->entityManager->getRepository(HubPurchase::class)->findOneBy(['orderReference' => $reference]);

        return $purchase instanceof HubPurchase ? $purchase : null;
    }
}
