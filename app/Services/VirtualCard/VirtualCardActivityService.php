<?php

declare(strict_types=1);

namespace App\Services\VirtualCard;

use App\Models\VirtualCard;
use App\Models\VirtualCardActivity;
use App\Models\VirtualCardTopup;
use App\Services\VirtualCard\Drivers\SupportsCardActivity;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A card's own ledger. The platform writes its top-ups and withdrawals here
 * as they settle; a provider that can list a card's purchases and declines
 * is asked for them, no more than once every few minutes per card, and
 * every row is keyed by the provider's reference so a second sync writes
 * nothing twice.
 */
class VirtualCardActivityService
{
    /** How long a card's activity is trusted before the provider is asked again. */
    public const SYNC_EVERY_MINUTES = 10;

    public const FILTERS = ['all', 'purchases', 'topups', 'declined'];

    public function __construct(private readonly VirtualCardProviderFactory $providers) {}

    /**
     * One row, once: a reference already on this card is left as it is
     * unless its status moved on (pending → settled).
     *
     * @param array<string, mixed> $row
     */
    public function record(VirtualCard $card, array $row): VirtualCardActivity
    {
        $reference = isset($row['reference']) && $row['reference'] !== '' ? (string) $row['reference'] : null;

        return DB::transaction(function () use ($card, $row, $reference): VirtualCardActivity {
            $existing = $reference === null
                ? null
                : VirtualCardActivity::query()->where('virtual_card_id', $card->id)->where('provider_reference', $reference)->lockForUpdate()->first();

            $attributes = [
                'virtual_card_id'    => $card->id,
                'user_id'            => $card->user_id,
                'kind'               => (string) ($row['kind'] ?? VirtualCardActivity::KIND_PURCHASE),
                'status'             => (string) ($row['status'] ?? VirtualCardActivity::STATUS_SETTLED),
                'merchant'           => $row['merchant'] ?? null,
                'category'           => $row['category'] ?? null,
                'channel'            => $row['channel']  ?? null,
                'amount'             => (string) ($row['amount'] ?? '0'),
                'currency'           => (string) ($row['currency'] ?? ($card->provider?->cardCurrency() ?? $card->wallet?->currency?->code ?? '')),
                'original_amount'    => $row['original_amount']   ?? null,
                'original_currency'  => $row['original_currency'] ?? null,
                'decline_reason'     => isset($row['decline_reason']) && in_array($row['decline_reason'], VirtualCardActivity::REASONS, true) ? $row['decline_reason'] : null,
                'provider_reference' => $reference,
                'occurred_at'        => $row['occurred_at'] ?? now(),
                'meta'               => $row['meta']        ?? null,
            ];

            if ($existing !== null) {
                if ($existing->status !== $attributes['status']) {
                    $existing->update(['status' => $attributes['status']]);
                }

                return $existing;
            }

            return VirtualCardActivity::query()->create($attributes);
        }, 3);
    }

    /**
     * A settled top-up, in the card's currency.
     */
    public function recordTopup(VirtualCard $card, VirtualCardTopup $attempt): VirtualCardActivity
    {
        return $this->record($card, [
            'reference'   => 'topup:'.$attempt->id,
            'kind'        => VirtualCardActivity::KIND_TOPUP,
            'status'      => VirtualCardActivity::STATUS_SETTLED,
            'amount'      => (string) ($attempt->card_amount ?? $attempt->amount),
            'currency'    => (string) ($attempt->card_currency ?? $attempt->currency),
            'channel'     => 'wallet',
            'merchant'    => $attempt->transaction?->trx_id,
            'occurred_at' => $attempt->processed_at ?? now(),
        ]);
    }

