<?php

namespace Base\Forge\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What a hub client sends to buy in one click, as its user confirmed it (plan item 17.3): the
 * offer, and the price and currency the confirmation showed - the amount quote() gave, which the
 * forge charges only if it is still the amount the order comes to.
 */
final class PurchaseRequest
{
    public function __construct(
        /** The offer's id, as the catalogue lists it (packages[].offers[].id). */
        #[Assert\Positive]
        public readonly int $offer = 0,
        /** The amount confirmed, in minor units (cents): GET /upm/-/hub/quote's price. */
        #[Assert\PositiveOrZero]
        public readonly int $price = -1,
        /** ISO 4217, as the quote gave it. */
        #[Assert\NotBlank, Assert\Currency]
        public readonly string $currency = '',
        /** A saved method's id (GET /upm/-/hub/payment-methods); the first one that has not expired when null. */
        #[Assert\Length(max: 255), Assert\Regex('/^[A-Za-z0-9_-]+$/')]
        public readonly ?string $method = null,
        /**
         * What its user stated, as the confirmation showed it (GET /upm/-/hub/quote's consent):
         * {"version": "<the quote's consent.version>", "locale": "fr", "terms": true,
         * "immediate_supply": true, "early_service": true} - each statement the quote listed, true.
         * Read by HubPurchases (PurchaseConsent).
         *
         * @var array<string, mixed>|null
         */
        #[Assert\Type('array')]
        public readonly ?array $consent = null,
    ) {
    }
}
