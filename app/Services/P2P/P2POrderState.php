<?php

declare(strict_types=1);

namespace App\Services\P2P;

use App\Enums\P2P\OrderSide;
use App\Enums\P2P\OrderStatus;
use App\Models\P2P\Order;

/**
 * Where a P2P order stands for one of its two parties: which side of the
 * trade they are on, whether a pending order has run out of time, and which
 * of the next steps are theirs to take.
 *
 * The website's live status poll and the app both read this, so a button
 * cannot be offered in one and refused in the other.
 */
class P2POrderState
{
    /**
     * @return array{role: string, is_expired: bool, display_status: OrderStatus, can_mark_paid: bool, can_release: bool, can_cancel: bool}
     */
    public function for(Order $order, int $actorId): array
    {
        $order->loadMissing('offer');

        $side     = $order->offer->side;
        $sellerId = (int) ($side === OrderSide::SELL ? $order->maker_id : $order->taker_id);
        $buyerId  = (int) ($side === OrderSide::SELL ? $order->taker_id : $order->maker_id);
        $role     = $actorId === $sellerId ? 'seller' : ($actorId === $buyerId ? 'buyer' : 'guest');

        $isExpired = $order->status->value === 'PENDING'
            && $order->expires_at
            && now()->greaterThan($order->expires_at);

        return [
            'role'           => $role,
            'is_expired'     => (bool) $isExpired,
            'display_status' => $isExpired ? OrderStatus::EXPIRED : $order->status,
            'can_mark_paid'  => $role === 'buyer'  && $order->status->value === 'PENDING' && ! $isExpired,
            'can_release'    => $role === 'seller' && $order->status->value === 'PAID',
            'can_cancel'     => $role === 'buyer'  && $order->status->value === 'PENDING' && ! $isExpired,
        ];
    }
}
