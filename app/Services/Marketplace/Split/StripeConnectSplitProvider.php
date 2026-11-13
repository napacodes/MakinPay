<?php

declare(strict_types=1);

namespace App\Services\Marketplace\Split;

use App\Data\SplitPaymentData;
use App\Enums\Marketplace\OrderStatus;
use App\Exceptions\NotifyErrorException;
use App\Models\Currency;
use App\Models\MarketplaceOrder;
use App\Models\Vendor;
use App\Services\Payment\Stripe\StripeConnectService;
use App\Services\Payment\Stripe\StripeMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 2 split driver: the buyer pays with a card on Stripe Checkout and
 * Stripe performs the split itself — a destination charge sends the vendor
 * net straight to the vendor's connected account while `application_fee_amount`
 * keeps the platform commission on the platform account.
 *
 * Unlike the wallet driver no Digikash wallet is debited or credited, so this
 * driver deliberately writes no wallet ledger `Transaction` rows: the
 * MarketplaceOrder is the record of the sale, and inventing ledger entries for
 * money that never moved through a wallet would desynchronise the statements.
 *
 * The order is created PENDING before the redirect and only flips to RELEASED
 * when Stripe confirms payment on the `checkout.session.completed` webhook.
 */
class StripeConnectSplitProvider implements SplitProvider
{
    public function __construct(protected StripeConnectService $connect) {}

    public function code(): string
    {
        return 'stripe_connect';
    }

    /**
     * The buyer leaves the site for Stripe Checkout, so the split cannot be
     * settled within the request.
     */
    public function supportsInstantSplit(): bool
    {
        return false;
    }

    /**
     * Duck-typed capability probe used by the order handler (the same pattern
     * `PaymentService` uses for optional gateway `withdraw()` support): a
     * vendor that has not finished Stripe onboarding cannot receive a
     * destination charge, so the handler falls back to the wallet driver.
     */
    public function supportsVendor(Vendor $vendor): bool
    {
        return $this->connect->isEnabled() && $this->connect->isReady($vendor);
    }

    /**
     * @throws NotifyErrorException
     */
    public function charge(SplitPaymentData $data): SplitResult
    {
        $vendor   = Vendor::query()->findOrFail($data->vendorId);
        $currency = Currency::query()->findOrFail($data->currencyId);

        $this->guardVendorReady($vendor);

        $order = MarketplaceOrder::create([
            'buyer_id'          => $data->buyerId,
            'vendor_id'         => $vendor->id,
            'currency_id'       => $currency->id,
            'gross_amount'      => $data->gross,
            'commission_amount' => $data->commission,
            'vendor_net'        => $data->vendorNet,
            'status'            => OrderStatus::PENDING,
            'provider'          => $this->code(),
            'remarks'           => $data->remarks,
        ]);

        $reference = 'marketplace_order_'.$order->id;
        $order->update(['trx_reference' => $reference]);

        try {
            $session = $this->connect->client()->checkout->sessions->create(
                $this->sessionPayload($order, $vendor, $currency, $reference)
            );
        } catch (Throwable $e) {
            Log::error('Stripe Connect checkout session failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            $order->update(['status' => OrderStatus::CANCELLED]);

            throw new NotifyErrorException(__('Could not start the Stripe payment: :msg', ['msg' => $e->getMessage()]));
        }

        $order->update(['provider_reference' => $session->id]);

        return new SplitResult(
            order: $order->refresh(),
            redirectUrl: (string) $session->url,
        );
    }

    /**
     * Confirm a paid checkout session. Idempotent — Stripe retries webhooks and
     * may deliver `checkout.session.completed` more than once.
     */
    public function release(MarketplaceOrder $order, ?string $paymentIntentId = null): MarketplaceOrder
    {
        return DB::transaction(function () use ($order, $paymentIntentId) {
            $fresh = MarketplaceOrder::query()->lockForUpdate()->find($order->id);

            if (! $fresh || $fresh->status !== OrderStatus::PENDING) {
                return $fresh ?? $order;
            }

            $fresh->update(array_filter([
                'status'             => OrderStatus::RELEASED,
                'released_at'        => now(),
                'provider_reference' => $paymentIntentId,
            ], static fn ($value) => $value !== null));

            return $fresh->refresh();
        }, 3);
    }

    /**
     * Reverse the charge, the transfer to the vendor, and the platform fee in
     * one Stripe call so all three legs unwind together.
     *
     * @throws NotifyErrorException
     */
    public function refund(MarketplaceOrder $order): SplitResult
    {
        $order = MarketplaceOrder::query()->findOrFail($order->id);

        if ($order->status !== OrderStatus::RELEASED) {
            throw new NotifyErrorException(__('Only released orders can be refunded.'));
        }

        $paymentIntent = (string) $order->provider_reference;

        if (! str_starts_with($paymentIntent, 'pi_')) {
            throw new NotifyErrorException(__('This order has no Stripe payment to refund.'));
        }

        try {
            $this->connect->client()->refunds->create([
                'payment_intent'         => $paymentIntent,
                'refund_application_fee' => true,
                'reverse_transfer'       => true,
                'metadata'               => ['order_id' => (string) $order->id],
            ]);
        } catch (Throwable $e) {
            Log::error('Stripe Connect refund failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            throw new NotifyErrorException(__('Stripe refund failed: :msg', ['msg' => $e->getMessage()]));
        }

        $order->update([
            'status'      => OrderStatus::REFUNDED,
            'refunded_at' => now(),
        ]);

        return new SplitResult(order: $order->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(MarketplaceOrder $order, Vendor $vendor, Currency $currency, string $reference): array
    {
        $code       = strtolower((string) $currency->code);
        $gross      = StripeMoney::toSubunit((float) $order->gross_amount, $code);
        $commission = StripeMoney::toSubunit((float) $order->commission_amount, $code);

        $paymentIntentData = [
            'transfer_data' => [
                'destination' => (string) $vendor->stripe_connect_account_id,
            ],
            'metadata' => [
                'order_id'  => (string) $order->id,
                'vendor_id' => (string) $vendor->id,
            ],
        ];

        // Stripe rejects a zero application fee; a 100% vendor split simply
        // omits the field.
        if ($commission > 0) {
            $paymentIntentData['application_fee_amount'] = $commission;
        }

        return [
            'mode'       => 'payment',
            'line_items' => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => $code,
                    'unit_amount'  => $gross,
                    'product_data' => [
                        'name' => $vendor->display_name,
                    ],
                ],
            ]],
            'client_reference_id' => $reference,
            'payment_intent_data' => $paymentIntentData,
            'success_url'         => route('user.marketplace.orders'),
            'cancel_url'          => route('user.marketplace.pay', $vendor),
            'metadata'            => ['order_id' => (string) $order->id],
        ];
    }

    /**
     * @throws NotifyErrorException
     */
    private function guardVendorReady(Vendor $vendor): void
    {
        if (! $this->connect->isEnabled()) {
            throw new NotifyErrorException(__('Stripe Connect is not enabled.'));
        }

        if (! $this->connect->isReady($vendor)) {
            throw new NotifyErrorException(__(':vendor has not finished Stripe onboarding yet.', [
                'vendor' => $vendor->display_name,
            ]));
        }
    }
}
