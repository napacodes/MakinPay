<?php

declare(strict_types=1);

namespace App\Services\P2P;

use App\Constants\CurrencyType;
use App\Enums\P2P\OfferStatus;
use App\Enums\P2P\OrderSide;
use App\Enums\P2P\OrderStatus;
use App\Exceptions\NotifyErrorException;
use App\Models\P2P\Offer;
use App\Models\P2P\Order as P2POrder;
use App\Models\P2P\PaymentAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\SubscriptionFeatureService;

/**
 * Publishing, pausing and withdrawing a customer's P2P trade ads - for the
 * website and the app alike.
 *
 * An ad is backed by one of the customer's active crypto wallets, priced in
 * an active fiat, and lists only payment methods the customer has saved an
 * account for - a buyer must have somewhere to pay. The customer's plan caps
 * how many ads they may run. An ad with an open trade cannot be paused,
 * resumed or deleted.
 */
class P2POfferService
{
    public function __construct(
        private readonly SubscriptionFeatureService $subscriptionFeatures,
        private readonly P2POfferMarketplace $marketplace,
        private readonly P2PMarketPrice $prices,
    ) {}

    /**
     * Why the customer's plan will not let them publish another ad, or null.
     */
    public function adQuotaRefusal(User $user): ?string
    {
        if (! $this->subscriptionFeatures->canUse($user, 'p2p_trading')) {
            return __('Creating P2P trade ads requires Pro or Business access.');
        }

        $limit = $this->subscriptionFeatures->getLimit($user, 'p2p_ad_limit');

        if ($limit === null) {
            return null;
        }

        if ($limit === 0) {
            return __('Creating P2P trade ads requires Pro or Business access.');
        }

        $activeAdsCount = Offer::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [OfferStatus::ACTIVE->value, OfferStatus::PAUSED->value])
            ->count();

        if ($activeAdsCount >= $limit) {
            return __('You have reached your plan\'s P2P ad limit. Upgrade your plan or remove an existing ad to create a new one.');
        }

