<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per line of a mass payment: the recipient as typed, who it was
 * pinned to at preview, the frozen figures, and where its money went.
 *
 * `recipient_user_id` and `recipient_wallet_id` are pinned when the draft is
 * built, re-validated at confirm and only ever re-checked, never re-resolved,
 * when the line is paid. The amounts are set on valid lines only and are
 * frozen at confirm.
 *
 * `issue_code` holds a stable MassPaymentIssue code, never translated text,
 * so a report or an API client can rely on it in any language.
 *
 * Unique keys carry the exactly-once story:
 *   - (mass_payment_batch_id, line_no): one row per line of the file;
 *   - debit_transaction_id: one reserved debit per line, so confirm cannot
 *     bind the same ledger row to two lines;
 *   - credit_transaction_id: at most one RECEIVE_MONEY credit per line, so a
 *     retried payout cannot pay twice;
 *   - refund_transaction_id: at most one REFUND per line, so a retried failure
 *     cannot release the same money twice.
 *
 * The batch key is RESTRICT: a batch whose lines carry ledger links must
 * never vanish from under them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mass_payment_rows')) {
            return;
        }

        Schema::create('mass_payment_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mass_payment_batch_id')->constrained('mass_payment_batches')->restrictOnDelete();
            $table->unsignedInteger('line_no');

            $table->string('recipient_input', 191);
            $table->string('recipient_type', 16)->nullable();
            $table->string('currency_input', 10)->nullable();
            $table->string('client_reference', 100)->nullable();

            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->string('recipient_label', 191)->nullable();

            $table->string('amount_input', 64);
            $table->decimal('entered_amount', 24, 8)->nullable();
            $table->decimal('base_amount', 24, 8)->nullable();
            $table->decimal('base_fee', 24, 8)->nullable();
            $table->decimal('net_amount', 24, 8)->nullable();
            $table->decimal('payable_amount', 24, 8)->nullable();
            $table->decimal('wallet_fee', 24, 8)->nullable();
            $table->decimal('exchange_rate', 36, 18)->nullable();

            $table->string('note', 255)->nullable();

            $table->string('status', 16);
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason', 40)->nullable();

            $table->string('issue_code', 40)->nullable();
            $table->json('warnings')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('debit_transaction_id')->nullable()->unique()->constrained('transactions')->nullOnDelete();
            $table->foreignId('credit_transaction_id')->nullable()->unique()->constrained('transactions')->nullOnDelete();
            $table->foreignId('refund_transaction_id')->nullable()->unique()->constrained('transactions')->nullOnDelete();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['mass_payment_batch_id', 'line_no'], 'mass_payment_rows_batch_line_unique');
            $table->index(['mass_payment_batch_id', 'status', 'id'], 'mass_payment_rows_batch_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mass_payment_rows');
    }
};
