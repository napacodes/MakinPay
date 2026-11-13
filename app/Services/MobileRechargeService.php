<?php

namespace App\Services;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\MethodType;
use App\Enums\MobileRechargeStatus;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Exceptions\ProviderOutcomeUnknownException;
use App\Models\MobileRecharge;
use App\Models\MobileRechargeProvider;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\MobileRecharge\MobileRechargeProviderManager;
use App\Services\MobileRecharge\MobileRechargeProviderResult;
use App\Services\MobileRecharge\MobileRechargeQuote;
use App\Support\DecimalMoney;
use App\Support\FeeCalculator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Throwable;

class MobileRechargeService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly TransactionService $transactions,
        private readonly MobileRechargeProviderManager $providers,
    ) {}

    /**
     * What a catalogue plan costs the customer, in their wallet's currency.
     *
     * A provider prices a plan in its own sender currency (Reloadly: the
     * account's currency) and charges the platform exactly that. The plan
     * used to be charged as if that figure were already in the wallet's
     * currency - a 1.24 USD bundle paid from a BDT wallet cost 1.24 BDT - so
     * the figure is converted at the site's rates, and the provider is later
     * asked for the plan's own sender amount, unchanged.
     *
     * @param  array<string, mixed>                                                                        $plan
     * @return array{amount: string, currency: string, provider_amount: string, provider_currency: string}
     *
     * @throws NotifyErrorException when the plan's currency cannot be converted into the wallet's
     */
    public function planCharge(array $plan, string $walletCurrency): array
    {
        $providerAmount   = (string) ($plan['amount'] ?? '0');
        $providerCurrency = strtoupper((string) ($plan['currency'] ?? ''));
        $walletCurrency   = strtoupper($walletCurrency);

        $rate = $providerCurrency === '' ? null : app(CurrencyService::class)->crossRate($providerCurrency, $walletCurrency);

        if ($rate === null || ! is_numeric($providerAmount) || DecimalMoney::compare($providerAmount, '0') <= 0) {
            throw new NotifyErrorException(__('This bundle cannot be paid from a :currency wallet.', ['currency' => $walletCurrency]));
        }

        return [
            'amount'            => Money::quantize(DecimalMoney::multiply($providerAmount, $rate, DecimalMoney::CALCULATION_SCALE), $walletCurrency),
            'currency'          => $walletCurrency,
            'provider_amount'   => $providerAmount,
            'provider_currency' => $providerCurrency,
        ];
    }

    /**
     * @throws NotifyErrorException
     */
    public function quote(float $amount, string $currency, bool $fromPlan = false): MobileRechargeQuote
    {
        $provider = $this->providers->resolveActiveProvider();

        if (! $fromPlan) {
            $this->ensureAmountWithinLimits($amount, $provider);
        }

        $fee = $provider
            ? $provider->calculateFee($amount)
            : $this->calculateLegacyFee($amount);

        return new MobileRechargeQuote(
            amount: round($amount, 8),
            fee: $fee,
            total: round($amount + $fee, 8),
            currency: $currency,
        );
    }

    private function calculateLegacyFee(float $amount): float
    {
        ['fixed' => $fixed, 'percent' => $percent] = $this->feeRule(null);

        return app(FeeCalculator::class)->total($amount, $fixed, $percent);
    }

    /**
     * The amount limits a recharge is held to - the provider's, or the site
     * settings' when no provider is configured. Zero means no limit.
     *
     * @return array{min: float, max: float}
     */
    public function limits(?MobileRechargeProvider $provider): array
    {
        if ($provider) {
            return [
                'min' => (float) ($provider->min_amount ?? 0),
                'max' => $provider->max_amount !== null ? (float) $provider->max_amount : 0.0,
            ];
        }

        return [
            'min' => (float) setting('mobile_recharge_min_amount', config('mobile_services.recharge.min_amount', 10)),
            'max' => (float) setting('mobile_recharge_max_amount', config('mobile_services.recharge.max_amount', 10000)),
        ];
    }

    /**
     * The fee a recharge is charged - the provider's, or the site settings'
     * when no provider is configured.
     *
     * @return array{fixed: mixed, percent: mixed}
     */
    public function feeRule(?MobileRechargeProvider $provider): array
    {
        if ($provider) {
            return ['fixed' => $provider->fee_fixed, 'percent' => $provider->fee_percent];
        }

        return [
            'fixed'   => setting('mobile_recharge_fee_fixed', config('mobile_services.recharge.fee_fixed', 0)),
            'percent' => setting('mobile_recharge_fee_percent', config('mobile_services.recharge.fee_percent', 0)),
        ];
    }

    /**
     * Refuses a wallet currency or a country the active provider does not
     * recharge - the check recharge() makes, for a quote to make first.
     *
     * @throws NotifyErrorException
     */
    public function assertSupported(string $currency, ?string $country = null): void
    {
        $this->ensureProviderSupports($this->providers->resolveActiveProvider(), $currency, $country);
    }

    /**
     * @throws NotifyErrorException
     */
    /**
     * @param array<string, mixed> $metadata what the app chose - a detected operator id, a plan id - kept on the recharge for the provider and the receipt
     */
    public function recharge(User $user, Wallet $wallet, string $phoneNumber, float $amount, ?string $operator = null, ?string $country = null, array $metadata = []): MobileRecharge
    {
        $this->ensureRechargeAllowed($user, $wallet);
        // A bundle is priced by the provider's catalogue; the airtime limits
        // are for an amount the customer typed.
        $fromPlan = isset($metadata['plan_id']);

        $provider = $this->providers->resolveActiveProvider();

        $this->ensureProviderSupports($provider, $wallet->currency->code, $country);

        $quote = $this->quote($amount, $wallet->currency->code, $fromPlan);

        if (! $this->wallets->isWalletBalanceSufficient($wallet->uuid, $quote->total)) {
            throw new NotifyErrorException(__('Insufficient balance.'));
        }

        $providerCode = $provider?->code ?? $this->providers->providerCode();

        [$recharge, $transaction] = DB::transaction(function () use ($user, $wallet, $phoneNumber, $operator, $country, $quote, $providerCode, $metadata): array {
            $this->wallets->assertOperational($wallet);
            $this->wallets->subtractMoney($wallet, $quote->total);

            $recharge = MobileRecharge::query()->create([
                'user_id'      => $user->id,
                'wallet_id'    => $wallet->id,
                'phone_number' => $this->normalizePhoneNumber($phoneNumber),
                'operator'     => $operator,
                'country'      => $country ? strtoupper($country) : null,
                'amount'       => $quote->amount,
                'fee'          => $quote->fee,
                'total_amount' => $quote->total,
                'currency'     => $quote->currency,
                'provider'     => $providerCode,
                'status'       => MobileRechargeStatus::PENDING,
                'metadata'     => $metadata,
            ]);

            $transaction = $this->transactions->create(new TransactionData(
                user_id: $user->id,
                trx_type: TrxType::MOBILE_RECHARGE,
                amount: $quote->amount,
                amount_flow: AmountFlow::MINUS,
                fee: $quote->fee,
                currency: $quote->currency,
                provider: $providerCode,
                processing_type: MethodType::AUTOMATIC,
                net_amount: $quote->amount,
                payable_amount: $quote->total,
                payable_currency: $quote->currency,
                wallet_reference: $wallet->uuid,
                trx_data: [
                    'mobile_recharge_id' => $recharge->id,
                    'phone_number'       => $recharge->phone_number,
                    'operator'           => $recharge->operator,
                    'country'            => $recharge->country,
                ],
                description: __('Mobile recharge for :phone', ['phone' => $recharge->phone_number]),
                status: TrxStatus::PENDING,
                exchange_rate: \Wallet::fxRate($quote->currency),
            ));

            $recharge->update(['transaction_id' => $transaction->id]);

            return [$recharge->refresh(), $transaction->refresh()];
        }, 3);

        try {
            $result = $this->providers->activeProvider()->recharge($recharge->load('transaction'));

            return $this->applyProviderResult($recharge, $transaction, $result);
        } catch (ProviderOutcomeUnknownException $e) {
            // MR1: a timeout, a dropped connection or a 5xx after the request
            // may have left. The airtime may have been delivered, so nothing
            // is refunded and nothing is retried: the debit stays held and the
            // recharge waits, flagged, for the outcome to be established.
            report($e);

            return $this->holdForUnknownOutcome($recharge, $transaction, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->failAndRefund($recharge, $transaction, $e->getMessage());
        }
    }

    /**
     * MR1: the provider may or may not have delivered. The recharge stays
     * PROCESSING and its transaction PENDING - the money held, nothing
     * refunded - with the reason on the row for the operator.
     */
    private function holdForUnknownOutcome(MobileRecharge $recharge, Transaction $transaction, string $reason): MobileRecharge
    {
        DB::transaction(function () use ($recharge, $transaction, $reason): void {
            $lockedTransaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();
            $lockedRecharge    = MobileRecharge::query()->whereKey($recharge->id)->lockForUpdate()->first();

            if ($lockedTransaction?->status !== TrxStatus::PENDING || $lockedRecharge === null || $this->isSettled($lockedRecharge)) {
                return;
            }

            $lockedRecharge->update([
                'status'   => MobileRechargeStatus::PROCESSING,
                'metadata' => array_merge((array) $lockedRecharge->metadata, [
                    'outcome_unknown'        => true,
                    'outcome_unknown_reason' => $reason,
                    'outcome_unknown_at'     => now()->toIso8601String(),
                ]),
            ]);

            $lockedTransaction->update([
                'remarks' => __('Waiting for the provider to confirm whether the recharge was delivered.'),
            ]);
        }, 3);

        return $recharge->refresh();
    }

    private function isSettled(MobileRecharge $recharge): bool
    {
        return in_array($recharge->status, [MobileRechargeStatus::COMPLETED, MobileRechargeStatus::FAILED], true);
    }

    /**
     * @throws NotifyErrorException
     */
    private function ensureRechargeAllowed(User $user, Wallet $wallet): void
    {
        if ((int) $wallet->user_id !== (int) $user->id) {
            throw new NotifyErrorException(__('The selected wallet is invalid.'));
        }

        $this->wallets->assertOperational($wallet);
    }

    /**
     * @throws NotifyErrorException
     */
    private function ensureAmountWithinLimits(float $amount, ?MobileRechargeProvider $provider = null): void
    {
        ['min' => $min, 'max' => $max] = $this->limits($provider);

        if ($min > 0 && $amount < $min) {
            throw new NotifyErrorException(__('Amount must be at least :min.', ['min' => $min]));
        }

        if ($max > 0 && $amount > $max) {
            throw new NotifyErrorException(__('Amount must not exceed :max.', ['max' => $max]));
        }
    }

    /**
     * @throws NotifyErrorException
     */
    private function ensureProviderSupports(?MobileRechargeProvider $provider, string $currency, ?string $country): void
    {
        if (! $provider) {
            return;
        }

        if (! $provider->supportsCurrency($currency)) {
            throw new NotifyErrorException(__('The selected provider does not support the wallet currency :currency.', [
                'currency' => $currency,
            ]));
        }

        if ($country && ! $provider->supportsCountry($country)) {
            throw new NotifyErrorException(__('The selected provider does not support recharge in :country.', [
                'country' => strtoupper($country),
            ]));
        }
    }

    private function applyProviderResult(
        MobileRecharge $recharge,
        Transaction $transaction,
        MobileRechargeProviderResult $result
    ): MobileRecharge {
        if ($result->status === MobileRechargeStatus::FAILED) {
            return $this->failAndRefund($recharge, $transaction, $result->message ?? __('Mobile recharge failed.'));
        }

        $transactionStatus = $result->status === MobileRechargeStatus::COMPLETED
            ? TrxStatus::COMPLETED
            : TrxStatus::PENDING;

        DB::transaction(function () use ($recharge, $transaction, $result, $transactionStatus): void {
            // Decided under the lock: a recharge another path has already
            // settled is not overwritten.
            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();
            $recharge    = MobileRecharge::query()->whereKey($recharge->id)->lockForUpdate()->first();

            if ($transaction?->status !== TrxStatus::PENDING || $recharge === null || $this->isSettled($recharge)) {
                return;
            }

            $recharge->update([
                'status'             => $result->status,
                'provider_reference' => $result->reference,
                'failure_reason'     => null,
                'metadata'           => array_merge((array) $recharge->metadata, $result->payload),
                'processed_at'       => $result->status === MobileRechargeStatus::COMPLETED ? now() : null,
            ]);

            $transaction->update([
                'status'        => $transactionStatus,
                'trx_reference' => $result->reference,
                'remarks'       => $result->message,
            ]);
        }, 3);

        return $recharge->refresh();
    }

    /**
     * MR1: refunds once. The recharge and its transaction are re-read under
     * a lock and refunded only while the transaction is still PENDING and
     * the recharge unsettled; it used to decide on the models it was handed,
     * so two failures - or a failure and a later provider answer - could
     * each credit the wallet.
     */
    private function failAndRefund(MobileRecharge $recharge, Transaction $transaction, string $reason): MobileRecharge
    {
        DB::transaction(function () use ($recharge, $transaction, $reason): void {
            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();
            $recharge    = MobileRecharge::query()->whereKey($recharge->id)->lockForUpdate()->first();

            if ($transaction?->status !== TrxStatus::PENDING || $recharge === null || $this->isSettled($recharge)) {
                return;
            }

            $this->wallets->creditSettlementByWalletUuid($transaction->wallet_reference, (float) $transaction->payable_amount);

            $this->transactions->create(new TransactionData(
                user_id: (int) $transaction->user_id,
                trx_type: TrxType::REFUND,
                amount: (float) $transaction->payable_amount,
                amount_flow: AmountFlow::PLUS,
                fee: 0,
                currency: $transaction->payable_currency ?? $transaction->currency,
                provider: 'system',
                processing_type: MethodType::AUTOMATIC,
                net_amount: (float) $transaction->payable_amount,
                payable_amount: (float) $transaction->payable_amount,
                payable_currency: $transaction->payable_currency ?? $transaction->currency,
                wallet_reference: $transaction->wallet_reference,
                trx_reference: $transaction->trx_id,
                trx_data: [
                    'refunded_trx_id' => $transaction->trx_id,
                    'source'          => 'mobile_recharge',
                ],
                remarks: $reason,
                description: __('Refund for failed mobile recharge'),
                status: TrxStatus::COMPLETED,
                exchange_rate: \Wallet::fxRate($transaction->payable_currency ?? $transaction->currency),
            ));

            $recharge->update([
                'status'         => MobileRechargeStatus::FAILED,
                'failure_reason' => $reason,
                'processed_at'   => now(),
            ]);

            $transaction->update([
                'status'  => TrxStatus::FAILED,
                'remarks' => $reason,
            ]);
        }, 3);

        return $recharge->refresh();
    }

    private function normalizePhoneNumber(string $phoneNumber): string
    {
        return preg_replace('/[^\d+]/', '', $phoneNumber) ?? $phoneNumber;
    }
}
