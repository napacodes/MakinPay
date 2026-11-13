<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * A ticket reply could be 255 characters and no more.
     *
     * messages.message was created as a VARCHAR(255) while the ticket's opening
     * message is TEXT, so a customer could explain a problem at length once and
     * then answer support's questions in a tweet. A longer reply was a database
     * error on a strict server and silently cut short on a lenient one; the
     * forms were made to refuse it, which was correct and still not enough for
     * a support conversation.
     *
     * Widening VARCHAR to TEXT loses nothing from any existing row. The column
     * keeps its NOT NULL.
     */
    public function up(): void
    {
        if (! $this->applies() || $this->isText()) {
            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->text('message')->change();
        });
    }

    /*
     * Narrowing back would cut every reply longer than 255 characters, so it
     * refuses while any such reply exists rather than destroy customer text.
     * LENGTH counts bytes on MySQL, which makes the check cautious, never lax.
     */
    public function down(): void
    {
        if (! $this->applies() || ! $this->isText()) {
            return;
        }

        if (DB::table('messages')->whereRaw('LENGTH(message) > 255')->exists()) {
            throw new RuntimeException(
                'messages.message holds ticket replies longer than 255 characters; narrowing it would cut them short. Move or shorten them first.'
            );
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->string('message')->change();
        });
    }

    private function applies(): bool
    {
        return Schema::hasTable('messages') && Schema::hasColumn('messages', 'message');
    }

    private function isText(): bool
    {
        $column = collect(Schema::getColumns('messages'))->firstWhere('name', 'message');

        return $column !== null && str_contains(strtolower((string) $column['type']), 'text');
    }
};
