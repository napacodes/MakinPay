<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\P2P\Order;
use App\Models\P2P\PaymentAccount;
use App\Support\P2PPaymentFileDisk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Move P2P payment-account files off the public disk.
 *
 * Every file a payment method's file field took before these were made
 * private was written to storage/app/public, which the storage symlink
 * exposes to the web - and its path was printed on the account and on every
 * order that carried it. New uploads go to the private disk now, and the old
 * ones stay readable through the same controlled routes, but they stay
 * exposed until somebody moves them; that is the operator's call.
 *
 * A file is copied, verified by size, and only then removed from the public
 * disk, so a crash midway leaves it readable in one place or the other and
 * never in neither. Accounts are rewritten first, then every order snapshot
 * that recorded the same path. Safe to interrupt and safe to repeat.
 */
class PrivatiseP2PPaymentFiles extends Command
{
    protected $signature = 'p2p:privatise-payment-files
                            {--dry-run : List what would move without touching anything}';

    protected $description = 'Move P2P payment-account files off the public disk into private storage';

    private bool $dryRun = false;

    private int $moved = 0;

    private int $missing = 0;

    /** @var array<string, string|null> Legacy path => private path, or null when it could not move. */
    private array $moves = [];

    /** @var list<string> */
    private array $failed = [];

    public function handle(): int
    {
        // One instance can serve several calls in a process; start clean.
        $this->dryRun  = (bool) $this->option('dry-run');
        $this->moved   = 0;
        $this->missing = 0;
        $this->moves   = [];
        $this->failed  = [];

        foreach (PaymentAccount::withTrashed()->whereNotNull('field_values')->cursor() as $account) {
            $values             = is_array($account->field_values) ? $account->field_values : [];
            [$values, $changed] = $this->rewrite($values);

            if ($changed && ! $this->dryRun) {
                $account->field_values = $values;
                $account->saveQuietly();
            }
        }

        // JSON escapes the slashes it stores, so match the part without one;
        // the private prefix does not contain it.
        $orders = Order::query()->where(fn ($query) => $query
            ->where('payer_payment_account_snapshot', 'like', '%payment-accounts%')
            ->orWhere('receiver_payment_account_snapshot', 'like', '%payment-accounts%'));

        foreach ($orders->cursor() as $order) {
            $changed = false;

            foreach (['payer_payment_account_snapshot', 'receiver_payment_account_snapshot'] as $column) {
                $snapshot = is_array($order->{$column}) ? $order->{$column} : [];

                [$snapshot['field_values'], $valuesChanged] = $this->rewrite((array) ($snapshot['field_values'] ?? []));
                [$snapshot['details'], $detailsChanged]     = $this->rewriteDetails((array) ($snapshot['details'] ?? []));

                if ($valuesChanged || $detailsChanged) {
                    $order->{$column} = $snapshot;
                    $changed          = true;
                }
            }

            if ($changed && ! $this->dryRun) {
                $order->saveQuietly();
            }
        }

        $this->newLine();
        $this->info($this->dryRun
            ? "{$this->moved} file(s) would move."
            : "{$this->moved} file(s) moved, {$this->missing} missing.");

        foreach ($this->failed as $failure) {
            $this->error('failed: '.$failure);
        }

        return $this->failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>                    $values
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function rewrite(array $values): array
    {
        $changed = false;

        foreach ($values as $key => $value) {
            $to = $this->privatePathFor($value);

            if ($to !== null) {
                $values[$key] = $to;
                $changed      = true;
            }
        }

        return [$values, $changed];
    }

    /**
     * @param  array<int, mixed>                    $details
     * @return array{0: array<int, mixed>, 1: bool}
     */
    private function rewriteDetails(array $details): array
    {
        $changed = false;

        foreach ($details as $index => $detail) {
            $to = is_array($detail) ? $this->privatePathFor($detail['value'] ?? null) : null;

            if ($to !== null) {
                $details[$index]['value'] = $to;
                $changed                  = true;
            }
        }

        return [$details, $changed];
    }

    /**
     * Where a legacy file now lives, moving it on first sight; null when the
     * value is no legacy file or it could not be moved.
     */
    private function privatePathFor(mixed $value): ?string
    {
        if (! P2PPaymentFileDisk::isStoredFile($value) || ! P2PPaymentFileDisk::isLegacy((string) $value)) {
            return null;
        }

        $from = P2PPaymentFileDisk::normalise((string) $value);

        if (! array_key_exists($from, $this->moves)) {
            $this->moves[$from] = $this->move($from);
        }

        return $this->moves[$from];
    }

    private function move(string $from): ?string
    {
        $public  = Storage::disk('public');
        $private = Storage::disk(P2PPaymentFileDisk::DISK);
        $to      = P2PPaymentFileDisk::PREFIX.'/legacy/'.substr($from, strlen(P2PPaymentFileDisk::LEGACY_PREFIX) + 1);

        if ($private->exists($to) && ! $public->exists($from)) {
            // An earlier run moved the file but stopped before the rows.
            return $this->dryRun ? null : $to;
        }

        if (! $public->exists($from)) {
            $this->warn("missing on disk, left alone: {$from}");
            $this->missing++;

            return null;
        }

        if ($this->dryRun) {
            $this->line("would move  {$from}  ->  {$to}");
            $this->moved++;

            return null;
        }

        try {
            $stream = $public->readStream($from);

            if (! is_resource($stream)) {
                throw new RuntimeException('could not open for reading');
            }

            $private->writeStream($to, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            // Verified before the original goes, never after.
            if ($private->size($to) !== $public->size($from)) {
                throw new RuntimeException('copy size did not match the original');
            }

            $public->delete($from);
            $this->moved++;

            return $to;
        } catch (Throwable $e) {
            $this->failed[] = $from.' - '.$e->getMessage();

            return null;
        }
    }
}
