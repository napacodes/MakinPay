<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per mass payment: a merchant paying many wallets from one source
 * wallet in one currency, in a single confirmed batch.
 *
 * A batch is a draft until the owner confirms it. Confirming reserves the
 * whole total in one wallet debit and writes one PENDING MASS_PAYMENT ledger
 * row per payable line; the lines are then paid or refunded one at a time.
 * The batch row itself never holds money: the per-line ledger rows do.
 *
 * Money columns are decimal(24,8) and read back as exact strings. The totals
 * are written from the stored rows with DecimalMoney, never from floats.
 * `paid_count`, `failed_count`, `paid_payable`, `paid_base_fee` and
 * `released_amount` are written only when the batch is finalized, from the
 * ledger.
 *
 * Unique keys:
 *   - uuid: the route key, so a batch is never addressed by its sequential id;
 *   - reference: the human-readable MP reference written onto every debit's
 *     trx_reference. The model's existence check only makes a collision
 *     unlikely; this index is what refuses one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mass_payment_batches')) {
            return;
        }

        Schema::create('mass_payment_batches', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('reference', 20)->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('currency_code', 10);

            $table->string('kind', 16)->default('wallet');
            $table->string('source', 16);
            $table->string('channel', 8)->default('web');
            $table->string('original_filename', 191)->nullable();

            $table->string('status', 24)->index();
            $table->boolean('skip_invalid')->default(false);
            $table->boolean('warnings_acknowledged')->default(false);

            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('paid_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->decimal('total_amount', 24, 8)->default(0);
            $table->decimal('total_fee', 24, 8)->default(0);
            $table->decimal('total_payable', 24, 8)->default(0);
            $table->decimal('total_base_amount', 24, 8)->default(0);
            $table->decimal('total_base_fee', 24, 8)->default(0);
            $table->decimal('paid_payable', 24, 8)->default(0);
            $table->decimal('paid_base_fee', 24, 8)->default(0);
            $table->decimal('released_amount', 24, 8)->default(0);

            $table->decimal('exchange_rate', 36, 18)->nullable();

            $table->char('preview_fingerprint', 64)->nullable();

            $table->timestamp('stop_requested_at')->nullable();
            $table->string('stop_requested_by_type', 16)->nullable();
            $table->unsignedBigInteger('stop_requested_by_id')->nullable();

            $table->string('hold_reason', 40)->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('admin_note')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->json('metadata')->nullable();

            $table->index(['user_id', 'id']);
            $table->index(['status', 'last_progress_at']);
            $table->index('wallet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mass_payment_batches');
    }
};
