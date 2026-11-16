<?php

namespace Base\Forge\Service;

use Base\Entity\User;
use Base\Forge\Entity\License;
use Base\Forge\Entity\LicenseSeat;
use Base\Forge\Entity\Product\LicenseOffer;
use Base\Forge\Entity\Software;
use Base\Contract\UnitOfWork;

/** Issues licences (from a paid offer, or by hand) and the keys of their seats. */
class LicenseIssuer
{
    public function __construct(private readonly UnitOfWork $unitOfWork)
    {
    }

    /**
     * A licence as the offer describes it: its seats, its validity, its
     * updates - its first seat its owner's own, to give away if they buy
     * it for someone else. Persisted, not flushed.
     */
    public function issue(Software $software, User $owner, ?LicenseOffer $offer = null, ?string $orderReference = null, ?\DateTimeImmutable $at = null): License
    {
        $at ??= new \DateTimeImmutable();

        $license = new License($software, $owner);
        $license->setOrderReference($orderReference);

        if ($offer) {
            $license->setSeats($offer->getSeats());
            $license->setMachinesPerSeat($offer->getMachinesPerSeat());
            if ($months = $offer->getDurationMonths()) {
                $license->setExpiresAt($at->modify(sprintf('+%d months', $months)));
            }
            if ($months = $offer->getUpdatesMonths()) {
                $license->setUpdatesUntil($at->modify(sprintf('+%d months', $months)));
            }
        }

        // Theirs to begin with: nothing to tell them, they just bought it.
        $license->addHolder((new LicenseSeat($license, (string) $owner->getEmail(), $owner))->markNotified());
        $this->unitOfWork->persist($license);

        return $license;
    }

    /**
     * A new key for the seat, replacing the old one (which stops working at
     * once). Returned in clear this once; only its hash is kept. Flushed.
     */
    public function rekey(LicenseSeat $seat): string
    {
        $key = LicenseKey::generate();
        $seat->setKey($key);
        $this->unitOfWork->flush();

        return $key;
    }
}
