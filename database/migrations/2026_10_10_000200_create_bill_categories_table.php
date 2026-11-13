<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The groups a customer browses billers by: electricity, water, TV and so on.
 *
 * `icon` is a key from config('bill_payments.category_icons'), each one a
 * static SVG in public/general/static/svg. A system category ships with the
 * module and cannot be deleted, only switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bill_categories')) {
            return;
        }

        Schema::create('bill_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->string('icon', 64)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('status')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_categories');
    }
};
