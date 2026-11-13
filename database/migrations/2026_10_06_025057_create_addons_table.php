<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per installed add-on. The code itself lives in addons/<slug>; this
 * row is the operator's record of it: which version is installed, whether it
 * is switched on, and the last thing that went wrong.
 *
 * Guarded so a re-run on an upgraded install is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('addons')) {
            return;
        }

        Schema::create('addons', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 100);
            $table->string('version', 32);
            $table->string('status', 16)->default('installed')->index();
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addons');
    }
};
