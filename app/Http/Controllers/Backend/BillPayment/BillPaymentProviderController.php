<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\BillPayment;

use App\Contracts\BillPayment\ProvidesBillPaymentDiagnostics;
use App\Contracts\BillPayment\ReceivesBillPaymentWebhooks;
use App\Contracts\BillPayment\SyncsBillers;
use App\Http\Controllers\Backend\BaseController;
use App\Http\Requests\Backend\BillPayment\StoreBillPaymentProviderRequest;
use App\Http\Requests\Backend\BillPayment\SyncBillPaymentBillersRequest;
use App\Http\Requests\Backend\BillPayment\UpdateBillPaymentProviderRequest;
use App\Jobs\SyncBillPaymentBillers;
use App\Models\BillCategory;
use App\Models\BillPaymentProvider;
use App\Models\Currency;
use App\Models\Plugin;
use App\Services\BillPayment\BillPaymentProviderManager;
use App\Services\BillPayment\Sync\BillerSyncReport;
use App\Support\DecimalMoney;
use App\Support\PluginSecretFields;
use App\Traits\FileManageTrait;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Who pays the biller, and the fee rule billers inherit.
 *
 * The code and the driver are chosen once: payments snapshot the code, and
 * the driver decides whether an operator or an API fulfils a payment. The
 * Manual provider ships with the module and cannot be deleted, and no
 * provider with billers or payments can be (billers and bill_payments both
 * reference it with RESTRICT). Switching a provider off hides every biller
 * it pays.
 *
 * An API driver keeps its credentials on its own plugin row,
 * `bill-payment-{id}`, created and deleted with the provider. The
 * Integration Center edits that row (it already blanks and keeps secrets),
 * so this screen never shows or writes a credential.
 */
class BillPaymentProviderController extends BaseController
{
    use FileManageTrait;

    /** The logo a provider's credentials plugin shows in the Integration Center. */
    public const PLUGIN_LOGO = 'general/static/svg/receipt.svg';

    /**
     * Each catalogue-sync skip reason in plain words, for the sync summary.
     *
     * @var array<string, string>
     */
    public const SKIP_REASON_LABELS = [
        BillerSyncReport::SKIP_REQUIRES_INVOICE   => 'Needs an invoice',
        BillerSyncReport::SKIP_INTERNATIONAL_ONLY => 'International amounts only',
        BillerSyncReport::SKIP_CURRENCY_UNKNOWN   => 'Currency not set up here',
        BillerSyncReport::SKIP_COUNTRY_NOT_MAPPED => 'No currency configured for the country',
        BillerSyncReport::SKIP_COUNTRY_MISMATCH   => 'Listed under another country',
        BillerSyncReport::SKIP_CATEGORY_UNMAPPED  => 'Category not synced',
        BillerSyncReport::SKIP_CATEGORY_MISSING   => 'Bill category missing here',
        BillerSyncReport::SKIP_DUPLICATE_CODE     => 'Listed twice',
        BillerSyncReport::SKIP_PRECISION          => 'Price has too many decimals',
    ];

    public static function permissions(): array
    {
        return [
            'index'                                                             => 'bill-payment-list',
            'create|store|edit|update|destroy|toggleStatus|testConnection|sync' => 'bill-payment-manage',
        ];
    }

    public function index(): View
    {
        $providers = BillPaymentProvider::query()
            ->withCount('billers')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('backend.bill_payment.providers.index', [
            'providers'    => $providers,
            'driverLabels' => $this->driverLabels(),
            'stats'        => [
                'total'   => $providers->count(),
                'active'  => $providers->where('status', true)->count(),
                'billers' => (int) $providers->sum('billers_count'),
            ],
        ]);
    }

    public function create(): View
    {
        return view('backend.bill_payment.providers.create', $this->formData(new BillPaymentProvider([
            'status' => true,
        ])));
    }

    public function store(StoreBillPaymentProviderRequest $request): RedirectResponse
    {
        $data       = $request->validated();
        $attributes = $this->attributesFrom($request, $data);

        DB::transaction(function () use ($attributes, $data): void {
            $provider = BillPaymentProvider::query()->create(array_merge($attributes, [
                'code'      => $data['code'],
                'driver'    => $data['driver'],
                'is_system' => false,
            ]));

            $this->ensureCredentialsPlugin($provider);
        });

        notifyEvs('success', __('Provider added.'));

        return redirect()->route('admin.bill-payment.providers.index');
    }

    public function edit(BillPaymentProvider $billPaymentProvider): View
    {
        return view('backend.bill_payment.providers.edit', $this->formData($billPaymentProvider));
    }

