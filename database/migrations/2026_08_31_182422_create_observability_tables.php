<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Error Management & Observability storage.
 *
 * Two tables carry the weight. error_groups is one row per fingerprint — the
 * row the Issues list reads, cheap to sort and count because every number it
 * shows is a counter column maintained on ingest. error_events is one row per
 * occurrence and holds the heavy JSON. That split is the only reason a
 * multi-million-event install can still sort the list in under 100ms; counting
 * over the event table would undo it.
 *
 * error_stat_buckets pre-aggregates five-minute windows so the Overview charts
 * never touch error_events either.
 *
 * Every table is guarded with hasTable because DigiKash ships to existing
 * installations and this migration replays on update.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('error_groups')) {
            Schema::create('error_groups', function (Blueprint $table) {
                $table->id();

                /*
                 * Deterministic: exception class + normalised message + first
                 * application frame. Two events with the same hash are the
                 * same bug, which is what makes grouping possible at all.
                 */
                $table->char('fingerprint', 32)->unique();

                /*
                 * Stable public id. Appears in notification URLs and in debug
                 * report filenames, so it must outlive the auto-increment key.
                 */
                $table->ulid('group_id')->unique();

                $table->string('title');
                $table->string('exception_class')->nullable();
                $table->text('message')->nullable();
                $table->string('error_code', 32)->nullable()->index();
                $table->string('culprit')->nullable();

                /*
                 * PSR-3 / RFC 5424 name. The UI collapses the eight into four
                 * badges; storing the collapsed value would lose the
                 * distinction between critical and emergency forever.
                 */
                $table->string('level', 16)->default('error');
                $table->string('source', 32)->default('php');
                $table->string('status', 16)->default('new');

                $table->timestamp('first_seen')->nullable();
                $table->timestamp('last_seen')->nullable();

                $table->unsignedBigInteger('event_count')->default(0);
                $table->unsignedInteger('affected_user_count')->default(0);

                $table->unsignedBigInteger('assignee_id')->nullable()->index();
                $table->json('tags')->nullable();

                $table->timestamp('resolved_at')->nullable();
                $table->string('resolved_release', 64)->nullable();
                $table->string('first_release', 64)->nullable();
                $table->string('last_release', 64)->nullable();

                $table->timestamps();

                $table->index(['status', 'level', 'last_seen'], 'error_groups_list_idx');
                $table->index(['source', 'last_seen'], 'error_groups_source_idx');
            });
        }

        if (! Schema::hasTable('error_events')) {
            Schema::create('error_events', function (Blueprint $table) {
                $table->id();
                $table->ulid('event_id')->unique();
                $table->foreignId('error_group_id')->constrained('error_groups')->cascadeOnDelete();

                $table->string('level', 16)->default('error');

                /*
                 * Scrubbed before the insert, never at display time.
                 */
                $table->json('context')->nullable();
                $table->json('request')->nullable();
                $table->json('state')->nullable();
                $table->json('redaction_report')->nullable();

                /*
                 * OpenTelemetry ids, so an error links to a distributed trace
                 * instead of ending the investigation.
                 */
                $table->char('trace_id', 32)->nullable()->index();
                $table->char('span_id', 16)->nullable();

                $table->string('release', 64)->nullable();
                $table->unsignedBigInteger('user_id')->nullable()->index();

                $table->timestamp('created_at')->nullable();

                $table->index(['error_group_id', 'created_at'], 'error_events_group_time_idx');

                /*
                 * Retention pruning deletes by age across every group, so the
                 * composite above cannot serve it — its leading column is the
                 * group. Without this the nightly job scans the largest table
                 * in the module.
                 */
                $table->index('created_at', 'error_events_created_idx');
            });
        }

        if (! Schema::hasTable('error_stat_buckets')) {
            Schema::create('error_stat_buckets', function (Blueprint $table) {
                $table->id();
                $table->timestamp('bucket_at');
                $table->unsignedBigInteger('error_group_id')->nullable();
                $table->string('source', 32);
                $table->string('level', 16);
                $table->unsignedBigInteger('count')->default(0);

                $table->unique(['bucket_at', 'error_group_id', 'source', 'level'], 'error_stat_buckets_slot_unq');
                $table->index(['bucket_at', 'source'], 'error_stat_buckets_source_idx');
            });
        }

        if (! Schema::hasTable('error_notification_channels')) {
            Schema::create('error_notification_channels', function (Blueprint $table) {
                $table->id();
                $table->string('key', 32)->unique();
                $table->string('driver', 32);
                $table->string('label');
                $table->boolean('is_enabled')->default(false);

                /*
                 * Webhook URLs and bot tokens. Encrypted by the model cast, so
                 * a database dump handed to support does not carry the keys to
                 * every alerting channel the platform owns.
                 */
                $table->text('credentials')->nullable();
                $table->json('settings')->nullable();

                $table->string('status', 16)->default('not_connected');
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('paused_until')->nullable();
                $table->timestamp('last_delivery_at')->nullable();
                $table->string('last_delivery_status', 32)->nullable();
                $table->unsignedInteger('last_delivery_ms')->nullable();

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('error_delivery_log')) {
            Schema::create('error_delivery_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('error_group_id')->nullable()->index();
                $table->string('channel_key', 32);

                /*
                 * throttled and quiet_hours are states, not omissions. A
                 * suppressed attempt stays here on purpose so an admin can
                 * answer "why was I not paged?" without reading code.
                 */
                $table->string('status', 24);
                $table->unsignedSmallInteger('attempt')->default(1);
                $table->unsignedSmallInteger('response_code')->nullable();
                $table->string('response_summary')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->string('acknowledged_by')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['channel_key', 'created_at'], 'error_delivery_log_channel_idx');
            });
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('error_delivery_log');
        Schema::dropIfExists('error_notification_channels');
        Schema::dropIfExists('error_stat_buckets');
        Schema::dropIfExists('error_events');
        Schema::dropIfExists('error_groups');
    }
};
