<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A callback tried to settle a transaction that is not its own.
 *
 * Raised only for the binding itself: the callback's gateway is not the
 * gateway that created the transaction, a legacy row resolves to a different
 * gateway, a legacy origin cannot be named at all, or something tried to
 * change the callback gateway mid-request.
 *
 * Deliberately not a replacement for provider authentication, signature,
 * amount, currency or gateway-specific failures — those remain each driver's
 * responsibility and keep their own exceptions.
 *
 * The message is for the log, never for the response body: it names the
 * transaction and both gateways, and none of that belongs in a public reply
 * to an unauthenticated caller.
 */
class GatewayBindingViolationException extends RuntimeException {}
