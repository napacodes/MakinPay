<?php

namespace App\Http\Controllers\Backend;

use App\Constants\CurrencyRole;
use App\Exceptions\NotifyErrorException;
use App\Http\Requests\Currency\StoreUpdateRequest;
use App\Models\Currency;
use App\Services\Currency\BaseCurrencyRebaseService;
use App\Services\CurrencyConversionService;
use App\Support\CurrencyPrecision;
use App\Support\DecimalMoney;
use App\Support\Money;
use App\Traits\FileManageTrait;
use DB;
use Exception;
use Illuminate\Http\Request;
use Log;

class CurrencyController extends BaseController
{
    use FileManageTrait;

    public static function permissions(): array
    {
        return [
            'index|store|edit|update|destroy|rates' => 'currency-manage',
        ];
    }

    // Display a listing of currencies
    public function index()
    {
        $currencies = Currency::with(['activeRoles'])->get();

        return view('backend.currencies.index', compact('currencies'));
    }

    // Store a newly created currency in storage
    public function store(StoreUpdateRequest $request)
    {
        $validated = $request->validated();

        // Default to the currency's conventional precision when the admin
        // leaves the field blank: ISO 4217 minor unit for fiat, asset
        // precision for crypto.
        $validated['decimal_places'] = CurrencyPrecision::clamp(
            $request->input('decimal_places'),
            CurrencyPrecision::for($validated['code'], $validated['type']),
        );

        // The display columns are NOT NULL with a default; a blank select must
        // fall back to that default rather than reach the insert as null.
        $validated['symbol_position'] = $validated['symbol_position'] ?? Money::POSITION_PREFIX;
        $validated['number_grouping'] = $validated['number_grouping'] ?? Money::GROUPING_STANDARD;

        $validated['auto_wallet'] = $this->toBoolean($request->default ? true : $request->auto_wallet);
        $validated['status']      = $this->toBoolean($request->status);
        $validated['default']     = $this->toBoolean($request->default);

        // Process and assign the flag image. When the admin skips the
        // file picker (most common silent-failure path), fall back to a
        // generic placeholder so the create still succeeds and they can
        // upload a real flag from the edit screen later.
        $validated['flag'] = $this->handleFlagUpload($request)
            ?? 'general/static/default/placeholder.png';

        $becomesDefault = $validated['default'];

        // The new row must exist with its own rate before any rebase reads it,
        // and the whole switch has to be atomic — a failure between clearing
        // the old default and setting the new one leaves zero base currencies.
        $currency = DB::transaction(function () use ($validated, $becomesDefault) {
            $currency = Currency::create($validated);

            if (! $becomesDefault) {
                $this->enforceDefaultCurrency();

                return $currency;
            }

            app(BaseCurrencyRebaseService::class)->rebaseTo($currency, auth('admin')->id());

            Currency::where('default', true)->whereKeyNot($currency->getKey())->update(['default' => false]);
            $currency->forceFill(['default' => true])->save();

            return $currency;
        });

        /*
         * Only the roles that were actually posted, then the rest filled in.
         *
         * The fallback used to be CurrencyRole::getRoles(), which returns a
         * flat list of role-name STRINGS — so $role['role_name'] fatalled on a
         * string the moment a currency was created without a roles array, the
         * exact case the form request documents as supported. ensureCurrencyRoles()
         * already knows how to write a default row and is what edit() uses, so
         * both paths now agree on what a missing role becomes.
         */
        foreach ((array) $request->input('roles', []) as $role) {
            if (! is_array($role) || ! isset($role['role_name'])) {
                continue;
            }

            $currency->roles()->create(array_merge(
                ['role_name' => $role['role_name']],
                $this->roleFeeAttributes($role),
            ));
        }

        $this->ensureCurrencyRoles($currency);

        notifyEvs('success', __('Currency created successfully'));

        return redirect()->back();
    }

    // Remove the specified currency from storage

