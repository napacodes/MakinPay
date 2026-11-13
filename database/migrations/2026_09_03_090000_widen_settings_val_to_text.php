<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Several settings hold far more than a varchar(255): the KYC
            // consent statement validates at max:5000, and the maintenance,
            // cookie, and PWA offline texts all allow 500-1000 characters.
            $table->text('val')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('val')->nullable()->change();
        });
    }
};
