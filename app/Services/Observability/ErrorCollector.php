<?php

namespace App\Services\Observability;

use App\Enums\Observability\ErrorLevel;
use App\Enums\Observability\ErrorSource;
use App\Enums\Observability\ErrorStatus;
use App\Jobs\Observability\DispatchErrorAlert;
use App\Models\Observability\ErrorEvent;
use App\Models\Observability\ErrorGroup;
use App\Models\Observability\ErrorNotificationChannel;
use App\Models\Observability\ErrorStatBucket;
use Composer\InstalledVersions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use WeakMap;

/**
 * The single ingest pipe every source flows into.
 *
 * Two rules govern everything here.
 *
 * The first is that a failure inside this class cannot become a failure of the
 * request it was observing. Reporting an error must never be the reason a
 * payment does not settle, so report() swallows whatever goes wrong and the
 * caller is never told. capture() is the version that talks back, used by
 * tests and by the admin panel's own test-event button.
 *
 * The second is that nothing sensitive is written and then cleaned up later.
 * Every payload passes RedactionPipeline before the insert, because a value
 * scrubbed on the way out was already on disk on the way in.
 */
class ErrorCollector
{
    /**
     * Breadcrumbs are a ring buffer. When the cap is hit the oldest entry
     * goes, never the newest — the moments just before the throw are the
     * whole reason the buffer exists.
     */
    private const BREADCRUMB_LIMIT = 50;

    /**
     * Lines of source either side of the offending line.
     */
    private const EXCERPT_RADIUS = 5;

    /**
     * Payload keys a source listener may contribute that belong in state
     * rather than in free-form extra context.
     *
     * @var list<string>
     */
    private const STATE_KEYS = ['job', 'queries', 'domain_refs'];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $breadcrumbs = [];

    private ?bool $storageReady = null;

    /**
     * Throwables already written in this process. The exception handler and
     * a source listener (a failed job, a failed scheduled task) both report
     * the same instance, and one bug must count once.
     */
    private ?WeakMap $captured = null;

    /**
     * The queue job in progress, when there is one, so an exception it
     * throws is filed under the queue with the job on it — whichever path
     * reports it first.
     *
     * @var array<string, mixed>|null
     */
    private ?array $currentJob = null;

    public function __construct(
        private RedactionPipeline $redaction,
        private ErrorFingerprinter $fingerprinter,
        private TraceContext $trace,
    ) {}

    /**
     * Capture without ever raising. This is what the framework's exception
     * reporter calls, and it is deliberately incapable of making things worse.
     *
     * @param array<string, mixed> $context
     */
    public function report(Throwable $throwable, array $context = [], ?ErrorSource $source = null, ?ErrorLevel $level = null): void
    {
        try {
            $this->capture($throwable, $context, $source, $level);
        } catch (Throwable) {
            /*
             * Intentionally silent. Logging here would re-enter the same
             * handler that is already failing, and the alternative — letting
             * this bubble — replaces somebody's real error with ours.
             */
        }
    }

    /**
     * Capture and return the stored occurrence. Raises on failure, so only
     * callers that can handle a failure should use it.
     *
     * @param array<string, mixed> $context
     */
    public function capture(Throwable $throwable, array $context = [], ?ErrorSource $source = null, ?ErrorLevel $level = null): ?ErrorEvent
    {
        $this->captured ??= new WeakMap;

        if (isset($this->captured[$throwable])) {
            return null;
        }

        $this->captured[$throwable] = true;

        if ($this->currentJob !== null && ! isset($context['job'])) {
            $context['job'] = $this->currentJob;
        }

        $source ??= $this->inferSource($throwable);
        $level  ??= $source->defaultLevel();

        $frames    = $this->frames($throwable);
        $firstApp  = $this->firstApplicationFrame($frames);
        $appMarker = $firstApp !== null
            ? ($firstApp['file'] ?? '').':'.($firstApp['function'] ?? '')
            : null;

        /*
         * A source listener contributes its own fields on top of the common
         * record — a queue failure brings a job, a database failure brings
         * queries. Those belong in state, where the redaction pipeline
         * scrubs them with the request-body rules; everything else the
         * caller passed is kept as free-form extra context.
         */
        $stateKeys = array_intersect_key($context, array_flip(self::STATE_KEYS));
        $extra     = array_diff_key($context, $stateKeys);

        return $this->record($stateKeys + [
            'level'           => $level,
            'source'          => $source,
            'exception_class' => $throwable::class,
            'message'         => $message = $this->redaction->scrubMessage($throwable->getMessage()),
            'error_code'      => $this->errorCodeFrom($throwable, $context),
            'fingerprint'     => $this->fingerprinter->fingerprint($throwable::class, $message, $appMarker),
            'culprit'         => $this->fingerprinter->culprit(
                $firstApp['file']     ?? $throwable->getFile(),
                $firstApp['line']     ?? $throwable->getLine(),
                $firstApp['function'] ?? null
            ),
            'frames'       => $frames,
            'code_excerpt' => $this->excerpt($throwable->getFile(), $throwable->getLine()),
            'context'      => $extra,
        ]);
    }

