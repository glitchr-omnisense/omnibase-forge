<?php

namespace Base\Forge\Service;

use Base\Entity\User;
use Base\Forge\Entity\PaymentCustomer;
use Base\Forge\Entity\PaymentSetup;
use Base\Forge\Exception\HubPurchaseException;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateway;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateways;
use Doctrine\ORM\EntityManagerInterface;
use Omnitrade\Exception\OmnitradeException;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Customer;
use Omnitrade\Model\MethodSetup;
use Omnitrade\Model\SavedMethod;
use Omnitrade\Request\CompleteMethodSetup;
use Omnitrade\Request\FetchSavedMethods;
use Omnitrade\Request\RemoveSavedMethod;
use Omnitrade\Request\SetupMethod;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * The payment methods a hub client's user saves once and buys with (plan items 16.2 and 16.4):
 * on the provider's hosted page, through the omnitrade gateway forge.hub.payment_gateway names
 * (omnisense/omnitrade 1.2's SetupMethod and CompleteMethodSetup), for a customer of theirs at the provider - the only
 * thing kept here (PaymentCustomer). The methods are the provider's, read from it each time: the
 * forge never sees nor stores a card number.
 *
 * A user's customer is made under a lock of their own, so that two pages opened at once do not
 * make them two customers at the provider.
 *
 * Each setup is kept (PaymentSetup) until the buyer is back on the return page, which completes
 * it (complete(): omnisense/omnitrade 1.2's CompleteMethodSetup whenever the gateway supports it -
 * a read at Stripe, the approved setup turned into a saved method at PayPal; the same code for
 * every provider). A buyer who closed the tab before the provider sent them back has theirs
 * completed the next time their methods are listed (methods()), for three hours.
 */
class HubPayments
{
    /** How long a setup not completed yet is asked again when the methods are listed. */
    public const SETUP_LIFETIME = '3 hours';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $locks,
        private readonly ?OmnitradeGateways $gateways = null,
        #[Autowire('%forge.hub.payment_gateway%')] private readonly ?string $gatewayName = 'stripe',
    ) {
    }

    /** The omnitrade gateway one-click purchases go through, when one is set. */
    public function gatewayName(): ?string
    {
        return null !== $this->gatewayName && '' !== $this->gatewayName ? $this->gatewayName : null;
    }

    /** Whether one-click purchases are offered here: a gateway set, and omnitrade installed. */
    public function isEnabled(): bool
    {
        return null !== $this->gatewayName() && null !== $this->gateways;
    }

    /**
     * The marketplace's bridge to that gateway, able to save methods.
     *
     * @throws HubPurchaseException payments_unavailable (503): no gateway, no omnitrade, keys missing
     */
    public function bridge(): OmnitradeGateway
    {
        $name = $this->gatewayName();
        $bridge = null !== $name ? $this->gateways?->get($name) : null;
        if (null === $bridge || !class_exists(SetupMethod::class) || !$bridge->gateway()->supports(SetupMethod::class)) {
            throw new HubPurchaseException(HubPurchaseException::PAYMENTS_UNAVAILABLE, 503);
        }

        return $bridge;
    }

    /**
     * The shop's payment method naming that gateway (its gatewayFactory), which the order is paid with.
     *
     * @throws HubPurchaseException payments_unavailable (503) when the shop has none
     */
    public function paymentMethod(): PaymentMethod
    {
        $method = $this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['gatewayFactory' => $this->gatewayName()], ['id' => 'DESC']);
        if (!$method instanceof PaymentMethod) {
            throw new HubPurchaseException(HubPurchaseException::PAYMENTS_UNAVAILABLE, 503);
        }

        return $method;
    }

    /** Who the user is at the provider, once they saved a method. */
    public function customer(User $user): ?PaymentCustomer
    {
        return $this->entityManager->getRepository(PaymentCustomer::class)->findOneBy(['user' => $user, 'gateway' => $this->gatewayName()]);
    }

    /**
     * The user's saved methods, as the provider has them now; none before they saved one.
     *
     * @return list<SavedMethod>
     *
     * @throws HubPurchaseException payments_unavailable (503): the provider cannot be asked
     */
    public function methods(User $user): array
    {
        $this->completePending($user);
        $customer = $this->customer($user);
        if (null === $customer) {
            return [];
        }
        try {
            return $this->bridge()->gateway()->execute(new FetchSavedMethods($customer->getReference()))->getMethods();
        } catch (OmnitradeException) {
            throw new HubPurchaseException(HubPurchaseException::PAYMENTS_UNAVAILABLE, 503);
        }
    }

    /**
     * The provider's page where the user saves a method, for their customer there - made now
     * when they have none, and kept. With $token, the setup is kept under it (PaymentSetup) for
     * the return page to complete; $returnUrl is expected to carry it (?setup=<token>).
     *
     * @throws HubPurchaseException payments_unavailable (503)
     */
    public function setup(User $user, string $returnUrl, ?string $locale = null, ?string $token = null): MethodSetup
    {
        $bridge = $this->bridge();
        $lock = $this->locks->createLock('forge-hub-account-'.$user->getId(), 60);
        $lock->acquire(true);
        try {
            $customer = $this->customer($user);
            try {
                $setup = $bridge->gateway()->execute(new SetupMethod(
                    new Customer((string) $user->getEmail(), (string) $user, $customer?->getReference()),
                    $returnUrl,
                    idempotencyKey: 'forge-setup-'.$user->getId().'-'.bin2hex(random_bytes(8)),
                    metadata: ['forge_user' => (string) $user->getId()],
                    locale: $locale,
                ))->getSetup();
            } catch (OmnitradeException) {
                throw new HubPurchaseException(HubPurchaseException::PAYMENTS_UNAVAILABLE, 503);
            }
            if (null === $setup) {
                throw new HubPurchaseException(HubPurchaseException::PAYMENTS_UNAVAILABLE, 503);
            }
            if (null === $customer && null !== $setup->customer) {
                $this->entityManager->persist(new PaymentCustomer($user, (string) $this->gatewayName(), $setup->customer));
            }
            if (null !== $token) {
                $this->entityManager->persist(new PaymentSetup($user, (string) $this->gatewayName(), $token, $setup->reference, $setup->customer ?? $customer?->getReference()));
            }
            $this->entityManager->flush();

            return $setup;
        } finally {
            $lock->release();
        }
    }

    /** The setup a return URL names, or null. */
    public function findSetup(string $token): ?PaymentSetup
    {
        $setup = $this->entityManager->getRepository(PaymentSetup::class)->findOneBy(['token' => $token]);

        return $setup instanceof PaymentSetup ? $setup : null;
    }

    /**
     * The setup completed at the provider now that its buyer is back (CompleteMethodSetup, when the
     * gateway supports it; one that saves on its own page needs nothing more): saved, or not saved
     * when the buyer did not finish it ($final: they came back without - else it stays pending,
     * they may still be on the page). Flushed. Asked again of a setup already completed, it says
     * nothing new.
     *
     * @return SavedMethod|null the method saved, when the provider answers it
     *
     * @throws HubPurchaseException unknown_payment_method (404): not this customer's setup (marked not saved);
     *                              payments_unavailable (503): the provider cannot be asked (kept pending)
     */
    public function complete(PaymentSetup $setup, bool $final = true): ?SavedMethod
    {
        if (!$setup->isPending()) {
            return null;
        }
        $gateway = $this->bridge()->gateway();
        if (!$gateway->supports(CompleteMethodSetup::class)) {
            $setup->markSaved();
            $this->entityManager->flush();

            return null;
        }
        try {
            $method = $gateway->execute(new CompleteMethodSetup($setup->getReference(), $setup->getCustomer()))->getMethod();
            $setup->markSaved();
        } catch (ProviderException $e) {
            if (CompleteMethodSetup::NOT_COMPLETED === $e->providerCode) {
                if ($final) {
                    $setup->markNotSaved();
                    $this->entityManager->flush();
                }

                return null;
            }
            if (CompleteMethodSetup::NOT_THIS_CUSTOMER === $e->providerCode) {
                $setup->markNotSaved();
                $this->entityManager->flush();

                throw new HubPurchaseException(HubPurchaseException::UNKNOWN_PAYMENT_METHOD, 404);
            }

            throw new HubPurchaseException(HubPurchaseException::PAYMENTS_UNAVAILABLE, 503);
        } catch (OmnitradeException) {
            throw new HubPurchaseException(HubPurchaseException::PAYMENTS_UNAVAILABLE, 503);
        }
        $this->entityManager->flush();

        return $method;
    }

    /**
     * The user's setups still pending on this gateway: completed when younger than SETUP_LIFETIME
     * (a buyer who saved the method and closed the tab before being sent back), given up older.
     * Nothing it meets stops the listing.
     */
    private function completePending(User $user): void
    {
        $since = new \DateTimeImmutable('-'.self::SETUP_LIFETIME);
        foreach ($this->entityManager->getRepository(PaymentSetup::class)->findBy(['user' => $user, 'gateway' => $this->gatewayName(), 'state' => PaymentSetup::PENDING], ['id' => 'ASC']) as $setup) {
            $young = $setup->getCreatedAt() > $since;
            try {
                $this->complete($setup, !$young);
            } catch (HubPurchaseException) {
                // Not this customer's (marked not saved), or the provider unreachable (asked again next time).
            }
            if (!$young && $setup->isPending()) {
                $setup->markNotSaved();
                $this->entityManager->flush();
            }
        }
    }

    /**
     * One of the user's methods forgotten at the provider.
     *
     * @throws HubPurchaseException unknown_payment_method (404): not one of theirs; payments_unavailable (503)
     */
    public function remove(User $user, string $reference): void
    {
        $customer = $this->customer($user) ?? throw new HubPurchaseException(HubPurchaseException::UNKNOWN_PAYMENT_METHOD, 404);
        try {
            $this->bridge()->gateway()->execute(new RemoveSavedMethod($customer->getReference(), $reference));
        } catch (ProviderException $e) {
            if (CompleteMethodSetup::NOT_THIS_CUSTOMER === $e->providerCode || 'resource_missing' === $e->providerCode) {
                throw new HubPurchaseException(HubPurchaseException::UNKNOWN_PAYMENT_METHOD, 404);
            }

            throw new HubPurchaseException(HubPurchaseException::PAYMENTS_UNAVAILABLE, 503);
        } catch (OmnitradeException) {
            throw new HubPurchaseException(HubPurchaseException::PAYMENTS_UNAVAILABLE, 503);
        }
    }

    /**
     * A saved method as the clients show it: what the buyer recognises it by, never more.
     *
     * @return array<string, mixed>
     */
    public static function describe(SavedMethod $method): array
    {
        return array_filter([
            'id' => $method->reference,
            'type' => $method->code,
            'label' => $method->label,
            'brand' => $method->brand,
            'last4' => $method->last4,
            'expires' => null !== $method->expiryYear && null !== $method->expiryMonth ? sprintf('%04d-%02d', $method->expiryYear, $method->expiryMonth) : null,
            'expired' => $method->isExpired(),
        ], static fn ($value) => null !== $value);
    }
}
