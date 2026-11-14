<?php

namespace Base\Forge\Exception;

/**
 * Why a hub client's purchase or payment-method request is refused, as the client reads it: an
 * error code ($error), the HTTP status to answer with, and what the client needs to go on - the
 * current price after a price_changed, for instance (docs/hub.md, "Buying in one click").
 */
final class HubPurchaseException extends \RuntimeException
{
    public const UNKNOWN_OFFER = 'unknown_offer';
    public const PRICE_CHANGED = 'price_changed';
    public const PAYMENT_METHOD_REQUIRED = 'payment_method_required';
    public const UNKNOWN_PAYMENT_METHOD = 'unknown_payment_method';
    public const IDEMPOTENCY_KEY_REUSED = 'idempotency_key_reused';
    public const PAYMENTS_UNAVAILABLE = 'payments_unavailable';
    public const PURCHASE_IN_PROGRESS = 'purchase_in_progress';
    /** The buyer's statements missing, or one of them not true: nothing charged (422, with the texts). */
    public const CONSENT_REQUIRED = 'consent_required';
    /** The statements sent are not the version shown now (another wording, language, terms): show them again (409). */
    public const CONSENT_CHANGED = 'consent_changed';

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $error,
        public readonly int $status,
        public readonly array $data = [],
        string $message = '',
    ) {
        parent::__construct('' !== $message ? $message : $error);
    }

    /** @return array<string, mixed> the body to answer with */
    public function body(): array
    {
        return ['error' => $this->error] + $this->data;
    }
}
