<?php

namespace App\Http\Controllers\Backend;

use App\Enums\KycStatus;
use App\Http\Requests\Backend\KycProviderConnectionTestRequest;
use App\Http\Requests\Backend\KycProviderRequest;
use App\Models\KycProvider;
use App\Models\KycSubmission;
use App\Services\Handlers\KycProviderConnectionTester;
use App\Services\Kyc\KycAutomationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class KycProviderController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'index'                                         => 'kyc-provider-list',
            'store|update|destroy|activate|deactivate|test' => 'kyc-provider-manage',
        ];
    }

    /**
     * Provider gallery: every supported driver is always shown as a card,
     * merged with its saved configuration (when one exists).
     */
    public function index(): View
    {
        $configured = KycProvider::query()
            ->orderByDesc('priority')
            ->get()
            ->keyBy('driver');

        /*
         * One grouped query for the whole gallery rather than one per card:
         * how many submissions each engine cleared today, which is the only
         * figure on a provider card that moves during a shift.
         */
        $verifiedToday = KycSubmission::query()
            ->whereNotNull('provider')
            ->where('status', KycStatus::APPROVED)
            ->where('verified_at', '>=', now()->startOfDay())
            ->selectRaw('provider, COUNT(*) as total')
            ->groupBy('provider')
            ->pluck('total', 'provider');

        $catalog = collect((array) config('kyc.catalog', []))
            ->map(function (array $meta, string $driver) use ($configured, $verifiedToday): array {
                $provider = $configured->get($driver);

                if (! $provider) {
                    $provider         = new KycProvider;
                    $provider->driver = $driver;
                    $provider->code   = str_replace('_', '-', $driver);
                    $provider->name   = (string) ($meta['name'] ?? '');
                }

                return $meta + [
                    'driver'         => $driver,
                    'provider'       => $configured->get($driver),
                    'form_provider'  => $provider,
                    'verified_today' => (int) ($verifiedToday[$driver] ?? $verifiedToday[str_replace('_', '-', $driver)] ?? 0),
                ];
            })
            ->values();

        $activeProvider = $configured->first(fn (KycProvider $provider): bool => (bool) $provider->status);

        $pending = KycSubmission::query()->where('status', KycStatus::PENDING);
        $oldest  = (clone $pending)->min('created_at');

        $stats = [
            'total'   => $catalog->count(),
            'active'  => $activeProvider?->name,
            'auto_on' => KycAutomationSettings::enabled(),
            /*
             * The routing strip and the manual-review row both state what is
             * actually waiting behind the engine. Without these the page
             * could describe the routing but never how much of it lands on a
             * person, which is the number an admin is choosing between.
             */
            'countries'      => count($activeProvider?->countries ?? []),
            'pending'        => (clone $pending)->count(),
            'oldest_pending' => $oldest ? Carbon::parse($oldest) : null,
        ];

        $countries = collect(getCountries())
            ->sortBy('name')
            ->values()
            ->all();

        return view('backend.kyc.providers.index', compact('catalog', 'stats', 'countries'));
    }

    public function store(KycProviderRequest $request): RedirectResponse
    {
        $data = $this->guardActivationWithoutKeys($request->validated());

        $provider = DB::transaction(function () use ($data): KycProvider {
            $provider = KycProvider::query()->create($data);
            $this->enforceSingleActive($provider);

            return $provider;
        });

        notifyEvs('success', __('KYC provider saved.'));

        return redirect()->route('admin.kyc.providers.index');
    }

    public function update(KycProviderRequest $request, KycProvider $provider): RedirectResponse
    {
        $data = $request->validated();

        if (blank($data['credentials'] ?? null)) {
            unset($data['credentials']);
        }

        $data = $this->guardActivationWithoutKeys($data, $provider);

        DB::transaction(function () use ($provider, $data): void {
            $provider->update($data);
            $this->enforceSingleActive($provider);
        });

        notifyEvs('success', __('KYC provider updated.'));

        return redirect()->route('admin.kyc.providers.index');
    }

    public function test(
        KycProviderConnectionTestRequest $request,
        KycProviderConnectionTester $tester
    ): JsonResponse {
        $data     = $request->validated();
        $provider = isset($data['provider_id'])
            ? KycProvider::query()
                ->where('driver', $data['driver'])
                ->find($data['provider_id'])
            : null;

        try {
            $result = $tester->test(
                $data['driver'],
                $data['credentials'] ?? [],
                $data['mode'],
                $provider
            );

            /*
             * Remember it. The verdict used to go to the browser and nowhere
             * else, so a provider card could not say when its engine was
             * last reached — the one fact that tells an admin whether a
             * silent queue is the provider's fault or their own.
             */
            $provider?->forceFill([
                'last_tested_at'   => now(),
                'last_test_status' => $result['status'] ?? null,
            ])->save();

            return response()->json($result, $result['status'] === 'error' ? 422 : 200);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status'  => 'error',
                'message' => __('The provider connection could not be tested. Check the credentials and try again.'),
            ], 422);
        }
    }

    /**
     * Make this provider the single active one. Any other enabled provider is
     * switched off in the same step so two engines never compete.
     */
    public function activate(KycProvider $provider): RedirectResponse
    {
        if (blank($provider->credentials)) {
            notifyEvs('error', __('Add the API credentials for :name before activating it.', ['name' => $provider->name]));

            return redirect()->route('admin.kyc.providers.index');
        }

        DB::transaction(function () use ($provider): void {
            $provider->update(['status' => true]);
            $this->enforceSingleActive($provider);
        });

        notifyEvs('success', __(':name is now the active KYC provider. All other providers were switched off.', ['name' => $provider->name]));

        return redirect()->route('admin.kyc.providers.index');
    }

    public function deactivate(KycProvider $provider): RedirectResponse
    {
        $provider->update(['status' => false]);

        notifyEvs('success', __(':name deactivated. Submissions now go to manual review.', ['name' => $provider->name]));

        return redirect()->route('admin.kyc.providers.index');
    }

    public function destroy(KycProvider $provider): RedirectResponse
    {
        $provider->delete();

        notifyEvs('success', __('KYC provider configuration removed.'));

        return redirect()->route('admin.kyc.providers.index');
    }

    /**
     * A provider can only go live with API keys in place. When the switch is
     * on but no keys exist (incoming or already saved), save it disabled and
     * tell the admin why.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function guardActivationWithoutKeys(array $data, ?KycProvider $provider = null): array
    {
        $hasKeys = filled($data['credentials'] ?? null) || filled($provider?->credentials);

        if (($data['status'] ?? false) && ! $hasKeys) {
            $data['status'] = false;
            notifyEvs('info', __('Saved as inactive — add the API keys first, then activate it.'));
        }

        return $data;
    }

    /**
     * Single-active invariant: when the given provider is enabled, every other
     * provider is disabled to avoid two engines verifying at once.
     */
    protected function enforceSingleActive(KycProvider $provider): void
    {
        if (! $provider->status) {
            return;
        }

        /*
         * The page promises the previous engine is paused "in the same
         * transaction". It was two unwrapped statements, so a failure
         * between them — or two admins activating at once — left the site
         * with two live engines and no way to tell which verified a given
         * submission. The rows are locked before the demotion so the second
         * request waits rather than reading a stale status.
         */
        DB::transaction(function () use ($provider): void {
            $others = KycProvider::query()
                ->whereKeyNot($provider->id)
                ->where('status', true)
                ->lockForUpdate()
                ->pluck('id');

            if ($others->isNotEmpty()) {
                KycProvider::query()->whereIn('id', $others)->update(['status' => false]);
            }
        });
    }
}