        return null;
    }

    /**
     * @param array{wallet_id: int|string, quote_currency_id: int|string, side: string, price: mixed, min_amount: mixed, max_amount?: mixed, payment_method_ids?: array<int, int|string>, terms?: ?string, payment_window_minutes?: mixed} $validated
     *
     * @throws NotifyErrorException
     */
    public function create(User $user, array $validated): Offer
    {
        $side             = OrderSide::from($validated['side']);
        $paymentMethodIds = collect($validated['payment_method_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($paymentMethodIds->isEmpty()) {
            throw new NotifyErrorException($side === OrderSide::SELL
                ? __('Sell trade ads must include at least one accepted payment method.')
                : __('Buy trade ads must include at least one preferred payment method.'));
        }

        $savedMethodIds = PaymentAccount::query()
            ->where('user_id', $user->id)
            ->pluck('payment_method_id')
            ->map(fn ($id) => (int) $id);

        if ($paymentMethodIds->diff($savedMethodIds)->isNotEmpty()) {
            throw new NotifyErrorException($side === OrderSide::SELL
                ? __('Add your saved payment account details for each selected payment method before publishing a sell ad.')
                : __('Add your saved payment account details for each preferred payment method before publishing a buy ad.'));
        }

        $wallet = $this->tradableWallet($user, (int) $validated['wallet_id']);

        if (! $wallet) {
            throw new NotifyErrorException(__('P2P trade ads support crypto wallets only. Choose an active crypto wallet to continue.'));
        }

        $offer = Offer::create([
            'user_id'                => $user->id,
            'wallet_id'              => $wallet->id,
            'quote_currency_id'      => (int) $validated['quote_currency_id'],
            'side'                   => $side,
            'price'                  => (float) $validated['price'],
            'price_type'             => $validated['price_type'] ?? Offer::PRICE_FIXED,
            'margin_pct'             => isset($validated['margin_pct']) ? (float) $validated['margin_pct'] : null,
            'min_amount'             => (float) $validated['min_amount'],
            'max_amount'             => isset($validated['max_amount']) ? (float) $validated['max_amount'] : null,
            'status'                 => OfferStatus::ACTIVE,
            'payment_window_minutes' => (int) ($validated['payment_window_minutes'] ?? (int) setting('p2p_order_expiry_minutes', 45)),
            'terms'                  => $validated['terms'] ?? null,
        ]);

        $offer->paymentMethods()->sync($paymentMethodIds->all());

        $this->float($offer);

        return $offer;
    }

    /**
     * Change an ad's price, limits, window, terms or methods. Refused while a
     * trade is open on it, as pausing and deleting are; the app shows why.
     *
     * @param array{price?: mixed, price_type?: ?string, margin_pct?: mixed, min_amount?: mixed, max_amount?: mixed, payment_method_ids?: array<int>, terms?: ?string, payment_window_minutes?: ?int} $validated
     *
     * @throws NotifyErrorException
     */
    public function update(User $user, Offer $offer, array $validated): Offer
    {
        if ($offer->status === OfferStatus::DISABLED) {
            throw new NotifyErrorException(__('This trade ad cannot be updated.'));
        }

        if ($this->hasActiveOrders($offer)) {
            throw new NotifyErrorException(__('This trade ad has active orders and cannot be updated right now.'));
        }

        $changes = [];
        foreach (['min_amount', 'max_amount', 'terms', 'payment_window_minutes'] as $key) {
            if (array_key_exists($key, $validated)) {
                $changes[$key] = $validated[$key];
            }
        }
        if (array_key_exists('price', $validated) && $validated['price'] !== null) {
            $changes['price'] = (float) $validated['price'];
        }
        if (array_key_exists('price_type', $validated) && $validated['price_type'] !== null) {
            $changes['price_type'] = $validated['price_type'];
            $changes['margin_pct'] = $validated['price_type'] === Offer::PRICE_FLOAT ? (float) ($validated['margin_pct'] ?? $offer->margin_pct ?? 0) : null;
        } elseif (array_key_exists('margin_pct', $validated) && $offer->price_type === Offer::PRICE_FLOAT) {
            $changes['margin_pct'] = (float) $validated['margin_pct'];
        }

        if (isset($validated['payment_method_ids'])) {
            $ids = collect($validated['payment_method_ids'])->map(fn ($id) => (int) $id)->filter()->unique()->values();
            if ($ids->isEmpty()) {
                throw new NotifyErrorException(__('A trade ad must keep at least one payment method.'));
            }
            $saved = PaymentAccount::query()->where('user_id', $user->id)->pluck('payment_method_id')->map(fn ($id) => (int) $id);
            if ($ids->diff($saved)->isNotEmpty()) {
                throw new NotifyErrorException(__('Add your saved payment account details for each selected payment method first.'));
            }
            $offer->paymentMethods()->sync($ids->all());
        }

        if ($changes !== []) {
            $offer->update($changes);
        }

        $this->float($offer);

        return $offer->refresh();
    }

    /**
     * A floating ad's price is the market's, not the advertiser's.
     */
    private function float(Offer $offer): void
    {
        if ($offer->price_type !== Offer::PRICE_FLOAT) {
            return;
        }
        $price = $this->prices->priceFor($offer->load(['wallet.currency', 'quoteCurrency']));
        if ($price !== null) {
            $offer->update(['price' => $price]);
        }
    }

    /**
     * Pause an active ad, or resume a paused one.
     *
     * @throws NotifyErrorException
     */
    public function toggle(Offer $offer): Offer
    {
        if ($offer->status === OfferStatus::DISABLED) {
            throw new NotifyErrorException(__('This trade ad cannot be updated.'));
        }

        if ($this->hasActiveOrders($offer)) {
            throw new NotifyErrorException(__('This trade ad has active orders and cannot be updated right now.'));
        }

        if ($offer->status !== OfferStatus::ACTIVE && ! $this->marketplace->isOperational($offer)) {
            throw new NotifyErrorException(__('This trade ad cannot be resumed because one of its currencies is unavailable.'));
        }

        $offer->update([
            'status' => $offer->status === OfferStatus::ACTIVE ? OfferStatus::PAUSED : OfferStatus::ACTIVE,
        ]);

        return $offer;
    }

    /**
     * @throws NotifyErrorException
     */
    public function delete(Offer $offer): void
    {
        if ($this->hasActiveOrders($offer)) {
            throw new NotifyErrorException(__('This trade ad has active orders and cannot be deleted right now.'));
        }

        $offer->delete();
    }

    /**
     * One of the customer's active crypto wallets - the only kind an ad can
     * be backed by.
     */
    public function tradableWallet(User $user, int $walletId): ?Wallet
    {
        return Wallet::query()
            ->with('currency')
            ->where('id', $walletId)
            ->where('user_id', $user->id)
            ->active()
            ->whereHas('currency', fn ($q) => $q->where('type', CurrencyType::CRYPTO))
            ->first();
    }

    private function hasActiveOrders(Offer $offer): bool
    {
        return P2POrder::query()
            ->where('offer_id', $offer->id)
            ->whereIn('status', [
                OrderStatus::PENDING->value,
                OrderStatus::PAID->value,
                OrderStatus::DISPUTED->value,
            ])
            ->exists();
    }
}
