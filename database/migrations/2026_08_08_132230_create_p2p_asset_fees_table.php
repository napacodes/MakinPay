<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-asset P2P trading fees.
 *
 * The maker and taker fees on `p2p_settings` are a single global pair, but a
 * P2P fee is charged in the asset being traded — `P2POrderService` holds
 * `amount + fee` from the seller's crypto wallet. One number therefore means
 * something different on every offer: a flat 0.0005 is about thirty dollars on
 * a BTC trade and a twentieth of a cent on a USDT one.
 *
 * A percentage alone does not solve it either. The flat component exists to
 * cover the cost of settling a trade, and that cost is denominated per asset.
 *
 * This table is an override, not a replacement: a currency with no row here
 * falls back to the global pair, so an install that only trades stablecoins
 * never has to touch it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p2p_asset_fees', function (Blueprint $table): void {
            $table->id();

            // One row per asset — the resolver looks the fee up by currency, so
            // a second row could only ever be ambiguous.
            $table->foreignId('currency_id')->unique()->constrained()->cascadeOnDelete();

            $table->decimal('maker_fee_pct', 8, 4)->default(0);
            $table->decimal('maker_fee_fixed', 24, 8)->default(0)
                ->comment('Flat maker fee, in the traded asset.');
            $table->decimal('maker_fee_min', 24, 8)->nullable();
            $table->decimal('maker_fee_max', 24, 8)->nullable();

            $table->decimal('taker_fee_pct', 8, 4)->default(0);
            $table->decimal('taker_fee_fixed', 24, 8)->default(0)
                ->comment('Flat taker fee, in the traded asset.');
            $table->decimal('taker_fee_min', 24, 8)->nullable();
            $table->decimal('taker_fee_max', 24, 8)->nullable();

            // Switching an override off falls back to the global pair without
            // losing the figures the operator tuned.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p2p_asset_fees');
    }
};
