<?php

namespace App\Services;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\FeeMode;
use App\Enums\FixPctType;
use App\Enums\MethodType;
use App\Enums\Tax\TaxableType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Models\DepositMethod;
use App\Models\Transaction as TransactionModel;
use App\Models\User;
use App\Models\Wallet as WalletModel;
use App\Models\WithdrawMethod;
use App\Services\Handlers\WithdrawHandler;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Tax\TaxEngine;
use App\Services\Tax\TaxQuote;
use App\Support\CustomerPhone;
use App\Support\DecimalMoney;
use App\Support\FeeCalculator;
use App\Support\MobileMoneyPrompt;
use App\Support\Money;
use App\Support\ProviderLimit;
use App\Support\WithdrawFieldNormalizer;
use App\Traits\FileManageTrait;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Log;
use Throwable;
use Transaction;
use Wallet;

class PaymentService
{
    use FileManageTrait;

    protected PaymentGatewayFactory $paymentFactory;

    public function __construct(PaymentGatewayFactory $paymentFactory)
    {
        $this->paymentFactory = $paymentFactory;
    }

    /**
     * Handle deposit via payment method.
     *
     * `$enteredAmount` is denominated in the method's own currency — which is
     * the wallet's, since a method may only serve a wallet of its own currency.
     * The customer therefore types "0.016 BTC", not the base-currency figure
     * that happens to buy it, and the conversion to base happens here, once.
     *
     * `$customerPhone` is the payer's number as the checkout collected it. It
     * is kept only for a gateway that sends it on (CustomerPhone), so a card
     * deposit never stores a number nothing reads.
     *
     * @throws Throwable
     */
    public function depositWithPaymentMethod($paymentMethodId, $enteredAmount, $walletId, ?string $customerPhone = null): array
    {
        DB::beginTransaction();

        try {
            $wallet = WalletModel::with('currency')
                ->where('id', $walletId)
                ->where('user_id', auth()->id())
                ->firstOrFail();
            Wallet::assertOperational($wallet);
            $depositMethod = DepositMethod::findOrFail($paymentMethodId);

            /*
             * A method the administrator has switched off takes no more money.
             *
             * The switch used to be a visibility control only: it hid the
             * method from the deposit screen and stopped nobody who reached
             * this far, so an operator turning off a gateway that was failing
             * went on seeing deposits filed against it. Resolved with a plain
             * findOrFail here, which is what let that happen.
             */
            if (! $depositMethod->status) {
                throw new NotifyErrorException(__('This deposit method is no longer available. Please choose another.'));
            }

            if ($enteredAmount <= 0) {
                throw new NotifyErrorException(__('Amount must be greater than zero.'));
            }

            if ($depositMethod->currency !== $wallet->currency->code) {
                throw new NotifyErrorException(__('The selected payment method is not available for this wallet.'));
            }

            // A deposit that cannot be priced must not be booked.
            Wallet::assertConvertible($wallet->currency);

            // Back to base through the method's own rate, so the figure the
            // customer typed is exactly what `calculateTransactionDetails()`
            // reproduces on the way out: entered / rate * rate == entered.
            $amount = $this->toBaseAmount($enteredAmount, $depositMethod);

            // The configured limits are base-denominated, so they are compared
            // against the converted figure rather than the typed one.
            if (DecimalMoney::compare($amount, (string) $depositMethod->min_deposit) < 0 || DecimalMoney::compare($amount, (string) $depositMethod->max_deposit) > 0) {
                throw new NotifyErrorException(__('Amount must be between :min and :max.', [
                    'min' => Money::trimFee($this->fromBaseAmount($depositMethod->min_deposit, $depositMethod)).' '.$depositMethod->currency,
                    'max' => Money::trimFee($this->fromBaseAmount($depositMethod->max_deposit, $depositMethod)).' '.$depositMethod->currency,
                ]));
            }

            // Use the method's own configured conversion rate, exactly like the
            // withdraw path does. Passing the wallet currency's rate here
            // overrode it, so an admin who set "1 USD = 0.92 EUR" on the method
            // silently had deposits converted at the currency's 0.85 instead.
            $details = $this->withTax($this->calculateTransactionDetails($amount, $depositMethod), TaxableType::Deposit, auth()->user(), $depositMethod);

            // The processor's own floor applies to the invoice it will open,
            // which is the converted total the customer actually pays it.
            ProviderLimit::assert($depositMethod, $details['payableAmount']);

            if ($depositMethod->type === MethodType::MANUAL) {

                $credentials = collect($depositMethod->fields)->map(function ($field) {

                    $credentials = request('credentials');

                    if (isset($credentials[$field['name']]) && is_file($credentials[$field['name']])) {
                        // Handle file upload
                        $field['value'] = $this->uploadPaymentProof($credentials[$field['name']]);
                    } else {
                        // Handle non-file inputs
                        $field['value'] = $credentials[$field['name']] ?? null;
                    }

                    return $field;
                });

                $details['trxData'] = $credentials->toArray();
            }

            $phoneMode     = $depositMethod->customerPhoneMode();
            $customerPhone = CustomerPhone::normalize($customerPhone);

            // A depositor is paying from their own account, so a gateway that
            // cannot start without a number falls back to the one on their
            // profile - the same number every other phone-aware gateway falls
            // back to - rather than refusing an app build that never sends one.
            if ($customerPhone === null && $phoneMode->isRequired()) {
                $customerPhone = CustomerPhone::normalize(auth()->user()?->phone);
            }

            if ($customerPhone === null && $phoneMode->isRequired()) {
                throw new NotifyErrorException(__('Enter the mobile money number the payment should be requested from.'));
            }

            if ($phoneMode->isRequired()) {
                MobileMoneyPrompt::assertMayPrompt($customerPhone);
            }

            if ($customerPhone !== null && $phoneMode->isCollected()) {
                $details['trxData'] = array_merge($details['trxData'] ?? [], ['customer_phone' => $customerPhone]);
            }

            $data = $this->createTransactionData($details, $depositMethod, $wallet, TrxType::DEPOSIT);

            $transaction = Transaction::create($data);
            $gatewayCode = $depositMethod->paymentGateway->code ?? $depositMethod->type->value;

            $paymentGateway = $this->paymentFactory->getGateway($gatewayCode);

            // Bind the transaction to the gateway that is about to be handed
            // the money, before it is. `provider` records the method's display
            // name, which an administrator can edit and reuse; this is the
            // identity settlement checks a callback against.
            $this->bindGateway($transaction, $gatewayCode);

            if ($phoneMode->isRequired()) {
                MobileMoneyPrompt::recordPrompt($customerPhone);
            }

            $redirectUrl = $paymentGateway->deposit($details['payableAmount'], $depositMethod->currency, $transaction->trx_id);

            DB::commit();

            return [$transaction, $redirectUrl];

            /*
             * THROWABLE, not Exception. The transaction above is managed by
             * hand, and an Error is not an Exception - so a TypeError, a
             * failed coercion or a call on null escaped this catch without
             * reaching DB::rollBack(). Measured: the transaction level went
             * in at 1 and came out at 2.
             *
             * Transaction::create() runs before the gateway is resolved, so
             * that left a PENDING deposit row behind for a deposit that never
             * happened. withdrawMoney already caught Throwable.
             */
        } catch (Throwable $e) {

            DB::rollBack();
            Log::error('Deposit failed', ['error' => $e->getMessage()]);

            /*
             * A REFUSAL KEEPS ITS TYPE. This used to rethrow
             * `new Exception($e->getMessage())` unconditionally, so every
             * NotifyErrorException raised above - wrong currency, amount
             * outside the limits, unusable rate, a method the administrator
             * switched off - arrived at the caller as a generic fault. No
             * caller could tell "the customer typed something we will not
             * accept" from "the gateway is down". The web papered over it by
             * flashing whatever message came out; the mobile API could not,
             * because a generic Exception is a 500 and the envelope drops 5xx
             * messages on purpose.
             *
             * withdrawMoney has always done this. This is the deposit half
             * catching up, not a new idea.
             */
            if ($e instanceof NotifyErrorException) {
                throw $e;
            }

            /*
             * And a FAULT does not carry its message out. The old rethrow
             * passed $e->getMessage() along, so a driver error naming an
             * internal host reached whatever the web flashed at the customer.
             * It is logged above, where it belongs.
             *
             * Deliberately NOT converted to NotifyErrorException: that would
             * close the first gap by opening a worse one, telling the API a
             * connection failure is something the customer can fix by
             * retyping. A fault should be a 500 with no details.
             */
            throw new Exception(
                __('The deposit could not be completed. Please try again.'),
                0,
                // Chained, so a tracker still gets the real type and stack
                // while the customer gets the safe sentence. Wrapping without
                // this turns every TypeError into an indistinguishable
                // 'deposit could not be completed' in the error log.
                $e,
            );
        }
    }

