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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('category_id')->nullable();
            $table->string('title');
            $table->text('message');
            $table->string('attachment')->nullable();
            $table->string('priority');
            $table->string('status');
            $table->boolean('is_resolved')->default(0);
            $table->timestamps();
        });

        $this->linkMessagesToTickets();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('messages') && $this->messagesAreLinked()) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->dropForeign(['ticket_id']);
            });
        }

        Schema::dropIfExists('tickets');
    }

    /**
     * `messages.ticket_id` points here, but that table is created by the
     * migration immediately before this one — too early to declare a
     * constraint against a table that does not exist yet. It is declared here
     * instead, and skipped when the shipped database dump already carries it.
     */
    private function linkMessagesToTickets(): void
    {
        if (! Schema::hasTable('messages') || $this->messagesAreLinked()) {
            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->foreign('ticket_id')->references('id')->on('tickets');
        });
    }

    private function messagesAreLinked(): bool
    {
        return collect(Schema::getForeignKeys('messages'))
            ->contains(fn (array $key): bool => in_array('ticket_id', $key['columns'], true));
    }
};
