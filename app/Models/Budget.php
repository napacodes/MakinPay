<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A spending limit the customer set for one category, in their display
 * currency, over a week, a month or a run of dates of their own.
 */
class Budget extends Model
{
    public const PERIODS = ['weekly', 'monthly', 'custom'];

    public const THRESHOLDS = [50, 80, 100];

    protected $fillable = ['user_id', 'category', 'amount', 'currency', 'period', 'starts_on', 'ends_on', 'alert_threshold', 'rollover', 'last_alert_period', 'last_alert_level'];

    protected function casts(): array
    {
        return [
            'amount'           => 'decimal:8',
            'starts_on'        => 'date',
            'ends_on'          => 'date',
            'alert_threshold'  => 'integer',
            'rollover'         => 'boolean',
            'last_alert_level' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The period this budget is in right now, shifted by whole periods: 0
     * is the current one, -1 the one before. A custom budget has exactly
     * one period, its own dates.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(int $offset = 0, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        return match ($this->period) {
            'weekly' => [$now->startOfWeek()->addWeeks($offset), $now->startOfWeek()->addWeeks($offset)->endOfWeek()],
            'custom' => [
                CarbonImmutable::parse($this->starts_on ?? $now)->startOfDay(),
                CarbonImmutable::parse($this->ends_on ?? $this->starts_on ?? $now)->endOfDay(),
            ],
            default => [$now->startOfMonth()->addMonths($offset), $now->startOfMonth()->addMonths($offset)->endOfMonth()],
        };
    }

    /**
     * A stable name for one period, so an alert is sent once per period.
     */
    public function periodKey(?CarbonImmutable $now = null): string
    {
        [$start] = $this->range(0, $now);

        // Short on purpose: the column holds sixteen characters.
        return substr($this->period, 0, 1).':'.$start->toDateString();
    }
}