    /**
     * Handle withdrawal process.
     *
     * @throws Throwable
     */
    public function withdrawMoney($withdrawAccount, $wallet, $enteredAmount): TransactionModel
    {
        DB::beginTransaction();

        try {
            $withdrawAccount->loadMissing('withdrawMethod.paymentGateway');
            $withdrawMethod = $withdrawAccount->withdrawMethod;

            /*
             * A method the administrator has switched off pays nobody out.
             *
             * WithdrawMethod::active() guarded the picker, the credential
             * fields and account creation - everything except the payout. So
             * switching a method off hid it from customers who did not yet
             * have an account on it and stopped nobody who did, which is the
             * opposite of what an operator ending a gateway contract means by
             * the switch.
             *
             * Withdrawals ALREADY created and awaiting approval are not
             * affected: they settle through TransactionService, which never
             * comes back through here.
             */
            if (! $withdrawMethod?->status) {
                throw new NotifyErrorException(__('This payout method is no longer available. Please choose another.'));
            }
            $wallet = WalletModel::where('id', $wallet->id)
                ->where('user_id', auth()->id())
                ->lockForUpdate()
                ->firstOrFail();
            Wallet::assertOperational($wallet);

            if ($enteredAmount <= 0) {
                throw new NotifyErrorException(__('Amount must be greater than zero.'));
            }

            if ((int) $withdrawAccount->user_id !== (int) auth()->id()) {
                throw new NotifyErrorException(__('Invalid withdrawal account.'));
            }

            // Typed in the payout method's currency, converted to base once so
            // everything downstream keeps working in the ledger's unit.
            $amount = $this->toBaseAmount($enteredAmount, $withdrawMethod);

            // Platform limits are base-denominated, so they are checked here
            // rather than in the controller, where only the typed figure was
            // available and the comparison was between two different units.
            if (! $withdrawMethod->isWithinLimits($amount)) {
                throw new NotifyErrorException(__('Amount must be between :min and :max.', [
                    'min' => Money::trimFee($this->fromBaseAmount($withdrawMethod->min_withdraw, $withdrawMethod)).' '.$withdrawMethod->currency,
                    'max' => Money::trimFee($this->fromBaseAmount($withdrawMethod->max_withdraw, $withdrawMethod)).' '.$withdrawMethod->currency,
                ]));
            }

            // The limits above were checked against the figure typed, in both
            // fee modes: a customer who meets the minimum is not then told
            // their payout minus the fee does not.
            $details = $this->withTax($this->calculateTransactionDetails($amount, $withdrawMethod, feeMode: FeeMode::withdraw()), TaxableType::Withdraw, auth()->user(), $withdrawMethod);

            // The payout the processor is asked to make is the net amount, so
            // that is the figure its own limits apply to.
            ProviderLimit::assert($withdrawMethod, $details['netAmount']);

            $details['trxData'] = $withdrawAccount->credentials;

            // Compared as decimals: both are strings now, and `<` on two
            // numeric strings coerces them to doubles (CR1).
            if (DecimalMoney::compare((string) $wallet->balance, $details['payableAmount']) < 0) {
                throw new NotifyErrorException(__('Insufficient wallet balance.'));
            }

            // Subtract money from wallet
            Wallet::subtractMoney($wallet, $details['payableAmount']);

            $data        = $this->createTransactionData($details, $withdrawMethod, $wallet, TrxType::WITHDRAW);
            $transaction = Transaction::create($data);

            DB::commit();

        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Withdrawal failed', ['error' => $e->getMessage()]);

            if ($e instanceof NotifyErrorException) {
                throw $e;
            }

            throw new NotifyErrorException(__('Withdrawal processing failed. Please try again.'));
        }

        if ($withdrawMethod->type === MethodType::AUTOMATIC) {
            try {
                $this->processAutomaticWithdrawal($transaction, $withdrawMethod, $details);
            } catch (Throwable $e) {
                Log::error('Automatic withdrawal gateway failed', [
                    'transaction_id' => $transaction->trx_id,
                    'gateway'        => $withdrawMethod->paymentGateway?->code,
                    'error'          => $e->getMessage(),
                ]);

                $this->refundFailedAutomaticWithdrawal($transaction, $e->getMessage());

                throw new NotifyErrorException(__('Withdrawal processing failed. The deducted amount has been refunded.'));
            }
        }

        // Notify the admin/user
        if ($transaction->processing_type === MethodType::MANUAL) {
            app(WithdrawHandler::class)->handleSubmitted($transaction);
        }

        return $transaction;
    }

