<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Constants\CurrencyRole as CurrencyRoleName;
use App\Constants\CurrencyType;
use App\Models\Currency;
use App\Support\InstallationManager;
use Illuminate\Database\Seeder;

/**
 * Seed the currencies the shipped payment methods settle in.
 *
 * `PaymentGatewayMethodSeeder` creates roughly forty deposit and withdraw
 * methods across eleven settlement currencies, but nothing was creating those
 * currencies: the installer seeds exactly one (the buyer's base) and every
 * other currency had to be added by hand. A fresh install therefore came up
 * with most of its payment methods pointing at a currency the platform did not
 * know — enabled, listed on the deposit page, and unusable. `money:audit-precision`
 * reports each one as `unknown-method-currency`.
 *
 * This seeder is the other half of that pair. `PaymentGatewayMethodSeeder` now
 * refuses to enable a method whose settlement currency is missing, and this
 * makes sure it is not missing. `MethodCurrencyIntegrityTest` asserts the two
 * agree, so a gateway added later with a new settlement currency fails the
 * suite rather than shipping broken.
 *
 * Idempotent, and deliberately create-only: an existing currency is left
 * exactly as the operator configured it. Re-seeding must never reset a rate
 * someone has tuned, flip a currency they disabled, or overwrite a live rate
 * with the static reference figure below.
 */
class CurrencySeeder extends Seeder
{
    /**
     * Reference rates: how many units of the currency one unit of a USD base
     * buys. Starting values only — they are what a fresh install shows before
     * the operator sets real ones or enables `currency:refresh-rates`, and they
     * are never written over an existing row.
     *
     * Kept in step with `PaymentGatewayMethodSeeder::CURRENCY_RATES` by
     * `MethodCurrencyIntegrityTest`, so a method and the currency it settles in
     * cannot ship quoting different numbers for the same pair.
     *
     * @var array<string, array{type: string, rate: string, name?: string, symbol?: string, flag?: string}>
     */
    private const CATALOG = [
        'USD' => ['type' => CurrencyType::FIAT, 'rate' => '1'],
        'EUR' => ['type' => CurrencyType::FIAT, 'rate' => '0.92'],
        'NGN' => ['type' => CurrencyType::FIAT, 'rate' => '1500'],
        'SAR' => ['type' => CurrencyType::FIAT, 'rate' => '3.75'],
        'INR' => ['type' => CurrencyType::FIAT, 'rate' => '83'],
        'BRL' => ['type' => CurrencyType::FIAT, 'rate' => '5.20'],
        'GHS' => ['type' => CurrencyType::FIAT, 'rate' => '14.50'],
        'EGP' => ['type' => CurrencyType::FIAT, 'rate' => '48'],

        // Not in the installer's catalog, so the presentation fields are
        // supplied here. UGX has no minor unit — CurrencyPrecision knows that,
        // and the model's creating hook applies it.
        'UGX' => [
            'type'   => CurrencyType::FIAT,
            'rate'   => '3800',
            'name'   => 'Ugandan Shilling',
            'symbol' => 'USh',
            'flag'   => 'ug',
        ],

        'BTC' => [
            'type'   => CurrencyType::CRYPTO,
            'rate'   => '0.000015',
            'name'   => 'Bitcoin',
            'symbol' => '₿',
            'flag'   => 'btc',
        ],
        'USDT' => [
            'type'   => CurrencyType::CRYPTO,
            'rate'   => '1',
            'name'   => 'Tether',
            'symbol' => '₮',
            'flag'   => 'usdt',
        ],
        'USDC' => [
            'type'   => CurrencyType::CRYPTO,
            'rate'   => '1',
            'name'   => 'USD Coin',
            'symbol' => '$',
            'flag'   => 'usdc',
        ],
    ];

    /**
     * Role limits are denominated in the base currency, the same for every
     * currency, which is why one set of figures serves the whole catalog.
     * These mirror what the admin form defaults to.
     *
     * @var array<string, array{min: float, max: float|null}>
     */
    private const ROLE_LIMITS = [
        CurrencyRoleName::SENDER        => ['min' => 10, 'max' => 100000],
        CurrencyRoleName::REQUEST_MONEY => ['min' => 10, 'max' => 100000],
        CurrencyRoleName::EXCHANGE      => ['min' => 10, 'max' => 10000],
        CurrencyRoleName::VOUCHER       => ['min' => 10, 'max' => 10000],
        CurrencyRoleName::GIFT_CARD     => ['min' => 10, 'max' => 10000],
        CurrencyRoleName::PAYMENT       => ['min' => 10, 'max' => 10000],
        CurrencyRoleName::WITHDRAW      => ['min' => 0,  'max' => null],
    ];

