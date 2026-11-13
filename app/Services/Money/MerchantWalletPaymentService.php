<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\EnvironmentMode;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\MerchantPaymentFailed;
use App\Exceptions\NotifyErrorException;
use App\Exceptions\PayerBalanceShort;
use App\Models\Merchant;
use App\Models\Transaction as TransactionModel;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\Handlers\PaymentHandler;
use App\Services\WalletService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Transaction;
use Wallet;

/**
 * Paying a merchant's checkout out of a customer's wallet.
 *
 * Lifted out of MerchantPaymentReceiveController, which is a thousand lines
 * holding four different flows. This is the one that moves money, and it is
 * the one a phone needs; the checkout page, the gateway hand-off and the
 * voucher path stay where they are.
 *
 * TWO KINDS OF NO, and the difference is the whole reason this has its own
 * exception. A NotifyErrorException means the attempt never started - the
 * checkout is still open and the customer can try again. A
 * MerchantPaymentFailed means the checkout has been closed: failTransaction
 * has run and the merchant has been told. The controller expressed that
 * difference as `throw` versus `return $this->handleFailure(...)`, which only
 * reads as a difference if you already know one returns a redirect.
 *
 * The money movement is unchanged and is still one DB::transaction. The
 * merchant's credit is PaymentHandler::handleSuccess's, via
 * Transaction::completeTransaction on the checkout row - which is also where
 * the guard against settling the same checkout twice lives.
 */
