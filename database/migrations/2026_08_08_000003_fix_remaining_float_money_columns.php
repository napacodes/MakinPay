<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last two money columns the precision work did not reach.
 *
 * `agents.commission` is a percentage in a `double`. The same change was made
 * to `merchants.fee`, and agents were simply missed. A percentage survives a
 * float far better than an amount does, but there is no reason to keep one when
 * every other fee column is decimal — and 2.9 does not round-trip through
 * binary floating point exactly.
 *
 * `user_ranks.transaction_amount` is worse: a base-currency threshold stored as
 * `int unsigned`. It is compared against `SUM(transactions.amount)`, which is
 * decimal(24,8), so a rank at "0.5" was silently stored as 0 and every user
 * qualified for it immediately. The admin form validates it as `numeric` and
 * the column then throws the fraction away without saying so — a rank
 * threshold of 10.50 becomes 10.
 *
 * Widening an int to a decimal preserves every existing value.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A percentage: decimal(8,4) matches merchants.fee and leaves room for
        // four decimal places of rate, which is more than any fee schedule uses.
        $this->change('agents', 'commission', fn (Blueprint $table) => $table->decimal('commission', 8, 4)->default(0));

        // An amount: decimal(24,8) matches the ledger it is compared against.
        $this->change('user_ranks', 'transaction_amount', fn (Blueprint $table) => $table->decimal('transaction_amount', 24, 8)->default(0));
    }

    /**
     * Reverting the rank threshold truncates any fractional value that was set
     * in the meantime — the column simply cannot hold it. Lossy but
     * non-throwing, so a rollback cannot strand an operator part way through.
     */
    public function down(): void
    {
        $this->change('agents', 'commission', fn (Blueprint $table) => $table->double('commission')->default(0));
        $this->change('user_ranks', 'transaction_amount', fn (Blueprint $table) => $table->unsignedInteger('transaction_amount'));
    }

    private function change(string $table, string $column, callable $definition): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($definition): void {
            $definition($blueprint)->change();
        });
    }
};
