<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\P2P\MarketAlert;
use App\Notifications\P2PMarketAlertNotification;
use App\Services\P2P\P2POfferMarketplace;
use App\Support\ApiMoney;
use Illuminate\Console\Command;

/**
 * Tell a customer once when an ad matching a search they saved appears.
 */
class CheckP2PMarketAlerts extends Command
{
    protected $signature = 'p2p:market-alerts';

    protected $description = 'Notify customers whose saved P2P market search now has offers.';

    public function handle(P2POfferMarketplace $marketplace): int
    {
        $sent = 0;
        MarketAlert::query()->whereNull('notified_at')->with('user')->orderBy('id')->chunkById(100, function ($chunk) use ($marketplace, &$sent): void {
            foreach ($chunk as $alert) {
                $user = $alert->user;
                if ($user === null) {
                    continue;
                }
                $page = $marketplace->search($user, [
                    'side'              => $alert->side,
                    'currency'          => $alert->asset,
                    'fiat'              => $alert->fiat,
                    'payment_method_id' => $alert->payment_method_id,
                    'amount'            => $alert->amount === null ? null : (string) $alert->amount,
                    'sort'              => 'price',
                ], 1);
                if ($page->total() === 0) {
                    continue;
                }
                $best = $page->getCollection()->first();
                $alert->update(['notified_at' => now()]);
                $user->notify(new P2PMarketAlertNotification($alert, $page->total(), ApiMoney::amount((string) $best->price, $alert->fiat)));
                $sent++;
            }
        });
        $this->components->info(sprintf('%d market alert(s) sent.', $sent));

        return self::SUCCESS;
    }
}
