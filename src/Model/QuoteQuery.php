<?php

namespace Base\Forge\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** The offer a hub client asks the price of, before it shows its user the confirmation. */
final class QuoteQuery
{
    public function __construct(
        #[Assert\Positive]
        public readonly int $offer = 0,
    ) {
    }
}
