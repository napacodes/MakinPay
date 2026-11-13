<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per bill payment: the attempt and its state.
 *
 * The ledger row (`transaction_id`) records what the money did; this records
 * what the attempt did, which is the only thing that can say whether the
 * biller was ever asked to be paid. `dispatch_state` plus `submitted_at` is
 * that fact, and it decides whether a refund is safe.
 *
 * Everything a receipt shows is snapshotted here when the money is reserved
 * (biller name, category, item, customer-number label, provider), so editing
 * or removing a biller never rewrites a customer's history.
 *
 * Money is held twice: `bill_*` is what the biller receives and `amount`,
 * `fee`, `total_amount`, `currency` is what the wallet pays. They are the same
 * currency in GF2; `exchange_rate` stays null until cross-currency payments.
 *
 * Unique keys carry the idempotency story:
 *   - (user_id, client_idempotency_key): one payment per client request, so a
 *     resubmit or an API retry replays instead of debiting again;
 *   - provider_request_id: the key sent to a provider so it cannot pay twice;
 *   - (provider, provider_transaction_id): a provider webhook maps to exactly
 *     one payment;
 *   - transaction_id and refund_transaction_id: one debit row and at most one
 *     refund row per payment.
 *
 * `token` (a prepaid-meter token, a voucher PIN) is encrypted by the model and
 * never copied into the ledger, notifications or logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bill_payments')) {
            return;
        }

        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('reference', 20)->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('biller_id')->constrained('billers')->restrictOnDelete();
            $table->foreignId('biller_item_id')->nullable()->constrained('biller_items')->nullOnDelete();
            $table->foreignId('bill_payment_provider_id')->constrained('bill_payment_providers')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained('transactions')->nullOnDelete();
            $table->foreignId('refund_transaction_id')->nullable()->unique()->constrained('transactions')->nullOnDelete();

            $table->string('biller_name', 120);
            $table->string('category_code', 64);
            $table->string('item_name', 120)->nullable();
            $table->string('item_code', 128)->nullable();
            $table->char('country', 2);
            $table->string('customer_number_label', 80);
            $table->string('customer_number', 64);
            $table->string('provider_code', 64);
            $table->string('driver', 32);
            $table->string('fulfilment', 16);

            $table->decimal('bill_amount', 24, 8);
            $table->string('bill_currency', 10);
            $table->decimal('amount', 24, 8);
            $table->decimal('fee', 24, 8)->default(0);
            $table->decimal('total_amount', 24, 8);
            $table->string('currency', 10);
            $table->decimal('exchange_rate', 36, 18)->nullable();

            $table->string('status', 32)->default('pending')->index();
            $table->string('dispatch_state', 32)->default('reserved')->index();

            $table->string('provider_request_id', 64)->unique();
            $table->string('provider_transaction_id', 128)->nullable();
            $table->string('receipt_reference', 128)->nullable();
            $table->text('token')->nullable();

            $table->string('client_idempotency_key', 128)->nullable();
            $table->char('client_request_hash', 64)->nullable();
            $table->string('channel', 8)->default('web');

            $table->string('failure_reason', 500)->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('claimed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->json('metadata')->nullable();

            // Written and committed before the fulfiller is called. A null
            // here is the proof that nobody was ever asked to pay the biller.
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_idempotency_key'], 'bill_payments_user_idempotency_unique');
            $table->unique(['bill_payment_provider_id', 'provider_transaction_id'], 'bill_payments_provider_txn_unique');
            $table->index(['biller_id', 'receipt_reference']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['dispatch_state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payments');
    }
};
