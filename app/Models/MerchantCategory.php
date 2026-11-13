<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a customer said a merchant's payments are: the classifier's guess
 * corrected once, and remembered for every later payment to that merchant.
 */
class MerchantCategory extends Model
{
    protected $fillable = ['user_id', 'merchant_key', 'category'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
