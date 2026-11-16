<?php

namespace Base\Forge\Entity;

use Base\Mapping as ORM;

/**
 * One machine a seat's software is activated on: a game, an application
 * installed on a computer. The licence allows so many machines a seat
 * (License::$machinesPerSeat); whoever holds the seat frees one from their
 * account - a new computer, an old one gone - and the studio from the back
 * office.
 *
 * The machine is known by a hash of the identifier its software sends
 * (Unity's deviceUniqueIdentifier, macOS's IOPlatformUUID...): the raw
 * hardware identifier is never stored. Its name and platform are what the
 * software reports, to recognise it by in a list.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forge_license_activation')]
#[ORM\UniqueConstraint(name: 'forge_license_activation_machine', columns: ['seat_id', 'machine'])]
class LicenseActivation implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LicenseSeat::class, inversedBy: 'activations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LicenseSeat $seat = null;

    /** SHA-256 of the identifier the software sent. */
    #[ORM\Column(length: 64)]
    private string $machine = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $platform = null;

    /** The version of the software that asked, as it says. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $version = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $activatedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(?LicenseSeat $seat = null, string $machine = '')
    {
        $this->seat = $seat;
        $this->machine = $machine;
        $this->activatedAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->activatedAt;
    }

    /** The hash a machine is known by: the one its software can compute too. */
    public static function hash(string $identifier): string
    {
        return hash('sha256', trim($identifier));
    }

    public function __toString(): string
    {
        return ($this->name ?: substr($this->machine, 0, 8)).($this->platform ? ' ('.$this->platform.')' : '');
    }

    public function getId(): ?int { return $this->id; }
    public function getSeat(): ?LicenseSeat { return $this->seat; }
    public function setSeat(?LicenseSeat $seat): self { $this->seat = $seat; return $this; }
    public function getMachine(): string { return $this->machine; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = null !== $name ? mb_substr(trim($name), 0, 128) ?: null : null; return $this; }

    public function getPlatform(): ?string { return $this->platform; }
    public function setPlatform(?string $platform): self { $this->platform = null !== $platform ? mb_substr(trim($platform), 0, 32) ?: null : null; return $this; }

    public function getVersion(): ?string { return $this->version; }
    public function setVersion(?string $version): self { $this->version = null !== $version ? mb_substr(trim($version), 0, 32) ?: null : null; return $this; }

    public function getActivatedAt(): \DateTimeImmutable { return $this->activatedAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function seen(?\DateTimeImmutable $at = null): self { $this->lastSeenAt = $at ?? new \DateTimeImmutable(); return $this; }
}
