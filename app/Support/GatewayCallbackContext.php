<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\GatewayBindingViolationException;

/**
 * Which gateway the current request arrived through, if any.
 *
 * Thirty-odd call sites across twenty-seven drivers complete transactions, and
 * most reach TransactionService through a facade with no idea of their own
 * code. Threading a parameter through all of them would mean editing every
 * driver — files with no test coverage — in a change whose entire purpose is
 * security. So the gateway is established once, at the edge, and read where
 * settlement happens.
 *
 * Registered as a scoped binding: one instance per request or queue job, never
 * shared between them.
 *
 * Write-once on purpose. A driver able to reassign this mid-request could
 * simply announce itself as the gateway that owns the transaction, which is
 * the exact attack the binding exists to stop.
 */
class GatewayCallbackContext
{
    private ?string $gatewayCode = null;

    /**
     * Name the gateway this request came through.
     *
     * @throws GatewayBindingViolationException if one has already been named
     */
    public function initialize(string $gatewayCode): void
    {
        // Re-asserting the same gateway changes nothing and is not an attack:
        // a provider retry handled in the same process arrives claiming the
        // gateway it already claimed. The invariant is that identity cannot be
        // *switched*, which the guard below still enforces — refusing a repeat
        // of the identical value was stricter than the property this class
        // exists to protect, and broke legitimate retries.
        if ($this->gatewayCode !== null && hash_equals($this->gatewayCode, $gatewayCode)) {
            return;
        }

        if ($this->gatewayCode !== null) {
            throw new GatewayBindingViolationException(sprintf(
                'Callback gateway is already set to %s and cannot be changed to %s.',
                $this->gatewayCode,
                $gatewayCode,
            ));
        }

        $this->gatewayCode = $gatewayCode;
    }

    public function gatewayCode(): ?string
    {
        return $this->gatewayCode;
    }

    /**
     * False for everything that is not an external callback — admin approvals,
     * reconciliation commands, queued jobs — which must keep settling exactly
     * as they did before.
     */
    public function isInitialized(): bool
    {
        return $this->gatewayCode !== null;
    }
}