    public function recordWithdraw(VirtualCard $card, string $cardAmount, string $currency, string $reference): VirtualCardActivity
    {
        return $this->record($card, [
            'reference'   => 'withdraw:'.$reference,
            'kind'        => VirtualCardActivity::KIND_WITHDRAW,
            'status'      => VirtualCardActivity::STATUS_SETTLED,
            'amount'      => $cardAmount,
            'currency'    => $currency,
            'channel'     => 'wallet',
            'merchant'    => $reference,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Ask the provider for the card's purchases and declines, when it can
     * answer and has not been asked lately. A provider that cannot, or an
     * error on its side, leaves what is already known.
     *
     * @return int how many rows were new
     */
    public function sync(VirtualCard $card, bool $force = false): int
    {
        $card->loadMissing(['provider', 'wallet.currency']);
        $provider = $card->provider;
        if ($provider === null || ! $provider->supports('activity')) {
            return 0;
        }
        if (! $force && $card->activity_synced_at !== null && $card->activity_synced_at->greaterThan(now()->subMinutes(self::SYNC_EVERY_MINUTES))) {
            return 0;
        }

        try {
            $driver = $this->providers->getProvider($provider->code);
            if (! $driver instanceof SupportsCardActivity) {
                return 0;
            }
            $rows = $driver->listCardActivity($card);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }

        $before = VirtualCardActivity::query()->where('virtual_card_id', $card->id)->count();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $this->record($card, $row);
            }
        }
        $card->forceFill(['activity_synced_at' => now()])->save();

        return VirtualCardActivity::query()->where('virtual_card_id', $card->id)->count() - $before;
    }

    /**
     * A page of the card's ledger, newest first, narrowed by one of FILTERS.
     */
    public function list(VirtualCard $card, string $filter = 'all', int $perPage = 30): LengthAwarePaginator
    {
        $query = VirtualCardActivity::query()->where('virtual_card_id', $card->id);

        match ($filter) {
            'purchases' => $query->whereIn('kind', [VirtualCardActivity::KIND_PURCHASE, VirtualCardActivity::KIND_REFUND]),
            'topups'    => $query->whereIn('kind', [VirtualCardActivity::KIND_TOPUP, VirtualCardActivity::KIND_WITHDRAW]),
            'declined'  => $query->where('kind', VirtualCardActivity::KIND_DECLINE),
            default     => $query,
        };

        return $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * What the app's tiles show: this month's spend (settled and pending
     * purchases less refunds), the last purchase, and how many declines.
     *
     * @return array{spent_this_month: string, last_used: ?array{merchant: ?string, amount: string, currency: string, at: ?string}, declined: int}
     */
    public function summary(VirtualCard $card): array
    {
        $month = VirtualCardActivity::query()
            ->where('virtual_card_id', $card->id)
            ->where('occurred_at', '>=', now()->startOfMonth())
            ->whereIn('kind', [VirtualCardActivity::KIND_PURCHASE, VirtualCardActivity::KIND_REFUND, VirtualCardActivity::KIND_FEE])
            ->whereIn('status', [VirtualCardActivity::STATUS_SETTLED, VirtualCardActivity::STATUS_PENDING])
            ->get(['kind', 'amount']);

        $spent = '0';
        foreach ($month as $row) {
            $spent = $row->kind === VirtualCardActivity::KIND_REFUND
                ? DecimalMoney::subtract($spent, (string) $row->amount, DecimalMoney::CALCULATION_SCALE)
                : DecimalMoney::add($spent, (string) $row->amount, DecimalMoney::CALCULATION_SCALE);
        }
        if (DecimalMoney::compare($spent, '0') < 0) {
            $spent = '0';
        }

        $last = VirtualCardActivity::query()
            ->where('virtual_card_id', $card->id)
            ->where('kind', VirtualCardActivity::KIND_PURCHASE)
            ->orderByDesc('occurred_at')
            ->first();

        $currency = $card->provider?->cardCurrency() ?? $card->wallet?->currency?->code;

        return [
            'spent_this_month' => ApiMoney::amount($spent, $currency),
            'last_used'        => $last === null ? null : [
                'merchant' => $last->merchant,
                'amount'   => ApiMoney::amount((string) $last->amount, $last->currency),
                'currency' => $last->currency,
                'at'       => $last->occurred_at?->toIso8601String(),
            ],
            'declined' => VirtualCardActivity::query()->where('virtual_card_id', $card->id)->where('kind', VirtualCardActivity::KIND_DECLINE)->where('occurred_at', '>=', now()->subDays(30))->count(),
        ];
    }

    /**
     * The provider's wording for a refusal, read into the one reason the
     * app can act on.
     */
    public static function reasonFrom(?string $text): string
    {
        $probe = strtolower((string) $text);

        return match (true) {
            str_contains($probe, 'limit')                                                                                   => VirtualCardActivity::REASON_LIMIT,
            str_contains($probe, 'froz')         || str_contains($probe, 'inactive')                                        => VirtualCardActivity::REASON_FROZEN,
            str_contains($probe, 'insufficient') || str_contains($probe, 'fund')                                            => VirtualCardActivity::REASON_INSUFFICIENT,
            str_contains($probe, 'control')      || str_contains($probe, 'not allowed') || str_contains($probe, 'restrict') => VirtualCardActivity::REASON_CONTROL,
            default                                                                                                         => VirtualCardActivity::REASON_ISSUER,
        };
    }
}
