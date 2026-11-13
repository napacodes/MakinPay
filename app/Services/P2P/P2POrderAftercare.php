<?php

declare(strict_types=1);

namespace App\Services\P2P;

use App\Enums\P2P\DisputeStatus;
use App\Enums\P2P\OrderStatus;
use App\Exceptions\NotifyErrorException;
use App\Models\P2P\Dispute;
use App\Models\P2P\OfferFeedback;
use App\Models\P2P\Order;
use App\Models\User;
use App\Notifications\P2P\P2POrderStatusNotification;
use Illuminate\Support\Facades\DB;

/**
 * What happens around a P2P trade rather than to its money - reviewing a
 * finished one and disputing an open one - for the website and the app alike.
 *
 * A review is the taker's, once, on a completed trade. A dispute is either
 * party's, once, on a pending or paid trade inside the dispute window; it
 * freezes the trade for an administrator to settle.
 */
class P2POrderAftercare
{
    /**
     * @throws NotifyErrorException
     */
    /**
     * @param list<string> $tags
     */
    public function review(User $user, Order $order, int $rating, ?string $comment, array $tags = []): OfferFeedback
    {
        return DB::transaction(function () use ($user, $order, $rating, $comment, $tags): OfferFeedback {
            // Locked, so two submissions of the same review queue up and the
            // second finds the first.
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== OrderStatus::COMPLETED) {
                throw new NotifyErrorException(__('You can submit a review only after the trade is completed.'));
            }

            if ((int) $user->id !== (int) $order->taker_id) {
                throw new NotifyErrorException(__('Only the counterparty can submit a review for this trade.'));
            }

            $exists = OfferFeedback::query()
                ->where('order_id', $order->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($exists) {
                throw new NotifyErrorException(__('You have already submitted a review for this trade.'));
            }

            return OfferFeedback::create([
                'offer_id' => (int) $order->offer_id,
                'order_id' => (int) $order->id,
                'user_id'  => (int) $user->id,
                'rating'   => $rating,
                'comment'  => $comment,
                'tags'     => $tags === [] ? null : array_values($tags),
            ]);
        });
    }

    /**
     * @throws NotifyErrorException
     */
    public function dispute(User $user, Order $order, string $reason): Dispute
    {
        $windowMinutes = (int) setting('p2p_dispute_window_minutes', 120);

        $this->assertDisputable($order, $windowMinutes);

        $dispute = DB::transaction(function () use ($user, $order, $reason, $windowMinutes): Dispute {
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->assertDisputable($order, $windowMinutes);

            $existingDispute = Dispute::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($existingDispute) {
                throw new NotifyErrorException(__('A dispute has already been opened for this trade.'));
            }

            $dispute = Dispute::query()->create([
                'order_id'  => $order->id,
                'raised_by' => (int) $user->id,
                'status'    => DisputeStatus::OPEN,
                'reason'    => $reason,
            ]);

            $order->update([
                'status'      => OrderStatus::DISPUTED,
                'disputed_at' => now(),
            ]);

            return $dispute;
        }, 3);

        foreach ([User::find($order->maker_id), User::find($order->taker_id)] as $party) {
            $party?->notify(new P2POrderStatusNotification(
                orderId: (int) $order->id,
                title: __('Trade Dispute Opened'),
                message: __('A trade dispute has been opened for Order #:id.', ['id' => $order->order_number]),
                data: ['status' => 'DISPUTED']
            ));
        }

        return $dispute;
    }

    /**
     * @throws NotifyErrorException
     */
    private function assertDisputable(Order $order, int $windowMinutes): void
    {
        if (! in_array($order->status->value, ['PENDING', 'PAID'], true)) {
            throw new NotifyErrorException(__('This order cannot be disputed now.'));
        }

        if ($order->status === OrderStatus::PENDING && $order->expires_at && now()->greaterThan($order->expires_at)) {
            throw new NotifyErrorException(__('This order is already expired. Please refresh the page.'));
        }

        $baseTime = $order->paid_at ?? $order->created_at;

        if ($windowMinutes > 0 && $baseTime && now()->greaterThan($baseTime->copy()->addMinutes($windowMinutes))) {
            throw new NotifyErrorException(__('Dispute window has expired for this order.'));
        }
    }
}