    /**
     * @throws Throwable
     */
    public function paymentWithPaymentMethod($paymentMethodCode, $transaction)
    {

        $depositMethod = DepositMethod::getByCode($paymentMethodCode);
        $amount        = $transaction->amount;

        if ($amount <= 0) {
            throw new Exception(__('Amount must be greater than zero.'));
        }

        // The payer here is a guest of somebody else's invoice, so there is no
        // profile to fall back to: a gateway that charges a phone number gets
        // the one the payer gave, or the payment is not opened at all.
        if ($depositMethod->customerPhoneMode()->isRequired()) {
            $customerPhone = CustomerPhone::normalize($transaction->trx_data['customer_phone'] ?? null);

            if ($customerPhone === null) {
                throw new NotifyErrorException(__('Enter the mobile money number the payment should be requested from.'));
            }

            MobileMoneyPrompt::assertMayPrompt($customerPhone);
            MobileMoneyPrompt::recordPrompt($customerPhone);
        }

        $gatewayCode    = $depositMethod->paymentGateway->code ?? $depositMethod->type;
        $paymentGateway = $this->paymentFactory->getGateway($gatewayCode);

        // A merchant invoice exists before the customer has chosen anything,
        // so the binding is stamped here — the moment a gateway is actually
        // selected and is about to open the payment — rather than at creation.
        $this->bindGateway($transaction, (string) $gatewayCode);

        return $paymentGateway->deposit($transaction->payable_amount, $depositMethod->currency, $transaction->trx_id);
    }

