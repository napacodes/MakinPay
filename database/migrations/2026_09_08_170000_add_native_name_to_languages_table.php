<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The language's name in its own language — العربية, Español, বাংলা.
         *
         * A language picker exists for people who cannot read the page they
         * are looking at. An Arabic speaker who lands on a Spanish page and
         * finds "árabe" in the list has been handed a word they cannot read;
         * "العربية" they recognise at a glance. W3C's i18n guidance is
         * explicit about it, and it is why the picker on every large site
         * reads this way.
         *
         * Nullable, and read as `native_name ?: name`, so an installation
         * that upgrades into this column and never fills it renders exactly
         * what it rendered before.
         */
        Schema::table('languages', function (Blueprint $table): void {
            $table->string('native_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('languages', function (Blueprint $table): void {
            $table->dropColumn('native_name');
        });
    }
};
