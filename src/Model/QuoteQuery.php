<?php

namespace Base\Forge\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** The offer a hub client asks the price of, before it shows its user the confirmation. */
final class QuoteQuery
{
    public function __construct(
        #[Assert\Positive]
        public readonly int $offer = 0,
        /** The language the statements to accept are worded in (fr, en_GB...); the request's when none. */
        #[Assert\Regex('/^[a-z]{2,3}([_-][A-Za-z]{2,4})?$/')]
        public readonly ?string $locale = null,
    ) {
    }
}
