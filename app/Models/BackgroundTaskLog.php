<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackgroundTaskLog extends Model
{
    use MassPrunable;

    /**
     * How long a run started by cron is kept. Four commands on this schedule
     * tick every minute, so the table gains roughly 6,400 rows a day and would
     * grow without limit - on a screen that counts rows per task on every page
     * load. A fortnight answers everything this history is asked: whether cron
     * is running, and what failed recently.
     */
    private const SCHEDULED_RETENTION_DAYS = 14;

    /**
     * A manual run is somebody's deliberate act, recorded against their admin
     * account, so it is kept far longer than a cron tick. There are only ever a
     * handful of them.
     */
    private const MANUAL_RETENTION_DAYS = 365;

    protected $fillable = [
        'task_key',
        'command_signature',
        'status',
        'options',
        'output',
        'error_message',
        'started_at',
        'finished_at',
        'duration_ms',
        'executed_by',
        'trigger_type',
    ];

    protected function casts(): array
    {
        return [
            'options'     => 'array',
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'executed_by');
    }

    /**
     * Rows old enough to drop, kept on the two clocks above.
     *
     * A row still marked `running` is left alone whatever its age: it means a
     * run started and never reported back, which is the one thing on this
     * screen worth keeping until somebody has looked at it.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('status', '!=', 'running')
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $scheduled): void {
                        $scheduled
                            ->where('trigger_type', 'scheduled')
                            ->where('created_at', '<', now()->subDays(self::SCHEDULED_RETENTION_DAYS));
                    })
                    ->orWhere(function (Builder $manual): void {
                        $manual
                            ->where('trigger_type', '!=', 'scheduled')
                            ->where('created_at', '<', now()->subDays(self::MANUAL_RETENTION_DAYS));
                    });
            });
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function durationLabel(): string
    {
        if ($this->duration_ms === null) {
            return '-';
        }

        if ($this->duration_ms < 1000) {
            return $this->duration_ms.' ms';
        }

        return number_format($this->duration_ms / 1000, 2).' s';
    }

    public function outputSummary(int $maxLength = 120): string
    {
        $text = $this->isFailed() ? ($this->error_message ?? '') : ($this->output ?? '');
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ($text === '') {
            return 'No output';
        }

        return mb_strlen($text) > $maxLength
            ? mb_substr($text, 0, $maxLength).'...'
            : $text;
    }
}
