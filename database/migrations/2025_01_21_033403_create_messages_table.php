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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained();

            // `tickets` is created by the migration that runs immediately
            // after this one, and MySQL refuses a foreign key to a table that
            // does not exist yet. The constraint is added from there instead.
            $table->foreignId('ticket_id');

            $table->string('message');
            $table->string('attachment')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