    public function update(UpdateBillPaymentProviderRequest $request, BillPaymentProvider $billPaymentProvider): RedirectResponse
    {
        $attributes = $this->attributesFrom($request, $request->validated(), $billPaymentProvider);

        DB::transaction(function () use ($attributes, $billPaymentProvider): void {
            $billPaymentProvider->update($attributes);

            /*
             * Only the name follows the provider. The credentials belong to
             * the Integration Center, so this save never reads or rewrites
             * them; a provider still without its plugin row gets one.
             */
            $billPaymentProvider->plugin?->update(['name' => $billPaymentProvider->name]);

            $this->ensureCredentialsPlugin($billPaymentProvider);
        });

        notifyEvs('success', __('Provider updated.'));

        return redirect()->route('admin.bill-payment.providers.index');
    }

    public function destroy(BillPaymentProvider $billPaymentProvider): RedirectResponse
    {
        if ($billPaymentProvider->isSystem()) {
            notifyEvs('error', __('This provider ships with Bill payments and cannot be deleted. Disable it instead.'));

            return redirect()->back();
        }

        if ($billPaymentProvider->billers()->exists()) {
            notifyEvs('error', __('This provider pays billers. Move them to another provider first, or disable it instead.'));

            return redirect()->back();
        }

        if ($billPaymentProvider->payments()->exists()) {
            notifyEvs('error', __('This provider has payments and cannot be deleted. Disable it instead.'));

            return redirect()->back();
        }

        DB::transaction(function () use ($billPaymentProvider): void {
            $plugin = $billPaymentProvider->plugin;

            $billPaymentProvider->delete();

            if ($plugin !== null && $plugin->type === Plugin::TYPE_BILL_PAYMENT) {
                $plugin->delete();
            }
        });

        notifyEvs('success', __('Provider deleted.'));

        return redirect()->route('admin.bill-payment.providers.index');
    }

    public function toggleStatus(BillPaymentProvider $billPaymentProvider): RedirectResponse
    {
        $billPaymentProvider->forceFill(['status' => ! $billPaymentProvider->status])->save();

        notifyEvs('success', $billPaymentProvider->status
            ? __('Provider enabled.')
            : __('Provider disabled. The billers it pays are hidden from customers.'));

        return redirect()->back();
    }

    /**
     * Probe the provider's saved credentials and report what it said.
     *
     * The same JSON envelope as the mobile recharge providers' test, so one
     * admin script renders either. A driver that cannot be probed answers
     * honestly instead of erroring. A passing test is stamped on the plugin,
     * which the Integration Center card reads as "Tested 2h ago".
     */
    public function testConnection(BillPaymentProvider $billPaymentProvider, BillPaymentProviderManager $manager): JsonResponse
    {
        try {
            $driver = $manager->driverFor($billPaymentProvider);

            $result = $driver instanceof ProvidesBillPaymentDiagnostics
                ? $driver->testConnection()
                : [
                    'ok'         => false,
                    'mode'       => null,
                    'message'    => __('This driver does not support a connection test.'),
                    'latency_ms' => null,
                    'details'    => ['implemented' => false],
                ];
        } catch (Throwable $exception) {
            report($exception);

            $result = [
                'ok'         => false,
                'mode'       => null,
                'message'    => __('The connection test could not be completed.'),
                'latency_ms' => null,
                'details'    => ['exception' => class_basename($exception)],
            ];
        }

        if (($result['ok'] ?? false) === true && $billPaymentProvider->plugin !== null) {
            $billPaymentProvider->plugin->forceFill(['last_tested_at' => now()])->save();
        }

        /*
         * BP1: a passing test is what resumes a provider the breaker paused,
         * and the balance it read is what the provider screen shows. A
         * failing test changes neither: the pause stands until the account
         * is fixed and proven.
         */
        $resumed = false;

        if (($result['ok'] ?? false) === true) {
            $balance  = $result['details']['balance']  ?? null;
            $currency = $result['details']['currency'] ?? null;

            if (is_numeric($balance)) {
                $billPaymentProvider->forceFill([
                    'balance_amount'     => DecimalMoney::normalize((string) $balance, DecimalMoney::CALCULATION_SCALE),
                    'balance_currency'   => is_string($currency) && $currency !== '' ? strtoupper(mb_substr($currency, 0, 8)) : null,
                    'balance_checked_at' => now(),
                ])->save();
            }

            if ($billPaymentProvider->isPaused()) {
                $billPaymentProvider->resume();
                $resumed = true;
            }
        }

        return response()->json([
            'provider' => [
                'id'      => $billPaymentProvider->id,
                'name'    => $billPaymentProvider->name,
                'driver'  => $billPaymentProvider->driver,
                'paused'  => $billPaymentProvider->isPaused(),
                'resumed' => $resumed,
            ],
            'result' => $result,
        ]);
    }

