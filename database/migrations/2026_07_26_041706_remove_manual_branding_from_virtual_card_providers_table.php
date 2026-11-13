<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('virtual_card_providers', function (Blueprint $table) {
            $table->dropColumn(['brand', 'brand_color', 'display_label']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('virtual_card_providers', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('logo');
            $table->string('brand_color', 16)->nullable()->after('brand');
            $table->string('display_label', 24)->nullable()->after('brand_color');
        });
    }
};
