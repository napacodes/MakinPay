<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Factories\SavedBillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a customer's "My bills": a biller and the account number it knows
 * them by, with the name they gave it. Holds no money; the last payment shown
 * beside it is read from bill_payments.
 *
 * BP3: it may carry a monthly reminder day. Nothing is paid by itself; the
 * reminder points the customer at the one-tap pay page.
 *
 * @property int         $id
 * @property int         $user_id
 * @property int         $biller_id
 * @property string      $customer_number
 * @property string|null $nickname
 * @property int|null    $remind_day
 * @property Carbon|null $reminded_for
 */
class SavedBill extends Model
{
    /** @use HasFactory<SavedBillFactory> */
    use HasFactory;

    public const REMIND_DAY_MIN = 1;

    public const REMIND_DAY_MAX = 31;

    protected $fillable = [
        'user_id',
        'biller_id',
        'customer_number',
        'nickname',
        'remind_day',
        'reminded_for',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id'      => 'integer',
            'biller_id'    => 'integer',
            'remind_day'   => 'integer',
            'reminded_for' => 'date',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Biller, $this>
     */
    public function biller(): BelongsTo
    {
        return $this->belongsTo(Biller::class);
    }

    /** The customer's name for it, else the biller's. */
    public function title(): string
    {
        return filled($this->nickname) ? (string) $this->nickname : (string) $this->biller?->name;
    }

    /**
     * Whether the reminder falls on this date: its day of the month, or the
     * month's last day when the month is shorter than the day asked for.
     */
    public function remindsOn(CarbonInterface $date): bool
    {
        if ($this->remind_day === null) {
            return false;
        }

        return (int) $date->day === min((int) $this->remind_day, (int) $date->daysInMonth);
    }
}