    /**
     * Queue a catalogue sync for the chosen countries and categories.
     *
     * One sync per provider at a time: a second request while one is queued
     * or running is answered, not queued. The results land on the provider's
     * sync_state and show on this screen once the queue has run it.
     */
    public function sync(SyncBillPaymentBillersRequest $request, BillPaymentProvider $billPaymentProvider): RedirectResponse
    {
        $class = $this->driverClass((string) $billPaymentProvider->driver);

        if ($class === null || ! is_subclass_of($class, SyncsBillers::class)) {
            notifyEvs('error', __('This provider cannot sync billers.'));

            return redirect()->back();
        }

        $dryRun = $request->boolean('dry_run');
        $job    = new SyncBillPaymentBillers(
            (int) $billPaymentProvider->id,
            $request->countries(),
            array_values(array_map('strval', (array) ($request->validated('categories') ?? []))),
            $dryRun,
        );

        // The same lock ShouldBeUnique takes, taken here so the admin is told
        // when a sync is already waiting instead of it being dropped silently.
        if (! (new UniqueLock(app(CacheRepository::class)))->acquire($job)) {
            notifyEvs('warning', __('A sync for this provider is already queued or running. Its results appear here when it finishes.'));

            return redirect()->back();
        }

        app(Dispatcher::class)->dispatch($job);

        notifyEvs('success', $dryRun
            ? __('Preview queued for :countries. It writes no billers; the counts appear here when it finishes.', ['countries' => implode(', ', $job->countries)])
            : __('Sync queued for :countries. New billers arrive switched off; the results appear here when it finishes.', ['countries' => implode(', ', $job->countries)]));

        return redirect()->back();
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(BillPaymentProvider $provider): array
    {
        return [
            'provider'     => $provider,
            'driverLabels' => $this->driverLabels(),
            'currencies'   => Currency::query()->orderBy('code')->get(['code', 'name', 'decimal_places']),
            'setup'        => $this->setupFor($provider),
        ];
    }

    /**
     * What the edit screen's setup panels need to know about the driver,
     * read from its class alone: nothing here builds a driver or reads a
     * credential. The one outside fact is whether the site takes deposits
     * through the same provider, whose dashboard webhook then stays on the
     * deposit address (the driver's static depositWebhookUrl()).
     *
     * @return array{api: bool, diagnostics: bool, webhooks: bool, webhook_url: string|null, deposit_webhook_url: string|null, live_checks: list<string>, plugin: Plugin|null, sync: bool, sync_categories: array<string, string>, sync_countries: string, sync_state: array<string, mixed>}
     */
    private function setupFor(BillPaymentProvider $provider): array
    {
        $class = $provider->exists ? $this->driverClass((string) $provider->driver) : null;
        $api   = $class !== null && method_exists($class, 'credentialTemplate');

        $webhooks = $class !== null && is_subclass_of($class, ReceivesBillPaymentWebhooks::class);

        $liveChecks = $class !== null && method_exists($class, 'liveCheckPending')
            ? array_values(array_filter((array) $class::liveCheckPending(), 'is_string'))
            : [];

        return [
            'api'                 => $api,
            'diagnostics'         => $class !== null && is_subclass_of($class, ProvidesBillPaymentDiagnostics::class),
            'webhooks'            => $webhooks,
            'webhook_url'         => $webhooks ? route('webhooks.bill-payments', ['providerCode' => $provider->code]) : null,
            'deposit_webhook_url' => $webhooks && method_exists($class, 'depositWebhookUrl') ? $class::depositWebhookUrl() : null,
            'live_checks'         => $liveChecks,
            'plugin'              => $api ? $provider->plugin : null,
            ...$this->syncSetupFor($provider, $class),
        ];
    }

    /**
     * What the catalogue sync panel needs: whether the driver syncs, the
     * bill categories it maps (code => name, only those that exist here), the
     * provider's saved sync countries and the last report per country.
     *
     * @param  class-string|null                                                                                                   $class
     * @return array{sync: bool, sync_categories: array<string, string>, sync_countries: string, sync_state: array<string, mixed>}
     */
    private function syncSetupFor(BillPaymentProvider $provider, ?string $class): array
    {
        if ($class === null || ! is_subclass_of($class, SyncsBillers::class)) {
            return ['sync' => false, 'sync_categories' => [], 'sync_countries' => '', 'sync_state' => []];
        }

        $codes = SyncBillPaymentBillersRequest::mappedCategoryCodes($provider);
        $names = BillCategory::query()->whereIn('code', $codes)->pluck('name', 'code')->all();

        $categories = [];

        foreach ($codes as $code) {
            if (isset($names[$code])) {
                $categories[$code] = (string) $names[$code];
            }
        }

        $countries = is_array($provider->config) ? (array) ($provider->config['sync_countries'] ?? []) : [];

        return [
            'sync'            => true,
            'sync_categories' => $categories,
            'sync_countries'  => implode(', ', array_filter($countries, 'is_string')),
            'sync_state'      => is_array($provider->sync_state) ? $provider->sync_state : [],
        ];
    }

    /**
     * Give an API provider its credentials plugin, `bill-payment-{id}`, the
     * way mobile recharge providers get theirs. The plugin starts from the
     * driver's credential template with every secret sealed, and in the
     * provider's own on/off state. A provider that already has one, or whose
     * driver needs no credentials, is left alone.
     */
    private function ensureCredentialsPlugin(BillPaymentProvider $provider): void
    {
        $class = $this->driverClass((string) $provider->driver);

        if ($class === null || ! method_exists($class, 'credentialTemplate') || $provider->plugin_id !== null) {
            return;
        }

        $code = 'bill-payment-'.$provider->id;

        if (Plugin::query()->where('code', $code)->exists()) {
            $code .= '-'.Str::lower(Str::random(6));
        }

        $plugin = Plugin::query()->create([
            'type'        => Plugin::TYPE_BILL_PAYMENT,
            'code'        => $code,
            'name'        => $provider->name,
            'logo'        => self::PLUGIN_LOGO,
            'description' => __('Credentials for the :provider bill payment provider.', ['provider' => $provider->name]),
            'credentials' => json_encode(PluginSecretFields::seal((array) $class::credentialTemplate())),
            'status'      => (bool) $provider->status,
        ]);

        $provider->forceFill(['plugin_id' => $plugin->id])->save();
        $provider->setRelation('plugin', $plugin);
    }

    /**
     * The registered class for a driver key, or null when it is not one.
     *
     * @return class-string|null
     */
    private function driverClass(string $driver): ?string
    {
        $class = ((array) config('bill_payments.drivers', []))[$driver] ?? null;

        return is_string($class) && $class !== '' && class_exists($class) ? $class : null;
    }

    /**
     * Everything an admin may change on a provider. The fee columns are not
     * nullable, so a blank fee is stored as zero.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributesFrom(StoreBillPaymentProviderRequest $request, array $data, ?BillPaymentProvider $provider = null): array
    {
        $attributes = [
            'name'            => $data['name'],
            'description'     => $data['description']     ?? null,
            'fee_percent'     => $data['fee_percent']     ?? '0',
            'fee_fixed'       => $data['fee_fixed']       ?? '0',
            'fee_currency'    => $data['fee_currency']    ?? null,
            'processing_note' => $data['processing_note'] ?? null,
            'status'          => (bool) ($data['status'] ?? false),
            'sort_order'      => (int) ($data['sort_order'] ?? $provider?->sort_order ?? ((int) BillPaymentProvider::query()->max('sort_order') + 10)),
        ];

        if ($request->hasFile('logo')) {
            $attributes['logo'] = $this->uploadImage($request->file('logo'), $provider?->logo);
        }

        $config = $this->configFrom($data, $provider);

        if ($config !== null) {
            $attributes['config'] = $config;
        }

        return $attributes;
    }

    /**
     * The provider `config` keys this form owns, merged into whatever the
     * column already holds, so a key another screen writes survives a save.
     * Null when the form posted none of them and the column needs no write.
     *
     * @param  array<string, mixed>      $data
     * @return array<string, mixed>|null
     */
    private function configFrom(array $data, ?BillPaymentProvider $provider): ?array
    {
        $posted = is_array($data['config'] ?? null) ? $data['config'] : [];
        $config = is_array($provider?->config) ? $provider->config : [];
        $owned  = [];

        if (array_key_exists('sync_countries', $posted)) {
            $owned['sync_countries'] = array_values(array_unique(array_filter((array) $posted['sync_countries'], 'is_string')));
        }

        if (array_key_exists('customer_lookup', $posted) || ($provider === null && $posted !== [])) {
            $owned['customer_lookup'] = (string) ($posted['customer_lookup'] ?? StoreBillPaymentProviderRequest::CUSTOMER_LOOKUP_DEFAULT);
        }

        // BP4: the daily catalogue sync, off unless the admin switches it on.
        if (array_key_exists('auto_sync', $posted)) {
            $owned['auto_sync'] = filter_var($posted['auto_sync'], FILTER_VALIDATE_BOOLEAN);
        }

        return $owned === [] ? null : array_merge($config, $owned);
    }

    /**
     * @return array<string, string>
     */
    private function driverLabels(): array
    {
        $configured = (array) config('bill_payments.driver_labels', []);
        $labels     = [];

        foreach (array_keys((array) config('bill_payments.drivers', [])) as $driver) {
            $labels[$driver] = (string) ($configured[$driver] ?? ucfirst((string) $driver));
        }

        return $labels;
    }
}
