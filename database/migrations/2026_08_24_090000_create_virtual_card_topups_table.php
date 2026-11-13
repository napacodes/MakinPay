<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per attempt to move money from a wallet onto a virtual card.
 *
 * The ledger entry records what the money did; this records what the *attempt*
 * did, which is a different question and the only one a recovery routine can
 * answer. A card funded at the provider while the local settle died leaves a
 * ledger row saying PENDING and nothing at all to say whether the gateway was
 * ever contacted. `dispatch_state` plus `submitted_at` is that missing fact,
 * and it is what makes an automatic refund safe in one case and forbidden in
 * the other.
 *
 * Shaped after `mobile_recharges`, which is the same problem — a wallet debit
 * paired with an irreversible external call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_card_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('virtual_card_id')->constrained('virtual_cards')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            // Ledger scale, matching transactions.* — a card funded from a
            // crypto wallet moves amounts at eight decimals.
            $table->decimal('amount', 24, 8);
            $table->decimal('fee', 24, 8)->default(0);
            $table->decimal('total_amount', 24, 8);
            $table->string('currency', 10);

            $table->string('provider', 64);

            // Minted at reservation time and reused by every verified retry,
            // so a provider that supports idempotency cannot fund twice.
            $table->string('provider_reference')->nullable()->index();
            $table->string('provider_transaction_id')->nullable();

            $table->string('status', 32)->default('pending')->index();
            $table->string('dispatch_state', 32)->default('reserved')->index();

            $table->string('client_idempotency_key')->nullable();
            $table->string('client_request_hash', 64)->nullable();

            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();

            // Written and committed before the provider request goes out. A
            // null here is the proof that no request was ever sent.
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_idempotency_key'], 'virtual_card_topups_user_key_unique');
            $table->index(['dispatch_state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_card_topups');
    }
};
