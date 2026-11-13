<?php

namespace App\Services\Observability\Channels;

use App\Models\Observability\ErrorGroup;
use App\Models\Observability\ErrorNotificationChannel;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared HTTP behaviour for every webhook-shaped channel.
 *
 * Each subclass supplies only its own dialect: the URL to post to and the body
 * that provider expects. Everything else — the timeout, turning an exception
 * into a result, the measured duration — belongs to all of them and is written
 * once so one adapter cannot quietly behave differently under failure.
 */
abstract class WebhookChannelAdapter implements ChannelAdapter
{
    /**
     * Short on purpose. This runs on a queue worker that has other channels
     * waiting, and a provider that has not answered in ten seconds is not
     * about to.
     */
    protected const TIMEOUT_SECONDS = 10;

    /**
     * Every provider here takes a single incoming-webhook URL. A channel that
     * needs more than one field overrides this.
     *
     * @return array<string, array{label: string, rule: string, secret: bool, help: string}>
     */
    public function credentialFields(): array
    {
        return [
            'webhook_url' => [
                'label'  => __('Incoming webhook URL'),
                'rule'   => 'required|url|max:2048',
                'secret' => true,
                'help'   => __('Create an incoming webhook in the channel you want the alerts in, then paste its URL here.'),
            ],
        ];
    }

    public function send(ErrorGroup $group, string $body, ErrorNotificationChannel $channel): ChannelResult
    {
        $url = $this->endpoint($channel);

        if ($url === null) {
            return ChannelResult::failed(null, 'No endpoint is configured for this channel.');
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders($this->headers($channel))
                ->asJson()
                ->post($url, $this->payload($group, $body, $channel));

            $duration = $this->elapsed($startedAt);

            if ($response->successful()) {
                return ChannelResult::ok($response->status(), $duration);
            }

            /*
             * The provider's body is deliberately not stored. It can echo the
             * payload back, and the payload can contain the alert text; the
             * status code is what the delivery log actually needs.
             */
            return ChannelResult::failed($response->status(), $this->summarise($response->status()), $duration);
        } catch (Throwable $exception) {
            return ChannelResult::failed(null, class_basename($exception), $this->elapsed($startedAt));
        }
    }

    /**
     * Extra request headers. Incoming webhooks carry their secret in the
     * URL and need none; a provider API authenticates with a token instead.
     *
     * @return array<string, string>
     */
    protected function headers(ErrorNotificationChannel $channel): array
    {
        return [];
    }

    /**
     * Where this channel posts. Null when it has not been configured, which is
     * a state the card shows rather than an error worth retrying.
     */
    protected function endpoint(ErrorNotificationChannel $channel): ?string
    {
        $url = data_get($channel->credentials, 'webhook_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    protected function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function summarise(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'The provider rejected the credentials.',
            $status === 404 => 'The provider does not recognise this endpoint.',
            $status === 429 => 'The provider is rate limiting us.',
            $status >= 500  => 'The provider returned a server error.',
            default         => 'The provider refused the message.',
        };
    }
}
