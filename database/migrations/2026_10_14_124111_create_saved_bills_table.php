<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "My bills": the bills a customer chose to keep, so the next payment is one
 * tap. One row per customer, biller and account number, with the name the
 * customer gave it ("Living room TV").
 *
 * It holds no money and no status: the last payment shown beside it is read
 * from bill_payments. Removing a customer or a biller removes its rows.
 * Guarded, so a second run or a partial earlier one is harmless.
 */
return new class extends Migration
{
    private const TABLE = 'saved_bills';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('biller_id')->constrained('billers')->cascadeOnDelete();
            $table->string('customer_number', 64);
            $table->string('nickname', 60)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'biller_id', 'customer_number'], 'saved_bills_owner_account_unique');
            $table->index(['user_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
