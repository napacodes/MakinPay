<?php

declare(strict_types=1);

namespace App\Services\Payment\Payout;

use RuntimeException;

/**
 * The provider answered a lookup with something that cannot describe one
 * payout, such as two transfers under one idempotency group. Nothing is
 * settled or refunded on such an answer; the line goes to review.
 */
class PayoutContradictionException extends RuntimeException {}