    /**
     * Every settlement currency the shipped payment methods can be seeded
     * with. `MethodCurrencyIntegrityTest` reads this rather than the private
     * const so the guarantee is checked against the real list.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * The reference rate for a code, or null when it is not in the catalog.
     */
    public static function referenceRate(string $code): ?string
    {
        return self::CATALOG[strtoupper(trim($code))]['rate'] ?? null;
    }

    public function run(): void
    {
        $catalog = InstallationManager::currencyCatalog();

        // Whether a base already exists decides only whether the first created
        // currency claims the flag. It must not be re-read inside the loop, or
        // a fresh install would mark USD default and then leave it that way
        // while later iterations saw a base and skipped — which is correct, but
        // only by accident. Settle it once.
        $hasDefault = Currency::query()->where('default', true)->exists();

        foreach (self::CATALOG as $code => $definition) {
            if (Currency::query()->where('code', $code)->exists()) {
                continue;
            }

            $presentation = $catalog[$code] ?? [];

            $currency = Currency::query()->create([
                'name'   => $definition['name'] ?? $presentation['name'] ?? $code,
                'code'   => $code,
                'symbol' => $definition['symbol'] ?? $presentation['symbol'] ?? $code,
                'flag'   => $definition['flag']   ?? $presentation['flag'] ?? 'general/static/default/placeholder.png',
                'type'   => $definition['type'],

                // decimal_places is deliberately omitted: the model's creating
                // hook derives it from CurrencyPrecision, so UGX gets 0, BTC 8
                // and the rest 2 without this seeder restating ISO 4217.
                'exchange_rate' => $definition['rate'],
                'rate_live'     => false,

                // Only the base currency provisions a wallet for every user.
                // The rest are opt-in, so a buyer who never enables NGN does
                // not hand a NGN wallet to everyone who signs up.
                'auto_wallet' => ! $hasDefault,
                'default'     => ! $hasDefault,
                'status'      => true,
            ]);

            $hasDefault = true;

            $this->seedRoles($currency);
        }

        $this->backfillMissingRoles();
    }

    /**
     * Give a role-less currency its roles, whatever created it.
     *
     * Currencies arrive by several routes — the installer, this seeder, the
     * admin form, a restored SQL dump — and only the admin form was creating
     * the role rows. A currency without them is inert: the wallet exists and
     * shows a balance, but every feature gate resolves through
     * `getRoleInfo()`, finds nothing, and quietly refuses to send, exchange or
     * withdraw. That is a difficult symptom to trace back to a missing row.
     *
     * Only genuinely absent roles are added. A role the operator deactivated
     * still exists with `is_active` false and is left alone, so this cannot
     * turn a feature back on behind their back.
     */
    private function backfillMissingRoles(): void
    {
        $currencies = Currency::query()->with('roles:id,currency_id,role_name')->get();

        foreach ($currencies as $currency) {
            $present = $currency->roles->pluck('role_name');
            $missing = array_diff(array_keys(self::ROLE_LIMITS), $present->all());

            if ($missing === []) {
                continue;
            }

            $this->seedRoles($currency, $missing);
        }
    }

    /**
     * A currency with no roles is inert: every feature gate reads
     * `getRoleInfo()` and finds nothing, so the wallet exists but cannot send,
     * exchange or withdraw. The admin form creates all seven on save; this
     * does the same.
     *
     * @param list<string>|null $only Restrict to these role names.
     */
    private function seedRoles(Currency $currency, ?array $only = null): void
    {
        $roles = $only === null
            ? self::ROLE_LIMITS
            : array_intersect_key(self::ROLE_LIMITS, array_flip($only));

        foreach ($roles as $roleName => $limits) {
            $currency->roles()->create([
                'role_name'   => $roleName,
                'min_limit'   => $limits['min'],
                'max_limit'   => $limits['max'],
                'fee_fixed'   => 0,
                'fee_percent' => 0,
                'fee_min'     => null,
                'fee_max'     => null,
                'is_active'   => true,
            ]);
        }
    }
}
