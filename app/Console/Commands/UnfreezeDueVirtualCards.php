<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VirtualCard\VirtualCardStatus;
use App\Models\VirtualCard;
use App\Services\VirtualCard\VirtualCardControls;
use Illuminate\Console\Command;
use Throwable;

/**
 * Lift every freeze whose time is up. A freeze for an hour, a day or a
 * week is a promise the customer relies on; this keeps it.
 */
class UnfreezeDueVirtualCards extends Command
{
    protected $signature = 'virtual-card:unfreeze-due';

    protected $description = 'Unfreeze virtual cards whose timed freeze has ended';

    public function handle(VirtualCardControls $controls): int
    {
        $cards = VirtualCard::query()
            ->with('provider')
            ->where('status', VirtualCardStatus::Inactive->value)
            ->whereNotNull('frozen_until')
            ->where('frozen_until', '<=', now())
            ->orderBy('id')
            ->get();

        $lifted = 0;
        foreach ($cards as $card) {
            try {
                $controls->unfreeze($card);
                $lifted++;
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->components->info(sprintf('%d freeze(s) lifted.', $lifted));

        return self::SUCCESS;
    }
}