    /**
     * Record which gateway a transaction belongs to.
     *
     * Written once, when the gateway is resolved and the external flow is
     * about to start. A customer who abandons checkout and picks a different
     * method legitimately re-binds: the invoice follows whichever gateway
     * currently holds it, and settling still requires a callback from that
     * gateway.
     */
    /**
     * Books a deposit that has already arrived - an on-chain transfer an
     * add-on's watcher saw land on an address it owns - as a PENDING ledger
     * row for the wallet that address belongs to. Nothing is credited here:
     * the caller completes it through Transaction::completeTransaction() once
     * its own confirmation rules are met, which takes the row lock, the
     * PENDING check and the wallet credit.
     *
     * The customer did not type an amount, so the fee comes out of what
     * arrived instead of going on top: everything received is the payable
     * figure, the method's fee is charged on its base value, and the rest is
     * what the wallet gets. Bounds and the provider floor are the caller's
     * business (money that arrived below them still arrived); a method an
     * administrator switched off, a wallet that cannot take money, or a fee
     * that would swallow the whole transfer is refused, so the caller can
     * hold the transfer for review instead.
     *
     * @param string               $receivedAmount in the method's own currency, as a decimal string
     * @param array<string, mixed> $trxData        stored on the row, e.g. chain, tx hash, log index
     *
     * @throws NotifyErrorException
     */
    public function recordDetectedDeposit(DepositMethod $depositMethod, WalletModel $wallet, string $receivedAmount, array $trxData, string $gatewayCode): TransactionModel
    {
        return DB::transaction(function () use ($depositMethod, $wallet, $receivedAmount, $trxData, $gatewayCode): TransactionModel {
            $wallet->loadMissing(['currency', 'user']);
            Wallet::assertOperational($wallet);

            if (! $depositMethod->status) {
                throw new NotifyErrorException(__('This deposit method is switched off.'));
            }

            if ($depositMethod->currency !== $wallet->currency->code) {
                throw new NotifyErrorException(__('The deposit method and the wallet are in different currencies.'));
            }

            $scale    = Money::decimalsIfKnown($depositMethod->currency) ?? DecimalMoney::CALCULATION_SCALE;
            $received = DecimalMoney::normalize($receivedAmount, $scale);

            if (DecimalMoney::compare($received, '0', $scale) <= 0) {
                throw new NotifyErrorException(__('Amount must be greater than zero.'));
            }

            Wallet::assertConvertible($wallet->currency);

            $rate          = DecimalMoney::rate($depositMethod->conversion_rate ?? 1);
            $grossBase     = $this->toBaseAmount($received, $depositMethod);
            $chargeBase    = DecimalMoney::normalize($depositMethod->calculateCharge($grossBase), DecimalMoney::CALCULATION_SCALE);
            $payableCharge = DecimalMoney::normalize(DecimalMoney::multiply($chargeBase, $rate), $scale);
            $netAmount     = DecimalMoney::subtract($received, $payableCharge, $scale);

            // GF6: nothing was typed to put a tax on top of, so the tax comes
            // out of what arrived exactly as the fee does, worked out on what
            // is left after the fee.
            $tax = $wallet->user !== null && DecimalMoney::compare($netAmount, '0', $scale) > 0
                ? $this->taxes()->quote(TaxableType::Deposit, $wallet->user, (string) $depositMethod->currency, $netAmount, $payableCharge, $rate)
                : TaxQuote::none((string) $depositMethod->currency, $scale);
            $netAmount = DecimalMoney::subtract($netAmount, $tax->total, $scale);

            if (DecimalMoney::compare($netAmount, '0', $scale) <= 0) {
                throw new NotifyErrorException(__('The deposit fee is larger than the amount received.'));
            }

            $details = [
                'amount'         => DecimalMoney::divide($netAmount, $rate),
                'charge'         => $chargeBase,
                'netAmount'      => $netAmount,
                'payableCharge'  => $payableCharge,
                'payableAmount'  => $received,
                'conversionRate' => $rate,
                'trxData'        => $trxData,
                'tax'            => $tax,
            ];

            $transaction = Transaction::create($this->createTransactionData($details, $depositMethod, $wallet, TrxType::DEPOSIT, (int) $wallet->user_id));
            $this->bindGateway($transaction, $gatewayCode);

            return $transaction;
        });
    }

