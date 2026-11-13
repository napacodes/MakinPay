<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VirtualCard\VirtualCardStatus;
use App\Models\VirtualCard;
use App\Services\VirtualCard\VirtualCardManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ask the provider about every card still waiting to be provisioned.
 *
 * StroWallet and Bitnob issue asynchronously: `create` allocates an id and the
 * PAN, expiry and final status follow later, announced by a webhook. A site
 * whose webhook URL was never configured at the provider — or sits behind a
 * firewall — therefore had cards that stayed `pending` forever, unfundable and
 * unusable, with no way for the customer to move them on. This runs every few
 * minutes and asks directly, through the same provider-verified sync the
 * webhook and the admin "Refresh" button use.
 *
 * Only cards that have had time to provision are asked, and each pass is
 * bounded so a large backlog cannot turn into a burst against the provider.
 */
class SyncPendingVirtualCards extends Command
{
    protected $signature = 'virtual-card:sync-pending-cards
                            {--minutes=2 : Only ask about cards older than this, so an issuance still in flight is left alone.}
                            {--days=30 : Ignore cards left pending longer than this; they belong to an operator.}
                            {--limit=50 : Maximum cards to ask about in one pass.}';

    protected $description = 'Refresh pending virtual cards from their provider so asynchronous issuances finish locally';

    public function handle(VirtualCardManager $manager): int
    {
        $cards = VirtualCard::query()
            ->with(['provider', 'wallet.currency', 'request'])
            ->where('status', VirtualCardStatus::Pending->value)
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('minutes')))
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($cards->isEmpty()) {
            $this->components->info('No pending virtual cards to sync.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($cards as $card) {
            if (! $card->provider || ! $card->provider->supports('card_details')) {
                $rows[] = [$card->id, $card->provider?->code ?? '—', 'skipped', 'provider cannot report card details'];

                continue;
            }

            try {
                $changed = $manager->syncCardFromProvider($card);
                $rows[]  = [
                    $card->id,
                    $card->provider->code,
                    $changed === [] ? 'unchanged' : 'updated',
                    $changed === [] ? $card->status?->value : implode(', ', $changed).' → '.$card->status?->value,
                ];
            } catch (Throwable $e) {
                report($e);
                $rows[] = [$card->id, $card->provider->code, 'error', $e->getMessage()];
            }
        }

        $this->table(['Card', 'Provider', 'Result', 'Detail'], $rows);

        return self::SUCCESS;
    }
}
