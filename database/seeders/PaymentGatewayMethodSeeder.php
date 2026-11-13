<?php

namespace Database\Seeders;

use App\Enums\FixPctType;
use App\Enums\MethodType;
use App\Models\Currency;
use App\Models\DepositMethod;
use App\Models\PaymentGateway;
use App\Models\WithdrawMethod;
use App\Support\DecimalMoney;
use App\Support\WithdrawFieldNormalizer;
use Illuminate\Database\Seeder;

class PaymentGatewayMethodSeeder extends Seeder
{
    /**
     * Configured currency code => its stored exchange rate.
     *
     * Loaded once per run. A method's `conversion_rate` is the same quantity
     * as the currency's `exchange_rate` — how many units of the method's
     * currency one base unit buys — so reading it from the table rather than
     * from CURRENCY_RATES below is what keeps the two from disagreeing.
     * They used to: this seeder's frozen table said NGN 1500 while the
     * operator's currency record said 1375.12, and `money:audit-precision`
     * reported the pair as 9% apart with no way to tell which was meant.
     *
     * @var array<string, string>|null
     */
    private ?array $configuredRates = null;

    private const array PREFERRED_CURRENCIES = [
        'airtel'       => 'UGX',
        'binance'      => 'USDT',
        'bitnob'       => 'USDT',
        'bitpayserver' => 'BTC',
        'blockchain'   => 'BTC',
        'blockio'      => 'BTC',
        'cashmaal'     => 'USD',
        'coinbase'     => 'USD',
        'coingate'     => 'EUR',
        'coinpayments' => 'BTC',
        'cryptomus'    => 'USDT',
        'flutterwave'  => 'NGN',
        'instamojo'    => 'INR',
        'mercadopago'  => 'BRL',
        'mollie'       => 'EUR',
        'moneroo'      => 'USD',
        'mtn'          => 'GHS',
        'nowpayments'  => 'USDT',
        'paymob'       => 'EGP',
        'paypal'       => 'USD',
        'paystack'     => 'NGN',
        'razorpay'     => 'INR',
        'stripe'       => 'USD',
        'strowallet'   => 'USD',
        'twocheckout'  => 'USD',
        'voguepay'     => 'NGN',
    ];

    private const array CURRENCY_RATES = [
        'USD'  => 1.00,
        'EUR'  => 0.92,
        'GBP'  => 0.79,
        'CAD'  => 1.36,
        'AUD'  => 1.51,
        'JPY'  => 156.00,
        'CHF'  => 0.90,
        'NGN'  => 1500.00,
        'GHS'  => 14.50,
        'KES'  => 130.00,
        'UGX'  => 3800.00,
        'TZS'  => 2600.00,
        'ZAR'  => 18.10,
        'RWF'  => 1300.00,
        'ZMW'  => 26.00,
        'XAF'  => 604.00,
        'XOF'  => 604.00,
        'BRL'  => 5.20,
        'MXN'  => 17.00,
        'INR'  => 83.00,
        'EGP'  => 48.00,
        'SAR'  => 3.75,
        'AED'  => 3.67,
        'OMR'  => 0.38,
        'BTC'  => 0.000015,
        'ETH'  => 0.00031,
        'LTC'  => 0.012,
        'DOGE' => 15.00,
        'BCH'  => 0.0022,
        'BNB'  => 0.0016,
        'USDT' => 1.00,
        'BUSD' => 1.00,
    ];

    private const array CURRENCY_SYMBOLS = [
        'USD'  => '$',
        'EUR'  => 'EUR',
        'GBP'  => 'GBP',
        'CAD'  => 'C$',
        'AUD'  => 'A$',
        'JPY'  => 'JPY',
        'CHF'  => 'CHF',
        'NGN'  => 'NGN',
        'GHS'  => 'GHS',
        'KES'  => 'KSh',
        'UGX'  => 'USh',
        'TZS'  => 'TSh',
        'ZAR'  => 'R',
        'RWF'  => 'RF',
        'ZMW'  => 'ZK',
        'XAF'  => 'FCFA',
        'XOF'  => 'CFA',
        'BRL'  => 'R$',
        'MXN'  => 'Mex$',
        'INR'  => 'INR',
        'EGP'  => 'EGP',
        'SAR'  => 'SAR',
        'AED'  => 'AED',
        'OMR'  => 'OMR',
        'BTC'  => 'BTC',
        'ETH'  => 'ETH',
        'LTC'  => 'LTC',
        'DOGE' => 'DOGE',
        'BCH'  => 'BCH',
        'BNB'  => 'BNB',
        'USDT' => 'USDT',
        'BUSD' => 'BUSD',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PaymentGateway::query()
            ->orderBy('name')
            ->get()
            ->each(function (PaymentGateway $gateway): void {
                $this->seedDepositMethod($gateway);
                $this->seedWithdrawMethod($gateway);
            });
    }

