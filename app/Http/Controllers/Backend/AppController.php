<?php

namespace App\Http\Controllers\Backend;

use App\Enums\CustomCodeType;
use App\Models\CustomCode;
use App\Services\Addons\AddonLoader;
use App\Services\BackgroundHealthService;
use App\Support\AdminSearch\PageSearcher;
use App\Support\AdminShell;
use App\Support\ControlPanelManager;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PDO;
use Throwable;

class AppController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'appInfo|appInfoReport'           => 'app-info',
            'controlPanel'                    => 'app-info',
            'styleManager|styleManagerUpdate' => 'style-manager',
            'clearCache'                      => 'app-clear-cache',
            'optimize'                        => 'app-optimize',
            'getMenusForSearch'               => 'app-info', // Allow all admin users to search menus
            'sendSmtpTestEmail'               => 'site-setting-update',
        ];
    }

    /**
     * The oldest PHP and Laravel this release supports.
     *
     * composer.json's own constraints, restated because the deployed bundle
     * ships without composer.json. AppInfoViewTest fails the day the two
     * disagree, so a bumped requirement cannot leave this page vouching for a
     * runtime the release no longer accepts.
     */
    public const MINIMUM_PHP = '8.3';

    public const MINIMUM_LARAVEL = '11.44';

    /**
     * Radius of the health ring in the view's 148px viewBox. The arc length is
     * worked out here so the blade draws a number rather than computing one.
     */
    private const RING_RADIUS = 62;

    /**
     * One page: the health overview first, then the facts, each in one card.
     *
     * Links written for the old tabbed page, ?section=php, still answer 200;
     * system-info.js turns them into a jump to the matching card.
     */
    public function appInfo(): View
    {
        return view('backend.app.info', $this->systemInfo());
    }

    /**
     * The same facts as plain text, for a support ticket.
     *
     * Protected values stay protected: demo mode is a property of the
     * installation, not of the format it is read in, so the text report hides
     * exactly what the page hides.
     */
    public function appInfoReport(): Response
    {
        $info  = $this->systemInfo();
        $line  = static fn (string $label, string $value): string => str_pad($label, 28).$value;
        $shown = static fn (?string $value): string => $value === null ? __('Protected') : ($value === '' ? '—' : $value);

        $lines = [
            setting('site_title', config('app.name')).' — '.__('System report').' — '.$info['checkedAt']->toDateTimeString(),
            '',
            '## '.__('Health'),
            $line(__('Checks passing'), $info['health']['passed'].' / '.$info['health']['total']),
        ];

        foreach ($info['issues'] as $issue) {
            $lines[] = $line($issue['severityLabel'].' · '.$issue['area'], $issue['title']);
        }

        $lines[] = '';
        $lines[] = '## '.__('Runtime stack');

        foreach ($info['stack'] as $layer) {
            $lines[] = $line($layer['role'], $layer['name'].' '.$shown($layer['version']).($layer['detail'] !== null && $layer['detail'] !== '' ? ' · '.$layer['detail'] : ''));
        }

        $lines[] = '';
        $lines[] = '## '.__('Security');

        foreach ($info['security'] as $check) {
            $lines[] = $line($check['label'], $shown($check['state']));
        }

        $lines[] = '';
        $lines[] = '## '.__('PHP limits');

        foreach ($info['limits'] as $limit) {
            $lines[] = $line($limit['label'], $limit['value'] === null ? __('Protected') : trim($limit['value'].' '.$limit['unit']));
        }

        foreach ([__('Extensions') => $info['extensions'], __('Functions') => $info['functions']] as $heading => $chips) {
            $lines[] = '';
            $lines[] = '## '.$heading;
            $lines[] = $chips === null
                ? __('Protected')
                : implode(', ', array_map(
                    fn (array $chip): string => $chip['name'].($chip['ok'] ? '' : ' ('.__('missing').')'),
                    $chips
                ));
        }

        $lines[] = '';
        $lines[] = '## '.__('Drivers & locale');

        foreach (array_merge($info['drivers'], $info['locale']) as $row) {
            $lines[] = $line($row['label'], $shown($row['value']));
        }

        $lines[] = '';
        $lines[] = '## '.__('Background services');

        foreach ($info['services']['rows'] as $service) {
            $lines[] = $line($service['label'], $shown($service['state']).' · '.$shown($service['detail']));
        }

        $lines[] = '';
        $lines[] = '## '.__('Host');

        foreach ($info['host'] as $row) {
            $lines[] = $line($row['label'], $shown($row['value']));
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type'  => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Everything the page and the text report share.
     *
     * Two kinds of thing, kept apart so nothing is said twice. Checks are
     * judgements — each passes, or fails and says what to do — and they feed
     * the health ring and the list beside it. Facts are values, and each lives
     * in exactly one place: a version in the stack, a limit in the PHP card, a
     * driver under Drivers & locale. A null value is one demo mode hides.
     *
     * @return array<string, mixed>
     */
    private function systemInfo(): array
    {
        $isDemo    = (bool) config('app.demo', false);
        $protected = 0;

        /* A value demo mode hides, counted so the overview can say how many. */
        $guard = function (string $value) use ($isDemo, &$protected): ?string {
            if ($isDemo) {
                $protected++;

                return null;
            }

            return $value;
        };

        $extensions = $this->extensionChips();
        $functions  = $this->requiredFunctions();

        /*
         * Demo mode keeps the runtime checks back with the values they judge:
         * "gmp is missing" tells a visitor as much as the extension list does.
         */
        $security   = $this->securityChecks(request()->isSecure(), $isDemo);
        $runtime    = $isDemo ? [] : $this->runtimeChecks($extensions, $functions);
        $background = $this->backgroundChecks($isDemo);
        $checks     = array_merge($security, $runtime, $background);

        /* A check whose state or detail demo mode withholds is a protected value too. */
        foreach ($checks as $check) {
            $protected += ($check['state'] === null ? 1 : 0) + ($check['detail'] === null ? 1 : 0);
        }

        $issues = array_values(array_filter(array_column($checks, 'issue')));

        if (AdminShell::updatesAvailable() > 0) {
            $canUpdate = Route::has('admin.app.updater.index')
                && in_array('project-updater-view', session('admin_permissions', []), true);

            $issues[] = $this->issue(
                'info',
                __('Release'),
                __('A new DigiKash release is ready to install'),
                __('Read what changed before you update.'),
                $canUpdate ? __('Review update') : null,
                $canUpdate ? route('admin.app.updater.index') : null,
            );
        }

        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($issues, static fn (array $a, array $b): int => $rank[$a['severity']] <=> $rank[$b['severity']]);

        $runtimeTone = static fn (string $key): string => $isDemo
            ? 'neutral'
            : (collect($runtime)->firstWhere('key', $key)['tone'] ?? 'neutral');

        $database = $this->databaseEngine();
        $server   = $this->webServer();

        $stack = [
            $this->layer('system', __('System'), $isDemo ? __('Operating system') : php_uname('s'), $guard(php_uname('r')), $guard(php_uname('m')), 'neutral', 'terminal'),
            $this->layer('server', __('Web server'), $isDemo ? __('HTTP server') : $server['name'], $guard($server['version']), $guard($this->sapiLabel()), 'neutral', 'globe'),
            $this->layer('php', __('Language'), 'PHP', $guard(PHP_VERSION), __('Requires :version+', ['version' => self::MINIMUM_PHP]), $runtimeTone('php'), 'code', copy: true),
            $this->layer('laravel', __('Framework'), 'Laravel', $guard(app()->version()), __('Requires :version+', ['version' => self::MINIMUM_LARAVEL]), $runtimeTone('laravel'), 'grid', copy: true),
            $this->layer('app', __('Application'), 'DigiKash', (string) config('app.version'), __('Installed build'), 'neutral', 'box', copy: true, isApp: true),
            $this->layer(
                'database',
                __('Database'),
                $isDemo ? __('Database engine') : $database['engine'],
                $guard((string) $database['version']),
                $database['version'] === null ? __('Not answering') : __('Connected'),
                $database['version'] === null ? 'danger' : 'success',
                'database',
            ),
        ];

        $limits = [
            ['key' => 'memory', 'label' => __('Memory limit')]     + $this->sizeParts((string) ini_get('memory_limit'), $guard),
            ['key' => 'execution', 'label' => __('Max execution')] + $this->secondsParts((string) ini_get('max_execution_time'), $guard),
            ['key' => 'upload', 'label' => __('Upload max')]       + $this->sizeParts((string) ini_get('upload_max_filesize'), $guard),
            ['key' => 'post', 'label' => __('Post max')]           + $this->sizeParts((string) ini_get('post_max_size'), $guard),
        ];

        if ($isDemo) {
            $protected += 2;
        }

        $drivers = [
            ['key' => 'database', 'label' => __('Database connection'), 'value' => $guard((string) config('database.default'))],
            ['key' => 'cache', 'label' => __('Cache'), 'value' => $guard((string) config('cache.default'))],
            ['key' => 'queue', 'label' => __('Queue'), 'value' => $guard((string) config('queue.default'))],
            ['key' => 'session', 'label' => __('Session'), 'value' => $guard((string) config('session.driver'))],
            ['key' => 'storage', 'label' => __('File storage'), 'value' => $guard((string) config('filesystems.default'))],
        ];

        $host = [
            ['key' => 'hostname', 'label' => __('Hostname'), 'value' => $guard((string) gethostname())],
            ['key' => 'ip', 'label' => __('IP address'), 'value' => $guard((string) (request()->server('SERVER_ADDR') ?? __('Unknown')))],
        ];

        $counted = array_values(array_filter($checks, static fn (array $check): bool => $check['tone'] !== 'neutral'));
        $passed  = count(array_filter($counted, static fn (array $check): bool => $check['tone'] === 'success'));
        $failing = count($counted) - $passed;
        $total   = count($counted);
        $arc     = 2 * M_PI * self::RING_RADIUS;

        $severityCount = static fn (string $severity): int => count(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === $severity));

        $health = [
            'passed'        => $passed,
            'total'         => $total,
            'tone'          => $this->worstTone($counted),
            'circumference' => round($arc, 2),
            'arc'           => round($total === 0 ? $arc : $arc * $passed / $total, 2),
            'caption'       => __('checks pass'),
            'verdict'       => match (true) {
                $failing > 0 => trans_choice(':count check failing|:count checks failing', $failing, ['count' => $failing]),
                $isDemo      => __('Healthy, details hidden'),
                default      => __('Everything is healthy'),
            },
            'counts' => [
                'danger'  => $severityCount('danger'),
                'warning' => $severityCount('warning'),
                'info'    => $severityCount('info'),
            ],
        ];

        $flag = fn (array $group): ?string => in_array($tone = $this->worstTone($group), ['danger', 'warning'], true) ? $tone : null;

        return [
            'isDemo'      => $isDemo,
            'environment' => AdminShell::environment(),
            'checkedAt'   => now(),
            'health'      => $health,
            'issues'      => $issues,
            'stack'       => $stack,
            'security'    => $security,
            'limits'      => $limits,
            'extensions'  => $isDemo ? null : $extensions,
            'functions'   => $isDemo ? null : $functions,
            'drivers'     => $drivers,
            'locale'      => [
                ['key' => 'timezone', 'label' => __('Timezone'), 'value' => $guard((string) config('app.timezone'))],
                ['key' => 'language', 'label' => __('Language'), 'value' => (string) config('app.locale')],
            ],
            'services' => $this->servicesCard($background),
            'host'     => $host,
            'nav'      => [
                ['key' => 'overview', 'label' => __('Overview'), 'flag' => $flag($counted)],
                ['key' => 'stack', 'label' => __('Runtime stack'), 'flag' => null],
                ['key' => 'security', 'label' => __('Security'), 'flag' => $flag($security)],
                ['key' => 'php', 'label' => __('PHP'), 'flag' => $flag($runtime)],
                ['key' => 'drivers', 'label' => __('Drivers & locale'), 'flag' => null],
                ['key' => 'services', 'label' => __('Background services'), 'flag' => $flag($background)],
                ['key' => 'host', 'label' => __('Host'), 'flag' => null],
            ],
            'protectedCount' => $protected,
        ];
    }

    /**
     * The four settings that decide what a visitor can see or forge.
     *
     * In demo mode the application key and debug mode keep their state back:
     * "debug mode is on" on a public page is an invitation, not a status.
     * HTTPS and CSRF stay, because any visitor can see both for themselves.
     *
     * @return list<array<string, mixed>>
     */
    private function securityChecks(bool $isSecure, bool $isDemo = false): array
    {
        $hasAppKey = filled(config('app.key'));
        $isDebug   = (bool) config('app.debug');
        $area      = __('Security');

        $checks = [
            $this->check('app_key', __('Application key'), __('Encrypts sessions, cookies and stored secrets'), $hasAppKey ? __('Set') : __('Not set'), $hasAppKey ? 'success' : 'danger', $hasAppKey ? null : $this->issue(
                'danger',
                $area,
                __('The application key is not set'),
                __('Sessions, cookies and stored secrets cannot be encrypted. Run php artisan key:generate on the server.'),
            )),
            $this->check('https', __('HTTPS'), __('This admin session arrived over an encrypted connection'), $isSecure ? __('Secure') : __('Not secure'), $isSecure ? 'success' : 'danger', $isSecure ? null : $this->issue(
                'danger',
                $area,
                __('This page was served over plain HTTP'),
                __('Passwords and session cookies cross the network unencrypted. Install a TLS certificate and redirect HTTP to HTTPS.'),
            )),
            $this->check('csrf', __('CSRF protection'), __('Forms reject submissions that did not start on this site'), __('On'), 'success'),
            $this->check('debug', __('Debug mode'), __('Error pages hide stack traces and configuration from visitors'), $isDebug ? __('On') : __('Off'), $isDebug ? 'danger' : 'success', $isDebug ? $this->issue(
                'danger',
                $area,
                __('Debug mode is on'),
                __('Error pages show stack traces and configuration to visitors. Set APP_DEBUG=false in the environment file, then clear the cache.'),
            ) : null),
        ];

        if (! $isDemo) {
            return $checks;
        }

        return array_map(
            fn (array $check): array => in_array($check['key'], ['app_key', 'debug'], true)
                ? $this->check($check['key'], $check['label'], $check['detail'], null, 'neutral')
                : $check,
            $checks,
        );
    }

    /**
     * @param  list<array{name: string, ok: bool, needed_by: string|null}> $extensions
     * @param  list<array{name: string, ok: bool, hint: string}>           $functions
     * @return list<array<string, mixed>>
     */
    private function runtimeChecks(array $extensions, array $functions): array
    {
        $phpOk     = version_compare(PHP_VERSION, self::MINIMUM_PHP, '>=');
        $laravelOk = version_compare(app()->version(), self::MINIMUM_LARAVEL, '>=');
        $missing   = array_values(array_filter($extensions, static fn (array $chip): bool => ! $chip['ok']));
        $disabled  = array_values(array_filter($functions, static fn (array $chip): bool => ! $chip['ok']));
        $area      = __('PHP');

        $missingText = __('Install :names for PHP :version on the server, then restart PHP-FPM.', [
            'names'   => implode(', ', array_column($missing, 'name')),
            'version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
        ]);

        foreach ($missing as $chip) {
            if ($chip['needed_by'] !== null) {
                $missingText .= ' '.__(':addon needs :extension.', ['addon' => $chip['needed_by'], 'extension' => $chip['name']]);
            }
        }

        return [
            $this->check('php', __('PHP version'), __('Requires :version or newer', ['version' => self::MINIMUM_PHP]), $phpOk ? __('Supported') : __('Too old'), $phpOk ? 'success' : 'danger', $phpOk ? null : $this->issue(
                'danger',
                $area,
                __('PHP is older than this release supports'),
                __('Upgrade PHP to :version or newer on the server.', ['version' => self::MINIMUM_PHP]),
            )),
            $this->check('laravel', __('Laravel version'), __('Requires :version or newer', ['version' => self::MINIMUM_LARAVEL]), $laravelOk ? __('Supported') : __('Too old'), $laravelOk ? 'success' : 'danger', $laravelOk ? null : $this->issue(
                'danger',
                $area,
                __('Laravel is older than this release expects'),
                __('Run composer install with the lock file shipped in this release.'),
            )),
            $this->check('extensions', __('Extensions'), __('Required by the installer and installed add-ons'), $missing === [] ? __('All loaded') : trans_choice(':count missing|:count missing', count($missing), ['count' => count($missing)]), $missing === [] ? 'success' : 'warning', $missing === [] ? null : $this->issue(
                'warning',
                $area,
                trans_choice(':count required extension is not loaded|:count required extensions are not loaded', count($missing), ['count' => count($missing)]),
                $missingText,
                __('Show extensions'),
                '#sys-php',
            )),
            $this->check('functions', __('Functions'), __('Must not be listed in disable_functions'), $disabled === [] ? __('All enabled') : trans_choice(':count disabled|:count disabled', count($disabled), ['count' => count($disabled)]), $disabled === [] ? 'success' : 'warning', $disabled === [] ? null : $this->issue(
                'warning',
                $area,
                __(':names is disabled on this server', ['names' => implode(', ', array_column($disabled, 'name'))]),
                $disabled[0]['hint'],
                __('Show functions'),
                '#sys-php',
            )),
        ];
    }

    /**
     * Scheduler and queue, judged by the service the dashboard already trusts.
     *
     * An `unknown` stays neutral and out of the score, as the service intends:
     * a backlog this cannot measure is not a broken queue.
     *
     * Demo mode keeps the verdicts and hides what is behind them: the queue
     * driver and the job counts are operational facts about this server.
     *
     * @return list<array<string, mixed>>
     */
    private function backgroundChecks(bool $isDemo = false): array
    {
        try {
            $summary = app(BackgroundHealthService::class)->summary();
        } catch (Throwable) {
            return [];
        }

        $canOpenJobs = Route::has('admin.background-tasks.index')
            && in_array('background-task-list', session('admin_permissions', []), true);
        $jobsLabel = $canOpenJobs ? __('Open background jobs') : null;
        $jobsUrl   = $canOpenJobs ? route('admin.background-tasks.index') : null;
        $area      = __('Background');

        $scheduler = $summary['scheduler'];
        $heartbeat = $scheduler['minutes_ago'] === null
            ? __('No heartbeat yet')
            : __('Last heartbeat :minutes min ago', ['minutes' => (int) $scheduler['minutes_ago']]);

        $schedulerCheck = match ($scheduler['status']) {
            BackgroundHealthService::STATUS_OK      => $this->check('scheduler', __('Scheduler'), $heartbeat, __('OK'), 'success'),
            BackgroundHealthService::STATUS_STALLED => $this->check('scheduler', __('Scheduler'), $heartbeat, __('Stopped'), 'danger', $this->issue(
                'danger',
                $area,
                __('The scheduler has stopped'),
                __('Check the cron entry that runs php artisan schedule:run every minute.'),
                $jobsLabel,
                $jobsUrl,
            )),
            default => $this->check('scheduler', __('Scheduler'), $heartbeat, __('Waiting'), 'neutral'),
        };

        $queue  = $summary['queue'];
        $failed = (int) ($queue['failed'] ?? 0);
        $detail = match (true) {
            $isDemo                    => null,
            $queue['pending'] === null => __(':driver driver', ['driver' => $queue['driver']]).($queue['failed'] === null ? '' : ' · '.__(':count failed', ['count' => $failed])),
            default                    => __(':pending waiting · :failed failed', ['pending' => (int) $queue['pending'], 'failed' => $failed]),
        };

        $queueCheck = match (true) {
            $queue['status'] === BackgroundHealthService::STATUS_STALLED => $this->check('queue', __('Queue worker'), $detail, __('Stalled'), 'danger', $this->issue(
                'danger',
                $area,
                __('The queue worker is not keeping up'),
                __('A job has waited :minutes minutes. Make sure a worker is running — php artisan queue:work, kept alive by Supervisor.', ['minutes' => (int) $queue['oldest_minutes']]),
                $jobsLabel,
                $jobsUrl,
            )),
            $failed > 0 => $this->check('queue', __('Queue worker'), $detail, $isDemo ? __('Failures') : trans_choice(':count failed|:count failed', $failed, ['count' => $failed]), 'warning', $this->issue(
                'warning',
                $area,
                $isDemo ? __('Failed jobs are waiting') : trans_choice(':count failed job is waiting|:count failed jobs are waiting', $failed, ['count' => $failed]),
                __('Failed jobs are not retried on their own. Read the failure, fix the cause, then retry them from Background jobs.'),
                $jobsLabel,
                $jobsUrl,
            )),
            $queue['status'] === BackgroundHealthService::STATUS_UNKNOWN => $this->check('queue', __('Queue worker'), $detail, __('Not measured'), 'neutral'),
            default                                                      => $this->check('queue', __('Queue worker'), $detail, __('OK'), 'success'),
        };

        return [$schedulerCheck, $queueCheck];
    }

    /**
     * @param  list<array<string, mixed>>                                           $checks
     * @return array{tone: string, label: string, rows: list<array<string, mixed>>}
     */
    private function servicesCard(array $checks): array
    {
        $failing = count(array_filter($checks, static fn (array $check): bool => in_array($check['tone'], ['danger', 'warning'], true)));
        $waiting = $checks === [] || in_array('neutral', array_column($checks, 'tone'), true);

        return [
            'tone'  => $failing > 0 ? $this->worstTone($checks) : ($waiting ? 'neutral' : 'success'),
            'label' => match (true) {
                $checks === [] => __('Unavailable'),
                $failing > 0   => trans_choice(':count needs attention|:count need attention', $failing, ['count' => $failing]),
                $waiting       => __('Partly measured'),
                default        => __('All running'),
            },
            'rows' => $checks,
        ];
    }

    /**
     * @param  array<string, mixed>|null                                                                                                  $issue
     *                                                                                                                                           A null state or detail is one demo mode withholds; such a check is
     *                                                                                                                                           neutral, so it stays out of the score and raises nothing.
     * @return array{key: string, label: string, detail: string|null, state: string|null, tone: string, issue: array<string, mixed>|null}
     */
    private function check(string $key, string $label, ?string $detail, ?string $state, string $tone, ?array $issue = null): array
    {
        return [
            'key'    => $key,
            'label'  => $label,
            'detail' => $detail,
            'state'  => $state,
            'tone'   => $tone,
            'issue'  => $issue,
        ];
    }

    /**
     * @return array{severity: string, severityLabel: string, area: string, title: string, text: string, action: array{label: string, url: string}|null}
     */
    private function issue(string $severity, string $area, string $title, string $text, ?string $actionLabel = null, ?string $actionUrl = null): array
    {
        return [
            'severity'      => $severity,
            'severityLabel' => match ($severity) {
                'danger'  => __('Critical'),
                'warning' => __('Warning'),
                default   => __('Notice'),
            },
            'area'   => $area,
            'title'  => $title,
            'text'   => $text,
            'action' => $actionLabel !== null && $actionUrl !== null ? ['label' => $actionLabel, 'url' => $actionUrl] : null,
        ];
    }

    /**
     * @return array{key: string, role: string, name: string, version: string|null, detail: string|null, tone: string, icon: string, copy: bool, isApp: bool}
     */
    private function layer(string $key, string $role, string $name, ?string $version, ?string $detail, string $tone, string $icon, bool $copy = false, bool $isApp = false): array
    {
        return [
            'key'     => $key,
            'role'    => $role,
            'name'    => $name,
            'version' => $version,
            'detail'  => $detail,
            'tone'    => $tone,
            'icon'    => $icon,
            'copy'    => $copy && $version !== null && $version !== '',
            'isApp'   => $isApp,
        ];
    }

    /**
     * The most serious tone among some checks: danger, then warning, then
     * success. Neutral only when nothing was judged at all.
     *
     * @param list<array<string, mixed>> $checks
     */
    private function worstTone(array $checks): string
    {
        $tones = array_column($checks, 'tone');

        foreach (['danger', 'warning', 'success'] as $tone) {
            if (in_array($tone, $tones, true)) {
                return $tone;
            }
        }

        return 'neutral';
    }

    /**
     * "512M" as 512 and MB. -1 is PHP's "no limit".
     *
     * @return array{value: string|null, unit: string}
     */
    private function sizeParts(string $raw, Closure $guard): array
    {
        $raw = trim($raw);

        if ($raw === '-1') {
            return ['value' => $guard(__('No limit')), 'unit' => ''];
        }

        if (preg_match('/^(\d+)\s*([KMG])?$/i', $raw, $match) !== 1) {
            return ['value' => $guard($raw), 'unit' => ''];
        }

        if (! isset($match[2]) || $match[2] === '') {
            return ['value' => $guard((string) round((int) $match[1] / 1048576)), 'unit' => 'MB'];
        }

        return ['value' => $guard($match[1]), 'unit' => strtoupper($match[2]).'B'];
    }

    /**
     * Seconds, where 0 is PHP's "no limit".
     *
     * @return array{value: string|null, unit: string}
     */
    private function secondsParts(string $raw, Closure $guard): array
    {
        if ((int) $raw === 0) {
            return ['value' => $guard(__('No limit')), 'unit' => ''];
        }

        return ['value' => $guard((string) (int) $raw), 'unit' => __('sec')];
    }

    /**
     * The engine and its version, asked of the connection itself so MySQL,
     * MariaDB, PostgreSQL and SQLite all answer. Null is a database that did
     * not.
     *
     * @return array{engine: string, version: string|null}
     */
    private function databaseEngine(): array
    {
        $connection = (string) config('database.default');
        $driver     = (string) config('database.connections.'.$connection.'.driver', $connection);

        try {
            $version = (string) DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            $version = null;
        }

        $engine = match ($driver) {
            'mysql'   => 'MySQL',
            'mariadb' => 'MariaDB',
            'pgsql'   => 'PostgreSQL',
            'sqlite'  => 'SQLite',
            'sqlsrv'  => 'SQL Server',
            default   => ucfirst($driver),
        };

        if ($version !== null && stripos($version, 'mariadb') !== false) {
            $engine = 'MariaDB';
            /* MariaDB prefixes its version with 5.5.5- for old MySQL clients. */
            $version = (string) preg_replace('/^5\.5\.5-/', '', $version);
        }

        if ($version !== null && preg_match('/\d+(?:\.\d+)+/', $version, $match) === 1) {
            $version = $match[0];
        }

        return ['engine' => $engine, 'version' => $version];
    }

    /**
     * "nginx/1.26.2" as nginx and 1.26.2.
     *
     * @return array{name: string, version: string}
     */
    private function webServer(): array
    {
        $software = (string) request()->server('SERVER_SOFTWARE', '');

        if (preg_match('#^([A-Za-z][\w.-]*)(?:/([\w.-]+))?#', $software, $match) === 1) {
            return ['name' => $match[1], 'version' => $match[2] ?? ''];
        }

        return ['name' => __('Unknown'), 'version' => ''];
    }

    /**
     * How the web server hands requests to PHP.
     */
    private function sapiLabel(): string
    {
        return match (PHP_SAPI) {
            'fpm-fcgi'       => 'PHP-FPM',
            'apache2handler' => 'mod_php',
            'cgi-fcgi'       => 'FastCGI',
            'litespeed'      => 'LiteSpeed',
            'frankenphp'     => 'FrankenPHP',
            'cli-server'     => __('Built-in server'),
            default          => PHP_SAPI,
        };
    }

    /**
     * The PHP extensions this installation depends on, and whether each is
     * loaded.
     *
     * The installer's own list first — the one it refused to install without
     * — then whatever an installed add-on's manifest declares, so a server
     * that lost gmp after On-chain Deposits went in says so here and names
     * the add-on that needs it. Read from where both lists are already kept,
     * rather than a third copy that would drift from them.
     *
     * @return list<array{name: string, ok: bool, needed_by: string|null}>
     */
    private function extensionChips(): array
    {
        $neededBy = [];

        foreach ((array) config('installer.required_extensions', []) as $extension) {
            $neededBy[strtolower((string) $extension)] = null;
        }

        try {
            $loader = app(AddonLoader::class);

            foreach (array_keys($loader->installed()) as $slug) {
                $manifest = $loader->registry()->find($slug);

                foreach ($manifest?->phpExtensions ?? [] as $extension) {
                    $neededBy[strtolower($extension)] ??= $manifest->name;
                }
            }
        } catch (Throwable) {
            /* No add-ons table yet is an installation with no add-ons. */
        }

        $chips = [];

        foreach ($neededBy as $extension => $addon) {
            $chips[] = ['name' => (string) $extension, 'ok' => extension_loaded((string) $extension), 'needed_by' => $addon];
        }

        return $chips;
    }

    /**
     * The functions the installer requires, from its own list, and whether
     * this server lets PHP call each one.
     *
     * @return list<array{name: string, ok: bool, hint: string}>
     */
    private function requiredFunctions(): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $chips    = [];

        foreach ((array) config('installer.required_functions', []) as $name => $why) {
            $chips[] = [
                'name' => (string) $name,
                'ok'   => function_exists((string) $name) && ! in_array((string) $name, $disabled, true),
                'hint' => (string) $why,
            ];
        }

        return $chips;
    }

    public function styleManager()
    {
        $css = CustomCode::ofType(CustomCodeType::CSS)->firstOrNew([
            'type' => CustomCodeType::CSS,
        ], [
            'content' => '',
            'status'  => false,
        ]);

        return view('backend.app.style_manager', compact('css'));
    }

    public function styleManagerUpdate(Request $request)
    {
        $validated = $request->validate([
            'type'    => ['required', Rule::in(CustomCodeType::values())],
            'content' => 'nullable|string',
            'status'  => 'boolean',
        ]);

        CustomCode::updateOrCreate(
            ['type' => $validated['type']],
            [
                'content' => $validated['content'],
                'status'  => $request->boolean('status'),
            ]
        );

        notifyEvs('success', __('Style Manager Updated Successfully'));

        return redirect()->back();
    }

    public function optimize()
    {
        notifyEvs('success', __('Application Optimized Successfully'));
        Artisan::call('app:optimize');

        return redirect()->back();
    }

    public function clearCache()
    {
        notifyEvs('success', __('Cache Cleared Successfully'));
        Artisan::call('app:clear');

        return redirect()->back();
    }

    public function sendSmtpTestEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'test_email' => 'required|email:rfc',
        ], [], [
            'test_email' => __('recipient email'),
        ]);

        $recipient = $validated['test_email'];
        $mailer    = (string) config('mail.default');
        $host      = (string) config('mail.mailers.'.$mailer.'.host', '');
        $siteTitle = trim((string) setting('site_title', config('app.name'))) ?: (string) config('app.name');

        try {
            Mail::raw($this->smtpTestEmailBody($siteTitle, $mailer, $host), function ($message) use ($recipient, $siteTitle) {
                $message->to($recipient)
                    ->subject(__(':site — test email: your mail settings work', ['site' => $siteTitle]));
            });

            return response()->json([
                'status'  => 'success',
                'message' => __('Test email sent to :email — check the inbox and the spam folder.', ['email' => $recipient]),
                'sent_to' => $recipient,
                'mailer'  => $mailer,
            ]);
        } catch (\Exception $e) {
            Log::error('SMTP test email failed', [
                'mailer' => $mailer,
                'host'   => $host,
                'error'  => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => __('The mail server rejected the saved settings — no email was sent.'),
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function smtpTestEmailBody(string $siteTitle, string $mailer, string $host): string
    {
        return __('This is a test email from :site.', ['site' => $siteTitle])."\n\n"
            .__('If you are reading this, the mail settings saved in the admin panel are working and outgoing email is being delivered.')."\n\n"
            .__('Mailer: :mailer', ['mailer' => $mailer])."\n"
            .($host !== '' ? __('Host: :host', ['host' => $host])."\n" : '')
            .__('Sent at: :time', ['time' => now()->toDayDateTimeString()])."\n\n"
            .__('No action is needed. You can delete this email.');
    }

    /**
     * The legacy menu search, kept for callers outside the header.
     *
     * The flattening now lives in PageSearcher so this and the command
     * palette cannot disagree about what a page is; the response shape here
     * is unchanged. Only the keys this endpoint always returned are passed on.
     */
    public function getMenusForSearch(Request $request)
    {
        $flattenedMenus = array_map(
            static fn (array $entry): array => array_intersect_key(
                $entry,
                array_flip(['label', 'route', 'url', 'icon', 'params', 'permission', 'section', 'parent'])
            ),
            PageSearcher::flatten()
        );

        // Filter by search query if provided
        $query = $request->get('query', '');
        if (! empty($query)) {
            $flattenedMenus = array_filter($flattenedMenus, function ($menu) use ($query) {
                return stripos($menu['label'], $query) !== false || stripos($menu['section'], $query) !== false || (isset($menu['parent']) && stripos($menu['parent'], $query) !== false);
            });
        }

        // Limit results to prevent overwhelming UI
        $flattenedMenus = array_slice($flattenedMenus, 0, 10);

        return response()->json([
            'success' => true,
            'menus'   => array_values($flattenedMenus),
        ]);
    }

    /**
     * Control Panel - Quick access to all admin features
     */
    public function controlPanel()
    {
        $adminPermissions = session('admin_permissions', []);
        $controlPanelData = ControlPanelManager::build($adminPermissions);

        return view('backend.app.control-panel', compact('controlPanelData'));
    }
}
