<?php

namespace Base\Forge\Entity\Product;

use Base\Database\Attribute\DiscriminatorEntry;
use Base\Forge\Entity\Software;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Enum\Withdrawal;
use Doctrine\ORM\Mapping as ORM;

/**
 * A licence for a piece of software, sold through base-bundle-market: paying
 * for it issues a Forge\Entity\License per unit (OrderPaidSubscriber).
 */
#[ORM\Entity]
#[DiscriminatorEntry(value: 'forge_license_offer')]
class LicenseOffer extends Product
{
    #[ORM\ManyToOne(targetEntity: Software::class, inversedBy: 'offers')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Software $software = null;

    /** How long the licence stays valid; null: perpetual. */
    #[ORM\Column(nullable: true)]
    protected ?int $durationMonths = null;

    /** How long new releases are included; null: all of them. */
    #[ORM\Column(nullable: true)]
    protected ?int $updatesMonths = 12;

    #[ORM\Column(nullable: true)]
    protected ?int $seats = 1;

    /** On how many machines each seat may activate the software. */
    #[ORM\Column(nullable: true)]
    protected ?int $machinesPerSeat = 2;

    /** A key and downloads, nothing posted: checkout asks for no address. */
    public function isShippable(): bool
    {
        return false;
    }

    /**
     * Digital content supplied as soon as it is paid (a key, downloads): its buyer asks for
     * immediate supply and acknowledges losing the right of withdrawal before paying
     * (omnibase/marketplace's Service\Consents). setWithdrawal() says otherwise for one offer.
     */
    protected function defaultWithdrawal(): Withdrawal
    {
        return Withdrawal::DIGITAL_CONTENT;
    }

    public function getSoftware(): ?Software { return $this->software; }
    public function setSoftware(?Software $software): self { $this->software = $software; return $this; }

    public function getDurationMonths(): ?int { return $this->durationMonths; }
    public function setDurationMonths(?int $durationMonths): self { $this->durationMonths = $durationMonths ?: null; return $this; }

    public function getUpdatesMonths(): ?int { return $this->updatesMonths; }
    public function setUpdatesMonths(?int $updatesMonths): self { $this->updatesMonths = $updatesMonths ?: null; return $this; }

    public function getMachinesPerSeat(): int { return max(1, (int) ($this->machinesPerSeat ?? 2)); }
    public function setMachinesPerSeat(?int $machines): self { $this->machinesPerSeat = max(1, (int) ($machines ?? 2)); return $this; }

    public function getSeats(): int { return max(1, (int) $this->seats); }
    public function setSeats(int $seats): self { $this->seats = max(1, $seats); return $this; }
}
