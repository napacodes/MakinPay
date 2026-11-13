<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A P2P order gets a number of its own.
 *
 * Every link to an order carried the row id — /p2p/orders/349 — which told
 * a customer how many orders the platform had taken and let anyone walk the
 * sequence. The number is what the customer sees, quotes to support and
 * follows in a link; the id stays the primary key. Existing rows are
 * numbered here, so an upgraded install addresses its old orders the same
 * way as its new ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('p2p_orders', function (Blueprint $table): void {
            $table->string('order_number', 24)->nullable()->unique()->after('id');
        });

        DB::table('p2p_orders')
            ->select('id')
            ->whereNull('order_number')
            ->orderBy('id')
            ->chunkById(200, function ($orders): void {
                foreach ($orders as $order) {
                    DB::table('p2p_orders')
                        ->where('id', $order->id)
                        ->update(['order_number' => $this->number()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('p2p_orders', function (Blueprint $table): void {
            $table->dropUnique(['order_number']);
            $table->dropColumn('order_number');
        });
    }

    /**
     * The shape App\Models\P2P\Order gives a new order, written out here so
     * this migration keeps working whatever the model does later.
     */
    private function number(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        do {
            $number = 'P2P-';

            for ($i = 0; $i < 10; $i++) {
                $number .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (DB::table('p2p_orders')->where('order_number', $number)->exists());

        return $number;
    }
};