    private function bindGateway(TransactionModel $transaction, string $gatewayCode): void
    {
        $gatewayCode = trim($gatewayCode);

        if ($gatewayCode === '' || (string) $transaction->gateway_code === $gatewayCode) {
            return;
        }

        $transaction->forceFill(['gateway_code' => $gatewayCode])->save();
    }

    public function generateToken($trxId, $minutesValid = 30): string
    {
        // Prepare a payload with transaction ID and expiration
        $payload = [
            'trx_id' => $trxId,
            'exp'    => Carbon::now()->addMinutes($minutesValid)->timestamp,
        ];
        // Convert to JSON
        $jsonPayload = json_encode($payload);

        // Base64-encode
        $base64Payload = base64_encode($jsonPayload);

        // Create an HMAC signature using your app key or another secret key
        $secretKey = config('app.key'); // or a separate key from .env
        $signature = hash_hmac('sha256', $base64Payload, $secretKey);

        // Return final token as "base64Payload.signature"
        return $base64Payload.'.'.$signature;
    }

    /**
     * @throws NotifyErrorException
     */
    public function verifyTokenAndGetData($token)
    {
        // 1. Split the token into payload + signature
        [$base64Payload, $signature] = explode('.', $token);

        // 2. Verify the signature
        $secretKey         = config('app.key');
        $expectedSignature = hash_hmac('sha256', $base64Payload, $secretKey);

        if (! hash_equals($expectedSignature, $signature)) {
            throw new NotifyErrorException(__('Invalid or tampered token.'));
        }

        // 3. Decode the payload
        $payloadJson = base64_decode($base64Payload, true);
        $payload     = json_decode($payloadJson, true);

        // 4. Check expiration
        if (isset($payload['exp']) && $payload['exp'] < now()->timestamp) {
            throw new NotifyErrorException(__('Token has expired.'));
        }

        return $payload;

    }

    /**
     * What a payout of [$entered] would cost, from the same arithmetic that
     * charges it - for a review screen, before anything is filed.
     *
     * @return array{amount: string, charge: string, netAmount: string, payableCharge: string, payableAmount: string, tax: TaxQuote, feeMode: FeeMode, min: string|null, max: string|null, withinLimits: bool}
     */
    public function quoteWithdraw(WithdrawMethod $method, string|int|float $entered, ?User $user = null): array
    {
        $amount  = $this->toBaseAmount($entered, $method);
        $feeMode = FeeMode::withdraw();
        $details = $this->withTax($this->calculateTransactionDetails($amount, $method, feeMode: $feeMode), TaxableType::Withdraw, $user ?? auth()->user(), $method);

        return [
            'amount'        => $details['amount'],
            'charge'        => $details['charge'],
            'netAmount'     => $details['netAmount'],
            'payableCharge' => $details['payableCharge'],
            'payableAmount' => $details['payableAmount'],
            'tax'           => $details['tax'],
            'feeMode'       => $feeMode,
            'min'           => $method->min_withdraw === null ? null : $this->fromBaseAmount($method->min_withdraw, $method),
            'max'           => $method->max_withdraw === null ? null : $this->fromBaseAmount($method->max_withdraw, $method),
            'withinLimits'  => $method->isWithinLimits($amount),
        ];
    }

    /**
     * Convert an amount typed in the method's currency into the base currency.
     *
     * The exact inverse of the `amount * conversion_rate` in
     * {@see calculateTransactionDetails()}, through the same rate, so the
     * figure the customer typed is the figure the processor is asked for.
     *
     * @throws NotifyErrorException when the method has no usable rate
     */
    protected function toBaseAmount($enteredAmount, $method): string
    {
        $rate = DecimalMoney::rate($method->conversion_rate ?? 1);

        if (bccomp($rate, '0', DecimalMoney::RATE_SCALE) <= 0) {
            throw new NotifyErrorException(__('This payment method has no usable conversion rate.'));
        }

        // A decimal string at calculation scale: this is where the typed
        // figure enters the ledger's arithmetic, and a float here was the
        // first of the casts that bound it at `precision` 14 (CR1).
        return DecimalMoney::divide((string) $enteredAmount, $rate);
    }

    /**
     * The other direction, for showing a base-denominated limit in the currency
     * the customer is typing in.
     */
    protected function fromBaseAmount($baseAmount, $method): string
    {
        $rate = DecimalMoney::rate($method->conversion_rate ?? 1);

        if (bccomp($rate, '0', DecimalMoney::RATE_SCALE) <= 0) {
            return (string) $baseAmount;
        }

        return DecimalMoney::multiply((string) $baseAmount, $rate);
    }

