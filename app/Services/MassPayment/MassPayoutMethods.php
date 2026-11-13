<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Enums\MethodType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawMethod;
use App\Services\Payment\Payout\Drivers\StripeConnectPayoutGateway;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use App\Support\DecimalMoney;
use App\Support\WithdrawFieldNormalizer;
use Illuminate\Support\Collection;

/**
 * Which withdraw methods a mass payout may use, and why any other may not.
 *
 * A method is offered only when the admin opted it in through
 * `mass_payout_driver` and every one of these still holds:
 *
 *   - the method is switched on;
 *   - its currency is the source wallet's (mass payouts do no FX);
 *   - its conversion rate is positive, so the base figures can be derived;
 *   - its field schema has no file field, which a CSV line cannot carry;
 *   - its shape suits the driver ({@see compatibilityIssues()}), so a plain
 *     Stripe payout method is never reinterpreted as Stripe Connect and a
 *     Bitnob method cannot pay a crypto address;
 *   - the driver itself is ready ({@see PayoutGatewayFactory::massPayoutIssues()}).
 *
 * The shape is checked here as well as on the admin save, because the
 * gateway's own field schema (which an automatic method inherits) can change
 * after the method was saved.
 */
class MassPayoutMethods
{
    public const BITNOB_GATEWAY = 'bitnob';

    public const STRIPE_GATEWAY = 'stripe';

    /**
     * The field aliases the Bitnob driver reads, one group per detail it
     * requires. A Bitnob method's schema must carry one field of each group.
     *
     * @var array<string, list<string>>
     */
    public const BITNOB_REQUIRED_FIELDS = [
        'country'        => ['country'],
        'bank_code'      => ['bank_code', 'bank', 'provider_code'],
        'account_number' => ['account_number', 'phone_number', 'mobile_number'],
        'account_name'   => ['account_name', 'name', 'recipient_name'],
    ];

    /**
     * Fields that mean a crypto destination, which Bitnob mass payouts never
     * pay.
     *
     * @var list<string>
     */
    public const BITNOB_REFUSED_FIELDS = ['wallet_address', 'address'];

    public function __construct(private readonly PayoutGatewayFactory $gateways) {}