    public function update(StoreUpdateRequest $request, Currency $currency)
    {
        $validated = $request->validated();

        $validated['decimal_places'] = CurrencyPrecision::clamp(
            $request->input('decimal_places'),
            (int) $currency->decimal_places,
        );

        // An absent select keeps whatever the currency already had, so a form
        // posted without these fields cannot silently reset how it is written.
        $validated['symbol_position'] = ($validated['symbol_position'] ?? $currency->symbol_position) ?: Money::POSITION_PREFIX;
        $validated['number_grouping'] = ($validated['number_grouping'] ?? $currency->number_grouping) ?: Money::GROUPING_STANDARD;

        $validated['auto_wallet'] = $this->toBoolean($request->default ? true : $request->auto_wallet);
        $validated['status']      = $this->toBoolean($request->status);
        $validated['default']     = $this->toBoolean($request->default);

        // Prevent disabling the default currency
        if (($currency->default || $validated['default']) && ! $validated['status']) {
            return redirect()->back()->withErrors(__('The default currency cannot be disabled.'));
        }

        $isChangingDefaultCurrency = $validated['default'] && ! $currency->default;

        if ($isChangingDefaultCurrency && ! $request->boolean('default_change_acknowledged')) {
            return redirect()->back()
                ->withErrors(['default_change_acknowledged' => __('Please review and acknowledge the default currency change impact before continuing.')])
                ->withInput();
        }

        // Prevent changing the default currency
        if ($currency->default && ! $validated['default']) {
            return redirect()->back()->withErrors(__('The default currency cannot be changed.'));
        }

        // Process and assign the flag image if present
        if ($request->hasFile('flag')) {
            // Remove the old flag image if necessary
            $validated['flag'] = $this->handleFlagUpload($request, $currency->flag);
        }

        // The rate rebase, the flag flips and the currency update must all
        // land together. Previously they were three unguarded writes, so a
        // failure part way through could leave zero or two default currencies.
        try {
            DB::transaction(function () use ($currency, $validated, $isChangingDefaultCurrency): void {
                $currency->update($validated);

                if (! $isChangingDefaultCurrency) {
                    $this->enforceDefaultCurrency();

                    return;
                }

                // Rebase before flipping the flag: the divisor is the incoming
                // base's rate as expressed against the *outgoing* base.
                app(BaseCurrencyRebaseService::class)->rebaseTo($currency->refresh(), auth('admin')->id());

                Currency::where('default', true)
                    ->whereKeyNot($currency->getKey())
                    ->update(['default' => false]);

                $currency->forceFill([
                    'default'     => true,
                    'auto_wallet' => true,
                    'status'      => true,
                ])->save();
            });
        } catch (NotifyErrorException $e) {
            return redirect()->back()->withErrors($e->getMessage())->withInput();
        }

        // Get roles data from the request
        $rolesData = $request->input('roles', []);

        // Saved through the model rather than the query builder so the
        // fixed/percent <-> legacy fee sync in CurrencyRole::booted() runs.
        foreach ($rolesData as $id => $role) {
            $model = $currency->roles()->find($id);

            if ($model === null) {
                continue;
            }

            $model->fill($this->roleFeeAttributes($role))->save();
        }
        notifyEvs('success', __('Currency updated successfully'));

        return redirect()->back();
    }

    public function edit(Currency $currency)
    {
        $this->ensureCurrencyRoles($currency);

        // Eager load the 'roles' relationship along with the currency
        $currency->load('roles');

        return view('backend.currencies.edit', compact('currency'))->render();
    }

    public function destroy(Currency $currency)
    {
        // Prevent deleting the default currency
        if ($currency->default) {
            return redirect()->back()->withErrors(__('The default currency cannot be deleted.'));
        }

        // Check if there are active wallets associated with this currency
        if (DB::table('wallets')->where('currency_id', $currency->id)->exists()) {
            return redirect()->back()->withErrors(__('Cannot delete currency with active wallets.'));
        }

        try {
            // Use a database transaction for consistency
            DB::transaction(function () use ($currency) {
                // Remove the flag image if it exists
                if ($currency->flag) {
                    $this->delete($currency->flag);
                }

                // Delete the currency record
                $currency->delete();
            });

            // Success notification
            notifyEvs('success', __('Currency deleted successfully'));

            return redirect()->back();
        } catch (Exception $e) {
            // Log the error for debugging
            Log::error('Currency Deletion Error: '.$e->getMessage());

            notifyEvs('error', __('An error occurred while deleting the currency.'));

            // Return with an error message
            return redirect()->back();
        }
    }