    /**
     * Calculate transaction charges and amounts.
     *
     * `$amount` is the figure the customer typed, in base. Under OnTop it is
     * what the processor is asked for and the fee is debited beside it; under
     * Deducted it is what the wallet is debited and the fee comes out of it.
     * Either way `amount` + `charge` (base) and `netAmount` + `payableCharge`
     * (method currency) sum to what leaves the wallet, so every ledger reader
     * sees the same shape in both modes.
     */
    protected function calculateTransactionDetails($amount, $method, ?float $conversionRate = null, FeeMode $feeMode = FeeMode::OnTop)
    {
        // The method resolves its own role-aware fixed + percent components,
        // so the fee is computed in one place instead of being re-derived from
        // a single amount and a type discriminator here.
        $charge = $method->calculateCharge($amount);

        // Carried through to the ledger so the entry records which rate
        // produced these figures. Without it a completed row cannot be
        // reconciled once the rate moves.
        $conversionRate = DecimalMoney::rate($conversionRate ?? $method->conversion_rate);

        // Exact decimal arithmetic, and the exact inverse of toBaseAmount():
        // the entered figure divided by the rate and multiplied back must land
        // on itself, or the processor is asked for a different amount than the
        // customer typed. In floats a satoshi-scale rate does not round-trip.
        //
        // These three are the only figures here denominated in the method's own
        // currency: the wallet is debited `payableAmount` and the processor is
        // invoiced it, so they must land on that currency's precision rather
        // than on the ledger's eight places. A 1% fee on 99.01 USD is 0.9901,
        // and carrying that through made the payable total 100.0001 — which the
        // browser previewed as "100.00" and the balance check then refused as
        // "Insufficient wallet balance" against a wallet holding exactly 100.
        // Anything that did clear the check left a fraction of a cent behind,
        // so a USD wallet could never be emptied. `amount` and `charge` stay at
        // calculation scale: they are the base-denominated ledger figures, and
        // decimal(24,8) is what lets a satoshi-scale movement still reconcile
        // in the reporting currency.
        // A method names its currency in a plain string column, so it can
        // outlive the `currencies` row behind it — which is exactly what
        // `money:audit-precision` reports. Quantizing against the base currency
        // in that case would round a BTC payout to two places and write it away
        // as 0, so an unresolvable currency keeps calculation scale instead:
        // precision that cannot be verified is not discarded.
        $scale = Money::decimalsIfKnown($method->currency);

        /*
         * CUT, not rounded. `normalize` truncates at the given scale;
         * `round` was half-up, so a fee of 0.995 became 1.00 and the customer
         * paid a tenth of a cent more than the percentage they were quoted.
         *
         * The exchange and send money were settled on truncation for exactly
         * that reason - never charge more than is owed, and let the platform
         * absorb the tail - and these two were the last flows rounding the
         * other way. Four money paths, one rule.
         *
         * Nothing is created or destroyed: `payableAmount` below is derived
         * from these two figures, so cutting them reduces what is charged and
         * what is sent by the same amount.
         */
        /*
         * CR1: every figure from here on is a decimal string. Each of these
         * was cast to float, and a float reaching the ledger was bound at
         * `precision` 14, so a 1234567.12345678 BTC withdrawal was booked as
         * 1234567.1234568.
         */
        $amount = DecimalMoney::normalize($amount, DecimalMoney::CALCULATION_SCALE);

        $onMethodScale = fn (string $value): string => $scale === null
            ? DecimalMoney::normalize($value, DecimalMoney::CALCULATION_SCALE)
            : DecimalMoney::normalize($value, $scale);

        $payableCharge = $onMethodScale(DecimalMoney::multiply($charge, $conversionRate));
        $entered       = $onMethodScale(DecimalMoney::multiply($amount, $conversionRate));

        if (! $feeMode->isDeducted()) {
            $netAmount     = $entered;
            $payableAmount = DecimalMoney::add($netAmount, $payableCharge, $scale ?? DecimalMoney::CALCULATION_SCALE);

            return compact('amount', 'charge', 'netAmount', 'payableCharge', 'payableAmount', 'conversionRate', 'entered');
        }

        /*
         * Deducted: the typed figure is the whole debit, the fee is charged on
         * it, and what remains is sent. The fee is still computed on the
         * gross - that is what "20% fee, you receive 80" means everywhere it
         * is offered - and a fee that eats the whole amount is refused rather
         * than filing a payout of nothing.
         *
         * `amount` is re-derived from the net so that the base-denominated
         * pair keeps meaning what it does under OnTop: `amount` is what the
         * processor pays out, `charge` is what the platform keeps.
         */
        $payableAmount = $entered;
        $netAmount     = DecimalMoney::subtract($payableAmount, $payableCharge, $scale ?? DecimalMoney::CALCULATION_SCALE);

        if (DecimalMoney::compare($netAmount, '0') <= 0) {
            throw new NotifyErrorException(__('The fee is larger than the amount. Enter a larger amount.'));
        }

        $amount = DecimalMoney::divide($netAmount, $conversionRate);

        return compact('amount', 'charge', 'netAmount', 'payableCharge', 'payableAmount', 'conversionRate', 'entered');
    }

