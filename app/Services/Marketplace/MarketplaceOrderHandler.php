<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Data\SplitPaymentData;
use App\Enums\Marketplace\VendorStatus;
use App\Enums\SystemAccountType;
use App\Exceptions\NotifyErrorException;
use App\Models\Currency;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Marketplace\MarketplaceOrderNotification;
use App\Services\Marketplace\Split\SplitProviderFactory;
use App\Services\Marketplace\Split\SplitResult;
use InvalidArgumentException;

class MarketplaceOrderHandler
{
    public function __construct(
        protected CommissionCalculator $commission,
        protected SplitProviderFactory $providers,
    ) {}

    /**
     * Validate a marketplace purchase, split the payment, and persist the order.
     *
     * Kept for callers that only need the order; gateway drivers that redirect
     * off-site (Stripe Connect) should call `checkout()` for the redirect URL.
     *
     * @throws NotifyErrorException
     */
    public function purchase(User $buyer, Vendor $vendor, float $gross, Currency $currency, ?string $remarks = null): MarketplaceOrder
    {
        return $this->checkout($buyer, $vendor, $gross, $currency, $remarks)->order;
    }

    /**
     * Same validation and split as `purchase()`, but returns the full
     * SplitResult so an off-site redirect can be honoured.
     *
     * @throws NotifyErrorException
     */
    public function checkout(User $buyer, Vendor $vendor, float $gross, Currency $currency, ?string $remarks = null): SplitResult
    {
        $settings = MarketplaceSetting::current();

        if (! $settings || ! $settings->enabled) {
            throw new NotifyErrorException(__('The marketplace is not available right now.'));
        }

        if ($vendor->status !== VendorStatus::ACTIVE) {
            throw new NotifyErrorException(__('This vendor is not accepting orders right now.'));
        }

        if ((int) $vendor->user_id === (int) $buyer->id) {
            throw new NotifyErrorException(__('You cannot purchase from your own vendor account.'));
        }

        if ($gross <= 0) {
            throw new NotifyErrorException(__('Invalid amount.'));
        }

        $min = (float) ($settings->min_order_amount ?? 0);
        $max = $settings->max_order_amount !== null ? (float) $settings->max_order_amount : null;

        if ($min > 0 && $gross < $min) {
            throw new NotifyErrorException(__('Amount is below the minimum order amount.'));
        }

        if ($max !== null && $max > 0 && $gross > $max) {
            throw new NotifyErrorException(__('Amount is above the maximum order amount.'));
        }

        $split = $this->commission->forVendor($vendor, $gross);

        $commissionAccount = $settings->commission_user_id
            ? User::query()->find($settings->commission_user_id)
            : null;

        if ($split['commission'] > 0 && ! $commissionAccount?->isSystemAccount(SystemAccountType::MARKETPLACE_COMMISSION)) {
            throw new NotifyErrorException(__('The protected marketplace commission account is not configured. Please contact support.'));
        }

        $data = new SplitPaymentData(
            buyerId: (int) $buyer->id,
            vendorId: (int) $vendor->id,
            currencyId: (int) $currency->id,
            gross: $gross,
            commission: $split['commission'],
            vendorNet: $split['vendor_net'],
            commissionUserId: $commissionAccount ? (int) $commissionAccount->id : null,
            provider: $this->resolveProviderCode($settings, $vendor),
            remarks: $remarks,
        );

        $provider = $this->providers->make($data->provider);
        $result   = $provider->charge($data);

        // An off-site provider has not been paid yet — the buyer is still on
        // their way to the gateway, so the "you have a new sale" notification
        // waits for the webhook that releases the order.
        if ($provider->supportsInstantSplit()) {
            $this->notify($result->order, $vendor, $buyer);
        }

        return $result;
    }

    /**
     * Which split driver settles this order. The admin's choice only applies
     * when the driver can actually serve the vendor; otherwise the marketplace
     * degrades to the always-available wallet driver rather than failing the
     * checkout.
     */
    private function resolveProviderCode(MarketplaceSetting $settings, Vendor $vendor): string
    {
        $code = (string) ($settings->split_provider ?: 'wallet');

        if ($code === 'wallet') {
            return 'wallet';
        }

        try {
            $provider = $this->providers->make($code);
        } catch (InvalidArgumentException) {
            return 'wallet';
        }

        if (method_exists($provider, 'supportsVendor') && ! $provider->supportsVendor($vendor)) {
            return 'wallet';
        }

        return $code;
    }

    /**
     * Refund a released marketplace order back to the buyer.
     *
     * @throws NotifyErrorException
     */
    public function refund(MarketplaceOrder $order): MarketplaceOrder
    {
        $result = $this->providers->make((string) $order->provider)->refund($order);

        $buyer  = User::find($order->buyer_id);
        $vendor = $order->vendor;

        foreach ([$buyer, $vendor?->user] as $user) {
            $user?->notify(new MarketplaceOrderNotification(
                orderId: (int) $order->id,
                title: __('Order Refunded'),
                message: __('Marketplace order #:id has been refunded.', ['id' => $order->id]),
                data: ['status' => 'refunded'],
            ));
        }

        return $result->order;
    }

    /**
     * Announce a sale that was confirmed after the request that started it —
     * an off-site gateway order released by its webhook.
     */
    public function notifyPaid(MarketplaceOrder $order): void
    {
        $order->loadMissing(['vendor.user', 'buyer']);

        if (! $order->vendor || ! $order->buyer) {
            return;
        }

        $this->notify($order, $order->vendor, $order->buyer);
    }

    private function notify(MarketplaceOrder $order, Vendor $vendor, User $buyer): void
    {
        $buyer->notify(new MarketplaceOrderNotification(
            orderId: (int) $order->id,
            title: __('Purchase Successful'),
            message: __('Your order #:id was completed successfully.', ['id' => $order->id]),
            data: ['status' => $order->status->value],
        ));

        $vendor->user?->notify(new MarketplaceOrderNotification(
            orderId: (int) $order->id,
            title: __('New Sale'),
            message: __('You received a new order #:id.', ['id' => $order->id]),
            data: ['status' => $order->status->value],
        ));
    }
}
