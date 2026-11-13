<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Api\ApiErrorCode;
use App\Models\MassPaymentBatch;

/**
 * The batch cannot be confirmed as it stands. Nothing was debited.
 *
 * `$reason` is a stable code, never translated text, so an API client can
 * branch on it; the message is the translated sentence for a person.
 */
class MassPaymentNotConfirmableException extends NotifyErrorException
{
    public const NOT_DRAFT = 'not_draft';

    public const EXPIRED = 'expired';

    public const INVALID_ROWS = 'invalid_rows';

    public const WARNINGS_UNACKNOWLEDGED = 'warnings_unacknowledged';

    public const BATCH_AMOUNT_CAP = 'batch_amount_cap';

    public const NO_VALID_ROWS = 'no_valid_rows';

    public const ROW_CAP = 'row_cap';

    public function __construct(public readonly string $reason, public readonly ?MassPaymentBatch $batch = null)
    {
        parent::__construct(self::messageFor($reason), 409);
    }

    /** Its own 409, never mistaken for an idempotency conflict. */
    protected function apiErrorCode(int $status): ApiErrorCode
    {
        return ApiErrorCode::MassPaymentNotConfirmable;
    }

    /** The sentence shown for a reason code. */
    public static function messageFor(string $reason): string
    {
        return match ($reason) {
            self::NOT_DRAFT               => __('Only a draft batch can be confirmed.'),
            self::EXPIRED                 => __('This batch has expired. Upload it again.'),
            self::INVALID_ROWS            => __('Some rows are invalid. Fix them, or choose to skip them and pay the rest.'),
            self::WARNINGS_UNACKNOWLEDGED => __('Some rows look like duplicates. Confirm that you reviewed them.'),
            self::BATCH_AMOUNT_CAP        => __('The batch total is above the maximum allowed for one batch.'),
            self::NO_VALID_ROWS           => __('There is no valid row to pay.'),
            self::ROW_CAP                 => __('The batch has more rows than allowed.'),
            default                       => __('This batch cannot be confirmed.'),
        };
    }
}
