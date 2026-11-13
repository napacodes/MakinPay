<?php

namespace Database\Seeders;

use App\Constants\CurrencyType;
use App\Enums\FixPctType;
use App\Enums\MethodType;
use App\Models\Currency;
use App\Models\DepositMethod;
use App\Models\PaymentGateway;
use App\Models\WithdrawMethod;
use Illuminate\Database\Seeder;

/**
 * Seeds the automatic Bitnob stablecoin deposit capability: the USDT and USDC
 * currencies (auto-wallet), one deposit method per coin/chain pair, and the
 * USDT auto-payout withdraw method.
 *
 * The legacy `bitnob_usdt` code stays the USDT/TRC20 deposit method so existing
 * installs upgrade in place. BitnobPaymentGateway maps each method code to the
 * Bitnob chain when generating the on-chain deposit address.
 */
class BitnobStablecoinMethodSeeder extends Seeder
{
    /**
     * @var array<string, array{symbol: string, methods: array<string, string>}>
     */
    private const COINS = [
        'USDT' => [
            'symbol'  => '₮',
            'methods' => [
                'erc20'   => 'bitnob_usdt',
                'polygon' => 'bitnob-usdt-polygon',
                'base'    => 'bitnob-usdt-base',
                'solana'  => 'bitnob-usdt-solana',
            ],
        ],
        'USDC' => [
            'symbol'  => '$',
            'methods' => [
                'erc20'   => 'bitnob-usdc-erc20',
                'polygon' => 'bitnob-usdc-polygon',
                'base'    => 'bitnob-usdc-base',
                'solana'  => 'bitnob-usdc-solana',
            ],
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const NETWORK_LABELS = [
        'erc20'   => 'ERC20',
        'polygon' => 'Polygon',
        'base'    => 'Base',
        'solana'  => 'Solana',
        'trc20'   => 'TRC20',
        'bep20'   => 'BEP20',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $gateway = PaymentGateway::query()->where('code', 'bitnob')->first();

        if (! $gateway) {
            return;
        }

        foreach (self::COINS as $code => $coin) {
            $this->ensureCurrency($code, $coin['symbol']);

            foreach ($coin['methods'] as $network => $methodCode) {
                $this->seedDepositMethod($gateway, $code, $coin['symbol'], $network, $methodCode);
            }
        }

        $this->seedAutoWithdrawMethod($gateway);
    }

    private function ensureCurrency(string $code, string $symbol): void
    {
        Currency::query()->firstOrCreate(
            ['code' => $code],
            [
                'name'          => $code === 'USDC' ? 'USD Coin' : 'Tether',
                'symbol'        => $symbol,
                'type'          => CurrencyType::CRYPTO,
                'exchange_rate' => 1,
                'rate_live'     => false,
                'auto_wallet'   => true,
                'default'       => false,
                'status'        => true,
            ],
        );
    }

    private function seedDepositMethod(PaymentGateway $gateway, string $code, string $symbol, string $network, string $methodCode): void
    {
        DepositMethod::query()->updateOrCreate(
            ['method_code' => $methodCode],
            [
                'payment_gateway_id'      => $gateway->id,
                'logo'                    => $gateway->logo ?: 'general/static/default/payment-gateway.png',
                'name'                    => "{$code} ({$this->networkLabel($network)}) Instant",
                'type'                    => MethodType::AUTOMATIC,
                'currency'                => $code,
                'currency_symbol'         => $symbol,
                'min_deposit'             => 10.00,
                'max_deposit'             => 10000.00,
                'conversion_rate_live'    => false,
                'conversion_rate'         => 1.00,
                'charge_type'             => FixPctType::PERCENT->value,
                'charge'                  => 1.00,
                'user_charge'             => 1.00,
                'user_charge_type'        => FixPctType::PERCENT->value,
                'merchant_charge'         => 0.50,
                'merchant_charge_type'    => FixPctType::PERCENT->value,
                'fields'                  => [],
                'receive_payment_details' => '',
                'status'                  => (bool) $gateway->status,
            ]
        );
    }

    private function seedAutoWithdrawMethod(PaymentGateway $gateway): void
    {
        WithdrawMethod::query()->updateOrCreate(
            ['method_code' => 'bitnob-usdt-auto'],
            [
                'payment_gateway_id'   => $gateway->id,
                'logo'                 => $gateway->logo ?: 'general/static/default/payment-gateway.png',
                'name'                 => 'USDT Auto Payout',
                'type'                 => MethodType::AUTOMATIC,
                'currency'             => 'USDT',
                'currency_symbol'      => '₮',
                'min_withdraw'         => 20.00,
                'max_withdraw'         => 500.00,
                'conversion_rate_live' => false,
                'conversion_rate'      => 1.00,
                'charge_type'          => FixPctType::FIXED->value,
                'charge'               => 1.00,
                'user_charge'          => 1.00,
                'user_charge_type'     => FixPctType::FIXED->value,
                'merchant_charge'      => 1.00,
                'merchant_charge_type' => FixPctType::FIXED->value,
                'process_time_value'   => 30,
                'process_time_unit'    => 'minute',
                'fields'               => [
                    [
                        'name'        => 'wallet_address',
                        'type'        => 'text',
                        'label'       => 'USDT Wallet Address',
                        'placeholder' => 'Your USDT wallet address',
                        'validation'  => 'required',
                    ],
                    [
                        'name'       => 'network',
                        'type'       => 'select',
                        'label'      => 'Network',
                        'validation' => 'required',
                        'options'    => [
                            'trc20' => 'TRON (TRC20)',
                            'bep20' => 'BNB Smart Chain (BEP20)',
                        ],
                    ],
                ],
                'status' => (bool) $gateway->status,
            ]
        );
    }

    private function networkLabel(string $network): string
    {
        return self::NETWORK_LABELS[$network] ?? strtoupper($network);
    }
}