    /**
     * GF6: the tax on a deposit or a payout, charged on top of what the
     * customer pays in both fee modes - the amount sent or credited and the
     * fee are left as they are, and the payable grows by the tax. Worked out
     * in the method's currency on the net and the fee, at the method's rate.
     *
     * @param  array<string, mixed> $details from calculateTransactionDetails()
     * @return array<string, mixed>
     */
    private function withTax(array $details, TaxableType $type, ?User $user, DepositMethod|WithdrawMethod $method): array
    {
        $currency = (string) $method->currency;

        $details['tax'] = $user === null
            ? TaxQuote::none($currency, Money::decimals($currency))
            : $this->taxes()->quote(
                $type,
                $user,
                $currency,
                DecimalMoney::normalize($details['netAmount']),
                DecimalMoney::normalize($details['payableCharge']),
                (string) $details['conversionRate'],
            );

        if (! $details['tax']->isEmpty()) {
            $details['payableAmount'] = DecimalMoney::add(
                $details['payableAmount'],
                $details['tax']->total,
                Money::decimalsIfKnown($currency) ?? DecimalMoney::CALCULATION_SCALE,
            );
        }

        return $details;
    }

    /**
     * Resolved per use rather than held: this service lives as long as a
     * queue worker, and the rules an administrator edits must not go stale.
     */
    private function taxes(): TaxEngine
    {
        return app(TaxEngine::class);
    }

    /**
     * @deprecated Fees are no longer one type or the other. Prefer
     *             `$method->calculateCharge($amount)`, or FeeCalculator
     *             directly. Retained because customer code may call it.
     *
     * Accepts a string or an enum for `$chargeType` — the two forms are never
     * identical to each other, and comparing them with === is silently false.
     */
    protected function calculateCharge($amount, $charge, $chargeType)
    {
        $isPercent = FixPctType::matches($chargeType, FixPctType::PERCENT);

        return app(FeeCalculator::class)->total(
            $amount,
            fixed: $isPercent ? 0 : $charge,
            percent: $isPercent ? $charge : 0,
        );
    }

    /**
     * Create transaction data object.
     */
    protected function createTransactionData($details, $method, $wallet, $trxType, ?int $userId = null)
    {
        $tax = ($details['tax'] ?? null) instanceof TaxQuote
            ? $details['tax']->columns()
            : ['wallet_tax' => null, 'base_tax' => null, 'tax_breakdown' => null];

        return new TransactionData(
            user_id: $userId ?? auth()->id(),
            trx_type: $trxType,
            amount: $details['amount'],
            amount_flow: $trxType === TrxType::DEPOSIT ? AmountFlow::PLUS : AmountFlow::MINUS,
            fee: $details['charge'],
            // `currency` names the unit `amount` is in, and `amount` here is
            // the base-currency figure the customer typed — `net_amount` and
            // `payable_amount` are the wallet/method side. Stamping the
            // wallet's code was the one place in the ledger where the pair
            // disagreed, and every reader that trusted it divided a base
            // amount by the wallet's rate: a $1,000 BTC deposit reported as
            // $62,500,000 on the admin dashboard.
            currency: siteCurrency(),
            provider: $method->name,
            processing_type: $method->type,
            net_amount: $details['netAmount'],
            payable_amount: $details['payableAmount'],
            payable_currency: $method->currency,
            wallet_reference: $wallet->uuid,
            trx_data: $details['trxData'] ?? null,
            description: __(':type via :method', ['type' => $trxType->value, 'method' => $method->name]),
            status: TrxStatus::PENDING,
            // FX snapshot (IAS 21). `amount` and `fee` here are denominated in
            // the platform's base currency; the wallet side is what actually
            // moves in the user's account.
            exchange_rate: $details['conversionRate'] ?? null,
            base_amount: $details['amount'],
            base_fee: $details['charge'],
            base_currency: siteCurrency(),
            wallet_amount: $details['netAmount'],
            wallet_fee: $details['payableCharge'],
            // `entered` is the typed figure converted out and back through the
            // same rate. A path that does not carry it - a detected deposit,
            // where nothing was typed - falls back to the net, as before.
            entered_amount: $details['entered'] ?? $details['netAmount'],
            entered_currency: $method->currency,
            // GF6: the tax inside payable_amount, apart from the fee, so
            // payable = net + wallet_fee + wallet_tax.
            wallet_tax: $tax['wallet_tax'],
            base_tax: $tax['base_tax'],
            tax_breakdown: $tax['tax_breakdown'],
        );
    }

