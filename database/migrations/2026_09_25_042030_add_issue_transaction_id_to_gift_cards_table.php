<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * The ledger entry that charged the sender for a gift card - its amount,
     * the fee, and the wallet they came out of - so cancelling the card can
     * give back exactly what it took. Cards issued before this column have
     * none; cancelling one of those returns the card's value only, since
     * nothing recorded which entry was its charge.
     */
    public function up(): void
    {
        if (! Schema::hasTable('gift_cards') || Schema::hasColumn('gift_cards', 'issue_transaction_id')) {
            return;
        }

        Schema::table('gift_cards', function (Blueprint $table): void {
            $table->foreignId('issue_transaction_id')->nullable()->after('currency_id')->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('gift_cards', 'issue_transaction_id')) {
            return;
        }

        Schema::table('gift_cards', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('issue_transaction_id');
        });
    }
};
