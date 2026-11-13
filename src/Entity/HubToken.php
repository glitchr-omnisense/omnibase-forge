<?php

namespace Base\Forge\Entity;

use Base\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * An editor or a desktop hub linked to its user's account on this forge (plan item 16.1): the
 * token the device flow hands over (DeviceLinks), kept by Forge Store / Forge Hub in the user's
 * folder, never in a project, and sent as `Authorization: Bearer gla_...` to buy and to manage the
 * saved payment methods (HubAccounts). Unlike a seat's licence key it needs no licence: a first
 * purchase is made with it. Only its SHA-256 is stored, and a short prefix to recognise it by.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forge_hub_token')]
class HubToken
{
    public const PREFIX = 'gla_';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(length: 12)]
    private string $tokenPrefix;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(User $user, string $token)
    {
        $this->user = $user;
        $this->tokenHash = self::hash($token);
        $this->tokenPrefix = substr($token, 0, 12);
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getTokenPrefix(): string { return $this->tokenPrefix; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }

    public function markUsed(\DateTimeImmutable $at): self
    {
        $this->lastUsedAt = $at;

        return $this;
    }
}
