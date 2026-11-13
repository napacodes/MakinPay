<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A bill payment webhook could not be authenticated: no webhook secret is
 * configured for the provider, or the signature header is missing or wrong.
 *
 * The webhook controller answers 401 and records nothing. The message is for
 * the log, never for the response body, and must never contain the secret
 * or the header that was sent.
 */
class InvalidBillPaymentWebhookException extends RuntimeException {}
