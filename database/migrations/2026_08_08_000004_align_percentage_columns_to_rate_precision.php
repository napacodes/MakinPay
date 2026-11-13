<?php

use App\Support\SchemaColumnDefault;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring the last percentage columns up to the precision the rest already use.
 *
 * The feature-module widening set out the rule explicitly — amounts get
 * decimal(24,8), percentages get decimal(8,4), because four decimals is ample
 * for a rate and widening a percentage further implies a precision it does not
 * have. These three were left at decimal(5,2) and so cannot express a rate
 * finer than a hundredth of a percent: 0.125% rounds to 0.13, and interchange
 * and card-issuer schedules routinely quote three decimals.
 *
 * Widening a DECIMAL preserves every existing value.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const PERCENTAGES = [
        'mobile_recharge_providers' => ['fee_percent'],
        'virtual_card_providers'    => ['issue_fee_pct', 'additional_issue_fee_percent'],
    ];

    public function up(): void
    {
        $this->apply(8, 4);
    }

    /**
     * Narrowing rounds a three-decimal rate away. Lossy but non-throwing.
     */
    public function down(): void
    {
        $this->apply(5, 2);
    }

    private function apply(int $total, int $places): void
    {
        foreach (self::PERCENTAGES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = collect(Schema::getColumns($table))->keyBy('name');

            foreach ($columns as $column) {
                $current = $existing[$column] ?? null;

                if ($current === null || strtolower((string) $current['type']) === "decimal({$total},{$places})") {
                    continue;
                }

                // Restate the existing nullability and default: ->change()
                // regenerates the whole definition and drops what is omitted.
                Schema::table($table, function (Blueprint $blueprint) use ($column, $current, $total, $places): void {
                    $definition = $blueprint->decimal($column, $total, $places)
                        ->nullable((bool) ($current['nullable'] ?? false));

                    $default = SchemaColumnDefault::normalize($current['default'] ?? null);

                    if ($default !== null) {
                        $definition->default($default);
                    }

                    $definition->change();
                });
            }
        }
    }
};
