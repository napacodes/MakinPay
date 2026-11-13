<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Drivers\Support;

use RuntimeException;

/**
 * An amount cannot be put on the wire exactly as it is stored.
 *
 * Raised by {@see WireAmount::number()} before anything is sent. A driver
 * catches it and returns Failed, which is safe because the provider was
 * never contacted.
 */
class UnrepresentableAmountException extends RuntimeException {}
