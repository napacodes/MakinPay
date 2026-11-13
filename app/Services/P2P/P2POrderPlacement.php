<?php

declare(strict_types=1);

namespace App\Services\P2P;

use App\Enums\P2P\OrderSide;
use App\Exceptions\NotifyErrorException;
use App\Models\P2P\Offer;
use App\Models\P2P\Order;
use App\Models\P2P\PaymentAccount;
use App\Models\User;
use App\Services\SubscriptionFeatureService;
use Illuminate\Support\Carbon;

/**
 * Taking a P2P trade ad - for the website and the app alike.
 *
 * The taker picks one of their own payment accounts; this pairs it with the
 * advertiser's account for the same method - who pays from where, and into
 * where - and hands both to P2POrderService::createFromOffer, which locks the
 * ad, checks the pairing again inside its transaction and holds the seller's
 * escrow. The customer's plan caps how many trades they may take a month.
 */
class P2POrderPlacement
{
    public function __construct(
        private readonly P2POrderService $orders,
        private readonly SubscriptionFeatureService $subscriptionFeatures,
    ) {}

    /**
     * Why the customer's plan will not let them take another trade, or null.
     */
    public function tradeQuotaRefusal(User $user): ?string
    {
        if (! $this->subscriptionFeatures->canUse($user, 'p2p_trading')) {
            return __('P2P trading requires Pro or Business access. Browse offers freely, then upgrade to buy or sell.');
        }

        $limit = $this->subscriptionFeatures->getLimit($user, 'p2p_trade_limit');

        if ($limit === null) {
            return null;
        }

        if ($limit === 0) {
            return __('P2P trading requires Pro or Business access. Browse offers freely, then upgrade to buy or sell.');
        }

        $monthlyTrades = Order::query()
            ->where('taker_id', $user->id)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->count();

        if ($monthlyTrades >= $limit) {
            return __('You have hit your monthly P2P trade limit. Upgrade your plan for higher quotas.');
        }

        return null;
    }

    /**
     * @throws NotifyErrorException
     */
    public function place(User $taker, Offer $offer, int $paymentAccountId, float|int|string $amount): Order
    {
        $offer->loadMissing(['wallet.currency', 'paymentMethods', 'user']);

        $selectedAccount = PaymentAccount::query()
            ->with('paymentMethod')
            ->where('id', $paymentAccountId)
            ->where('user_id', $taker->id)
            ->first();

        if (! $selectedAccount) {
            throw new NotifyErrorException(__('Invalid payment account selected for this trade.'));
        }

        $paymentMethod = $selectedAccount->paymentMethod;

        if (! $paymentMethod || ! (bool) $paymentMethod->status) {
            throw new NotifyErrorException(__('The selected payment method is not available right now.'));
        }

        $payerAccount    = null;
        $receiverAccount = null;

        if ($offer->side === OrderSide::SELL) {
            $allowedMethodIds = $offer->paymentMethods
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! in_array((int) $selectedAccount->payment_method_id, $allowedMethodIds, true)) {
                throw new NotifyErrorException(__('This seller does not accept the selected payment method.'));
            }

            $payerAccount    = $selectedAccount;
            $receiverAccount = PaymentAccount::query()
                ->with('paymentMethod')
                ->where('user_id', (int) $offer->user_id)
                ->where('payment_method_id', (int) $selectedAccount->payment_method_id)
                ->first();

            if (! $receiverAccount) {
                throw new NotifyErrorException(__('The seller payment details for this method are not available anymore.'));
            }
        } else {
            $receiverAccount = $selectedAccount;

            if ($offer->paymentMethods->isNotEmpty()) {
                $allowedMethodIds = $offer->paymentMethods
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (! in_array((int) $selectedAccount->payment_method_id, $allowedMethodIds, true)) {
                    throw new NotifyErrorException(__('This buyer does not support the selected payment method.'));
                }

                $payerAccount = PaymentAccount::query()
                    ->with('paymentMethod')
                    ->where('user_id', (int) $offer->user_id)
                    ->where('payment_method_id', (int) $selectedAccount->payment_method_id)
                    ->first();

                if (! $payerAccount) {
                    throw new NotifyErrorException(__('The buyer payment account for this method is not available anymore.'));
                }
            }
        }

        return $this->orders->createFromOffer(
            offer: $offer,
            takerId: (int) $taker->id,
            amount: (float) $amount,
            paymentMethodId: (int) $paymentMethod->id,
            payerPaymentAccountId: $payerAccount?->id,
            receiverPaymentAccountId: $receiverAccount?->id,
            remarks: __('Payment method: :name', ['name' => (string) $paymentMethod->name]),
        );
    }
}
