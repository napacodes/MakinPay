<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VirtualCard\VirtualCardStatus;
use App\Models\VirtualCard;
use App\Services\VirtualCard\VirtualCardActivityService;
use Illuminate\Console\Command;

/**
 * Ask each provider that can list a card's purchases and declines for
 * every live card's recent activity, so the app's tiles and ledger stay
 * current between the customer's own visits.
 */
class SyncVirtualCardActivity extends Command
{
    protected $signature = 'virtual-card:sync-activity {--limit=200 : Maximum cards to ask about in one pass.}';

    protected $description = 'Refresh each live virtual card\'s purchases and declines from its provider';

    public function handle(VirtualCardActivityService $activity): int
    {
        $cards = VirtualCard::query()
            ->with(['provider', 'wallet.currency'])
            ->whereIn('status', [VirtualCardStatus::Active->value, VirtualCardStatus::Inactive->value, VirtualCardStatus::Blocked->value])
            ->where(fn ($q) => $q->whereNull('activity_synced_at')->orWhere('activity_synced_at', '<=', now()->subMinutes(VirtualCardActivityService::SYNC_EVERY_MINUTES)))
            ->orderBy('activity_synced_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $added = 0;
        foreach ($cards as $card) {
            $added += $activity->sync($card);
        }
        $this->components->info(sprintf('%d card(s) asked, %d new row(s).', $cards->count(), $added));

        return self::SUCCESS;
    }
}
