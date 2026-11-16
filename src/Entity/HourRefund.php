<?php

namespace Base\Forge\Entity;

use Base\Entity\User;
use Base\Mapping as ORM;

/**
 * Hours bought, paid back: the studio keeps the right to refund a purchase
 * - when the work asked for does not sit with the client's values, or the
 * studio's - and says why. The amount goes back on the card through
 * Stripe (or by bank transfer, by hand, for the manual gateway); the
 * hours it covered leave the ledger (a `refund` line); the client is told,
 * with the explanation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forge_hour_refund')]
#[ORM\Index(name: 'forge_hour_refund_order', columns: ['orderReference'])]
class HourRefund implements \Stringable
{
    public const STRIPE = 'stripe';
    public const TRANSFER = 'transfer';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $customer;

    #[ORM\Column(length: 64)]
    private string $orderReference;

    /** In cents, of the order's currency. */
    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** The hours it takes back from the ledger. */
    #[ORM\Column]
    private int $minutes;

    /** Why - sent to the client as written. */
    #[ORM\Column(type: 'text')]
    private string $reason;

    /** stripe (paid back on the card) or transfer (by hand). */
    #[ORM\Column(length: 16)]
    private string $method;

    /** Stripe's refund id (re_…). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stripeRefund = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $refundedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(?User $customer, string $orderReference, int $amount, string $currency, int $minutes, string $reason, string $method)
    {
        $this->customer = $customer;
        $this->orderReference = $orderReference;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->minutes = $minutes;
        $this->reason = $reason;
        $this->method = $method;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s − %s %s', $this->orderReference, number_format($this->amount / 100, 2, ',', ' '), $this->currency);
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): ?User { return $this->customer; }
    public function getOrderReference(): string { return $this->orderReference; }
    public function getAmount(): int { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getMinutes(): int { return $this->minutes; }
    public function getReason(): string { return $this->reason; }
    public function getMethod(): string { return $this->method; }
    public function isByTransfer(): bool { return self::TRANSFER === $this->method; }
    public function getStripeRefund(): ?string { return $this->stripeRefund; }
    public function setStripeRefund(?string $stripeRefund): self { $this->stripeRefund = $stripeRefund; return $this; }
    public function getRefundedBy(): ?User { return $this->refundedBy; }
    public function setRefundedBy(?User $refundedBy): self { $this->refundedBy = $refundedBy; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
