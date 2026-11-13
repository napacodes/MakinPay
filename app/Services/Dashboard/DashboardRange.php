<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The dashboard's date range, resolved from a preset key or an explicit pair.
 *
 * The previous dashboard accepted only start_date/end_date. Those are still
 * honoured — buyer integrations and bookmarks depend on them — and a preset is
 * simply a named way of producing the same pair.
 */
class DashboardRange
{
    /**
     * The range the page opens on, and the one an unknown preset falls back
     * to.
     *
     * A month, because a week is not enough of the business to open on: on a
     * site taking a handful of transactions a day, the seven-day chart is
     * mostly empty columns compared against an equally thin previous week,
     * so a single deposit swings the comparison by hundreds of percent.
     * Thirty days shows the shape an operator is looking for, and the
     * shorter spans are one click away.
     *
     * It must stay one of the keys presets() offers, or first load would
     * light none of the four buttons.
     */
    public const DEFAULT_PRESET = '30d';

    /**
     * @var array<string, int|null> preset key => days back, null for special handling
     */
    private const PRESET_DAYS = [
        'today'  => 0,
        '7d'     => 6,
        '14d'    => 13,
        '30d'    => 29,
        'month'  => null,
        'custom' => null,
    ];

    private function __construct(
        public readonly string $preset,
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $preset = (string) $request->query('range', '');

        if ($request->filled('start_date') || $request->filled('end_date')) {
            $start = $request->filled('start_date')
                ? Carbon::parse((string) $request->query('start_date'))->startOfDay()
                : now()->subDays(13)->startOfDay();
            $end = $request->filled('end_date')
                ? Carbon::parse((string) $request->query('end_date'))->endOfDay()
                : now()->endOfDay();

            if ($end->lt($start)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }

            return new self($preset === '' ? 'custom' : $preset, $start, $end);
        }

        return self::fromPreset($preset);
    }

    public static function fromPreset(string $preset): self
    {
        if (! array_key_exists($preset, self::PRESET_DAYS) || $preset === 'custom') {
            $preset = self::DEFAULT_PRESET;
        }

        if ($preset === 'month') {
            return new self($preset, now()->startOfMonth(), now()->endOfDay());
        }

        return new self(
            $preset,
            now()->subDays((int) self::PRESET_DAYS[$preset])->startOfDay(),
            now()->endOfDay(),
        );
    }

    /**
     * The buttons the money panel offers.
     *
     * `14d` and `month` stay resolvable — a bookmark or an integration may
     * still ask for them — they simply are not offered as a control.
     *
     * @return list<array{key: string, label: string, active: bool}>
     */
    public function presets(): array
    {
        $labels = [
            'today'  => __('Today'),
            '7d'     => __('7 days'),
            '30d'    => __('30 days'),
            'custom' => __('Custom'),
        ];

        $presets = [];

        foreach ($labels as $key => $label) {
            $presets[] = [
                'key'    => $key,
                'label'  => $label,
                'active' => $this->preset === $key,
            ];
        }

        return $presets;
    }

    public function label(): string
    {
        return match ($this->preset) {
            'today'  => __('Today'),
            '7d'     => __('Last 7 days'),
            '30d'    => __('Last 30 days'),
            'month'  => __('This month'),
            'custom' => $this->start->isoFormat('D MMM').' – '.$this->end->isoFormat('D MMM'),
            default  => __('Last 14 days'),
        };
    }

    /**
     * The span named the way the comparison line says it: "vs previous 7 days".
     */
    public function shortLabel(): string
    {
        return match ($this->preset) {
            'today'  => __('day'),
            '7d'     => __('7 days'),
            '30d'    => __('30 days'),
            'month'  => __('month'),
            'custom' => trans_choice('{1}:count day|[2,*]:count days', $this->days(), ['count' => $this->days()]),
            default  => __('14 days'),
        };
    }

    /**
     * Calendar days in the range, both ends counted.
     *
     * diffInDays() returns a float — a range that ends at 23:59:59 is 28.99
     * days, not 29 — so the fraction is floored before the inclusive day is
     * added. Returning it unfloored is a TypeError against this signature.
     */
    public function days(): int
    {
        return max(1, (int) floor($this->start->diffInDays($this->end)) + 1);
    }
}
