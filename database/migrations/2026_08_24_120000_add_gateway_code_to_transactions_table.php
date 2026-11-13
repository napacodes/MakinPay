<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which gateway created this transaction, as a machine-readable code.
 *
 * The ledger already carried `provider`, but that holds the payment method's
 * display name — editable by an administrator, duplicable across methods, and
 * therefore useless as a security identity. Without a stable code, nothing
 * stopped a callback arriving at one gateway's endpoint from settling a
 * transaction another gateway had created.
 *
 * Nullable because existing installations have transactions in flight. Those
 * resolve through the deposit/withdraw method relationship instead, and fail
 * closed when that relationship cannot name exactly one gateway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'gateway_code')) {
                $table->string('gateway_code', 64)->nullable()->after('provider');
                $table->index(['gateway_code', 'status']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'gateway_code')) {
                $table->dropIndex(['gateway_code', 'status']);
                $table->dropColumn('gateway_code');
            }
        });
    }
};
