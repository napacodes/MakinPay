<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Retry a mail the SMTP host refused *for now*, instead of losing it.
 *
 * `Illuminate\Bus\Queueable` carries no `backoff`, and
 * `SendQueuedNotifications` copies `tries`, `timeout` and `maxExceptions`
 * from the notification but never a backoff. A queued notification therefore
 * inherits the worker's `--backoff`, which is 0 on every worker command this
 * project puts in front of an administrator. Every attempt was spent inside
 * the same second, so a host that was refusing mail for the next few hours
 * turned into a `failed_jobs` row nobody reads.
 *
 * That is how a Gmail relay answering
 * `550-5.4.5 Daily user sending limit exceeded` cost a P2P trader the only
 * notice that their order had been created: the trade itself committed, the
 * bell feed updated, and the email died three times in three seconds.
 *
 * The distinction that matters is not the SMTP class. 550 is a permanent
 * code, but Gmail returns it for a quota that clears on its own, while a
 * genuine `550 5.1.1 no such user` never will. Retrying the second one only
 * postpones the moment somebody notices — the same reasoning
 * `App\Jobs\Observability\DeliverErrorAlert` already applies to alert
 * channels — so a permanent rejection is failed immediately rather than
 * released.
 *
 * Applies to the mail channel only. Notifications are queued one job per
 * channel, so releasing this one re-sends the email without writing the
 * database row or firing the broadcast a second time.
 */
class RetryTransientMailFailure
{
    /**
     * 5m, 30m, 2h, 6h — the last attempt lands roughly eight and a half hours
     * after the first.
     *
     * Wide enough to outlast greylisting, a per-hour relay cap and a short
     * provider outage. Deliberately not stretched past a daily quota's own
     * 24-hour window: a "your order was created" mail that arrives a day late,
     * long after the order expired, is worse than no mail. A relay that is out
     * of quota for a whole day is a configuration to change, not a delivery to
     * keep waiting on.
     *
     * @var list<int>
     */
    private const BACKOFF = [300, 1800, 7200, 21600];

    /**
     * Attempts this middleware allows. Must stay equal to the notification's
     * `$tries`, or the worker fails the job before the last backoff step runs.
     */
    public const MAX_ATTEMPTS = 5;

    public function handle(object $job, Closure $next): mixed
    {
        try {
            return $next($job);
        } catch (Throwable $e) {
            if (! $e instanceof TransportExceptionInterface) {
                throw $e;
            }

            $attempt = max(1, (int) $job->attempts());

            if (! $this->isTransient($e) || $attempt >= self::MAX_ATTEMPTS) {
                $job->fail($e);

                return null;
            }

            $backoff = self::BACKOFF;
            $delay   = $backoff[$attempt - 1] ?? (int) end($backoff);

            Log::warning('Mail transport refused a notification; retrying later.', [
                'notification' => $job::class,
                'attempt'      => $attempt,
                'retry_in'     => $delay,
                'response'     => mb_substr($e->getMessage(), 0, 200),
            ]);

            $job->release($delay);

            return null;
        }
    }

    /**
     * True when the host said "not now" rather than "not ever".
     *
     * A 4xx is SMTP's own temporary class, and a transport that never opened a
     * session at all reports no code. Beyond that the enhanced status code and
     * the wording are the only evidence available, because providers disagree
     * about which basic code a rate limit deserves.
     */
    private function isTransient(Throwable $e): bool
    {
        $code = (int) $e->getCode();

        if ($code === 0 || ($code >= 400 && $code < 500)) {
            return true;
        }

        return (bool) preg_match(
            '/\b4\.\d{1,3}\.\d{1,3}\b'
            .'|sending limit|rate.?limit|too many|quota|throttl'
            .'|try again|temporar|greylist|grey-list|deferred|server busy'
            .'|service not available|connection (could not be|timed out|reset)/i',
            $e->getMessage()
        );
    }
}
