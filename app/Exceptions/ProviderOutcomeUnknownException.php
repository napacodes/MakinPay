<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider was asked to move money and never answered.
 *
 * A timeout, a dropped connection or a gateway error page after the request
 * left: the card may or may not have been issued or funded. Nothing local may
 * be refunded on this, and nothing may be retried blindly. The caller records
 * the state, tells the operator, and leaves the debits in place to be reused
 * once the outcome is known.
 */
class ProviderOutcomeUnknownException extends RuntimeException {}
