<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the core ledger to satoshi precision.
 *
 * `transactions` stored money at decimal(15,2) while `wallets.balance` had
 * already been widened to decimal(20,8). Crediting 0.00094118 BTC to a wallet
 * therefore recorded 0 in the ledger, so balances and history diverged
 * permanently and could not be reconciled.
 *
 * decimal(24,8) matches the precision the newer modules that join against this
 * table already use (p2p_orders, wallet_earn_*, subscription_transactions) and
 * keeps the invariant "ledger scale >= wallet scale".
 *
 * Widening a DECIMAL is value preserving: 123.45 becomes 123.45000000. No data
 * migration is required on the way up.
 *
 * OPERATOR NOTE: MySQL cannot perform a DECIMAL precision change in place. This
 * runs as ALGORITHM=COPY, LOCK=SHARED — reads continue but writes to
 * `transactions` block for the duration. Budget roughly 1-3 minutes per million
 * rows and run it in a maintenance window.
 */
return new class extends Migration
{
    /** @var array<string, array{precision: string, null: string, extra: string}> */
    private const COLUMNS = [
        'amount'         => ['precision' => 'DECIMAL(24,8)', 'null' => 'NOT NULL', 'extra' => ''],
        'fee'            => ['precision' => 'DECIMAL(24,8)', 'null' => 'NULL', 'extra' => ''],
        'net_amount'     => ['precision' => 'DECIMAL(24,8)', 'null' => 'NOT NULL', 'extra' => ' DEFAULT 0'],
        'payable_amount' => ['precision' => 'DECIMAL(24,8)', 'null' => 'NULL', 'extra' => ''],
    ];

    public function up(): void
    {
        if ($this->alreadyAt('decimal(24,8)')) {
            return;
        }

        $this->applyPrecision(24, 8);

        Setting::set('money_precision_migrated_at', now()->toIso8601String());
    }

    /**
     * Narrowing back to 2 decimal places is inherently lossy. Rounding first
     * keeps the rollback from failing on out-of-range values, and throwing
     * instead would strand an operator half way through a rollback.
     */
    public function down(): void
    {
        if ($this->alreadyAt('decimal(15,2)')) {
            return;
        }

        Log::warning('Narrowing transactions money columns to decimal(15,2). Sub-cent precision is being rounded away and cannot be recovered.');

        DB::table('transactions')->update([
            'amount'         => DB::raw('ROUND(amount, 2)'),
            'fee'            => DB::raw('ROUND(fee, 2)'),
            'net_amount'     => DB::raw('ROUND(net_amount, 2)'),
            'payable_amount' => DB::raw('ROUND(payable_amount, 2)'),
        ]);

        $this->applyPrecision(15, 2);
    }

    /**
     * Laravel emits one ALTER TABLE per changed column, so four ->change()
     * calls would rebuild `transactions` four times. MySQL gets a single
     * batched statement; other drivers take the portable path.
     *
     * Every original modifier is restated verbatim because ->change()
     * regenerates the full column definition and silently drops whatever is
     * left out.
     */
    private function applyPrecision(int $total, int $places): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $modifications = collect(self::COLUMNS)
                ->map(fn (array $spec, string $column): string => sprintf(
                    'MODIFY `%s` DECIMAL(%d,%d) %s%s',
                    $column,
                    $total,
                    $places,
                    $spec['null'],
                    $spec['extra'],
                ))
                ->implode(', ');

            DB::statement("ALTER TABLE `transactions` {$modifications}");

            return;
        }

        Schema::table('transactions', function (Blueprint $table) use ($total, $places): void {
            $table->decimal('amount', $total, $places)->change();
            $table->decimal('fee', $total, $places)->nullable()->change();
            $table->decimal('net_amount', $total, $places)->default(0)->change();
            $table->decimal('payable_amount', $total, $places)->nullable()->change();
        });
    }

    /**
     * Re-entrancy guard so an operator re-running migrations after a partial
     * failure is a no-op rather than a second full table rebuild.
     */
    private function alreadyAt(string $type): bool
    {
        if (! Schema::hasTable('transactions')) {
            return true;
        }

        $columns = collect(Schema::getColumns('transactions'))->keyBy('name');

        foreach (array_keys(self::COLUMNS) as $column) {
            if (strtolower((string) ($columns[$column]['type'] ?? '')) !== $type) {
                return false;
            }
        }

        return true;
    }
};