    private function seedDepositMethod(PaymentGateway $gateway): void
    {
        if ($gateway->depositMethods()->where('type', MethodType::AUTOMATIC->value)->exists()) {
            return;
        }

        $currency = $this->preferredCurrency($gateway);
        $limits   = $this->limitsFor($currency, 10.00, 5000.00);
        $charge   = $this->depositChargeFor($gateway->code);

        DepositMethod::query()->updateOrCreate(
            ['method_code' => $this->depositMethodCode($gateway, $currency)],
            [
                'payment_gateway_id'      => $gateway->id,
                'logo'                    => $this->logoFor($gateway),
                'name'                    => "{$gateway->name} {$currency}",
                'type'                    => MethodType::AUTOMATIC,
                'currency'                => $currency,
                'currency_symbol'         => $this->symbolFor($currency),
                'min_deposit'             => $limits['min'],
                'max_deposit'             => $limits['max'],
                'conversion_rate_live'    => false,
                'conversion_rate'         => $this->rateFor($currency),
                'charge_type'             => FixPctType::PERCENT->value,
                'charge'                  => $charge['user'],
                'user_charge'             => $charge['user'],
                'user_charge_type'        => FixPctType::PERCENT->value,
                'merchant_charge'         => $charge['merchant'],
                'merchant_charge_type'    => FixPctType::PERCENT->value,
                'fields'                  => [],
                'receive_payment_details' => '',
                'status'                  => $gateway->status && $this->currencyIsConfigured($currency),
            ]
        );
    }

    private function seedWithdrawMethod(PaymentGateway $gateway): void
    {
        if (! $gateway->withdraw_available || $gateway->withdrawMethods()->where('type', MethodType::AUTOMATIC->value)->exists()) {
            return;
        }

        $currency = $this->preferredCurrency($gateway);
        $limits   = $this->limitsFor($currency, 20.00, 2500.00);
        $charge   = $this->withdrawChargeFor($gateway->code);
        $fields   = WithdrawFieldNormalizer::normalize($gateway->withdraw_field);

        if ($fields === []) {
            return;
        }

        WithdrawMethod::query()->updateOrCreate(
            ['method_code' => $this->withdrawMethodCode($gateway, $currency)],
            [
                'payment_gateway_id'   => $gateway->id,
                'logo'                 => $this->logoFor($gateway),
                'name'                 => "{$gateway->name} {$currency} Payout",
                'type'                 => MethodType::AUTOMATIC,
                'currency'             => $currency,
                'currency_symbol'      => $this->symbolFor($currency),
                'min_withdraw'         => $limits['min'],
                'max_withdraw'         => $limits['max'],
                'conversion_rate_live' => false,
                'conversion_rate'      => $this->rateFor($currency),
                'charge_type'          => FixPctType::PERCENT->value,
                'charge'               => $charge['user'],
                'user_charge'          => $charge['user'],
                'user_charge_type'     => FixPctType::PERCENT->value,
                'merchant_charge'      => $charge['merchant'],
                'merchant_charge_type' => FixPctType::PERCENT->value,
                'process_time_value'   => 10,
                'process_time_unit'    => 'minute',
                'fields'               => $fields,
                'status'               => $gateway->status && $this->currencyIsConfigured($currency),
            ]
        );
    }

    private function preferredCurrency(PaymentGateway $gateway): string
    {
        $currencies = collect($gateway->currencies)
            ->map(fn (mixed $currency): string => strtoupper(trim((string) $currency)))
            ->filter()
            ->values();

        $preferred = self::PREFERRED_CURRENCIES[$gateway->code] ?? null;

        if ($preferred && $currencies->contains($preferred)) {
            return $preferred;
        }

        foreach (['USD', 'USDT', 'EUR', 'BTC', 'NGN', 'GBP'] as $fallback) {
            if ($currencies->contains($fallback)) {
                return $fallback;
            }
        }

        return $currencies->first() ?? siteCurrency();
    }

