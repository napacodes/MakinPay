<?php

namespace App\Http\Controllers;

use App\Exceptions\NotifyErrorException;
use App\Http\Requests\InstallApplicationRequest;
use App\Http\Requests\TestInstallDatabaseConnectionRequest;
use App\Http\Requests\TestInstallEnvatoLicenseRequest;
use App\Http\Requests\TestInstallSmtpConnectionRequest;
use App\Support\InstallationManager;
use App\Support\InstallerServerReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class InstallerController extends Controller
{
    public function __construct(private readonly InstallationManager $installer) {}

    /**
     * The setup wizard.
     */
    public function index(InstallerServerReport $report): View|RedirectResponse
    {
        if ($this->installer->isInstalled()) {
            return redirect()->route('home');
        }

        return view('installer.wizard', [
            ...$this->viewData(),
            'serverReport'  => $report->build(),
            'installStages' => InstallationManager::INSTALL_STAGE_GROUPS,
            'links'         => (array) config('installer.links', []),
        ]);
    }

    /**
     * The single-page installer the wizard replaced, kept reachable for one
     * release as a fallback. It posts the same form and gets the redirect it
     * always had.
     */
    public function classic(): View|RedirectResponse
    {
        if ($this->installer->isInstalled()) {
            return redirect()->route('home');
        }

        return view('installer.index-legacy', $this->viewData());
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        $defaultConnection = (string) config('database.default', 'mysql');
        $defaultDatabase   = $defaultConnection === 'sqlite'
            ? (string) config('database.connections.sqlite.database', 'database/database.sqlite')
            : (string) config("database.connections.{$defaultConnection}.database", 'digikash');

        return [
            'status'   => $this->installer->status(),
            'defaults' => [
                'app_name'              => config('app.name', 'Digikash'),
                'app_url'               => InstallationManager::resolveInstallUrl(),
                'admin_prefix'          => InstallationManager::DEFAULT_ADMIN_PREFIX,
                'default_currency_code' => InstallationManager::DEFAULT_CURRENCY_CODE,
                'db_connection'         => $defaultConnection,
                'db_host'               => config("database.connections.{$defaultConnection}.host", config('database.connections.mysql.host', '127.0.0.1')),
                'db_port'               => config("database.connections.{$defaultConnection}.port", config('database.connections.mysql.port', 3306)),
                'db_database'           => $defaultDatabase,
                'db_username'           => config("database.connections.{$defaultConnection}.username", config('database.connections.mysql.username', 'root')),
                'site_timezone'         => (string) config('app.timezone', 'UTC'),
                'mail_port'             => 587,
                'mail_secure'           => 'tls',
            ],
            'currencyCatalog' => InstallationManager::currencyCatalog(),
            'timezoneCatalog' => InstallationManager::timezoneCatalog(),
            'mailProviders'   => InstallationManager::mailProviders(),
            'cronCommand'     => InstallationManager::cronCommand(),
        ];
    }

    /**
     * The Server check screen's "Re-run checks": the same report the page was
     * rendered with, rebuilt now so a fix made on the server shows at once.
     */
    public function requirements(InstallerServerReport $report): JsonResponse
    {
        $data = $report->build();

        return response()->json([
            'ok'      => $data['can_install'],
            'message' => $data['can_install']
                ? __('All :count checks passed.', ['count' => $data['total_count']])
                : trans_choice('{1} :count check failed — installation is blocked.|[2,*] :count checks failed — installation is blocked.', $data['failed_count'], ['count' => $data['failed_count']]),
            'data' => $data,
        ]);
    }

    public function testDatabase(TestInstallDatabaseConnectionRequest $request): JsonResponse
    {
        if ($this->installer->isInstalled()) {
            return response()->json([
                'ok'       => false,
                'status'   => 'error',
                'message'  => __('The application is already installed.'),
                'guidance' => $this->databaseGuidance(),
            ], 409);
        }

        try {
            return response()->json($this->installer->testDatabaseConnection($request->validated()));
        } catch (NotifyErrorException $e) {
            return response()->json([
                'ok'       => false,
                'status'   => 'error',
                'message'  => $e->getMessage(),
                'guidance' => $this->databaseGuidance(),
            ], 422);
        }
    }

    public function testLicense(TestInstallEnvatoLicenseRequest $request): JsonResponse
    {
        if ($this->installer->isInstalled()) {
            return response()->json([
                'ok'       => false,
                'status'   => 'error',
                'message'  => __('The application is already installed.'),
                'guidance' => $this->licenseGuidance(),
            ], 409);
        }

        try {
            // The license block carries the update-server token and the raw
            // payload. Only the server needs them; the page gets `details`.
            $payload = Arr::except($this->installer->verifyEnvatoLicense($request->validated()), ['license']);

            if (! $payload['ok']) {
                $payload['guidance'] = $this->licenseGuidance();
            }

            return response()->json($payload, $payload['ok'] ? 200 : 422);
        } catch (NotifyErrorException $e) {
            return response()->json([
                'ok'       => false,
                'status'   => 'error',
                'message'  => $e->getMessage(),
                'guidance' => $this->licenseGuidance(),
            ], 422);
        }
    }

    public function testSmtp(TestInstallSmtpConnectionRequest $request): JsonResponse
    {
        if ($this->installer->isInstalled()) {
            return response()->json([
                'ok'       => false,
                'status'   => 'error',
                'message'  => __('The application is already installed.'),
                'guidance' => $this->smtpGuidance(),
            ], 409);
        }

        $validated = $request->validated();
        $payload   = $this->installer->verifySmtpSettings($validated, (string) $validated['mail_test_recipient']);

        if (! $payload['ok']) {
            $payload['guidance'] = $this->smtpGuidance();
        }

        return response()->json($payload, $payload['ok'] ? 200 : 422);
    }

    /**
     * The wizard posts with Accept: application/json and gets the stages, log
     * and summary back to render its Installing and Success screens. A plain
     * form post (the legacy page) keeps the redirect it always had.
     */
    public function store(InstallApplicationRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $result = $this->installer->install($request->validated());

            if ($request->expectsJson()) {
                return response()->json([
                    'ok'      => true,
                    'message' => __('Installation completed. Sign in with your admin account.'),
                    'data'    => [
                        'admin_url'   => $result['admin_url'],
                        'admin_email' => $result['admin_email'],
                        'stages'      => $result['stages'],
                        'log'         => $result['log'],
                        'summary'     => $result['summary'],
                    ],
                ]);
            }

            notifyEvs('success', __('Installation completed. Sign in with your admin account.'));

            return redirect($result['admin_url']);
        } catch (NotifyErrorException $e) {
            if ($request->expectsJson()) {
                $report = $this->installer->stageReport();
                $failed = collect($report['stages'])->last(fn (array $stage): bool => $stage['status'] === 'failed');

                return response()->json([
                    'ok'       => false,
                    'message'  => $e->getMessage(),
                    'stage'    => $failed['group'] ?? null,
                    'guidance' => $this->installer->permissionGuidance(),
                    'data'     => $report,
                ], 422);
            }

            notifyEvs('error', $e->getMessage());

            return back()
                ->withInput($request->except([
                    'admin_password',
                    'admin_password_confirmation',
                    'db_password',
                    'mail_password',
                    'envato_purchase_code',
                ]))
                ->with('install_error', [
                    'message'  => $e->getMessage(),
                    'guidance' => $this->installer->permissionGuidance(),
                ]);
        }
    }

    /**
     * @return list<string>
     */
    private function databaseGuidance(): array
    {
        return [
            __('Confirm DB host and port. Local servers usually use 127.0.0.1 and port 3306.'),
            __('Make sure the database username and password are correct.'),
            __('Give the database user CREATE, ALTER, INDEX, INSERT, UPDATE, DELETE, and SELECT permissions.'),
            __('On cPanel, open MySQL Databases, add the user to the database, then choose All Privileges.'),
            __('On VPS/Linux, grant privileges from MySQL/MariaDB and restart the web server if PHP extensions were changed.'),
        ];
    }

    /**
     * @return list<string>
     */
    private function smtpGuidance(): array
    {
        return [
            __('Copy the SMTP host, port, username and password from your email provider or hosting control panel (cPanel → Email Accounts → Connect Devices).'),
            __('Match the encryption to the port: TLS with 587, SSL with 465.'),
            __('Gmail and Outlook need an app password, not your normal sign-in password, when two-step verification is on.'),
            __('Use a From address the SMTP account is allowed to send as — usually the same mailbox as the username.'),
            __('Many VPS providers block outgoing ports 25, 465 and 587 by default. Ask the host to open them if the connection times out.'),
        ];
    }

    /**
     * @return list<string>
     */
    private function licenseGuidance(): array
    {
        return [
            __('Copy the purchase code from Envato Downloads > License certificate & purchase code.'),
            __('The installer verifies the code through the Coevs update server, so author Envato tokens are never stored on the client website.'),
            __('Confirm PROJECT_UPDATER_SERVER_URL points to the official update server and outgoing HTTPS requests are allowed.'),
            __('The purchase code must match the configured Digikash Envato item.'),
        ];
    }
}