final class MerchantWalletPaymentService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletPinGuard $pins,
        private readonly FeatureManager $features,
    ) {}

    /**
     * Everything that has to be true before a payer's PIN is worth checking.
     *
     * Read by the API's read endpoint as well as by the payment itself, so a
     * customer is not asked for a PIN against a checkout that was never going
     * to be payable.
     *
     * @throws NotifyErrorException
     */
    public function assertPayable(TransactionModel $checkout): void
    {
        if ($checkout->status !== TrxStatus::PENDING) {
            throw new NotifyErrorException(__('Invalid or already processed transaction.'));
        }

        // The web checkout page refused an expired checkout; the API did not.
        if ($checkout->isExpired()) {
            throw new NotifyErrorException(__('This payment has expired.'));
        }
    }

    /**
     * The merchant behind a checkout row.
     *
     * @throws NotifyErrorException
     */
    public function merchantFor(TransactionModel $checkout): Merchant
    {
        $merchant = Merchant::find($checkout->trx_data['merchant_id'] ?? null);

        if ($merchant === null) {
            throw new NotifyErrorException(__('Invalid or already processed transaction.'));
        }

        return $merchant;
    }

    /**
     * Whether this payer is allowed to use merchant payment at all.
     *
     * The web's logged-in path skips this - X16 - while its wallet-id path
     * applies it. Applied here so no caller can forget, which is what a shared
     * service is for.
     *
     * @throws NotifyErrorException
     */
    public function assertPayerMayPay(User $payer): void
    {
        $reason = $this->features->denialReason('merchant_payment', 'user', $payer);

        if ($reason === null) {
            return;
        }

        throw new NotifyErrorException(match ($reason) {
            FeatureManager::DENIED_KYC_REQUIRED    => __('Please complete your KYC verification to access this feature.'),
            FeatureManager::DENIED_PHONE_REQUIRED  => __('Please enable phone verification to access this feature.'),
            FeatureManager::DENIED_COUNTRY_BLOCKED => __('This feature is not available in your country.'),
            default                                => __('This feature is currently unavailable.'),
        }, 403);
    }

    /**
     * Detect environment mode from transaction data or merchant configuration.
     */
    public function environmentFor(TransactionModel $checkout): EnvironmentMode
    {
        if (isset($checkout->trx_data['environment'])) {
            return EnvironmentMode::from($checkout->trx_data['environment']);
        }

        if (isset($checkout->trx_data['is_sandbox'])) {
            return $checkout->trx_data['is_sandbox'] ? EnvironmentMode::SANDBOX : EnvironmentMode::PRODUCTION;
        }

        $merchant = Merchant::find($checkout->trx_data['merchant_id'] ?? null);

        if ($merchant && $merchant->sandbox_enabled) {
            if (str_contains($checkout->remarks ?? '', 'SANDBOX_TRANSACTION')) {
                return EnvironmentMode::SANDBOX;
            }
        }

        return EnvironmentMode::PRODUCTION;
    }

    public function currencyFor(TransactionModel $checkout): string
    {
        return (string) ($checkout->payable_currency ?: $checkout->currency);
    }

    /**
     * Pay it.
     *
     * The order is the controller's, unchanged: state, self-payment, currency
     * match, balance, merchant wallet, both wallets operational, then the
     * money. Each check that closes the checkout does so by the same route it
     * always did - failTransaction plus the merchant's cancel page - and now
     * says so by throwing MerchantPaymentFailed rather than by returning a
     * redirect only a browser could use.
     *
     * $answerable is the account whose standing the administrator's rule for
     * merchant payment (KYC, phone, country) is read against, when that is
     * not the payer's own. A child's card pays out of the child's own wallet,
     * but a child has no verification of their own: the parent's covers
     * them, so the rule is the parent's to meet. Every other caller leaves it
     * out and the payer answers for themselves, as before.
     *
     * POS6: `$keepOpenWhenShort` is the till's ask. A wallet that cannot
     * cover the payment then throws PayerBalanceShort and leaves the checkout
     * pending for another try, instead of failing it; every other caller
     * leaves it out and the checkout fails as before.
     *
     * @throws NotifyErrorException
     * @throws PayerBalanceShort
     * @throws MerchantPaymentFailed
     * @throws Throwable
     */
    public function pay(User $payer, mixed $payerWallet, TransactionModel $checkout, Merchant $merchant, ?User $answerable = null, bool $keepOpenWhenShort = false): TransactionModel
    {
        $environment = $this->environmentFor($checkout);
        $isSandbox   = $environment->isSandbox();

        $this->assertPayable($checkout);

        /*
         * Applied here rather than left to the caller, because a caller that
         * forgets is exactly what happened: the web's wallet-id path checks
         * this and its logged-in path does not, so the same administrator rule
         * held for a guest typing a wallet number and not for the same person
         * signed in. That was X16. Putting it in front of the money makes it
         * impossible for any caller, including the next one, to skip.
         */
        $this->assertPayerMayPay($answerable ?? $payer);

        if ($payer->id === $checkout->user_id) {
            $this->closeCheckout(
                $checkout,
                __('You cannot pay to yourself.'),
                __('A payment attempt via User Wallet from :merchant customer has failed', ['merchant' => $merchant->business_name]),
            );
        }

        $payableAmount = $checkout->payable_amount;
        $currencyCode  = $this->currencyFor($checkout);

        // Resolved by the caller, because the two web entry points resolve it
        // differently - one from the signed-in payer, one from a wallet id the
        // customer typed - and each refuses a missing wallet in its own words
        // before it ever gets here.
        if (! $payerWallet || $payerWallet->currency?->code !== $currencyCode) {
            throw new NotifyErrorException(__('Wallet currency does not match the payment currency.'));
        }

        if (! Wallet::isWalletBalanceSufficient($payerWallet->uuid, $payableAmount)) {
            /*
             * POS6: a till asks for the checkout to stay open, so the customer
             * can show a new code or scan the shop's QR; nothing has moved.
             * Hosted checkout keeps failing the checkout, as its cancel page
             * and the merchant's IPN expect.
             */
            if ($keepOpenWhenShort) {
                throw new PayerBalanceShort(__('Insufficient balance'));
            }

            $this->closeCheckout($checkout, __('Insufficient balance'));
        }

        $merchantWallet = Wallet::getWalletByUserId($checkout->user_id, $currencyCode);

        if (! $merchantWallet) {
            $this->closeCheckout($checkout, __('Merchant wallet for this currency is not available.'));
        }

        $this->wallets->assertOperational($payerWallet);
        $this->wallets->assertOperational($merchantWallet);

        try {
            $completionDescription = $isSandbox
                ? __('SANDBOX: You have received a payment via User Wallet from :merchant customer', ['merchant' => $merchant->business_name])
                : __('You have received a payment via User Wallet from :merchant customer', ['merchant' => $merchant->business_name]);

            /*
             * The debit and the settlement are one unit, under a lock on the
             * checkout row. Before, the payer was debited in one transaction
             * and the checkout completed in the next - and completion quietly
             * does nothing for a checkout that is no longer pending. So a
             * second payer of the same QR, or a merchant's cancel landing in
             * between, left a customer debited with nothing credited. Now the
             * row is locked and read again first; whoever comes second finds
             * it settled and nothing of theirs moves.
             */
            DB::transaction(function () use ($checkout, $payerWallet, $payableAmount, $payer, $merchant, $environment, $currencyCode, $completionDescription): void {
                $locked = TransactionModel::query()->whereKey($checkout->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    throw new NotifyErrorException(__('Invalid or already processed transaction.'));
                }

                $this->assertPayable($locked);

                // Cast explicitly. `payable_amount` is a decimal column and
                // comes back as a string; this file declares strict types, so
                // the coercion is written down. The stored value is unchanged.
                $this->debitPayer($payerWallet, (float) $payableAmount, $payer, $merchant, $environment, $currencyCode);

                // Before completing it: the business's "payment received"
                // is written while it completes, and read "Payer" when the
                // name came only afterwards.
                $this->recordPayer($locked, $payer);

                Transaction::completeTransaction($checkout->trx_id, null, $completionDescription);
            });

            Log::info('Payment completed successfully', [
                'transaction_id' => $checkout->trx_id,
                'merchant_id'    => $merchant->id,
                'user_id'        => $payer->id,
                'amount'         => $payableAmount,
                'currency'       => $currencyCode,
                'environment'    => $environment->value,
                'is_sandbox'     => $isSandbox,
            ]);

            return $checkout->refresh();
        } catch (MerchantPaymentFailed $failure) {
            throw $failure;
        } catch (NotifyErrorException $refused) {
            // Settled, cancelled or expired while this payment was on its way:
            // nothing moved, and the checkout is not this attempt's to close.
            throw $refused;
        } catch (Exception $e) {
            Log::error('Transaction process failed', [
                'transaction_id' => $checkout->trx_id,
                'environment'    => $environment->value,
                'is_sandbox'     => $isSandbox,
                'error'          => $e->getMessage(),
            ]);

            $this->closeCheckout(
                $checkout,
                __('Transaction failed'),
                __('A payment attempt via User Wallet from :merchant customer has failed', ['merchant' => $merchant->business_name]),
            );
        }
    }

    /**
     * Write who paid onto the checkout.
     *
     * A hosted checkout carries the name the customer typed, but a counter
     * charge or a scanned link has nothing until the wallet pays it, and
     * the merchant's takings then listed every wallet payment as a guest.
     * The signed-in payer is known here and nowhere later, so it is kept
     * on the row: the name only when none was captured, the id always.
     */
    private function recordPayer(TransactionModel $checkout, User $payer): void
    {
        $data = is_array($checkout->trx_data) ? $checkout->trx_data : [];

        $data['payer_user_id'] = (int) $payer->id;

        if (! is_string($data['customer_name'] ?? null) || trim($data['customer_name']) === '') {
            $data['customer_name'] = (string) $payer->name;
        }

        $checkout->trx_data = $data;
        $checkout->save();
    }

    /**
     * Check the PIN, with a counter behind it.
     *
     * Its own scope, so a wrong guess here does not spend a customer's
     * withdraw or send-money allowance and vice versa - the same compromise
     * H03 made, and the same one X14 is about.
     *
     * @throws NotifyErrorException
     */
    public function assertPin(User $payer, ?string $pin): void
    {
        if (! $payer->hasWalletPin()) {
            throw new NotifyErrorException(__('Please set a Wallet PIN in settings before authorising payments.'));
        }

        $this->pins->assert($payer, $pin, 'merchant-payment-pin');
    }

    /**
     * The payer's side of the ledger, atomically. The merchant's credit is
     * completeTransaction's, on the checkout row.
     *
     * @throws Throwable
     */
    private function debitPayer(
        mixed $payerWallet,
        float $payableAmount,
        User $payer,
        Merchant $merchant,
        EnvironmentMode $environment,
        string $currencyCode,
    ): void {
        DB::transaction(function () use ($payerWallet, $payableAmount, $payer, $merchant, $environment, $currencyCode): void {
            $paymentHandler = app(PaymentHandler::class);

            $isSandbox = $environment->isSandbox();

            $description = $isSandbox
                ? __('SANDBOX: Payment to :merchant via your wallet', ['merchant' => $merchant->business_name])
                : __('Payment to :merchant via your wallet', ['merchant' => $merchant->business_name]);

            $transaction = Transaction::create(new TransactionData(
                user_id: $payer->id,
                trx_type: TrxType::PAYMENT,
                amount: $payableAmount,
                amount_flow: AmountFlow::MINUS,
                currency: $currencyCode,
                net_amount: $payableAmount,
                payable_amount: $payableAmount,
                payable_currency: $currencyCode,
                wallet_reference: $payerWallet->uuid,
                description: $description,
                status: TrxStatus::COMPLETED,
                remarks: $isSandbox ? 'SANDBOX_TRANSACTION' : null,
                // Whom it was paid to, for the customer's own notification
                // and receipt, which otherwise read "Merchant".
                trx_data: [
                    'merchant_id'   => $merchant->id,
                    'merchant_name' => $merchant->business_name,
                ],
                exchange_rate: Wallet::fxRate($payerWallet),
            ));

            $paymentHandler->handleSuccess($transaction);
        });
    }

    /**
     * Close the checkout: fail it, tell the merchant, and say where the
     * customer belongs next.
     *
     * @throws MerchantPaymentFailed
     */
    private function closeCheckout(TransactionModel $checkout, string $errorMessage, ?string $description = null): never
    {
        $environment = $this->environmentFor($checkout);

        Log::error('Payment transaction failed', [
            'transaction_id' => $checkout->trx_id ?? 'unknown',
            'environment'    => $environment->value,
            'is_sandbox'     => $environment->isSandbox(),
            'error_message'  => $errorMessage,
            'description'    => $description,
        ]);

        Transaction::failTransaction($checkout->trx_id, $errorMessage, $description);

        throw new MerchantPaymentFailed(
            $checkout,
            $checkout->trx_data['cancel_redirect'] ?? route('status.cancel'),
            $errorMessage,
        );
    }
}
