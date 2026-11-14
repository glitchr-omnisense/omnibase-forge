<?php

namespace Base\Forge\Entity;

use Base\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * A payment method being saved on the provider's own page from Forge Store or Forge Hub (plan
 * item 16.2, omnisense/omnitrade 1.2): the setup the provider made (MethodSetup::$reference: a
 * Stripe Checkout session, a PayPal setup token) and the customer it is for, until the buyer is
 * back and the forge completes it (HubPayments::complete(), the core's CompleteMethodSetup). The
 * provider's page sends the buyer back to /hub/payment-methods/saved?setup=<token>, in a browser
 * that may not be signed in: $token, random and single use, is what names the setup there.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forge_payment_setup')]
#[ORM\Index(name: 'forge_payment_setup_pending', fields: ['user', 'gateway', 'state'])]
class PaymentSetup
{
    public const PENDING = 'pending';
    public const SAVED = 'saved';
    public const NOT_SAVED = 'not_saved';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The omnitrade gateway's name (omnitrade.gateways.<name>). */
    #[ORM\Column(length: 64)]
    private string $gateway;

    /** What the return URL carries: 32 hex characters. */
    #[ORM\Column(length: 32, unique: true)]
    private string $token;

    /** The provider's setup: MethodSetup::$reference. */
    #[ORM\Column(length: 255)]
    private string $reference;

    /** The provider's customer it is for: MethodSetup::$customer. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customer;

    #[ORM\Column(length: 16)]
    private string $state = self::PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(User $user, string $gateway, string $token, string $reference, ?string $customer, ?\DateTimeImmutable $at = null)
    {
        $this->user = $user;
        $this->gateway = $gateway;
        $this->token = $token;
        $this->reference = $reference;
        $this->customer = $customer;
        $this->createdAt = $at ?? new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getGateway(): string { return $this->gateway; }
    public function getToken(): string { return $this->token; }
    public function getReference(): string { return $this->reference; }
    public function getCustomer(): ?string { return $this->customer; }
    public function getState(): string { return $this->state; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }

    public function isPending(): bool
    {
        return self::PENDING === $this->state;
    }

    public function markSaved(?\DateTimeImmutable $at = null): self
    {
        return $this->complete(self::SAVED, $at);
    }

    public function markNotSaved(?\DateTimeImmutable $at = null): self
    {
        return $this->complete(self::NOT_SAVED, $at);
    }

    private function complete(string $state, ?\DateTimeImmutable $at): self
    {
        $this->state = $state;
        $this->completedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }
}