    /**
     * The shared write path. Sources that have no Throwable — a crossed disk
     * threshold, a browser error posted by the JS SDK — arrive here directly.
     *
     * @param array<string, mixed> $payload
     */
    public function record(array $payload): ?ErrorEvent
    {
        if (! $this->storageIsReady()) {
            return null;
        }

        $this->redaction->reset();

        $level  = $payload['level'] instanceof ErrorLevel ? $payload['level'] : ErrorLevel::fromName($payload['level'] ?? null);
        $source = $payload['source'] instanceof ErrorSource ? $payload['source'] : ErrorSource::fromName($payload['source'] ?? null);

        $exceptionClass = (string) ($payload['exception_class'] ?? 'ReportedEvent');
        $message        = $this->redaction->scrubMessage((string) ($payload['message'] ?? ''));

        $fingerprint = (string) ($payload['fingerprint'] ?? $this->fingerprinter->fingerprint(
            $exceptionClass,
            $message,
            $payload['culprit'] ?? null
        ));

        $now     = Carbon::now();
        $release = $this->release();

        $context = $this->buildContext($payload, $release);
        $request = $this->buildRequest();
        $state   = $this->buildState($payload);

        $group = $this->resolveGroup($fingerprint, [
            'title'           => class_basename($exceptionClass),
            'exception_class' => $exceptionClass,
            'message'         => Str::limit($message, 2000, ''),
            'error_code'      => $payload['error_code'] ?? null,
            'culprit'         => Str::limit((string) ($payload['culprit'] ?? ''), 250, ''),
            'level'           => $level,
            'source'          => $source,
        ], $now, $release);

        $userId = $this->currentUserId();

        $event = ErrorEvent::query()->create([
            'error_group_id'   => $group->id,
            'level'            => $level,
            'context'          => $context,
            'request'          => $request,
            'state'            => $state,
            'redaction_report' => $this->redaction->report(),
            'trace_id'         => $this->trace->traceId(),
            'span_id'          => $this->trace->spanId(),
            'release'          => $release,
            'user_id'          => $userId,
            'created_at'       => $now,
        ]);

        $this->touchGroup($group, $now, $release, $userId);
        $this->rollUp($group, $source, $level, $now);
        $this->announce($group);

        return $event;
    }

    /**
     * Record a breadcrumb for the current request. Capped, and the cap drops
     * the oldest entry rather than refusing the newest.
     *
     * @param array<string, mixed> $data
     */
    public function breadcrumb(string $kind, string $label, array $data = []): void
    {
        $this->breadcrumbs[] = [
            'kind'  => $kind,
            'label' => $label,
            'data'  => $data,
            'at'    => Carbon::now()->toIso8601String(),
        ];

        if (count($this->breadcrumbs) > self::BREADCRUMB_LIMIT) {
            array_shift($this->breadcrumbs);
        }
    }

    /**
     * Hand the captured group to the alert router.
     *
     * Wrapped and best-effort. On a shared host the queue worker may not be
     * running at all, and the module has to degrade to "captured but not
     * announced" rather than to "capture throws". Storage already happened
     * by the time this is reached.
     *
     * Nothing is dispatched when no channel is connected, so an installation
     * that never set one up does not fill its queue with jobs that have
     * nowhere to deliver.
     */
    private function announce(ErrorGroup $group): void
    {
        try {
            if ($group->isMuted()) {
                return;
            }

            if (! ErrorNotificationChannel::query()->where('is_enabled', true)->exists()) {
                return;
            }

            DispatchErrorAlert::dispatch($group->group_id);
        } catch (Throwable) {
            /* No queue connection, or no channel table yet. Not fatal. */
        }
    }

