<?php

namespace Base\Forge\Entity;

use Base\Entity\User;
use Base\Forge\Entity\Product\LicenseOffer;
use Doctrine\ORM\Mapping as ORM;

/**
 * A one-click purchase from Forge Store or Forge Hub (plan item 16.3): an offer bought with a
 * saved payment method, off-session, at the price the client confirmed (item 17.3). One per
 * user and idempotency key: the same request sent twice - a retry after a timeout, a double
 * click - finds this row and charges nothing more (HubPurchases). $token is how the client
 * names it afterwards (GET /upm/-/hub/purchases/<token>), never the row's id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forge_hub_purchase')]
#[ORM\UniqueConstraint(name: 'forge_hub_purchase_key', fields: ['user', 'idempotencyKey'])]
#[ORM\Index(name: 'forge_hub_purchase_order', fields: ['orderReference'])]
class HubPurchase
{
    public const PENDING = 'pending';
    /** The bank asks its buyer to confirm (3-D Secure): $redirectUrl is its page. */
    public const REQUIRES_ACTION = 'requires_action';
    public const PAID = 'paid';
    public const REFUSED = 'refused';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $token;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $idempotencyKey;

    #[ORM\ManyToOne(targetEntity: LicenseOffer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LicenseOffer $offer;

    /** The amount the client confirmed, and charged: minor units, the order's net price. */
    #[ORM\Column]
    private int $price;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** The omnitrade gateway it was charged through. */
    #[ORM\Column(length: 64)]
    private string $gateway;

    /** The provider's saved method charged (pm_...): a reference, never a card number. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $method = null;

    /** The marketplace order it was paid with. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $orderReference = null;

    #[ORM\Column(length: 16)]
    private string $status = self::PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $redirectUrl = null;

    /** Why it was refused, as the provider or the shop said. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    /** When the licence key was handed to the client: once only. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $keyHandedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** Not stored: this request found it made by an earlier one with the same key. */
    private bool $replayed = false;

    public function __construct(User $user, string $idempotencyKey, LicenseOffer $offer, int $price, string $currency, string $gateway)
    {
        $this->token = bin2hex(random_bytes(16));
        $this->user = $user;
        $this->idempotencyKey = $idempotencyKey;
        $this->offer = $offer;
        $this->price = $price;
        $this->currency = strtoupper($currency);
        $this->gateway = $gateway;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getToken(): string { return $this->token; }
    public function getUser(): User { return $this->user; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getOffer(): ?LicenseOffer { return $this->offer; }
    public function getPrice(): int { return $this->price; }
    public function getCurrency(): string { return $this->currency; }
    public function getGateway(): string { return $this->gateway; }
    public function getMethod(): ?string { return $this->method; }
    public function getOrderReference(): ?string { return $this->orderReference; }
    public function getStatus(): string { return $this->status; }
    public function getRedirectUrl(): ?string { return $this->redirectUrl; }
    public function getReason(): ?string { return $this->reason; }
    public function getKeyHandedAt(): ?\DateTimeImmutable { return $this->keyHandedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Whether the same request: the offer, the price and the currency the key was first sent with. */
    public function isSameRequest(int $offer, int $price, string $currency): bool
    {
        return $this->offer?->getId() === $offer && $this->price === $price && $this->currency === strtoupper($currency);
    }

    public function isReplayed(): bool { return $this->replayed; }

    public function markReplayed(): self
    {
        $this->replayed = true;

        return $this;
    }

    public function isOver(): bool
    {
        return \in_array($this->status, [self::PAID, self::REFUSED], true);
    }

    public function charging(string $method, ?string $orderReference): self
    {
        $this->method = $method;
        $this->orderReference = $orderReference;

        return $this->touch();
    }

    public function setOrderReference(?string $orderReference): self
    {
        $this->orderReference = $orderReference;

        return $this->touch();
    }

    public function markPaid(): self
    {
        $this->status = self::PAID;
        $this->redirectUrl = null;
        $this->reason = null;

        return $this->touch();
    }

    public function markRequiresAction(string $url): self
    {
        $this->status = self::REQUIRES_ACTION;
        $this->redirectUrl = $url;

        return $this->touch();
    }

    public function markPending(): self
    {
        $this->status = self::PENDING;

        return $this->touch();
    }

    public function markRefused(?string $reason): self
    {
        $this->status = self::REFUSED;
        $this->redirectUrl = null;
        $this->reason = null !== $reason ? mb_substr($reason, 0, 255) : null;

        return $this->touch();
    }

    public function markKeyHanded(\DateTimeImmutable $at): self
    {
        $this->keyHandedAt = $at;

        return $this->touch();
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
