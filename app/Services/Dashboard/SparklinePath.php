<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

/**
 * The seven-day shape beside a counter, as SVG geometry.
 *
 * Plotted here rather than by a charting library: it is seven integers in an
 * 80x28 box, and loading a chart engine five times on page load to draw them
 * would cost more than the panel it decorates.
 */
class SparklinePath
{
    /**
     * The drawing box. Matches the viewBox the counter partial declares.
     */
    private const WIDTH = 80.0;

    private const HEIGHT = 28.0;

    /**
     * Head- and foot-room, so the highest reading is not flush with the top
     * edge and the lowest is not sitting on the bottom one.
     */
    private const PLOT_HEIGHT = 22.0;

    private const BASELINE = 26.0;

    /**
     * One reading is not a trend.
     *
     * A flat rule drawn across a card with a single day of history reads as
     * "steady", which is the opposite of what the delta beside it is saying
     * at that moment. Below two readings there is nothing honest to draw, and
     * the caller renders the card without a sparkline.
     *
     * @param  list<int|float>                                        $values
     * @return array{points: string, area: string, last: string}|null
     */
    public static function build(array $values): ?array
    {
        $values = array_values(array_map(static fn ($value): float => (float) $value, $values));
        $count  = count($values);

        if ($count < 2) {
            return null;
        }

        $low  = min($values);
        $high = max($values);

        /*
         * A flat series has no range to normalise against. It draws as a line
         * through the plot rather than as a division by zero.
         */
        $span = $high - $low ?: 1.0;

        $points = [];

        foreach ($values as $index => $value) {
            $points[] = [
                round($index / ($count - 1) * self::WIDTH, 1),
                round(self::BASELINE - ($value - $low) / $span * self::PLOT_HEIGHT, 1),
            ];
        }

        $line = implode(' ', array_map(
            static fn (array $point): string => $point[0].','.$point[1],
            $points
        ));

        return [
            'points' => $line,
            'area'   => 'M0,'.self::HEIGHT.' L'.str_replace(' ', ' L', $line).' L'.self::WIDTH.','.self::HEIGHT.' Z',
            'last'   => (string) end($points)[1],
        ];
    }
}