    private function processAutomaticWithdrawal(TransactionModel $transaction, WithdrawMethod $withdrawMethod, array $details): void
    {
        $gatewayCode = (string) $withdrawMethod->paymentGateway?->code;

        if ($gatewayCode === '') {
            throw new NotifyErrorException(__('Automatic withdrawal gateway is not configured.'));
        }

        $paymentGateway = $this->paymentFactory->getGateway($gatewayCode);

        if (! method_exists($paymentGateway, 'withdraw')) {
            throw new NotifyErrorException(__('This gateway does not support automatic withdrawals.'));
        }

        $credentialValues   = WithdrawFieldNormalizer::values($details['trxData'] ?? []);
        $withdrawCredential = count($credentialValues) === 1 ? reset($credentialValues) : $credentialValues;
        $response           = $paymentGateway->withdraw($details['netAmount'], $withdrawMethod->currency, $transaction->trx_id, $withdrawCredential);

        if (is_array($response)) {
            $status = $this->gatewayWithdrawalStatus($response);

            try {
                $transaction = $this->recordAutomaticWithdrawalResponse($transaction, $gatewayCode, $response);
                $this->applyAutomaticWithdrawalStatus($transaction, $gatewayCode, $response);
            } catch (Throwable $e) {
                if ($this->isFailedGatewayWithdrawalStatus($status)) {
                    throw $e;
                }

                Log::error('Automatic withdrawal response handling failed after gateway accepted request', [
                    'transaction_id' => $transaction->trx_id,
                    'gateway'        => $gatewayCode,
                    'status'         => $status,
                    'error'          => $e->getMessage(),
                ]);
            }
        }
    }

    private function recordAutomaticWithdrawalResponse(TransactionModel $transaction, string $gatewayCode, array $response): TransactionModel
    {
        $reference = data_get($response, 'reference')
            ?? data_get($response, 'transfer.reference')
            ?? data_get($response, 'data.reference');

        $trxData                           = $transaction->fresh()->trx_data ?? [];
        $trxData[$gatewayCode.'_withdraw'] = $response;

        // A payout settles through the same callback chain as a deposit, so it
        // carries the same binding. The provider is already known here; this
        // only writes it down.
        $updates = ['trx_data' => $trxData, 'gateway_code' => $gatewayCode];

        if (is_string($reference) && $reference !== '') {
            $updates['trx_reference'] = $reference;
        }

        $status = $this->gatewayWithdrawalStatus($response);
        if ($status === 'otp') {
            $updates['remarks'] = __('Gateway transfer requires OTP finalization.');
        }

        $transaction->update($updates);

        return $transaction->refresh();
    }

    private function applyAutomaticWithdrawalStatus(TransactionModel $transaction, string $gatewayCode, array $response): void
    {
        $status = $this->gatewayWithdrawalStatus($response);

        if ($status === null || $transaction->status !== TrxStatus::PENDING) {
            return;
        }

        if (in_array($status, ['success', 'successful', 'completed', 'complete', 'paid'], true)) {
            Transaction::completeTransaction(
                $transaction->trx_id,
                __(':gateway withdrawal completed.', ['gateway' => ucfirst($gatewayCode)])
            );

            return;
        }

        if ($this->isFailedGatewayWithdrawalStatus($status)) {
            Transaction::cancelTransaction(
                $transaction->trx_id,
                __(':gateway withdrawal failed with status: :status', ['gateway' => ucfirst($gatewayCode), 'status' => $status]),
                true
            );
        }
    }

    private function gatewayWithdrawalStatus(array $response): ?string
    {
        $status = data_get($response, 'status')
            ?? data_get($response, 'transfer.status')
            ?? data_get($response, 'data.status');

        if (! is_scalar($status)) {
            return null;
        }

        $status = strtolower(trim((string) $status));

        return $status !== '' ? $status : null;
    }

    private function refundFailedAutomaticWithdrawal(TransactionModel $transaction, string $reason): void
    {
        try {
            if ($transaction->fresh()?->status === TrxStatus::PENDING) {
                Transaction::cancelTransaction($transaction->trx_id, $reason, true);
            }
        } catch (Throwable $refundException) {
            Log::critical('Automatic withdrawal refund failed', [
                'transaction_id' => $transaction->trx_id,
                'error'          => $refundException->getMessage(),
            ]);
        }
    }

    private function isFailedGatewayWithdrawalStatus(?string $status): bool
    {
        return in_array($status, ['failed', 'fail', 'reversed', 'rejected', 'blocked', 'abandoned', 'canceled', 'cancelled'], true);
    }
}
