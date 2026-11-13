@extends('backend.background_tasks.layout')

@section('title', __('Setup'))

@section('bt_content')
    @php
        use App\Services\BackgroundHealthService;

        $btRoot        = base_path();
        $btSchedulerOk = ($backgroundHealth['scheduler']['status'] ?? null) === BackgroundHealthService::STATUS_OK;
        $btQueueOk     = ($backgroundHealth['queue']['status'] ?? null) === BackgroundHealthService::STATUS_OK;

        /*
         * Same three-way as the banner: `sync` has no worker to detect, and a
         * driver the service cannot read is not a worker that is missing.
         */
        $btQueueDriver  = (string) ($backgroundHealth['queue']['driver'] ?? config('queue.default'));
        $btQueueInline  = $btQueueDriver === 'sync';
        $btQueueUnknown = ! $btQueueInline
            && ($backgroundHealth['queue']['status'] ?? null) === BackgroundHealthService::STATUS_UNKNOWN;

        /*
         * Three steps, each with the one line it actually takes, and each with
         * this installation's own path rather than a placeholder to edit. The
         * status beside a step is the same measurement the banner reads, so a
         * step cannot claim to be running while the chip above says stalled.
         * `tone` is the pill: green for a step that reports in, amber for one
         * that has not, grey for one this server cannot measure.
         */
        $btSteps = [
            [
                'n'     => '1',
                'title' => __('Scheduler (cron)'),
                'text'  => __('One cron line runs every minute and fires each command on its own cadence.'),
                'code'  => '* * * * * cd '.$btRoot.' && php artisan schedule:run >> /dev/null 2>&1',
                'ok'    => $btSchedulerOk,
                'tone'  => $btSchedulerOk ? 'is-success' : 'is-running',
                'state' => $btSchedulerOk ? __('Running') : __('Not detected'),
            ],
            [
                'n'     => '2',
                'title' => __('Queue worker'),
                'text'  => __('Keep one worker alive with Supervisor so mails, KYC syncs and payouts process in the background.'),
                /*
                 * --timeout is not in the design reference and is kept
                 * anyway. Laravel hands a reserved job to a second worker once
                 * retry_after seconds pass, finished or not, so a worker
                 * allowed to outlive that window competes with a copy of its
                 * own job — and the job in flight here is a wallet credit or a
                 * payout. QueueRetryWindowTest reads this line and pins it
                 * below queue.connections.database.retry_after.
                 */
                'code'  => "[program:digikash-worker]\ncommand=php ".$btRoot."/artisan queue:work --sleep=3 --tries=3 --timeout=120\nautostart=true\nautorestart=true",
                /*
                 * Shared hosting has no Supervisor. The old guide carried this
                 * cron alternative and the redesign dropped it, which left a
                 * cPanel operator with a step they could not follow. The
                 * worker drains what is waiting and exits inside the minute;
                 * flock keeps two ticks from running two workers.
                 */
                'alt'   => [
                    'title' => __('No Supervisor? On cPanel or shared hosting, drain the queue from cron instead:'),
                    'code'  => '* * * * * cd '.$btRoot.' && flock -n /tmp/dk-queue.lock php artisan queue:work --stop-when-empty --max-time=55 --tries=3 --timeout=120 >> /dev/null 2>&1',
                ],
                'ok'    => $btQueueOk,
                'tone'  => match (true) {
                    $btQueueInline, $btQueueOk => 'is-success',
                    $btQueueUnknown            => 'is-never',
                    default                    => 'is-running',
                },
                'state' => match (true) {
                    $btQueueInline  => __('Inline (sync)'),
                    $btQueueUnknown => __('Not measured'),
                    $btQueueOk      => __('Running'),
                    default         => __('Not detected'),
                },
            ],
            [
                'n'     => '3',
                'title' => __('Verify'),
                'text'  => __('Trigger the scheduler once by hand; the health chips above turn green when both sides report in.'),
                'code'  => 'php artisan schedule:run && php artisan queue:work --once',
                'ok'    => $btSchedulerOk && $btQueueOk,
                'tone'  => match (true) {
                    $btSchedulerOk && $btQueueOk => 'is-success',
                    $btQueueUnknown              => 'is-never',
                    default                      => 'is-running',
                },
                'state' => match (true) {
                    $btSchedulerOk && $btQueueOk => __('Verified'),
                    $btQueueUnknown              => __('Not measurable'),
                    default                      => __('Pending'),
                },
            ],
        ];
    @endphp

    <div class="bt-setup">
        <div class="bt-setup__grid">
            @foreach($btSteps as $btStep)
                <div class="bt-step">
                    <div class="bt-step__head">
                        <span class="bt-step__identity">
                            <span class="bt-step__num" aria-hidden="true" dir="ltr">{{ $btStep['n'] }}</span>
                            <span class="bt-step__title">{{ $btStep['title'] }}</span>
                        </span>

                        <span class="bt-state {{ $btStep['tone'] }}">
                            <span class="bt-state__dot" aria-hidden="true"></span>{{ $btStep['state'] }}
                        </span>
                    </div>

                    <span class="bt-step__text">{{ $btStep['text'] }}</span>

                    <div class="bt-code">
                        <pre class="bt-code__pre" dir="ltr">{{ $btStep['code'] }}</pre>
                        <button type="button"
                                class="bt-code__copy"
                                data-bt-copy="{{ $btStep['code'] }}"
                                aria-label="{{ __('Copy') }}">
                            <i class="fa-solid fa-copy" aria-hidden="true"></i>
                        </button>
                    </div>

                    @isset($btStep['alt'])
                        <span class="bt-step__text">{{ $btStep['alt']['title'] }}</span>

                        <div class="bt-code">
                            <pre class="bt-code__pre" dir="ltr">{{ $btStep['alt']['code'] }}</pre>
                            <button type="button"
                                    class="bt-code__copy"
                                    data-bt-copy="{{ $btStep['alt']['code'] }}"
                                    aria-label="{{ __('Copy') }}">
                                <i class="fa-solid fa-copy" aria-hidden="true"></i>
                            </button>
                        </div>
                    @endisset
                </div>
            @endforeach
        </div>

        <div class="bt-note">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <span>
                {{ __("Health above updates automatically once the scheduler runs and a worker picks up a job. Nothing else to configure here — every command's cadence lives in") }}
                {{-- The reference named routes/console.php; this application
                     registers its schedule in bootstrap/app.php. --}}
                <span class="bt-note__path" dir="ltr">bootstrap/app.php</span>.
            </span>
        </div>
    </div>
@endsection
