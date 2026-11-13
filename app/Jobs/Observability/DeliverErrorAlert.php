<?php

namespace App\Jobs\Observability;

use App\Enums\Observability\ErrorLevel;
use App\Enums\Observability\ErrorSource;
use App\Models\Observability\ErrorDeliveryLog;
use App\Models\Observability\ErrorGroup;
use App\Models\Observability\ErrorNotificationChannel;
use App\Services\Observability\AlertRouter;
use App\Services\Observability\Channels\ChannelResult;
use App\Services\Observability\ErrorCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One alert, one channel, one attempt.
 *
 * Retries are the interesting part. A 429 or a 5xx is worth trying again on a
 * widening backoff; a 401, 403 or 404 is not, because no number of retries
 * fixes a wrong token, and continuing to try only delays telling an
 * administrator that nobody is being paged.
 *
 * Five consecutive failures trip a circuit breaker: the channel is paused for
 * fifteen minutes and an infrastructure error is raised so the other channels
 * carry the news that this one is down.
 */
class DeliverErrorAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * 10s, 60s, 5m, 25m, 2h. Widening rather than uniform, so a provider
     * having a bad minute is retried quickly while one having a bad afternoon
     * is not hammered.
     *
     * @var list<int>
     */
    private const BACKOFF = [10, 60, 300, 1500, 7200];

    private const FAILURES_BEFORE_BREAK = 5;

    private const BREAKER_PAUSE_MINUTES = 15;

    public int $tries = 5;

    public function __construct(
        private string $groupId,
        private string $channelKey,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    public function handle(AlertRouter $router, ErrorCollector $collector): void
    {
        $group   = ErrorGroup::query()->where('group_id', $this->groupId)->first();
        $channel = ErrorNotificationChannel::query()->where('key', $this->channelKey)->first();
        $adapter = $router->adapter($this->channelKey);

        if ($group === null || $channel === null || $adapter === null || ! $channel->isDeliverable()) {
            return;
        }

        // Planned before any earlier attempt for this group had landed — a
        // burst enqueues many of these at once. The window is checked again
        // now, against deliveries that have actually happened.
        if (! $router->stillDue($group, $this->channelKey)) {
            ErrorDeliveryLog::recordGate($group->id, $this->channelKey, ErrorDeliveryLog::STATUS_THROTTLED);

            return;
        }

        $result = $adapter->send($group, $router->body($group), $channel);

        $this->log($group, $result);

        if ($result->delivered) {
            $this->recordSuccess($channel, $result);

            return;
        }

        $this->recordFailure($channel, $result, $collector);

        /*
         * Releasing back onto the queue is what applies the backoff. A
         * permanent rejection is deliberately not released: the channel is
         * already marked failing, and four more attempts would only postpone
         * the moment somebody notices.
         */
        if ($result->isRetryable() && $this->attempts() < $this->tries) {
            $this->release(self::BACKOFF[min($this->attempts(), count(self::BACKOFF)) - 1]);
        }
    }

    private function log(ErrorGroup $group, ChannelResult $result): void
    {
        ErrorDeliveryLog::query()->create([
            'error_group_id' => $group->id,
            'channel_key'    => $this->channelKey,
            'status'         => $result->delivered
                ? ErrorDeliveryLog::STATUS_SENT
                : ($result->isRetryable() ? ErrorDeliveryLog::STATUS_RETRYING : ErrorDeliveryLog::STATUS_FAILED),
            'attempt'          => $this->attempts(),
            'response_code'    => $result->statusCode,
            'response_summary' => $result->summary,
            'duration_ms'      => $result->durationMs,
            'created_at'       => now(),
        ]);
    }

    private function recordSuccess(ErrorNotificationChannel $channel, ChannelResult $result): void
    {
        $channel->update([
            'status'               => ErrorNotificationChannel::STATUS_CONNECTED,
            'consecutive_failures' => 0,
            'paused_until'         => null,
            'last_delivery_at'     => now(),
            'last_delivery_status' => ErrorDeliveryLog::STATUS_SENT,
            'last_delivery_ms'     => $result->durationMs,
        ]);
    }

    private function recordFailure(ErrorNotificationChannel $channel, ChannelResult $result, ErrorCollector $collector): void
    {
        $failures = $channel->consecutive_failures + 1;

        $channel->update([
            'status' => $result->isRetryable()
                ? ErrorNotificationChannel::STATUS_RETRYING
                : ErrorNotificationChannel::STATUS_FAILING,
            'consecutive_failures' => $failures,
            'last_delivery_at'     => now(),
            'last_delivery_status' => ErrorDeliveryLog::STATUS_FAILED,
            'last_delivery_ms'     => $result->durationMs,
        ]);

        if ($result->isPermanentRejection()) {
            $this->raiseInfraError($collector, $channel, $result);

            return;
        }

        if ($failures >= self::FAILURES_BEFORE_BREAK) {
            $this->tripBreaker($collector, $channel);
        }
    }

    /**
     * Pause the channel and tell the others.
     *
     * A dead endpoint that keeps being retried consumes workers the healthy
     * channels still need, and the fact that one alerting route is down is
     * itself something an administrator has to hear about.
     */
    private function tripBreaker(ErrorCollector $collector, ErrorNotificationChannel $channel): void
    {
        $channel->update([
            'status'       => ErrorNotificationChannel::STATUS_FAILING,
            'paused_until' => now()->addMinutes(self::BREAKER_PAUSE_MINUTES),
        ]);

        $collector->record([
            'level'           => ErrorLevel::Critical,
            'source'          => ErrorSource::Infra,
            'exception_class' => self::class,
            'message'         => 'Alert channel '.$channel->key.' failed '.self::FAILURES_BEFORE_BREAK.' times in a row and was paused for '.self::BREAKER_PAUSE_MINUTES.' minutes.',
            'culprit'         => 'observability.channel.'.$channel->key,
        ]);
    }

    private function raiseInfraError(ErrorCollector $collector, ErrorNotificationChannel $channel, ChannelResult $result): void
    {
        $collector->record([
            'level'           => ErrorLevel::Critical,
            'source'          => ErrorSource::Infra,
            'exception_class' => self::class,
            'message'         => 'Alert channel '.$channel->key.' rejected the message with HTTP '.$result->statusCode.'. Its credentials or endpoint need fixing by hand.',
            'culprit'         => 'observability.channel.'.$channel->key,
        ]);
    }
}