    /**
     * Deposit and withdraw limits are denominated in the platform's base
     * currency — that is what the admin form collects, what the deposit page
     * displays, and what PaymentService validates the entered amount against.
     * They are therefore identical for every method, whatever currency the
     * method settles in.
     *
     * This used to multiply by the method's conversion rate and hardcode
     * per-coin amounts (BTC 0.0001, DOGE 25), denominating the limits in the
     * method's own currency instead. Nothing else in the codebase read them
     * that way, so a EUR method seeded at rate 0.92 ended up enforcing
     * "between 9.20 and 4600 USD" while appearing to say "€9.20 to €4600",
     * and a BTC method enforced a 2 USD ceiling.
     *
     * A limit imposed by the processor in its own currency is a real and
     * separate concern; it needs its own column rather than overloading this
     * one.
     *
     * @return array{min: float, max: float}
     */
    private function limitsFor(string $currency, float $baseMin, float $baseMax): array
    {
        return ['min' => $baseMin, 'max' => $baseMax];
    }

    /**
     * @return array{user: float, merchant: float}
     */
    private function depositChargeFor(string $gatewayCode): array
    {
        return match ($gatewayCode) {
            'paypal'      => ['user' => 3.49, 'merchant' => 2.99],
            'stripe'      => ['user' => 2.90, 'merchant' => 2.20],
            'paystack'    => ['user' => 1.50, 'merchant' => 1.00],
            'flutterwave' => ['user' => 1.40, 'merchant' => 1.00],
            'mtn', 'airtel' => ['user' => 1.25, 'merchant' => 0.90],
            default => ['user' => 1.00, 'merchant' => 0.50],
        };
    }

    /**
     * @return array{user: float, merchant: float}
     */
    private function withdrawChargeFor(string $gatewayCode): array
    {
        return match ($gatewayCode) {
            'paypal'   => ['user' => 2.00, 'merchant' => 1.50],
            'stripe'   => ['user' => 1.00, 'merchant' => 0.75],
            'paystack' => ['user' => 1.50, 'merchant' => 1.00],
            'moneroo'  => ['user' => 1.25, 'merchant' => 0.85],
            'bitnob'   => ['user' => 1.00, 'merchant' => 0.50],
            default    => ['user' => 1.00, 'merchant' => 0.50],
        };
    }

    private function depositMethodCode(PaymentGateway $gateway, string $currency): string
    {
        if ($gateway->code === 'bitnob' && $currency === 'USDT') {
            return 'bitnob_usdt';
        }

        return $this->methodCode($gateway, $currency);
    }

    private function withdrawMethodCode(PaymentGateway $gateway, string $currency): string
    {
        if ($gateway->code === 'bitnob') {
            return 'bitnob_payout';
        }

        return $this->methodCode($gateway, $currency);
    }

    private function methodCode(PaymentGateway $gateway, string $currency): string
    {
        return $gateway->code.'-'.strtolower($currency);
    }

    private function logoFor(PaymentGateway $gateway): string
    {
        return $gateway->logo ?: 'general/static/default/payment-gateway.png';
    }

    /**
     * The rate to seed a method with.
     *
     * The configured currency wins. CURRENCY_RATES is only the starting figure
     * for a currency the platform has not been given yet — and a method in that
     * position seeds disabled anyway, so the number is never charged against.
     */
    private function rateFor(string $currency): string
    {
        return $this->configuredRates()[strtoupper($currency)]
            ?? DecimalMoney::rate(self::CURRENCY_RATES[$currency] ?? 1.00);
    }

    /**
     * Whether the platform actually knows this currency.
     *
     * A method settling in a currency with no record cannot work: there is no
     * wallet to credit, no precision to format at, and its limits reconcile
     * against nothing. It used to be seeded enabled regardless, so a fresh
     * install listed unusable methods on the deposit page — the installer
     * creates one currency, while the gateways here resolve to eleven.
     *
     * Seeding it disabled leaves it visible in the admin panel, where adding
     * the currency and switching it on is one action, instead of visible to
     * customers, where choosing it is a dead end.
     */
    private function currencyIsConfigured(string $currency): bool
    {
        return array_key_exists(strtoupper($currency), $this->configuredRates());
    }

    /**
     * @return array<string, string>
     */
    private function configuredRates(): array
    {
        return $this->configuredRates ??= Currency::query()
            ->get(['code', 'exchange_rate'])
            ->mapWithKeys(fn (Currency $currency): array => [
                strtoupper((string) $currency->code) => DecimalMoney::rate(
                    (string) $currency->getRawOriginal('exchange_rate')
                ),
            ])
            ->all();
    }

    private function symbolFor(string $currency): string
    {
        return self::CURRENCY_SYMBOLS[$currency] ?? $currency;
    }
}
