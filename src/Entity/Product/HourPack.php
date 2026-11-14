<?php

namespace Base\Forge\Entity\Product;

use Base\Database\Attribute\DiscriminatorEntry;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Enum\Withdrawal;
use Doctrine\ORM\Mapping as ORM;

/**
 * Support hours, sold like any product of base-bundle-market: paying for n
 * of them credits n × minutes to the buyer's ledger (OrderPaidSubscriber).
 * An accepted quote becomes a one-off, unlisted HourPack of its own.
 */
#[ORM\Entity]
#[DiscriminatorEntry(value: 'forge_hour_pack')]
class HourPack extends Product
{
    #[ORM\Column(nullable: true)]
    protected ?int $minutes = null;

    /** Listed in the shop; false for the one-off pack of a quote. */
    #[ORM\Column(nullable: true)]
    protected ?bool $listed = true;

    /** Time, not goods: checkout asks for no address. */
    public function isShippable(): bool
    {
        return false;
    }

    /**
     * A service performed from the payment on (the hours credited at once): its buyer asks for it
     * to begin before the withdrawal period ends (omnibase/marketplace's Service\Consents).
     */
    protected function defaultWithdrawal(): Withdrawal
    {
        return Withdrawal::SERVICE;
    }

    /** A quote's own pack is bought once; listed packs, as many as wanted. */
    public function getMaxQuantity(): ?int
    {
        return $this->isListed() ? null : 1;
    }

    public function getMinutes(): int { return (int) $this->minutes; }
    public function setMinutes(int $minutes): self { $this->minutes = max(0, $minutes); return $this; }

    public function getHours(): float { return $this->getMinutes() / 60; }
    public function setHours(float $hours): self { return $this->setMinutes((int) round($hours * 60)); }

    public function isListed(): bool { return false !== $this->listed; }
    public function setListed(bool $listed): self { $this->listed = $listed; return $this; }

    /** In cents: what one hour of this pack costs. */
    public function getHourlyPrice(): int
    {
        return $this->getMinutes() > 0 ? (int) round($this->getUnitPrice() * 60 / $this->getMinutes()) : 0;
    }
}
