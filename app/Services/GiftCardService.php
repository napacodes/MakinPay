<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\CurrencyRole;
use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Mail\GiftCardDelivered;
use App\Models\GiftCard;
use App\Models\GiftCardTemplate;
use App\Models\Transaction as TransactionModel;
use App\Models\User;
use App\Models\Wallet as WalletModel;
use App\Services\Handlers\GiftCardHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Transaction;
use Wallet;

/**
 * Buying and redeeming gift cards - for the website and the app alike.
 *
 * Buying debits the sender's wallet for the amount plus the fee and writes the
 * card in one transaction; the debit is a conditional update, so a wallet that
 * runs dry between the balance check and the write refuses rather than going
 * negative.
 *
 * Redeeming credits only a wallet in the card's own currency, and claims the
 * card before crediting anyone. The website credited the card's amount into
 * whichever wallet was posted - 100 of a card's currency into a wallet of any
 * other - and checked the card, then wrote it unconditionally, so a second
 * request that had read it in between credited it again.
 */
class GiftCardService
{
    public function __construct(
        private readonly GiftCardHandler $handler,
        private readonly WalletService $wallets,
    ) {}

    /**
     * @param float|int|string                                                                                                                                                  $amount  In the site currency, as the gift card form takes it.
     * @param array{recipient_mode?: string, recipient_name: string, recipient_email: string, sender_name: string, message?: ?string, schedule?: mixed, scheduled_at?: ?string} $details
     *
     * @throws NotifyErrorException
     */
    public function issue(User $user, WalletModel $wallet, GiftCardTemplate $template, float|int|string $amount, array $details): GiftCard
    {
        if ((int) $wallet->user_id !== (int) $user->id) {
            throw new NotifyErrorException(__('That wallet does not belong to you.'));
        }

        $wallet->loadMissing('currency.roles');

        $this->wallets->assertOperational($wallet, CurrencyRole::GIFT_CARD);

        $amount = (float) $amount;
        $role   = $this->resolveRole($wallet);
        $fee    = Wallet::calculateFeeByRole($wallet, $amount, $role);

        $netAmount     = Wallet::conversionAmount($wallet, $amount);
        $payableAmount = Wallet::conversionAmount($wallet, $amount + $fee);

        if (! Wallet::isWalletBalanceSufficient($wallet->uuid, $payableAmount)) {
            throw new NotifyErrorException(__('Insufficient balance in the selected wallet.'));
        }

        Wallet::validateAmountLimitByRole($wallet, $amount, $role);

        $recipientUserId = null;
        if (($details['recipient_mode'] ?? 'email') === 'user') {
            $recipientUserId = User::where('email', $details['recipient_email'])->value('id');
        }

        $scheduledAt = (! empty($details['schedule']) && ! empty($details['scheduled_at']))
            ? $details['scheduled_at']
            : null;

        $giftCard = DB::transaction(function () use ($user, $wallet, $template, $amount, $fee, $netAmount, $payableAmount, $details, $recipientUserId, $scheduledAt): GiftCard {
            $charge = Transaction::create(new TransactionData(
                user_id: $user->id,
                trx_type: TrxType::GIFT_CARD,
                amount: $amount,
                amount_flow: AmountFlow::MINUS,
                fee: $fee,
                currency: siteCurrency(),
                net_amount: $netAmount,
                payable_amount: $payableAmount,
                payable_currency: $wallet->currency->code,
                wallet_reference: $wallet->uuid,
                description: __('Gift Card issued from :wallet wallet', ['wallet' => $wallet->name]),
                status: TrxStatus::COMPLETED,
                // The rate that produced net_amount and payable_amount.
                exchange_rate: Wallet::fxRate($wallet),
            ));

            Wallet::subtractMoney($wallet, $payableAmount);

            /*
             * Initial status is "pending" — the email has been queued
             * but the recipient hasn't opened the card yet. The
             * transition to "delivered" happens inside preview() the
             * first time a NON-sender lands on the public preview URL.
             */
            $card = GiftCard::create([
                'gift_card_template_id' => $template->id,
                'user_id'               => $user->id,
                'currency_id'           => $wallet->currency->id,
                // What cancelling gives back: this charge, fee and all.
                'issue_transaction_id' => $charge->id,
                'amount'               => $netAmount,
                'recipient_name'       => $details['recipient_name'],
                'recipient_email'      => $details['recipient_email'],
                'recipient_user_id'    => $recipientUserId,
                'sender_name'          => $details['sender_name'],
                'message'              => $details['message'] ?? null,
                'delivery_method'      => 'email',
                'scheduled_at'         => $scheduledAt,
                'delivered_at'         => null,
                'status'               => $scheduledAt ? 'scheduled' : 'pending',
                'is_active'            => true,
            ]);

            $template->increment('used_count');

            return $card;
        });

        if ($giftCard->status === 'pending') {
            try {
                Mail::to($giftCard->recipient_email)->queue(new GiftCardDelivered($giftCard));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $giftCard;
    }

    /**
     * @param string $code As typed - spaces and underscores read as hyphens, any case.
     *
     * @throws NotifyErrorException
     */
    public function redeem(User $user, string $code, int $walletId): TransactionModel
    {
        $code = strtoupper(str_replace([' ', '_'], '-', trim($code)));

        return DB::transaction(function () use ($user, $code, $walletId): TransactionModel {
            $giftCard = GiftCard::query()->where('code', $code)->lockForUpdate()->first();

            if (! $giftCard) {
                throw new NotifyErrorException(__('Gift card not found.'));
            }

            if ((int) $giftCard->user_id === (int) $user->id) {
                throw new NotifyErrorException(__('You cannot redeem your own gift card.'));
            }

            if (! $giftCard->canBeRedeemed()) {
                throw new NotifyErrorException(__('Gift card is invalid, expired, or already redeemed.'));
            }

            $wallet = WalletModel::query()
                ->with('currency')
                ->where('id', $walletId)
                ->where('user_id', $user->id)
                ->first();

            if (! $wallet) {
                throw new NotifyErrorException(__('Selected wallet is not available. Please pick a valid wallet.'));
            }

            // The card is worth its amount in its own currency, and only there.
            if ((int) $wallet->currency_id !== (int) $giftCard->currency_id) {
                throw new NotifyErrorException(__('This gift card can only be redeemed into a wallet in its own currency.'));
            }

            // The claim: only a card that is still redeemable flips, so of two
            // requests for the same code only one gets past here.
            $claimed = GiftCard::query()
                ->whereKey($giftCard->id)
                ->where('is_active', true)
                ->whereNull('redeemed_at')
                ->whereIn('status', ['pending', 'delivered'])
                ->update([
                    'is_active'          => false,
                    'status'             => 'redeemed',
                    'redeemed_by'        => $user->id,
                    'redeemed_wallet_id' => $wallet->id,
                    'redeemed_at'        => now(),
                ]);

            if ($claimed !== 1) {
                throw new NotifyErrorException(__('Gift card is invalid, expired, or already redeemed.'));
            }

            $trx = Transaction::create(new TransactionData(
                user_id: $user->id,
                trx_type: TrxType::GIFT_CARD_REDEEM,
                amount: (float) $giftCard->amount,
                amount_flow: AmountFlow::PLUS,
                fee: 0,
                currency: $wallet->currency->code,
                net_amount: (float) $giftCard->amount,
                payable_amount: (float) $giftCard->amount,
                payable_currency: $wallet->currency->code,
                wallet_reference: $wallet->uuid,
                description: __('Gift Card redeemed (:code)', ['code' => $giftCard->code]),
                status: TrxStatus::COMPLETED,
                exchange_rate: Wallet::fxRate($wallet),
            ));

            $trx->gift_card_code = $giftCard->code;

            $this->handler->handleSuccess($trx);

            return $trx;
        });
    }

    /**
     * Cancel a card nobody has redeemed and give the sender back what issuing
     * it took: the card's amount and the fee, into the wallet they came out
     * of. A card issued before its charge was recorded gets its value back
     * only, into the sender's wallet in its currency - nothing says which
     * ledger entry charged it, and a guess is not a refund.
     *
     * Cancelling used to flip the status and nothing else, so the sender's
     * money simply vanished. The card is locked and claimed conditionally, the
     * same way redeeming claims it, so of a cancel and a redeem racing for one
     * card exactly one wins, and a repeated cancel refunds once.
     *
     * @param list<string> $cancellableStatuses What the caller may cancel: the sender only an undelivered card, an administrator a delivered one too.
     *
     * @throws NotifyErrorException
     */
    public function cancel(GiftCard $giftCard, array $cancellableStatuses): TransactionModel
    {
        return DB::transaction(function () use ($giftCard, $cancellableStatuses): TransactionModel {
            $card = GiftCard::query()->with('currency')->whereKey($giftCard->id)->lockForUpdate()->first();

            if (! $card || ! $card->is_active || $card->redeemed_at !== null || ! in_array($card->status, $cancellableStatuses, true)) {
                throw new NotifyErrorException(__('This gift card can no longer be cancelled.'));
            }

            $charge = $card->issue_transaction_id
                ? TransactionModel::query()
                    ->whereKey($card->issue_transaction_id)
                    ->where('user_id', $card->user_id)
                    ->where('trx_type', TrxType::GIFT_CARD)
                    ->first()
                : null;

            $wallet = $this->refundWallet($card, $charge);

            if (! $wallet) {
                throw new NotifyErrorException(__('The sender has no wallet in this gift card\'s currency to refund into.'));
            }

            $claimed = GiftCard::query()
                ->whereKey($card->id)
                ->where('is_active', true)
                ->whereNull('redeemed_at')
                ->whereIn('status', $cancellableStatuses)
                ->update([
                    'status'    => 'cancelled',
                    'is_active' => false,
                ]);

            if ($claimed !== 1) {
                throw new NotifyErrorException(__('This gift card can no longer be cancelled.'));
            }

            $refund = $charge ? (float) $charge->payable_amount : (float) $card->amount;
            $code   = $wallet->currency->code;

            $trx = Transaction::create(new TransactionData(
                user_id: (int) $card->user_id,
                trx_type: TrxType::REFUND,
                amount: $refund,
                amount_flow: AmountFlow::PLUS,
                fee: 0,
                currency: $code,
                net_amount: $refund,
                payable_amount: $refund,
                payable_currency: $code,
                wallet_reference: $wallet->uuid,
                trx_reference: $charge?->trx_id,
                trx_data: array_filter([
                    'source'          => 'gift_card_cancel',
                    'gift_card_id'    => $card->id,
                    'refunded_trx_id' => $charge?->trx_id,
                    'fee_refunded'    => $charge !== null,
                ], fn ($value): bool => $value !== null),
                description: __('Refund for cancelled gift card'),
                status: TrxStatus::COMPLETED,
                exchange_rate: Wallet::fxRate($wallet),
            ));

            $this->wallets->creditSettlementByWalletUuid($wallet->uuid, $refund);

            return $trx;
        });
    }

    /**
     * The wallet the issue charged, while the sender still holds it; else
     * the sender's wallet in the card's currency - the same currency, as a
     * card is issued in its paying wallet's.
     */
    private function refundWallet(GiftCard $card, ?TransactionModel $charge): ?WalletModel
    {
        $paying = $charge?->wallet_reference
            ? WalletModel::query()->with('currency')->where('uuid', $charge->wallet_reference)->where('user_id', $card->user_id)->first()
            : null;

        if ($paying) {
            return $paying;
        }

        return $card->currency
            ? WalletModel::query()->with('currency')->where('user_id', $card->user_id)->where('currency_id', $card->currency_id)->first()
            : null;
    }

    private function resolveRole(WalletModel $wallet): string
    {
        $hasGiftCardRole = $wallet->currency
            ?->roles
            ?->where('role_name', CurrencyRole::GIFT_CARD)
            ?->where('is_active', true)
            ?->isNotEmpty();

        return $hasGiftCardRole ? CurrencyRole::GIFT_CARD : CurrencyRole::VOUCHER;
    }
}
