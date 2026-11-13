<?php

declare(strict_types=1);

namespace App\Services\Payment\Payout;

/**
 * The provider refused our credentials or permissions before anything was
 * sent. Nothing moved, so the claimed line may be refunded, and the batch
 * is held because every following line would be refused the same way.
 */
class PayoutProviderUnavailableException extends PayoutNotSentException {}