    /**
     * The methods the owner can pay out from this wallet with, by name.
     *
     * @return Collection<int, WithdrawMethod>
     */
    public function forWallet(User $owner, Wallet $wallet): Collection
    {
        $currency = (string) $wallet->currency?->code;

        return WithdrawMethod::query()
            ->active()
            ->whereNotNull('mass_payout_driver')
            ->with('paymentGateway')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (WithdrawMethod $method): bool => $this->issuesFor($method, $currency) === [])
            ->values()
            ->toBase();
    }

    /**
     * One eligible method by id, or null when it is missing or not eligible
     * for this wallet. Callers answer null with the same "not available"
     * refusal either way, so an id never reveals whether the method exists.
     */
    public function findForWallet(User $owner, Wallet $wallet, int $methodId): ?WithdrawMethod
    {
        return $this->forWallet($owner, $wallet)->first(
            fn (WithdrawMethod $method): bool => (int) $method->id === $methodId,
        );
    }

    /**
     * Every reason the method cannot be used for a mass payout, empty when it
     * can. With a currency, a method in any other currency is refused too.
     *
     * @return list<string>
     */
    public function issuesFor(WithdrawMethod $method, ?string $walletCurrency = null): array
    {
        $driver = $method->mass_payout_driver;

        if ($driver === null || $driver === '') {
            return [__('This method is not offered for mass payouts.')];
        }

        $issues = [];

        if (! $method->status) {
            $issues[] = __('This method is switched off.');
        }

        if ($walletCurrency !== null && strtoupper(trim((string) $method->currency)) !== strtoupper(trim($walletCurrency))) {
            $issues[] = __('This method pays out in a different currency from the wallet.');
        }

        if (! $this->hasUsableRate($method)) {
            $issues[] = __('This method has no usable conversion rate.');
        }

        array_push($issues, ...$this->compatibilityIssues(
            (string) $driver,
            $method->type,
            $method->paymentGateway?->code,
            $method->fields,
        ));

        if (in_array($driver, PayoutGatewayFactory::MASS_PAYOUT_DRIVERS, true)) {
            array_push($issues, ...$this->gateways->massPayoutIssues((string) $driver));
        }

        return array_values(array_unique($issues));
    }

    /**
     * Whether a method of this shape may carry this driver, as the reasons it
     * may not.
     *
     *   manual          a MANUAL method;
     *   bitnob          an AUTOMATIC method on the Bitnob gateway whose fields
     *                   carry every {@see BITNOB_REQUIRED_FIELDS} group and no
     *                   crypto address;
     *   stripe_connect  an AUTOMATIC method on the Stripe gateway with a
     *                   `stripe_account_id` field.
     *
     * No driver accepts a file field: a CSV line cannot carry one.
     *
     * @return list<string>
     */
    public function compatibilityIssues(string $driver, MethodType|string|null $type, ?string $gatewayCode, mixed $fields): array
    {
        if (! in_array($driver, PayoutGatewayFactory::MASS_PAYOUT_DRIVERS, true)) {
            return [__('This payout driver cannot be used for mass payouts.')];
        }

        $type       = $type instanceof MethodType ? $type : MethodType::tryFrom((string) $type);
        $normalized = WithdrawFieldNormalizer::normalize($fields);
        $names      = array_map(fn (array $field): string => (string) $field['name'], $normalized);
        $issues     = [];

        foreach ($normalized as $field) {
            if ($field['type'] === 'file') {
                $issues[] = __('A mass payout cannot collect the file field :field.', ['field' => $field['name']]);
            }
        }

        if ($driver === 'manual') {
            if ($type !== MethodType::MANUAL) {
                $issues[] = __('The manual driver needs a manual withdraw method.');
            }

            return $issues;
        }

        $expectedGateway = $driver === 'bitnob' ? self::BITNOB_GATEWAY : self::STRIPE_GATEWAY;

        if ($type !== MethodType::AUTOMATIC || $gatewayCode !== $expectedGateway) {
            $issues[] = $driver === 'bitnob'
                ? __('The Bitnob driver needs an automatic method on the Bitnob gateway.')
                : __('The Stripe Connect driver needs an automatic method on the Stripe gateway.');
        }

        if ($driver === 'bitnob') {
            foreach (self::BITNOB_REQUIRED_FIELDS as $detail => $aliases) {
                if (array_intersect($aliases, $names) === []) {
                    $issues[] = __('A Bitnob mass payout needs a :detail field (:aliases).', [
                        'detail'  => $detail,
                        'aliases' => implode(', ', $aliases),
                    ]);
                }
            }

            foreach (array_intersect(self::BITNOB_REFUSED_FIELDS, $names) as $name) {
                $issues[] = __('A Bitnob mass payout cannot pay a crypto address field (:field).', ['field' => $name]);
            }

            return $issues;
        }

        if (! in_array(StripeConnectPayoutGateway::ACCOUNT_DETAIL_KEY, $names, true)) {
            $issues[] = __('A Stripe Connect mass payout needs a :field field.', [
                'field' => StripeConnectPayoutGateway::ACCOUNT_DETAIL_KEY,
            ]);
        }

        return $issues;
    }

    /**
     * The "Mass payout" select for a method of this type: each driver that
     * type can carry, with its label and the readiness issues of the driver
     * itself. The method's own shape is checked on save.
     *
     * @return array<string, array{label: string, issues: list<string>}>
     */
    public function driverOptions(MethodType|string|null $type): array
    {
        $type    = $type instanceof MethodType ? $type : MethodType::tryFrom((string) $type);
        $labels  = (array) config('remittance.gateways', []);
        $drivers = $type === MethodType::MANUAL ? ['manual'] : ['bitnob', 'stripe_connect'];
        $options = [];

        foreach ($drivers as $driver) {
            $options[$driver] = [
                'label'  => (string) ($labels[$driver] ?? $driver),
                'issues' => array_values($this->gateways->massPayoutIssues($driver)),
            ];
        }

        return $options;
    }

    private function hasUsableRate(WithdrawMethod $method): bool
    {
        $rate = $method->conversion_rate;

        return is_numeric($rate) && DecimalMoney::compare(DecimalMoney::rate($rate), '0', DecimalMoney::RATE_SCALE) > 0;
    }
}
