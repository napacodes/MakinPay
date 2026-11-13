<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The reference data Bill payments needs before an admin can add a biller.
 *
 *  - bill_payment_providers `manual`: the operator pays the biller and records
 *    the receipt. It is a system row, so it cannot be deleted.
 *  - six system bill_categories: electricity, water, TV, internet, education
 *    and insurance.
 *
 * Each row is inserted only when its code is missing, and the migration runs
 * once, so a category an admin switched off stays off and an edited name or
 * note is never put back. Fresh installs and upgrades both get the rows here.
 */
return new class extends Migration
{
    /**
     * @var list<array{code: string, name: string, icon: string, sort_order: int}>
     */
    private const CATEGORIES = [
        ['code' => 'electricity', 'name' => 'Electricity', 'icon' => 'bill-electricity', 'sort_order' => 10],
        ['code' => 'water',       'name' => 'Water',       'icon' => 'bill-water',       'sort_order' => 20],
        ['code' => 'tv',          'name' => 'TV',          'icon' => 'bill-tv',          'sort_order' => 30],
        ['code' => 'internet',    'name' => 'Internet',    'icon' => 'bill-internet',    'sort_order' => 40],
        ['code' => 'education',   'name' => 'Education',   'icon' => 'bill-education',   'sort_order' => 50],
        ['code' => 'insurance',   'name' => 'Insurance',   'icon' => 'bill-insurance',   'sort_order' => 60],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('bill_payment_providers') || ! Schema::hasTable('bill_categories')) {
            return;
        }

        if (! DB::table('bill_payment_providers')->where('code', 'manual')->exists()) {
            DB::table('bill_payment_providers')->insert([
                'plugin_id'       => null,
                'code'            => 'manual',
                'name'            => 'Manual',
                'driver'          => 'manual',
                'logo'            => null,
                'description'     => 'Your team pays the biller and records the receipt.',
                'status'          => true,
                'is_system'       => true,
                'fee_percent'     => 0,
                'fee_fixed'       => 0,
                'fee_currency'    => null,
                'processing_note' => 'Paid by our team, usually within a few hours.',
                'config'          => null,
                'sort_order'      => 0,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        foreach (self::CATEGORIES as $category) {
            if (DB::table('bill_categories')->where('code', $category['code'])->exists()) {
                continue;
            }

            DB::table('bill_categories')->insert([
                'code'        => $category['code'],
                'name'        => $category['name'],
                'icon'        => $category['icon'],
                'description' => null,
                'is_system'   => true,
                'status'      => true,
                'sort_order'  => $category['sort_order'],
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    /**
     * Rows are left in place: once billers and payments point at them,
     * removing them would orphan that history. The tables themselves are
     * dropped by their own create migrations.
     */
    public function down(): void
    {
        //
    }
};
