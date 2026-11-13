<?php

namespace Database\Seeders;

use App\Enums\FixPctType;
use App\Enums\MethodType;
use App\Models\DepositMethod;
use App\Models\WithdrawMethod;
use Illuminate\Database\Seeder;

/**
 * Seeds manual USDT (TRC20/BEP20) deposit and withdraw methods.
 *
 * Deposit methods ship disabled because the receive payment details contain a
 * placeholder wallet address the admin must replace before enabling. Withdraw
 * methods ship enabled since every request still requires admin approval.
 */
class CryptoManualMethodSeeder extends Seeder
{
    private const array NETWORKS = [
        'trc20' => [
            'label'           => 'TRC20',
            'chain'           => 'TRON (TRC20)',
            'address_hint'    => 'starts with T',
            'withdraw_charge' => 1.00,
            'deposit_details' => 'SET-YOUR-TRC20-WALLET-ADDRESS',
        ],
        'bep20' => [
            'label'           => 'BEP20',
            'chain'           => 'BNB Smart Chain (BEP20)',
            'address_hint'    => 'starts with 0x',
            'withdraw_charge' => 0.50,
            'deposit_details' => 'SET-YOUR-BEP20-WALLET-ADDRESS',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::NETWORKS as $code => $network) {
            $this->seedDepositMethod($code, $network);
            $this->seedWithdrawMethod($code, $network);
        }
    }

    /**
     * @param array{label: string, chain: string, address_hint: string, withdraw_charge: float, deposit_details: string} $network
     */
    private function seedDepositMethod(string $code, array $network): void
    {
        DepositMethod::query()->updateOrCreate(
            ['method_code' => "usdt-{$code}-manual"],
            [
                'payment_gateway_id'   => null,
                'logo'                 => 'general/static/default/payment-gateway.png',
                'name'                 => "USDT ({$network['label']})",
                'type'                 => MethodType::MANUAL,
                'currency'             => 'USDT',
                'currency_symbol'      => '₮',
                'min_deposit'          => 10.00,
                'max_deposit'          => 10000.00,
                'conversion_rate_live' => false,
                'conversion_rate'      => 1.00,
                'charge_type'          => FixPctType::PERCENT->value,
                'charge'               => 0.00,
                'user_charge'          => 0.00,
                'user_charge_type'     => FixPctType::PERCENT->value,
                'merchant_charge'      => 0.00,
                'merchant_charge_type' => FixPctType::PERCENT->value,
                'fields'               => [
                    [
                        'name'       => 'transaction_hash',
                        'type'       => 'text',
                        'validation' => 'required',
                    ],
                    [
                        'name'       => 'sender_wallet_address',
                        'type'       => 'text',
                        'validation' => 'nullable',
                    ],
                ],
                'receive_payment_details' => $this->depositDetailsHtml($network),
                'status'                  => false,
            ]
        );
    }

    /**
     * @param array{label: string, chain: string, address_hint: string, withdraw_charge: float, deposit_details: string} $network
     */
    private function seedWithdrawMethod(string $code, array $network): void
    {
        WithdrawMethod::query()->updateOrCreate(
            ['method_code' => "usdt-{$code}-manual"],
            [
                'payment_gateway_id'   => null,
                'logo'                 => 'general/static/default/payment-gateway.png',
                'name'                 => "USDT ({$network['label']})",
                'type'                 => MethodType::MANUAL,
                'currency'             => 'USDT',
                'currency_symbol'      => '₮',
                'min_withdraw'         => 20.00,
                'max_withdraw'         => 5000.00,
                'conversion_rate_live' => false,
                'conversion_rate'      => 1.00,
                'charge_type'          => FixPctType::FIXED->value,
                'charge'               => $network['withdraw_charge'],
                'user_charge'          => $network['withdraw_charge'],
                'user_charge_type'     => FixPctType::FIXED->value,
                'merchant_charge'      => $network['withdraw_charge'],
                'merchant_charge_type' => FixPctType::FIXED->value,
                'process_time_value'   => 12,
                'process_time_unit'    => 'hour',
                'fields'               => [
                    [
                        'name'        => 'wallet_address',
                        'type'        => 'text',
                        'label'       => "USDT Wallet Address ({$network['label']})",
                        'placeholder' => "Your {$network['chain']} address ({$network['address_hint']})",
                        'validation'  => 'required',
                    ],
                ],
                'status' => true,
            ]
        );
    }

    /**
     * @param array{label: string, chain: string, address_hint: string, withdraw_charge: float, deposit_details: string} $network
     */
    private function depositDetailsHtml(array $network): string
    {
        return <<<HTML
<p><strong>Send USDT only on the {$network['chain']} network.</strong> Funds sent on any other network will be lost.</p>
<p>Deposit address: <strong>{$network['deposit_details']}</strong></p>
<p>After sending, submit the transaction hash below. Your deposit will be credited after admin confirmation.</p>
HTML;
    }
}
