<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Vault;
use App\Services\Vaults\VaultService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Top vaults up by their rules: the weekly or monthly amount whose day has
 * come, and yesterday's round-ups.
 */
class RunVaultAutoSaves extends Command
{
    protected $signature = 'vaults:auto-save';

    protected $description = 'Move scheduled auto-save amounts and round-ups into vaults.';

    public function handle(VaultService $vaults): int
    {
        $saved   = 0;
        $rounded = 0;

        Vault::query()->where('status', 'active')->whereNotNull('next_auto_at')->where('next_auto_at', '<=', now())->with(['user', 'wallet.currency'])->orderBy('id')
            ->chunkById(100, function ($chunk) use ($vaults, &$saved): void {
                foreach ($chunk as $vault) {
                    // One vault's surprise must not cost every vault after it its run.
                    try {
                        if ($vaults->runAutoSave($vault) !== null) {
                            $saved++;
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });

        Vault::query()->where('status', 'active')->where('round_ups', true)->with(['user', 'wallet.currency'])->orderBy('id')
            ->chunkById(100, function ($chunk) use ($vaults, &$rounded): void {
                foreach ($chunk as $vault) {
                    try {
                        if ($vaults->runRoundUps($vault) !== null) {
                            $rounded++;
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });

        $this->components->info(sprintf('%d auto-save(s), %d round-up deposit(s).', $saved, $rounded));

        return self::SUCCESS;
    }
}
