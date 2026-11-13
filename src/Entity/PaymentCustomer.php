<?php

namespace Base\Forge\Entity;

use Base\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Who a user is at a payment provider (plan item 16.2): the provider's customer reference
 * (Stripe's cus_...) under one omnitrade gateway, made the first time they save a payment method
 * from Forge Store or Forge Hub. That is all the forge keeps: the methods themselves are the
 * provider's, read from it each time (HubPayments) - never a card number, nor its last digits.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forge_payment_customer')]
#[ORM\UniqueConstraint(name: 'forge_payment_customer_gateway', fields: ['user', 'gateway'])]
class PaymentCustomer
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The omnitrade gateway's name (omnitrade.gateways.<name>). */
    #[ORM\Column(length: 64)]
    private string $gateway;

    /** The provider's customer: cus_... */
    #[ORM\Column(length: 255)]
    private string $reference;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $gateway, string $reference)
    {
        $this->user = $user;
        $this->gateway = $gateway;
        $this->reference = $reference;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getGateway(): string { return $this->gateway; }
    public function getReference(): string { return $this->reference; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
