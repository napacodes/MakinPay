<?php

declare(strict_types=1);

namespace App\Services\Payment\Payout;

use InvalidArgumentException;

/**
 * The row-agnostic payout contract mass payouts use. It sits next to the
 * remittance contract (PayoutGateway), which stays frozen.
 *
 * A driver never touches the database here and never retries a call that
 * can move money: the caller claims the line before `send()` and runs it
 * with no transaction open and no lock held.
 */
interface PayoutOrderGateway
{
    /**
     * Check the recipient offline, before any money is reserved or sent.
     *
     * @throws InvalidArgumentException with a field-level message
     */
    public function validateRecipient(PayoutRecipient $recipient): void;

    /**
     * The only call that can move money. A PayoutNotSentException means the
     * provider provably did nothing; any other throwable is an unknown
     * outcome and must never be refunded.
     *
     * @throws PayoutNotSentException
     */
    public function send(PayoutOrder $order): PayoutResult;

    /**
     * Read-only status check by the order's idempotency key or provider
     * reference. Never moves money.
     *
     * @throws PayoutNotFoundException when the provider holds no record
     */
    public function lookup(PayoutOrder $order): PayoutResult;

    public function supportsLookup(): bool;

    /** Worst-case duration of one send(), in seconds. */
    public function maxCallSeconds(): int;
}
