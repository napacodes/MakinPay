<?php

namespace App\Services;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\EnvironmentMode;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Models\Currency;
use App\Models\DepositMethod;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Support\CustomerPhone;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class MerchantCheckoutService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly MerchantService $merchantService,
    ) {}

    /**
     * @param  array<string, mixed>                                                                                            $data
     * @param  array<string, mixed>                                                                                            $context
     * @return array{transaction: Transaction, payment_url: string, info: array<string, mixed>, fee: float, net_amount: float}
     *
     * @throws NotifyErrorException
     */
    public function create(Merchant $merchant, EnvironmentMode $environment, array $data, array $context = []): array
    {
        $merchant->loadMissing(['user', 'currency', 'supportedCurrencies']);

        $currency = Currency::query()
            ->where('code', strtoupper(trim((string) $data['currency_code'])))
            ->first();

        if (! $currency) {
            throw new NotifyErrorException(__('Invalid currency code.'));
        }

        $this->walletService->assertCurrencyOperational($currency);

        if (! $merchant->supportsCurrency((int) $currency->id)) {
            throw new NotifyErrorException(__('Currency is not enabled for this merchant.'));
        }

        $wallet = $this->walletService->getWalletByUserId($merchant->user->id, $currency->code);
        if (! $wallet) {
            throw new NotifyErrorException(__('Receiver wallet for this currency is not available.'));
        }

        $this->walletService->assertOperational($wallet);

        $allowedMethods   = $this->normalizeAllowPaymentMethods($data['allow_payment_methods'] ?? null);
        $gatewaySelection = $this->merchantPaymentMethodSelection($merchant, $currency->code);
        $isSandbox        = $environment->isSandbox();

        $paymentData = array_merge([
            'ref_trx'                             => $data['ref_trx'],
            'description'                         => $data['description'] ?? null,
            'ipn_url'                             => $data['ipn_url'],
            'cancel_redirect'                     => $data['cancel_redirect'],
            'success_redirect'                    => $data['success_redirect'],
            'customer_name'                       => $data['customer_name']  ?? null,
            'customer_email'                      => $data['customer_email'] ?? null,
            'customer_phone'                      => CustomerPhone::normalize($data['customer_phone'] ?? null),
            'merchant_id'                         => $merchant->id,
            'merchant_name'                       => $merchant->business_name,
            'amount'                              => $data['payment_amount'],
            'currency_code'                       => $currency->code,
            'environment'                         => $environment->value,
            'is_sandbox'                          => $isSandbox,
            'allow_payment_methods'               => $allowedMethods,
            'merchant_payment_methods_restricted' => $gatewaySelection['restricted'],
            'merchant_payment_method_ids'         => $gatewaySelection['ids'],
            'merchant_payment_method_codes'       => $gatewaySelection['codes'],
        ], $context);

        [$fee, $netAmount] = PaymentLinkService::merchantFee(
            (float) $data['payment_amount'],
            $merchant->fee,
            $merchant->fee_fixed,
            $merchant->fee_min,
            $merchant->fee_max,
            $currency->code,
        );

        $transaction = DB::transaction(function () use ($merchant, $wallet, $currency, $data, $paymentData, $fee, $netAmount, $isSandbox): Transaction {
            $transaction = Transaction::query()->create((new TransactionData(
                user_id: $merchant->user->id,
                trx_type: TrxType::RECEIVE_PAYMENT,
                amount: $netAmount,
                amount_flow: AmountFlow::PLUS,
                fee: $fee,
                currency: $currency->code,
                net_amount: $netAmount,
                payable_amount: (float) $data['payment_amount'],
                payable_currency: $currency->code,
                wallet_reference: $wallet->uuid,
                trx_data: $paymentData,
                description: $paymentData['description'] ?? __('Payment from :customer', ['customer' => $paymentData['customer_name'] ?? __('Customer')]),
                status: TrxStatus::PENDING,
                exchange_rate: $this->walletService->fxRate($wallet),
            ))->toArray());

            if ($isSandbox) {
                $transaction->forceFill(['remarks' => 'SANDBOX_TRANSACTION'])->save();
            }

            return $transaction;
        });

        $paymentUrl = URL::signedRoute('payment.checkout', [
            'token' => Crypt::encryptString($transaction->trx_id),
        ], now()->addMinutes(900));

        return [
            'transaction' => $transaction,
            'payment_url' => $paymentUrl,
            'info'        => $paymentData,
            'fee'         => $fee,
            'net_amount'  => $netAmount,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeAllowPaymentMethods(null|string|array $input): array
    {
        if ($input === null) {
            return [];
        }

        $items = is_string($input)
            ? (preg_split('/[\s,|]+/', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            : $input;

        $items = array_map(static function (mixed $value): string {
            return Str::limit(Str::lower(trim((string) $value)), 60, '');
        }, $items);

        return array_values(array_unique(array_filter($items, static fn (string $value): bool => $value !== '')));
    }

    /**
     * @return array{restricted: bool, ids: array<int, int>, codes: array<int, string>}
     */
    private function merchantPaymentMethodSelection(Merchant $merchant, string $currencyCode): array
    {
        $merchant->loadMissing('paymentMethods.paymentGateway');

        $restricted = $merchant->paymentMethods->isNotEmpty();
        $methods    = $this->merchantService->configuredPaymentMethodsForCurrency($merchant, $currencyCode)
            ->filter(fn (DepositMethod $method): bool => $method->type === MethodType::AUTOMATIC && (bool) $method->status)
            ->values();

        return [
            'restricted' => $restricted,
            'ids'        => $methods->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'codes'      => $methods->pluck('method_code')->filter()->map(fn ($code): string => (string) $code)->values()->all(),
        ];
    }
}
