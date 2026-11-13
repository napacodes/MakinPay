<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reward extends Model
{
    protected $fillable = [
        'type',
        'level',
        'percentage',
        'fixed_amount',
        'min_reward',
        'max_reward',
    ];

    protected $casts = [
        'percentage'   => 'float',
        'fixed_amount' => 'decimal:8',
        'min_reward'   => 'decimal:8',
        'max_reward'   => 'decimal:8',
    ];
}
