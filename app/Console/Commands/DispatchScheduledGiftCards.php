<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\GiftCardDelivered;
use App\Models\GiftCard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Deliver the gift cards whose scheduled send time has arrived.
 *
 * A card bought for a future date is created with status `scheduled` and no
 * email queued — the sender's wallet is debited immediately, but nothing in
 * the application ever read that status back to send it. Every scheduled gift
 * card was therefore paid for and silently lost.
 *
 * This is the missing half of the flow. It mirrors the immediate path in
 * GiftCardController::store() exactly: the card moves to `pending`, and the
 * transition to `delivered` still happens the first time a non-sender opens
 * the preview link.
 *
 * The status flip is what makes the run idempotent — it happens inside a
 * locked transaction, so a second worker (or an overlapping schedule tick)
 * finds the row already out of `scheduled` and skips it. Mail is queued only
 * after that transaction commits, which is also why a queueing failure leaves
 * the card `pending` rather than `scheduled`: the card is still reachable
 * through its preview URL and the failure is logged, exactly as the immediate
 * path behaves.
 */
class DispatchScheduledGiftCards extends Command
{
    protected $signature = 'gift-card:dispatch-scheduled
                            {--limit=200 : Maximum gift cards to deliver per run.}';

    protected $description = 'Send the gift cards whose scheduled delivery time has passed';

    public function handle(): int
    {
        $limit  = max(1, min(1000, (int) $this->option('limit')));
        $sent   = 0;
        $failed = 0;

        foreach ($this->dueCardIds($limit) as $cardId) {
            $card = $this->claim($cardId);

            if (! $card) {
                continue;
            }

            try {
                Mail::to($card->recipient_email)->queue(new GiftCardDelivered($card));
                $sent++;
            } catch (Throwable $e) {
                $failed++;

                Log::error('Scheduled gift card email could not be queued', [
                    'gift_card_id' => $card->id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        $this->components->info(__('Delivered :count scheduled gift card(s).', ['count' => $sent]));

        if ($failed > 0) {
            $this->components->warn(__(':count gift card(s) were released but their email could not be queued.', ['count' => $failed]));
        }

        return self::SUCCESS;
    }

    /**
     * Cards whose send time has passed and that are still deliverable.
     *
     * Cancelled cards drop out through `is_active`, and a card that expired
     * while it sat waiting is never sent — delivering an already-dead code
     * would only confuse the recipient.
     *
     * @return list<int>
     */
    private function dueCardIds(int $limit): array
    {
        return GiftCard::query()
            ->where('status', 'scheduled')
            ->where('is_active', true)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * Move one card out of `scheduled` under a row lock.
     *
     * Returns the claimed card, or null when another run already took it.
     */
    private function claim(int $cardId): ?GiftCard
    {
        return DB::transaction(function () use ($cardId): ?GiftCard {
            $card = GiftCard::query()->whereKey($cardId)->lockForUpdate()->first();

            if (! $card || $card->status !== 'scheduled' || ! $card->is_active) {
                return null;
            }

            $card->forceFill(['status' => 'pending'])->save();

            return $card;
        });
    }
}
