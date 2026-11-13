<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\CurrencyRole;
use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Models\Transaction as TransactionModel;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Wallet as WalletModel;
use App\Services\Handlers\VoucherHandler;
use Illuminate\Support\Facades\DB;
use Transaction;
use Wallet;

/**
 * Issuing and redeeming vouchers - for the website and the app alike.
 *
 * Issuing debits the issuer's wallet for the amount plus the voucher fee and
 * writes the voucher, in one transaction; the debit is a conditional update,
 * so a wallet that runs dry between the balance check and the write refuses
 * rather than going negative.
 *
 * Redeeming claims the voucher before crediting anyone. The claim is a
 * conditional update on a still-unredeemed row, so of two requests racing for
 * the same code exactly one credits: the website used to check the voucher,
 * then write it unconditionally, and a second request that had read it in
 * between credited the same voucher again.
 */
class VoucherService
{
    public function __construct(
        private readonly VoucherHandler $handler,
        private readonly WalletService $wallets,
    ) {}

    /**
     * @param float|int|string $amount In the site currency, as the voucher form takes it.
     *
     * @throws NotifyErrorException
     */
    public function issue(User $user, WalletModel $wallet, float|int|string $amount): Voucher
    {
        // The caller scopes the wallet to the customer; this does not rely on it.
        if ((int) $wallet->user_id !== (int) $user->id) {
            throw new NotifyErrorException(__('The selected wallet is unavailable.'));
        }

        $this->wallets->assertOperational($wallet, CurrencyRole::VOUCHER);

        // A form posts the amount as text. The ledger's TransactionData takes a
        // float, which the controller this came from converted by coercion; the
        // callers bound the input to the currency's precision first.
        $amount = (float) $amount;

        $walletUuid = $wallet->uuid;

        $fee         = Wallet::calculateFeeByRole($wallet, $amount, CurrencyRole::VOUCHER);
        $totalAmount = $amount + $fee;

        // Convert amounts to wallet’s base currency
        $netAmount     = Wallet::conversionAmount($wallet, $amount);
        $payableAmount = Wallet::conversionAmount($wallet, $totalAmount);

        if (! Wallet::isWalletBalanceSufficient($walletUuid, $payableAmount)) {
            throw new NotifyErrorException(__('Insufficient balance.'));
        }

        Wallet::validateAmountLimitByRole($wallet, $amount, CurrencyRole::VOUCHER);

        return DB::transaction(function () use ($user, $walletUuid, $wallet, $amount, $fee, $netAmount, $payableAmount): Voucher {
            Transaction::create(new TransactionData(
                user_id: $user->id,
                trx_type: TrxType::VOUCHER,
                amount: $amount,
                amount_flow: AmountFlow::MINUS,
                fee: $fee,
                currency: siteCurrency(),
                net_amount: $netAmount,
                payable_amount: $payableAmount,
                payable_currency: $wallet->currency->code,
                wallet_reference: $walletUuid,
                description: __('Voucher issued from :wallet wallet', ['wallet' => $wallet->name]),
                status: TrxStatus::COMPLETED,
                // The rate that produced net_amount and payable_amount.
                exchange_rate: Wallet::fxRate($wallet),
            ));

            Wallet::subtractMoney($wallet, $payableAmount);

            return Voucher::create([
                'user_id'            => $user->id,
                'amount'             => $netAmount,
                'currency_id'        => $wallet->currency->id,
                'is_active'          => true,
                'redeemed_by'        => null,
                'redeemed_wallet_id' => null,
                'redeemed_at'        => null,
            ]);
        });
    }

    /**
     * @throws NotifyErrorException
     */
    public function redeem(User $user, string $code): TransactionModel
    {
        return DB::transaction(function () use ($user, $code): TransactionModel {
            $voucher = Voucher::query()->where('code', $code)->lockForUpdate()->first();

            if (! $voucher) {
                throw new NotifyErrorException(__('Voucher not found.'));
            }

            if ((int) $voucher->user_id === (int) $user->id) {
                throw new NotifyErrorException(__('You are not allowed to redeem your own voucher.'));
            }

            if (! $voucher->isValid()) {
                throw new NotifyErrorException(__('Voucher is invalid or has already been redeemed.'));
            }

            $wallet = WalletModel::query()
                ->where('user_id', $user->id)
                ->where('currency_id', $voucher->currency_id)
                ->first();

            if (! $wallet) {
                throw new NotifyErrorException(__('Wallet not found.'));
            }

            // The claim: only a row that is still unredeemed flips, so of two
            // requests for the same code only one gets past here.
            $claimed = Voucher::query()
                ->whereKey($voucher->id)
                ->where('is_active', true)
                ->whereNull('redeemed_at')
                ->update([
                    'is_active'          => false,
                    'redeemed_by'        => $wallet->user_id,
                    'redeemed_wallet_id' => $wallet->id,
                    'redeemed_at'        => now(),
                ]);

            if ($claimed !== 1) {
                throw new NotifyErrorException(__('Voucher is invalid or has already been redeemed.'));
            }

            $trx = Transaction::create(new TransactionData(
                user_id: $user->id,
                trx_type: TrxType::VOUCHER,
                amount: $voucher->amount,
                amount_flow: AmountFlow::PLUS,
                fee: 0,
                currency: $wallet->currency->code,
                net_amount: $voucher->amount,
                payable_amount: $voucher->amount,
                payable_currency: $wallet->currency->code,
                wallet_reference: $wallet->uuid,
                description: __('Voucher redeemed from :voucher Voucher Code', ['voucher' => $voucher->code]),
                status: TrxStatus::COMPLETED,
                exchange_rate: Wallet::fxRate($wallet),
            ));

            $trx->voucher_code = $voucher->code;

            $this->handler->handleSuccess($trx);

            return $trx;
        });
    }
}
