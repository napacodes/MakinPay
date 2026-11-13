<?php

namespace App\Providers;

use App\Enums\Observability\ErrorLevel;
use App\Enums\Observability\ErrorSource;
use App\Services\Observability\ErrorCollector;
use App\Services\Observability\TraceContext;
use Illuminate\Auth\Events\Failed as AuthenticationFailed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wires the remaining capture sources into the one ingest pipe.
 *
 * The exception reporter in bootstrap/app.php covers uncaught throwables. This
 * provider covers everything that fails without one — a query that deadlocks, a
 * job that exhausts its attempts, a scheduled command that failed, a provider
 * that refused the connection, a sign-in that was brute forced.
 *
 * Every listener is wrapped, because a listener that throws would turn an
 * observed failure into a second, louder one. The collector's own report() is
 * already silent; the wrapping protects the code that builds the payload before
 * report() is reached.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    /**
     * Queries slower than this are worth a breadcrumb on their own. Below it
     * they would drown the buffer that exists to show the moments before a
     * throw.
     */
    private const SLOW_QUERY_MS = 200;

    /**
     * Log levels worth a breadcrumb. Info and debug would fill the buffer
     * with the routine and push out the line that explains the failure.
     */
    private const BREADCRUMB_LOG_LEVELS = ['warning', 'error', 'critical', 'alert', 'emergency'];

    public function boot(): void
    {
        $this->bootTrace();
        $this->listenForRequests();
        $this->listenForDatabase();
        $this->listenForQueues();
        $this->listenForSchedule();
        $this->listenForOutboundHttp();
        $this->listenForAuth();
        $this->listenForLogs();
    }

    /**
     * Adopt the caller's trace at the start of the request, so an error
     * captured later can be lined up against the distributed trace it belongs
     * to rather than starting a new one.
     */
    private function bootTrace(): void
    {
        $this->guard(function (): void {
            $request = $this->app->bound('request') ? $this->app->make('request') : null;

            $this->app->make(TraceContext::class)->bootFromRequest(
                $request instanceof Request ? $request : null
            );
        });
    }

    /**
     * source:http — the route that was entered, kept as a breadcrumb so a
     * later failure can say where the request was going.
     */
    private function listenForRequests(): void
    {
        Event::listen(RouteMatched::class, function (RouteMatched $event): void {
            $this->guard(function () use ($event): void {
                $this->collector()->breadcrumb('route', (string) ($event->route->getName() ?? $event->route->uri()), [
                    'action' => $event->route->getActionName(),
                    'method' => $event->request->getMethod(),
                ]);
            });
        });
    }

    /**
     * source:database — bindings never travel with the breadcrumb. The
     * collector scrubs them when a query is attached to a captured event; a
     * breadcrumb has no such pass, so it carries shape and timing only.
     */
    private function listenForDatabase(): void
    {
        Event::listen(QueryExecuted::class, function (QueryExecuted $event): void {
            if ($event->time < self::SLOW_QUERY_MS) {
                return;
            }

            $this->guard(function () use ($event): void {
                $this->collector()->breadcrumb('query', Str::limit($event->sql, 200), [
                    'ms'         => round($event->time, 2),
                    'connection' => $event->connectionName,
                ]);
            });
        });
    }

    /**
     * source:queue — a job that has exhausted its attempts. The payload is
     * passed through as job state so the collector scrubs it with the same
     * rules as a request body; a Laravel job payload is the most common place
     * personal data hides.
     */
    private function listenForQueues(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->guard(function () use ($event): void {
                $this->collector()->enterJob([
                    'name'       => $event->job->resolveName(),
                    'queue'      => $event->job->getQueue(),
                    'attempts'   => $event->job->attempts(),
                    'connection' => $event->connectionName,
                ]);

                $this->collector()->breadcrumb('job', $event->job->resolveName(), [
                    'queue'    => $event->job->getQueue(),
                    'attempts' => $event->job->attempts(),
                ]);
            });
        });

        Event::listen(JobProcessed::class, function (): void {
            $this->guard(fn () => $this->collector()->leaveJob());
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            $this->guard(function () use ($event): void {
                $this->collector()->report(
                    $event->exception,
                    [
                        'job' => [
                            'name'       => $event->job->resolveName(),
                            'queue'      => $event->job->getQueue(),
                            'attempts'   => $event->job->attempts(),
                            'connection' => $event->connectionName,
                        ],
                    ],
                    ErrorSource::Queue
                );
            });
        });
    }

    /**
     * source:cron — a scheduled command that failed. Silence from the
     * scheduler is the failure nobody notices, so it is captured rather than
     * logged and forgotten.
     */
    private function listenForSchedule(): void
    {
        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            $this->guard(function () use ($event): void {
                $this->collector()->report(
                    $event->exception,
                    [
                        'command'    => $event->task->command ?? $event->task->description,
                        'expression' => $event->task->expression,
                    ],
                    ErrorSource::Cron
                );
            });
        });
    }

    /**
     * source:gateway — a third-party call that failed to connect, or answered
     * with a server error. A 4xx is left alone: it usually means the request
     * was wrong, which the calling code is expected to handle itself.
     */
    private function listenForOutboundHttp(): void
    {
        Event::listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            $this->guard(function () use ($event): void {
                $this->collector()->record([
                    'level'           => ErrorLevel::Error,
                    'source'          => ErrorSource::Gateway,
                    'exception_class' => ConnectionFailed::class,
                    'message'         => 'Connection to '.self::endpointOf($event->request->url()).' failed.',
                    'culprit'         => (string) (parse_url($event->request->url(), PHP_URL_HOST) ?: 'unknown host'),
                    'context'         => [
                        'endpoint' => self::endpointOf($event->request->url()),
                        'method'   => $event->request->method(),
                    ],
                ]);
            });
        });

        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            if (! $event->response->serverError()) {
                return;
            }

            $this->guard(function () use ($event): void {
                $this->collector()->record([
                    'level'           => ErrorLevel::Error,
                    'source'          => ErrorSource::Gateway,
                    'exception_class' => ResponseReceived::class,
                    'message'         => 'Provider answered '.$event->response->status().' for '.self::endpointOf($event->request->url()).'.',
                    'culprit'         => (string) (parse_url($event->request->url(), PHP_URL_HOST) ?: 'unknown host'),
                    'context'         => [
                        'endpoint' => self::endpointOf($event->request->url()),
                        'method'   => $event->request->method(),
                        'status'   => $event->response->status(),
                    ],
                ]);
            });
        });
    }

    /**
     * The scheme, host, port and path of a provider URL, without its query
     * string, fragment or credentials. Providers take customer numbers, API
     * keys and signatures as query parameters (Flutterwave's bill validate
     * puts the meter or smartcard number there), and the redaction pipeline
     * cannot pick them out of a whole URL. Without the query, one endpoint
     * also stays one error group instead of one per customer.
     */
    private static function endpointOf(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return strtok($url, '?#') ?: 'unknown endpoint';
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');
    }

    /**
     * source:auth and source:security — a failed sign-in is a breadcrumb, a
     * lockout is an event. One wrong password is a person; a rate limiter
     * tripping is a pattern, and the pattern is what an admin needs told.
     */
    private function listenForAuth(): void
    {
        Event::listen(AuthenticationFailed::class, function (AuthenticationFailed $event): void {
            $this->guard(function () use ($event): void {
                $this->collector()->breadcrumb('auth', 'Authentication failed', [
                    'guard' => $event->guard,
                ]);
            });
        });

        Event::listen(Lockout::class, function (Lockout $event): void {
            $this->guard(function () use ($event): void {
                $this->collector()->record([
                    'level'           => ErrorLevel::Warning,
                    'source'          => ErrorSource::Security,
                    'exception_class' => Lockout::class,
                    'message'         => 'Too many failed sign-in attempts were blocked by the rate limiter.',
                    'culprit'         => (string) $event->request->route()?->getName(),
                ]);
            });
        });
    }

    /**
     * A warning logged on the way to a failure, kept as a breadcrumb.
     *
     * Provider drivers explain a refusal in the log and then hand the caller a
     * null, so the exception that follows only says that something failed.
     * ShuftiPro's "domain not registered" sat in laravel.log while the error
     * page an operator could actually open showed "could not reach the
     * verification provider" and nothing else. Context is flattened to short
     * scalars here and scrubbed with every other breadcrumb on capture.
     */
    private function listenForLogs(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            if (! in_array($event->level, self::BREADCRUMB_LOG_LEVELS, true)) {
                return;
            }

            $this->guard(function () use ($event): void {
                $this->collector()->breadcrumb(
                    'log',
                    Str::limit($event->message, 200),
                    $this->flattenLogContext($event->context)
                );
            });
        });
    }

    /**
     * @param  array<array-key, mixed>    $context
     * @return array<string, scalar|null>
     */
    private function flattenLogContext(array $context): array
    {
        $flat = [];

        foreach (array_slice($context, 0, 10, true) as $key => $value) {
            $flat[(string) $key] = match (true) {
                $value === null, is_bool($value), is_int($value), is_float($value) => $value,
                is_string($value)           => Str::limit($value, 300),
                $value instanceof Throwable => Str::limit($value::class.': '.$value->getMessage(), 300),
                is_array($value)            => Str::limit((string) json_encode($value), 300),
                default                     => get_debug_type($value),
            };
        }

        return $flat;
    }

    private function collector(): ErrorCollector
    {
        return $this->app->make(ErrorCollector::class);
    }

    /**
     * Run a listener body without ever letting it escape.
     *
     * A listener that throws while building an observability payload would
     * replace the failure it was describing, which is the one outcome this
     * module must never produce.
     */
    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            /* Observability is never worth an outage. */
        }
    }
}
