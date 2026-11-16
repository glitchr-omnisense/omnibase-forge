<?php

namespace Base\Forge\Entity;

use Base\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Base\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One seat of a licence: the e-mail it is given to. Whoever signs in with
 * that e-mail - verified - downloads what the licence covers and installs
 * it with Composer, with a key of their own. The licence's owner gives its
 * seats, and takes them back; a paid licence starts with the owner's.
 *
 * The account is linked on the first match (a seat given to someone who
 * has none yet waits for them); the key is shown once, only its SHA-256 is
 * stored, plus a short prefix to recognise it by.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forge_license_seat')]
#[ORM\UniqueConstraint(name: 'forge_license_seat_email', columns: ['license_id', 'email'])]
#[UniqueEntity(fields: ['license', 'email'], message: 'This e-mail already has a seat on this licence.')]
class LicenseSeat implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: License::class, inversedBy: 'holders')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?License $license = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank, Assert\Email, Assert\Length(max: 180)]
    private string $email = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $keyHash = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $keyPrefix = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $assignedAt;

    /** Not stored: its holder was already told (LicenseSeats). */
    private bool $notified = false;

    /** @var Collection<int, LicenseActivation> the machines it is activated on */
    #[ORM\OneToMany(targetEntity: LicenseActivation::class, mappedBy: 'seat', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['lastSeenAt' => 'DESC'])]
    private Collection $activations;

    public function __construct(?License $license = null, string $email = '', ?User $user = null)
    {
        $this->license = $license;
        $this->setEmail($email);
        $this->user = $user;
        $this->assignedAt = new \DateTimeImmutable();
        $this->activations = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->email;
    }

    public function getId(): ?int { return $this->id; }

    public function getLicense(): ?License { return $this->license; }
    public function setLicense(?License $license): self { $this->license = $license; return $this; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = mb_strtolower(trim($email)); return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }

    public function getAssignedAt(): \DateTimeImmutable { return $this->assignedAt; }

    /** @return Collection<int, LicenseActivation> */
    public function getActivations(): Collection { return $this->activations; }

    public function findActivation(string $machine): ?LicenseActivation
    {
        return $this->activations->findFirst(fn (int $key, LicenseActivation $activation) => $activation->getMachine() === $machine);
    }

    public function addActivation(LicenseActivation $activation): self
    {
        if (!$this->activations->contains($activation)) {
            $activation->setSeat($this);
            $this->activations->add($activation);
        }

        return $this;
    }

    public function removeActivation(LicenseActivation $activation): self
    {
        $this->activations->removeElement($activation);

        return $this;
    }

    /** Machines it may still be activated on. */
    public function getMachinesLeft(): int
    {
        return max(0, (int) $this->license?->getMachinesPerSeat() - $this->activations->count());
    }

    public function isNotified(): bool { return $this->notified; }
    public function markNotified(): self { $this->notified = true; return $this; }

    /**
     * Whether this seat is that user's: linked to them, or given to their
     * e-mail - once they have proved it is theirs.
     */
    public function isHeldBy(User $user): bool
    {
        if (null !== $this->user) {
            return $this->user->getId() === $user->getId();
        }

        return $user->isVerified() && 0 === strcasecmp($this->email, (string) $user->getEmail());
    }

    public function getKeyPrefix(): ?string { return $this->keyPrefix; }
    public function hasKey(): bool { return null !== $this->keyHash; }

    /** Stores the key's hash; the key itself is the caller's to show once. */
    public function setKey(string $key): self
    {
        $this->keyHash = hash('sha256', $key);
        $this->keyPrefix = substr($key, 0, 12);

        return $this;
    }

    /** Not more seats given than the licence has. */
    #[Assert\Callback]
    public function validateSeats(ExecutionContextInterface $context): void
    {
        $license = $this->license;
        if ($license && $license->getHolders()->filter(fn (self $seat) => $seat !== $this)->count() >= $license->getSeats()) {
            $context->buildViolation(sprintf('Every seat of this licence is given (%d): raise its number, or take one back first.', $license->getSeats()))
                ->atPath('license')->addViolation();
        }
    }

    public function matchesKey(string $key): bool
    {
        return null !== $this->keyHash && hash_equals($this->keyHash, hash('sha256', $key));
    }
}
