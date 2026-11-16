<?php

namespace Base\Forge\Entity;

use Base\Entity\User;
use Base\Forge\Enum\LicenseStatus;
use Base\Forge\Repository\LicenseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Base\Mapping as ORM;

/**
 * The right to download a licensed piece of software - and to install it
 * from the Composer repository. Granted when a LicenseOffer is paid for, or
 * by hand from the back office.
 *
 * It has `seats`: that many e-mails may use it, one LicenseSeat each, with
 * a key of their own. Its owner - who bought it, or whom the studio gave it
 * to - gives the seats and takes them back; owning it is not holding one.
 */
#[ORM\Entity(repositoryClass: LicenseRepository::class)]
#[ORM\Table(name: 'forge_license')]
class License implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Software::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Software $software = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 16, enumType: LicenseStatus::class)]
    private LicenseStatus $status = LicenseStatus::ACTIVE;

    #[ORM\Column]
    private int $seats = 1;

    /** On how many machines each seat may activate the software. */
    #[ORM\Column(options: ['default' => 2])]
    private int $machinesPerSeat = 2;

    /** Null: perpetual. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /** Releases published after this date are not covered. Null: all of them. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatesUntil = null;

    /** The market order that paid for it, if any. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $orderReference = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, LicenseSeat> */
    #[ORM\OneToMany(targetEntity: LicenseSeat::class, mappedBy: 'license', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['assignedAt' => 'ASC'])]
    private Collection $holders;

    public function __construct(?Software $software = null, ?User $owner = null)
    {
        $this->software = $software;
        $this->owner = $owner;
        $this->createdAt = new \DateTimeImmutable();
        $this->holders = new ArrayCollection();
    }

    public function __toString(): string
    {
        return ($this->software?->getName() ?? '').' · '.$this->holders->count().'/'.$this->seats.' #'.$this->id;
    }

    public function getId(): ?int { return $this->id; }
    public function getSoftware(): ?Software { return $this->software; }
    public function setSoftware(Software $software): self { $this->software = $software; return $this; }
    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(User $owner): self { $this->owner = $owner; return $this; }

    /** @return Collection<int, LicenseSeat> the e-mails it is given to, first given first */
    public function getHolders(): Collection { return $this->holders; }

    public function getMachinesPerSeat(): int { return $this->machinesPerSeat; }
    public function setMachinesPerSeat(int $machines): self { $this->machinesPerSeat = max(1, $machines); return $this; }

    public function getSeatsTaken(): int { return $this->holders->count(); }
    public function getSeatsLeft(): int { return max(0, $this->seats - $this->holders->count()); }

    /** The seat given to that e-mail, if any. */
    public function findSeat(string $email): ?LicenseSeat
    {
        $email = mb_strtolower(trim($email));

        return $this->holders->findFirst(fn (int $key, LicenseSeat $seat) => $seat->getEmail() === $email);
    }

    /** The seat that user holds, if any. */
    public function findSeatOf(User $user): ?LicenseSeat
    {
        return $this->holders->findFirst(fn (int $key, LicenseSeat $seat) => $seat->isHeldBy($user));
    }

    /** Gives a seat to that e-mail - not checked against the seats left: LicenseSeats does. */
    public function addHolder(LicenseSeat $seat): self
    {
        if (!$this->holders->contains($seat)) {
            $seat->setLicense($this);
            $this->holders->add($seat);
        }

        return $this;
    }

    public function removeHolder(LicenseSeat $seat): self
    {
        $this->holders->removeElement($seat);

        return $this;
    }

    public function getStatus(): LicenseStatus { return $this->status; }
    public function setStatus(LicenseStatus $status): self { $this->status = $status; return $this; }

    public function getSeats(): int { return $this->seats; }
    /** Never fewer than the seats already given: take some back first. */
    public function setSeats(int $seats): self { $this->seats = max(1, $seats, $this->holders?->count() ?? 0); return $this; }

    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }

    public function getUpdatesUntil(): ?\DateTimeImmutable { return $this->updatesUntil; }
    public function setUpdatesUntil(?\DateTimeImmutable $updatesUntil): self { $this->updatesUntil = $updatesUntil; return $this; }

    public function getOrderReference(): ?string { return $this->orderReference; }
    public function setOrderReference(?string $orderReference): self { $this->orderReference = $orderReference; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isValid(?\DateTimeImmutable $at = null): bool
    {
        $at ??= new \DateTimeImmutable();

        return LicenseStatus::ACTIVE === $this->status && (null === $this->expiresAt || $this->expiresAt > $at);
    }

    /** Whether this licence opens that release: valid, and the release within its updates. */
    public function covers(Release $release, ?\DateTimeImmutable $at = null): bool
    {
        if (!$this->isValid($at) || null === $this->software || $release->getSoftware() !== $this->software) {
            return false;
        }

        return null === $this->updatesUntil
            || null === $release->getPublishedAt()
            || $release->getPublishedAt() <= $this->updatesUntil;
    }
}