    /**
     * Find the group for this fingerprint, or open one.
     *
     * Two workers can reach this at the same moment for the same new bug, so
     * the unique index is treated as the arbiter: on collision the row the
     * other worker just wrote is read back rather than a second group being
     * created for one cause.
     *
     * @param array<string, mixed> $attributes
     */
    private function resolveGroup(string $fingerprint, array $attributes, Carbon $now, ?string $release): ErrorGroup
    {
        $existing = ErrorGroup::query()->where('fingerprint', $fingerprint)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return ErrorGroup::query()->create($attributes + [
                'fingerprint'   => $fingerprint,
                'status'        => ErrorStatus::New,
                'first_seen'    => $now,
                'last_seen'     => $now,
                'first_release' => $release,
                'last_release'  => $release,
            ]);
        } catch (QueryException $exception) {
            $raced = ErrorGroup::query()->where('fingerprint', $fingerprint)->first();

            if ($raced === null) {
                throw $exception;
            }

            return $raced;
        }
    }

    /**
     * Advance the counters and the triage state.
     *
     * A group that was resolved and fires again becomes regressed, not new.
     * The distinction matters to whoever is on call: new means nobody has
     * looked at it, regressed means somebody already believed it was fixed.
     */
    private function touchGroup(ErrorGroup $group, Carbon $now, ?string $release, ?int $userId): void
    {
        $extra = [
            'last_seen'    => $now,
            'last_release' => $release ?? $group->last_release,
        ];

        if ($group->first_seen === null) {
            $extra['first_seen'] = $now;
        }

        if ($group->status === ErrorStatus::Resolved) {
            $extra['status']      = ErrorStatus::Regressed;
            $extra['resolved_at'] = null;
        }

        // Counters move by one atomic statement. A storm is exactly when two
        // workers capture the same group at once, and a read-modify-write
        // lost most of those increments.
        $group->increment('event_count', 1, $extra);

        /*
         * A distinct-user count over error_events would be the honest query
         * and the expensive one. Counting only the first sighting of a user
         * inside this group keeps it a counter column; the cost is one
         * bounded indexed existence check per capture.
         */
        if ($userId !== null && ! $this->groupHasSeenUser($group, $userId)) {
            $group->increment('affected_user_count');
        }
    }

    /**
     * Mark a queue job as the thing currently running.
     *
     * @param array<string, mixed> $job
     */
    public function enterJob(array $job): void
    {
        $this->currentJob = $job;
    }

    public function leaveJob(): void
    {
        $this->currentJob = null;
    }

    private function groupHasSeenUser(ErrorGroup $group, int $userId): bool
    {
        return ErrorEvent::query()
            ->where('error_group_id', $group->id)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Increment the five-minute rollup the Overview charts read.
     */
    private function rollUp(ErrorGroup $group, ErrorSource $source, ErrorLevel $level, Carbon $now): void
    {
        $bucketAt = $now->copy()->startOfHour()->addMinutes(intdiv($now->minute, 5) * 5);

        $bucket = ErrorStatBucket::query()->firstOrCreate([
            'bucket_at'      => $bucketAt,
            'error_group_id' => $group->id,
            'source'         => $source,
            'level'          => $level,
        ], ['count' => 0]);

        $bucket->increment('count');
    }

    /**
     * Technical context: the trace, the excerpt, the environment, and the
     * packages that actually appear in the trace rather than the whole lock
     * file.
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildContext(array $payload, ?string $release): array
    {
        $frames = (array) ($payload['frames'] ?? []);

        return [
            'stack'        => $frames,
            'code_excerpt' => $payload['code_excerpt'] ?? null,
            'release'      => $release,
            'commit_sha'   => $this->commitSha(),
            'branch'       => $this->branch(),
            'environment'  => config('app.env'),
            'php'          => PHP_VERSION,
            'laravel'      => app()->version(),
            'host'         => gethostname() ?: null,
            'packages'     => $this->packagesInTrace($frames),
            'extra'        => $this->redaction->scrub((array) ($payload['context'] ?? []), 'context.extra'),
        ];
    }

    /**
     * Request context. Route name is preferred over the raw URL wherever both
     * exist, because URLs carry ids and route names do not.
     *
     * @return array<string, mixed>
     */
    private function buildRequest(): array
    {
        $request = app()->bound('request') ? app('request') : null;

        /*
         * A console command and a queue worker both have a Request bound,
         * synthesised from an empty global. REQUEST_URI is what separates
         * a real inbound request from that stand-in, so it decides which
         * shape of context gets recorded rather than runningInConsole(),
         * which is also true inside the test suite.
         */
        if (! $request instanceof Request || ! $request->server->has('REQUEST_URI')) {
            return app()->runningInConsole()
                ? [
                    'console' => true,
                    'command' => implode(' ', array_slice((array) ($_SERVER['argv'] ?? []), 1)),
                ]
                : [];
        }

        $route = $request->route();

        return [
            'url'        => $request->url(),
            'method'     => $request->getMethod(),
            'route'      => is_object($route) && method_exists($route, 'getName') ? $route->getName() : null,
            'action'     => is_object($route) && method_exists($route, 'getActionName') ? $route->getActionName() : null,
            'middleware' => is_object($route) && method_exists($route, 'gatherMiddleware') ? array_values($route->gatherMiddleware()) : [],
            'headers'    => $this->redaction->scrubHeaders($request->headers->all()),
            'body'       => $this->redaction->scrub($request->all(), 'request.body'),
            'ip'         => $this->redaction->scrub(['ip' => $request->ip()], 'request')['ip'] ?? null,
            'user_id'    => $this->currentUserId(),
            'user_role'  => $this->currentUserRole(),
        ];
    }

    /**
     * State context: the queries, the job, the domain references that turn an
     * exception into a money figure, and the breadcrumbs.
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildState(array $payload): array
    {
        $queries = [];

        foreach ((array) ($payload['queries'] ?? []) as $index => $query) {
            $queries[] = [
                'sql'      => $query['sql'] ?? null,
                'bindings' => $this->redaction->scrubBindings(
                    (array) ($query['bindings'] ?? []),
                    $query['sql'] ?? null,
                    'state.queries.'.$index.'.bindings'
                ),
                'ms' => $query['ms'] ?? null,
            ];
        }

        return [
            'queries'     => $queries,
            'job'         => $this->redaction->scrub((array) ($payload['job'] ?? []), 'state.job'),
            'domain_refs' => $this->redaction->scrub((array) ($payload['domain_refs'] ?? []), 'state.domain_refs'),
            'breadcrumbs' => $this->redaction->scrub($this->breadcrumbs, 'state.breadcrumbs'),
        ];
    }

    /**
     * Flatten a trace into frames, marking which belong to this application.
     *
     * The thrown location is prepended, because PHP's own trace starts at the
     * caller and the line that actually threw is the one a reader wants first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function frames(Throwable $throwable): array
    {
        $frames = [[
            'file'     => $throwable->getFile(),
            'line'     => $throwable->getLine(),
            'function' => null,
            'class'    => null,
            'is_app'   => $this->isApplicationPath($throwable->getFile()),
            'args'     => [],
        ]];

        foreach ($throwable->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            $frames[] = [
                'file'     => $file,
                'line'     => $frame['line']     ?? null,
                'function' => $frame['function'] ?? null,
                'class'    => $frame['class']    ?? null,
                'is_app'   => $this->isApplicationPath($file),
                'args'     => $this->redaction->scrubFrameArgs(
                    (array) ($frame['args'] ?? []),
                    $file,
                    'context.stack.'.count($frames).'.args'
                ),
            ];
        }

        return $frames;
    }

    /**
     * @param  array<int, array<string, mixed>> $frames
     * @return array<string, mixed>|null
     */
    private function firstApplicationFrame(array $frames): ?array
    {
        foreach ($frames as $frame) {
            if (($frame['is_app'] ?? false) === true) {
                return $frame;
            }
        }

        return $frames[0] ?? null;
    }

    private function isApplicationPath(?string $file): bool
    {
        if (blank($file)) {
            return false;
        }

        $normalised = str_replace('\\', '/', (string) $file);
        $root       = str_replace('\\', '/', base_path());

        return ! str_contains($normalised, '/vendor/') && str_starts_with($normalised, $root);
    }

    /**
     * Read the failing lines out of the file as it exists right now, at throw
     * time.
     *
     * This is stored rather than resolved on the detail screen on purpose: a
     * trace rendered against current HEAD after a deploy points at whatever
     * moved into those line numbers since, and quietly lies about the code
     * that actually failed.
     *
     * @return array<string, mixed>|null
     */
    private function excerpt(?string $file, ?int $line): ?array
    {
        if (blank($file) || $line === null || ! is_readable((string) $file)) {
            return null;
        }

        $lines = @file((string) $file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return null;
        }

        $start = max(0, $line - 1 - self::EXCERPT_RADIUS);
        $end   = min(count($lines) - 1, $line - 1 + self::EXCERPT_RADIUS);
        $slice = [];

        for ($index = $start; $index <= $end; $index++) {
            $slice[] = [
                'number'    => $index + 1,
                'code'      => $lines[$index],
                'offending' => $index + 1 === $line,
            ];
        }

        return [
            'file'  => $this->relativePath((string) $file),
            'line'  => $line,
            'lines' => $slice,
        ];
    }

    /**
     * Only the packages appearing in the trace. The whole lock file would be
     * noise a reader has to skip past.
     *
     * @param  array<int, array<string, mixed>> $frames
     * @return array<string, string>
     */
    private function packagesInTrace(array $frames): array
    {
        $packages = [];

        foreach ($frames as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));

            if (! str_contains($file, '/vendor/')) {
                continue;
            }

            $bits = explode('/', Str::after($file, '/vendor/'));

            if (count($bits) < 2) {
                continue;
            }

            $name = $bits[0].'/'.$bits[1];

            $packages[$name] = $this->packageVersion($name);
        }

        return $packages;
    }

    private function packageVersion(string $package): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'unknown';
        }

        try {
            return (string) InstalledVersions::getPrettyVersion($package);
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /**
     * Which source an uncaught Throwable belongs to when nobody said.
     */
    private function inferSource(Throwable $throwable): ErrorSource
    {
        return match (true) {
            $throwable instanceof ValidationException => ErrorSource::Validation,
            $throwable instanceof AuthenticationException,
            $throwable instanceof AuthorizationException => ErrorSource::Auth,
            $throwable instanceof QueryException         => ErrorSource::Database,
            $throwable instanceof RequestException       => ErrorSource::Gateway,
            $throwable instanceof HttpExceptionInterface => ErrorSource::Http,
            $this->currentJob !== null                   => ErrorSource::Queue,
            app()->runningInConsole()                    => ErrorSource::Cron,
            default                                      => ErrorSource::Php,
        };
    }

    /**
     * A DK-DOMAIN-NNNN registry code when the thrower supplied one.
     *
     * @param array<string, mixed> $context
     */
    private function errorCodeFrom(Throwable $throwable, array $context): ?string
    {
        $code = $context['error_code'] ?? null;

        if (is_string($code) && preg_match('/^DK-[A-Z]{2,6}-\d{3,4}$/', $code) === 1) {
            return $code;
        }

        if (property_exists($throwable, 'errorCode') && is_string($throwable->errorCode)) {
            return $throwable->errorCode;
        }

        return null;
    }

    private function currentUserId(): ?int
    {
        try {
            foreach (['web', 'admin'] as $guard) {
                $id = Auth::guard($guard)->id();

                if ($id !== null) {
                    return (int) $id;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function currentUserRole(): ?string
    {
        try {
            if (Auth::guard('admin')->check()) {
                return 'admin';
            }

            return Auth::guard('web')->check() ? 'user' : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function release(): ?string
    {
        $version = (string) config('app.version');

        return $version !== '' ? $version : null;
    }

    private function commitSha(): ?string
    {
        $head = base_path('.git/HEAD');

        if (! is_readable($head)) {
            return null;
        }

        $contents = trim((string) @file_get_contents($head));

        if (str_starts_with($contents, 'ref: ')) {
            $ref = base_path('.git/'.trim(Str::after($contents, 'ref: ')));
            $sha = is_readable($ref) ? trim((string) @file_get_contents($ref)) : '';

            return $sha !== '' ? substr($sha, 0, 40) : null;
        }

        return $contents !== '' ? substr($contents, 0, 40) : null;
    }

    private function branch(): ?string
    {
        $head = base_path('.git/HEAD');

        if (! is_readable($head)) {
            return null;
        }

        $contents = trim((string) @file_get_contents($head));

        return str_starts_with($contents, 'ref: refs/heads/')
            ? Str::after($contents, 'ref: refs/heads/')
            : null;
    }

    /**
     * Whether the module has been migrated yet.
     *
     * An installation that has not run the observability migration still
     * throws exceptions, and the reporter still fires. Capture degrades to
     * a no-op there instead of turning one missing table into an error on
     * every single request. The answer is memoised per instance, and the
     * binding is request-scoped, so this costs one lookup per request.
     */
    private function storageIsReady(): bool
    {
        if ($this->storageReady === null) {
            try {
                $this->storageReady = Schema::hasTable('error_groups') && Schema::hasTable('error_events');
            } catch (Throwable) {
                $this->storageReady = false;
            }
        }

        return $this->storageReady;
    }

    private function relativePath(string $file): string
    {
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

        return str_replace('\\', '/', $relative);
    }
}
