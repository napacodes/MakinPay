<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\AppConfigService;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Give a long-running queue worker the Mail Settings the administrator
 * saved after it started.
 *
 * `queue:work` boots once and then runs for hours or days. AppServiceProvider
 * applied the SMTP host, port and credentials from the settings table during
 * that boot and never again, so a correction made in the admin panel reached
 * every web request immediately and the worker never — every queued email
 * kept failing against the old host until somebody restarted the process,
 * and the screen that queued it had already said "sent".
 *
 * Re-applying before each job is cheap: the reads come from the settings
 * cache, and `applyMailSettings()` only rebuilds the mailer when a value
 * actually differs.
 */
class RefreshMailSettingsBeforeJob
{
    /*
     * Not called `handle`: listener discovery is on for app/Listeners and
     * would register a `handle` method in every environment, including the
     * test suite, on top of the explicit console-only registration in
     * AppServiceProvider. See RecordScheduledTaskRun for the same rule.
     */
    public function __construct(private AppConfigService $appConfig) {}

    public function beforeJob(JobProcessing $event): void
    {
        $this->appConfig->applyMailSettings();
    }
}
