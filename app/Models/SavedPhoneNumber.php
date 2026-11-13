<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone number a customer keeps to hand for recharges: theirs, a
 * relative's, with a label of their own.
 */
class SavedPhoneNumber extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'phone_number', 'label', 'operator', 'country'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Digits with a leading plus, the way MobileRechargeService stores a
     * recharge's number, so a saved number and a recent one compare equal.
     */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        return $digits === '' ? '' : '+'.$digits;
    }
}
