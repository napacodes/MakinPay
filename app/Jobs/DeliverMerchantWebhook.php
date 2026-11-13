<?php

namespace App\Jobs;

use App\Models\MerchantWebhookDelivery;
use App\Services\MerchantWebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One delivery, retried on a widening schedule until it lands or runs out.
 *
 * The attempt count lives on the delivery row, not on the queue job, so a
 * worker restart or a re-dispatch cannot grant a merchant endpoint more
 * tries than the schedule says — and so the admin can see the count.
 */
class DeliverMerchantWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * Seconds to wait after each failed attempt: 1 min, 5 min, 30 min, 2 h, 6 h.
     * Six attempts in all, spread over roughly eight and a half hours — long
     * enough to ride out a merchant deploy, short enough that an outage is
     * visible the same working day.
     *
     * @var list<int>
     */
    public const RETRY_DELAYS = [60, 300, 1800, 7200, 21600];

    public const MAX_ATTEMPTS = 6;

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function handle(MerchantWebhookDispatcher $dispatcher): void
    {
        $delivery = MerchantWebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null || ! $delivery->isPending()) {
            return;
        }

        if ($dispatcher->deliver($delivery)) {
            return;
        }

        if ($delivery->attempts >= self::MAX_ATTEMPTS) {
            $dispatcher->abandon($delivery);

            return;
        }

        $delay = self::RETRY_DELAYS[min($delivery->attempts, count(self::RETRY_DELAYS)) - 1];

        $delivery->forceFill(['next_attempt_at' => now()->addSeconds($delay)])->save();

        $this->release($delay);
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = MerchantWebhookDelivery::query()->find($this->deliveryId);

        if ($delivery !== null && $delivery->isPending()) {
            app(MerchantWebhookDispatcher::class)->abandon($delivery, $exception?->getMessage());
        }
    }
}
