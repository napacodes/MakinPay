<?php

declare(strict_types=1);

namespace App\Services\Payment\Payout;

use RuntimeException;

/**
 * A read-only lookup found no record of the payout at the provider.
 *
 * Always inconclusive: a provider index can lag behind a payout it has
 * already accepted, so a missing record is never a reason to refund.
 */
class PayoutNotFoundException extends RuntimeException {}
