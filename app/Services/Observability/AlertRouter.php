<?php

namespace App\Services\Observability;

use App\Models\Observability\ErrorDeliveryLog;
use App\Models\Observability\ErrorGroup;
use App\Models\Observability\ErrorNotificationChannel;
use App\Services\Observability\Channels\ChannelAdapter;
use App\Services\Observability\Channels\DiscordChannelAdapter;
use App\Services\Observability\Channels\SlackChannelAdapter;
use App\Services\Observability\Channels\TeamsChannelAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Decides who gets told, and writes down every decision.
 *
 * Each gate below can silence an alert, and each one records why in the
 * delivery log. That is the whole point: "why was I not paged?" has to be
 * answerable from the panel rather than by reading this file.
 *
 * A suppressed attempt is a logged row, never a discarded one. Dropping them
 * would make a working throttle indistinguishable from a broken channel.
 */
class AlertRouter
{
    /**
     * Severity routing: how often an alert may repeat, and whether quiet hours
     * apply to it. Fatal ignores them, because a payment gateway that stopped
     * settling at 3am is still not settling at 8am.
     *
     * It deliberately does not name channels. An installation connects the one
     * or two places its team actually watches, and a hardcoded list would drop
     * a fatal error on a site whose team happens to live in Teams rather than
     * in whichever name was written here.
     *
     * @var array<string, array{alerts: bool, throttle: int, quiet_hours: bool}>
     */
    private const MATRIX = [
        'fatal'   => ['alerts' => true, 'throttle' => 15, 'quiet_hours' => false],
        'error'   => ['alerts' => true, 'throttle' => 30, 'quiet_hours' => true],
        'warning' => ['alerts' => true, 'throttle' => 240, 'quiet_hours' => true],
        'info'    => ['alerts' => false, 'throttle' => 0, 'quiet_hours' => true],
    ];

    public function __construct(private AlertTemplateRenderer $renderer) {}

    /**
     * Which channels should be told about this group right now, and which were
     * held back and why.
     *
     * @return array<int, array{channel: string, status: string}>
     */
    public function plan(ErrorGroup $group, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $rule = $this->rule($group);

        /*
         * Ignored means "stop alerting me". The group keeps being captured and
         * keeps counting; only this step stops.
         */
        if ($group->isMuted() || ! $rule['alerts']) {
            return [];
        }

        $plan = [];

        foreach (array_keys($this->adapters()) as $key) {
            $channel = $this->channelRow($key);

            if ($channel === null || ! $channel->isDeliverable()) {
                continue;
            }

            $plan[] = ['channel' => $key, 'status' => $this->gate($group, $key, $rule, $now)];
        }

        return $plan;
    }

    /**
     * The first gate this alert fails, or 'send' when it passes all of them.
     *
     * @param array{alerts: bool, throttle: int, quiet_hours: bool} $rule
     */
    private function gate(ErrorGroup $group, string $channelKey, array $rule, Carbon $now): string
    {
        if ($this->wasNotifiedWithin($group, $channelKey, $rule['throttle'], $now)) {
            return ErrorDeliveryLog::STATUS_THROTTLED;
        }

        if ($rule['quiet_hours'] && $this->inQuietHours($now)) {
            return ErrorDeliveryLog::STATUS_QUIET_HOURS;
        }

        // The sent row only exists once a delivery has landed, and a burst
        // plans hundreds of alerts before the first one does. The window is
        // therefore also claimed here, atomically, so the second plan inside
        // it is throttled whether or not the first has been delivered yet.
        if ($rule['throttle'] >= 1 && ! Cache::add($this->windowKey($group, $channelKey), 1, $rule['throttle'] * 60)) {
            return ErrorDeliveryLog::STATUS_THROTTLED;
        }

        return 'send';
    }

    /**
     * Whether a delivery planned earlier should still go out now.
     *
     * Asked by the delivery job itself, because the plan was made while the
     * queue may have been holding many identical jobs for the same group.
     */
    public function stillDue(ErrorGroup $group, string $channelKey, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now();
        $rule = $this->rule($group);

        if ($group->isMuted() || ! $rule['alerts']) {
            return false;
        }

        return ! $this->wasNotifiedWithin($group, $channelKey, $rule['throttle'], $now);
    }

    /**
     * The routing rule for a group's severity, falling back to the quietest
     * one rather than to an undefined index.
     *
     * @return array{alerts: bool, throttle: int, quiet_hours: bool}
     */
    private function rule(ErrorGroup $group): array
    {
        return self::MATRIX[$group->level->badge()] ?? self::MATRIX['info'];
    }

    /**
     * The severity matrix, for the screen that displays it.
     *
     * Read from the same constant the routing uses, so the table an
     * administrator reads can never describe behaviour this class does not
     * actually have.
     *
     * @return array<int, array{badge: string, alerts: bool, throttle: int, quiet_hours: bool}>
     */
    public function routing(): array
    {
        return array_map(
            static fn (string $badge): array => ['badge' => $badge] + self::MATRIX[$badge],
            array_keys(self::MATRIX)
        );
    }

    private function windowKey(ErrorGroup $group, string $channelKey): string
    {
        return 'observability:alert-window:'.$group->id.':'.$channelKey;
    }

    /**
     * Deduplication and throttling are the same question asked of one
     * fingerprint: has this group already been sent to this channel inside the
     * window? An increment is not a second message.
     */
    private function wasNotifiedWithin(ErrorGroup $group, string $channelKey, int $minutes, Carbon $now): bool
    {
        if ($minutes < 1) {
            return false;
        }

        return ErrorDeliveryLog::query()
            ->where('error_group_id', $group->id)
            ->where('channel_key', $channelKey)
            ->where('status', ErrorDeliveryLog::STATUS_SENT)
            ->where('created_at', '>=', $now->copy()->subMinutes($minutes))
            ->exists();
    }

    /**
     * The administrator's configured quiet window, in the platform timezone.
     *
     * Defaults to no quiet hours, so upgrading an installation does not
     * silently start withholding alerts somebody was relying on.
     */
    private function inQuietHours(Carbon $now): bool
    {
        $from = (string) setting('observability_quiet_hours_from', '');
        $to   = (string) setting('observability_quiet_hours_to', '');

        if ($from === '' || $to === '') {
            return false;
        }

        $local = $now->copy()->setTimezone(config('app.timezone'))->format('H:i');

        return $from <= $to
            ? ($local >= $from && $local < $to)
            : ($local >= $from || $local < $to);
    }

    /**
     * Every adapter this build ships, keyed by its machine name.
     *
     * @return array<string, ChannelAdapter>
     */
    public function adapters(): array
    {
        return [
            'slack'   => new SlackChannelAdapter,
            'discord' => new DiscordChannelAdapter,
            'teams'   => new TeamsChannelAdapter,
        ];
    }

    public function adapter(string $key): ?ChannelAdapter
    {
        return $this->adapters()[$key] ?? null;
    }

    /**
     * The message body for this group, rendered from the severity template.
     *
     * The "still firing" line is appended rather than left to the template,
     * because it is the sentence that stops a throttled channel from reading
     * like a single isolated failure.
     */
    public function body(ErrorGroup $group): string
    {
        $template = (string) setting(
            'observability_template_'.$group->level->badge(),
            AlertTemplateRenderer::DEFAULT_BODY
        );

        return $this->renderer->render($template, $group)
            ."\n".$this->renderer->stillFiringNotice($group);
    }

    private function channelRow(string $key): ?ErrorNotificationChannel
    {
        return ErrorNotificationChannel::query()->where('key', $key)->first();
    }
}
