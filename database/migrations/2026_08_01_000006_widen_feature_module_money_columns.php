<?php

use App\Support\SchemaColumnDefault;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the feature modules the core-ledger migration left behind.
 *
 * `transactions` was widened to decimal(24,8), but vouchers, gift cards,
 * payment links, virtual cards and remittance kept their own decimal(x,2)
 * amount columns. Because currency roles like VOUCHER and GIFT_CARD can be
 * enabled on a crypto currency, that reproduced the original bug exactly:
 * creating a 0.00094118 BTC voucher stored 0.
 *
 * Only real AMOUNT columns are touched. Percentage columns (commission_pct,
 * fee_percent, maker_fee_pct, …) stay at decimal(8,4) — 4 decimals is ample
 * for a rate, and widening them would imply a precision they do not have.
 *
 * Nullability and defaults are read back off the live schema rather than
 * hardcoded, so this migration cannot drop a modifier the way Laravel's
 * ->change() silently does.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const AMOUNT_COLUMNS = [
        'vouchers'                  => ['amount'],
        'gift_cards'                => ['amount'],
        'gift_card_templates'       => ['default_amount'],
        'payment_links'             => ['amount', 'min_amount', 'max_amount'],
        'virtual_card_fee_settings' => ['fee_amount', 'min_amount', 'max_amount', 'daily_amount_limit', 'approval_threshold'],
        'virtual_card_providers'    => ['issue_fee', 'min_balance'],
        'virtual_card_requests'     => ['initial_load_amount'],
        'remittance_corridors'      => ['fixed_fee', 'min_amount', 'max_amount'],
        'remittance_quotes'         => ['send_amount', 'fee_amount', 'total_pay', 'receive_amount'],
        'remittance_transfers'      => ['send_amount', 'fee_amount', 'total_paid', 'receive_amount'],
        'user_ranks'                => ['reward'],
    ];

    public function up(): void
    {
        $this->apply(24, 8);
    }

    /**
     * Narrowing rounds sub-cent precision away. Lossy but non-throwing, so a
     * rollback cannot strand an operator half way through.
     */
    public function down(): void
    {
        $this->apply(null, null);
    }

    private function apply(?int $total, ?int $places): void
    {
        foreach (self::AMOUNT_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = collect(Schema::getColumns($table))->keyBy('name');

            foreach ($columns as $column) {
                $current = $existing[$column] ?? null;

                if ($current === null) {
                    continue;
                }

                if ($total !== null && strtolower((string) $current['type']) === "decimal({$total},{$places})") {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($column, $total, $places, $current): void {
                    $definition = $total === null
                        ? $blueprint->decimal($column, 15, 2)
                        : $blueprint->decimal($column, $total, $places);

                    $definition->nullable((bool) $current['nullable']);

                    $default = SchemaColumnDefault::normalize($current['default']);

                    if ($default !== null) {
                        $definition->default($default);
                    }

                    $definition->change();
                });
            }
        }
    }
};