    // Show the form for editing the specified currency

    // Update the specified currency in storage

    /**
     * Normalize one submitted currency-role row.
     *
     * Accepts either shape: the new fixed + percent pair, or the legacy
     * amount + type pair that older cached Blades and external posts still
     * send. CurrencyRole::booted() keeps whichever pair was written in sync
     * with the other.
     *
     * @param  array<string, mixed> $role
     * @return array<string, mixed>
     */
    protected function roleFeeAttributes(array $role): array
    {
        $attributes = [
            'min_limit' => $role['min_limit'] ?? 0,
            'max_limit' => $role['max_limit'] ?? null,
            'fee_min'   => $role['fee_min']   ?? null,
            'fee_max'   => $role['fee_max']   ?? null,
            'is_active' => $role['status']    ?? false,
        ];

        if (array_key_exists('fee_fixed', $role) || array_key_exists('fee_percent', $role)) {
            return $attributes + [
                'fee_fixed'   => $role['fee_fixed']   ?? 0,
                'fee_percent' => $role['fee_percent'] ?? 0,
            ];
        }

        return $attributes + [
            'fee'      => $role['fee']      ?? 0,
            'fee_type' => $role['fee_type'] ?? null,
        ];
    }

    /**
     * Convert a value to boolean.
     */
    protected function toBoolean($value)
    {
        return (bool) $value;
    }

    /**
     * Ensure at least one currency is set as default.
     *
     * Guarded against an empty table — on a brand-new install (or
     * any state where every currency has been deleted) Currency::first()
     * returns null and `null->update(...)` used to 500 the create POST.
     */
    protected function enforceDefaultCurrency(): void
    {
        if (Currency::query()->where('default', true)->exists()) {
            return;
        }

        $first = Currency::query()->first();
        if ($first === null) {
            return;
        }

        $first->update(['default' => true]);
    }

    /**
     * Handle flag image upload if the file is present.
     */
    protected function handleFlagUpload(Request $request, $old = null)
    {
        return $request->hasFile('flag') ? self::uploadImage($request->file('flag'), $old) : null;
    }

    private function ensureCurrencyRoles(Currency $currency): void
    {
        $existingRoles = $currency->roles()->pluck('role_name')->all();

        foreach (CurrencyRole::getRoles() as $roleName) {
            if (in_array($roleName, $existingRoles, true)) {
                continue;
            }

            $currency->roles()->create([
                'role_name' => $roleName,
                'fee'       => 0,
                'fee_type'  => null,
                'min_limit' => 0,
                'max_limit' => null,
                'is_active' => false,
            ]);
        }
    }

    /**
     * Return exchange rates for currencies as JSON without blocking the index page.
     */
    public function rates(Request $request)
    {
        $base  = $request->input('base', siteCurrency());
        $codes = collect($request->input('codes', []))
            ->filter()
            ->values();

        $query = Currency::query()->select(['id', 'code', 'exchange_rate', 'rate_live', 'status']);
        if ($codes->isNotEmpty()) {
            $query->whereIn('code', $codes);
        }

        $currencies = $query->get();

        /** @var CurrencyConversionService $service */
        $service = app(CurrencyConversionService::class);

        $data = [];

        foreach ($currencies as $currency) {
            // Start with stored/cached value to avoid accessor (which may call external API)
            $rate = (float) $currency->getRawOriginal('exchange_rate');

            if ($currency->rate_live) {
                try {
                    $live = $service->convertCurrency(1, (string) $base, (string) $currency->code);
                    if ($live !== null) {
                        $rate = (float) $live;
                    }
                } catch (\Throwable $e) {
                    // Keep fallback rate
                }
            }

            $data[] = [
                'id'   => $currency->id,
                'code' => $currency->code,
                // Rates are emitted at rate scale, never at the site's display
                // precision, so live crypto rates do not arrive as "0.00".
                'rate' => DecimalMoney::rate($rate),
                'live' => (bool) $currency->rate_live,
            ];
        }

        return response()->json([
            'base'      => $base,
            'data'      => $data,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
