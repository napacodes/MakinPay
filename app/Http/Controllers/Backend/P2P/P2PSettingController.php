<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\P2P;

use App\Http\Controllers\Backend\BaseController;
use App\Models\Currency;
use App\Models\P2P\P2PAssetFee;
use App\Models\P2P\P2PSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class P2PSettingController extends BaseController
{
    // region Marketplace Settings

    public static function permissions(): array
    {
        return [
            'edit|update' => 'p2p-manage',
        ];
    }

    public function edit(): View
    {
        $settings = P2PSetting::query()->first() ?? P2PSetting::query()->create([]);

        $countryOptions = collect(getCountries())
            ->filter(fn ($country) => ! empty($country['code']))
            ->map(fn ($country) => [
                'code' => strtoupper((string) $country['code']),
                'name' => (string) ($country['name'] ?? $country['code']),
            ])
            ->sortBy('name')
            ->values()
            ->all();

        $allowedSelected = $this->splitCountryList((string) ($settings->allowed_countries ?? ''));
        $blockedSelected = $this->splitCountryList((string) ($settings->blocked_countries ?? ''));

        // Per-asset fee overrides. Eager-loaded with their currency because the
        // table below prints the code and symbol for every row.
        $assetFees = P2PAssetFee::query()
            ->with('currency:id,code,symbol,type')
            ->join('currencies', 'currencies.id', '=', 'p2p_asset_fees.currency_id')
            ->orderBy('currencies.code')
            ->select('p2p_asset_fees.*')
            ->get();

        // Only currencies without an override can be added, so the picker
        // cannot produce the duplicate the unique index would reject.
        $assetOptions = Currency::query()
            ->active()
            ->whereNotIn('id', $assetFees->pluck('currency_id'))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        return view('backend.p2p.settings.marketplace_settings', compact(
            'settings',
            'countryOptions',
            'allowedSelected',
            'blockedSelected',
            'assetFees',
            'assetOptions'
        ));
    }

    public function update(Request $request): RedirectResponse
    {
        $countryCodes = collect(getCountries())
            ->pluck('code')
            ->map(fn ($code) => strtoupper((string) $code))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $validated = $request->validate([
            // Total fee = fixed + (amount x percentage), clamped to any floor
            // and ceiling. A flat component matters on small trades, where a
            // pure percentage does not cover the cost of settling them.
            'maker_fee_pct'          => 'nullable|numeric|min:0|max:100',
            'maker_fee_fixed'        => 'nullable|numeric|min:0',
            'maker_fee_min'          => 'nullable|numeric|min:0',
            'maker_fee_max'          => 'nullable|numeric|min:0|gte:maker_fee_min',
            'taker_fee_pct'          => 'nullable|numeric|min:0|max:100',
            'taker_fee_fixed'        => 'nullable|numeric|min:0',
            'taker_fee_min'          => 'nullable|numeric|min:0',
            'taker_fee_max'          => 'nullable|numeric|min:0|gte:taker_fee_min',
            'order_expiry_minutes'   => 'required|integer|min:5|max:2880',
            'dispute_window_minutes' => 'required|integer|min:10|max:4320',
            'min_amount'             => 'nullable|numeric|min:0',
            'max_amount'             => 'nullable|numeric|min:0|gt:min_amount',
            'allowed_countries'      => 'nullable|array',
            'allowed_countries.*'    => ['string', 'size:2', Rule::in($countryCodes)],
            'blocked_countries'      => 'nullable|array',
            'blocked_countries.*'    => ['string', 'size:2', Rule::in($countryCodes)],
        ], [
            'max_amount.gt' => __('Maximum amount must be greater than minimum amount.'),
        ]);

        $allowed = $this->normalizeCountryArray($validated['allowed_countries'] ?? []);
        $blocked = $this->normalizeCountryArray($validated['blocked_countries'] ?? []);

        if ($this->containsEveryCountry($allowed, $countryCodes)) {
            $allowed = [];
        }

        if ($allowed !== [] && $blocked !== [] && array_intersect($allowed, $blocked) !== []) {
            throw ValidationException::withMessages([
                'blocked_countries' => __('Allowed and blocked country lists cannot share the same country.'),
            ]);
        }

        $settings = P2PSetting::query()->first() ?? P2PSetting::query()->create([]);

        $optional = fn (string $key): ?float => ($validated[$key] ?? null) !== null && $validated[$key] !== ''
            ? (float) $validated[$key]
            : null;

        $settings->update([
            'maker_fee_pct'          => (float) ($validated['maker_fee_pct'] ?? 0),
            'maker_fee_fixed'        => (float) ($validated['maker_fee_fixed'] ?? 0),
            'maker_fee_min'          => $optional('maker_fee_min'),
            'maker_fee_max'          => $optional('maker_fee_max'),
            'taker_fee_pct'          => (float) ($validated['taker_fee_pct'] ?? 0),
            'taker_fee_fixed'        => (float) ($validated['taker_fee_fixed'] ?? 0),
            'taker_fee_min'          => $optional('taker_fee_min'),
            'taker_fee_max'          => $optional('taker_fee_max'),
            'order_expiry_minutes'   => (int) $validated['order_expiry_minutes'],
            'dispute_window_minutes' => (int) $validated['dispute_window_minutes'],
            'min_amount'             => (float) ($validated['min_amount'] ?? 0),
            'max_amount'             => ($validated['max_amount'] ?? null) !== null && $validated['max_amount'] !== '' ? (float) $validated['max_amount'] : null,
            'allowed_countries'      => $allowed                           !== [] ? implode(',', $allowed) : null,
            'blocked_countries'      => $blocked                           !== [] ? implode(',', $blocked) : null,
        ]);

        return back()->with('notifyevs', ['type' => 'success', 'message' => __('P2P marketplace settings updated')]);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeCountryArray(array $values): array
    {
        return collect($values)
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn ($code) => $code !== '' && strlen($code) === 2)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $values
     * @param array<int, string> $countryCodes
     */
    private function containsEveryCountry(array $values, array $countryCodes): bool
    {
        if ($values === [] || count($values) !== count($countryCodes)) {
            return false;
        }

        return array_diff($countryCodes, $values) === [];
    }

    /**
     * @return array<int, string>
     */
    private function splitCountryList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn ($code) => $code !== '')
            ->unique()
            ->values()
            ->all();
    }

    // endregion
}
