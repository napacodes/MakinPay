<?php

namespace App\Http\Controllers\Frontend\P2P;

use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\P2P\StoreOrderRequest;
use App\Models\P2P\Offer;
use App\Models\P2P\Order;
use App\Services\Money\WalletPinGuard;
use App\Services\P2P\P2POrderAftercare;
use App\Services\P2P\P2POrderPlacement;
use App\Services\P2P\P2POrderService;
use App\Services\P2P\P2POrderState;
use App\Services\SubscriptionFeatureService;
use App\Support\P2PPaymentFileDisk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    // region Trade Order Flow

    public function __construct(
        protected P2POrderService $service,
        protected SubscriptionFeatureService $subscriptionFeatures,
        protected P2POrderPlacement $placement,
    ) {}

    public function index(): View
    {
        $orders = Order::query()
            ->with(['offer.quoteCurrency', 'wallet.currency', 'feedbacks', 'paymentMethod'])
            ->where(function ($q) {
                $q->where('maker_id', auth()->id())
                    ->orWhere('taker_id', auth()->id());
            })
            ->latest()
            ->paginate(15);

        return view('frontend.user.p2p.trade_orders.trade_orders', compact('orders'));
    }

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        if ($redirect = $this->guardP2PTradeQuota()) {
            return $redirect;
        }

        $validated = $request->validated();

        $offer = Offer::with(['wallet.currency', 'paymentMethods', 'user'])->findOrFail($validated['offer_id']);

        try {
            $order = $this->placement->place(auth()->user(), $offer, (int) $validated['payment_account_id'], $validated['amount']);
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());

            return back();
        }

        notifyEvs('success', __('Trade order created. Complete payment within the time window.'));

        return redirect()->route('user.p2p.orders.show', [$order, 'created' => 1]);
    }

    public function show(Order $order): View
    {
        $order->load(['offer.paymentMethods', 'offer.quoteCurrency', 'wallet.currency', 'maker', 'taker', 'feedbacks', 'paymentMethod', 'payerPaymentAccount.paymentMethod', 'receiverPaymentAccount.paymentMethod']);
        $this->authorize('view', $order);

        return view('frontend.user.p2p.trade_orders.trade_order_details', compact('order'));
    }

    /**
     * A file on either party's payment account, as the order recorded it -
     * for the two parties to the order only.
     */
    public function paymentFile(Order $order, string $side, string $field): StreamedResponse
    {
        $this->authorize('view', $order);

        $snapshot = $side === 'payer' ? $order->payer_payment_account_snapshot : $order->receiver_payment_account_snapshot;

        return P2PPaymentFileDisk::response(P2PPaymentFileDisk::fromDetails((array) ($snapshot['details'] ?? []), $field));
    }

    public function feedback(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('feedback', $order);

        $validator = Validator::make($request->all(), [
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput()
                ->with('p2p_feedback_order_id', $order->order_number);
        }

        $validated = $validator->validated();

        try {
            app(P2POrderAftercare::class)->review(auth()->user(), $order, (int) $validated['rating'], $validated['comment'] ?? null);
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());

            return back();
        }

        notifyEvs('success', __('Thanks! Your trade review has been submitted.'));

        return back();
    }

    public function status(Order $order): JsonResponse
    {
        $this->authorize('view', $order);
        $order->load(['offer', 'wallet.currency']);

        $sideEnum = $order->offer->side;
        $state    = app(P2POrderState::class)->for($order, (int) auth()->id());

        $role          = $state['role'];
        $isExpired     = $state['is_expired'];
        $displayStatus = $state['display_status'];
        $canMarkPaid   = $state['can_mark_paid'];
        $canRelease    = $state['can_release'];
        $canCancel     = $state['can_cancel'];

        return response()->json([
            'id'                  => $order->id,
            'status'              => $order->status->value,
            'status_label'        => $order->status->label(),
            'status_badge_class'  => $order->status->badgeClass(),
            'display_status'      => $displayStatus->value,
            'display_label'       => $displayStatus->label(),
            'display_badge_class' => $displayStatus->badgeClass(),
            'side'                => $sideEnum->value,
            'role'                => $role,
            'is_expired'          => $isExpired,
            'paid_at'             => optional($order->paid_at)->toIso8601String(),
            'completed_at'        => optional($order->completed_at)->toIso8601String(),
            'cancelled_at'        => optional($order->cancelled_at)->toIso8601String(),
            'expired_at'          => optional($order->expired_at)->toIso8601String(),
            'expires_at'          => optional($order->expires_at)->toIso8601String(),
            'now'                 => now()->toIso8601String(),
            'can_mark_paid'       => $canMarkPaid,
            'can_release'         => $canRelease,
            'can_cancel'          => $canCancel,
        ]);
    }

    public function markPaid(Order $order): RedirectResponse
    {
        $order->loadMissing('offer');
        $this->authorize('markPaid', $order);
        try {
            $this->service->markPaid($order, auth()->id());
            notifyEvs('success', __('Payment marked as sent. Waiting for escrow release.'));
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());
        }

        return back();
    }

    /**
     * A release hands the asset over for good, so the seller's wallet PIN is
     * asked for when one is set - after the step is known to be theirs, and
     * before anything moves. The same check as the mobile API's release.
     */
    public function release(Request $request, Order $order, WalletPinGuard $pins): RedirectResponse
    {
        $order->loadMissing('offer');
        $this->authorize('release', $order);
        $validated = $request->validate(['wallet_pin' => ['nullable', 'string']]);
        try {
            $pins->assert($request->user(), $validated['wallet_pin'] ?? null, context: ['p2p_order_id' => $order->id]);
            $this->service->release($order, auth()->id());
            notifyEvs('success', __('Escrow released successfully.'));
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());
        }

        return back();
    }

    public function cancel(Order $order): RedirectResponse
    {
        $this->authorize('cancel', $order);
        try {
            $this->service->cancel($order, auth()->id());
            notifyEvs('success', __('Trade order canceled.'));
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());
        }

        return back();
    }

    /**
     * Enforce the subscription-plan p2p_trade_limit quota for Buy/Sell actions.
     * Returns a redirect when the user has no quota or has hit their monthly cap,
     * null when they are allowed to proceed.
     */
    private function guardP2PTradeQuota(): ?RedirectResponse
    {
        $refusal = $this->placement->tradeQuotaRefusal(auth()->user());

        if ($refusal === null) {
            return null;
        }

        notifyEvs('warning', $refusal);

        return redirect()->route('user.subscription.plans');
    }
}
