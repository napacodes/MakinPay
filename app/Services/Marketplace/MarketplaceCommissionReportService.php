<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enums\SystemAccountType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\MarketplaceSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Support\DecimalMoney;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class MarketplaceCommissionReportService
{
    public function account(): ?User
    {
        $account = MarketplaceSetting::current()?->commissionUser()->first();

        return $account?->isSystemAccount(SystemAccountType::MARKETPLACE_COMMISSION)
            ? $account
            : null;
    }

    /**
     * @return array{
     *     wallets: Collection<int, array<string, mixed>>,
     *     lifetime: Collection<string, array<string, string|int>>,
     *     last_30_days: Collection<string, array<string, string|int>>,
     *     today: Collection<string, array<string, string|int>>,
     *     commission_count: int,
     *     refund_count: int,
     *     reconciled_count: int,
     *     unreconciled_count: int
     * }
     */
    public function summary(User $account): array
    {
        $lifetime   = $this->totals($account);
        $last30Days = $this->totals($account, now()->subDays(30));
        $today      = $this->totals($account, now()->startOfDay());
        $wallets    = $this->walletRows($account, $lifetime);

        return [
            'wallets'            => $wallets,
            'lifetime'           => $lifetime,
            'last_30_days'       => $last30Days,
            'today'              => $today,
            'commission_count'   => $this->ledger($account)->where('trx_type', TrxType::MARKETPLACE_COMMISSION)->count(),
            'refund_count'       => $this->ledger($account)->where('trx_type', TrxType::MARKETPLACE_REFUND)->count(),
            'reconciled_count'   => $wallets->where('is_reconciled', true)->count(),
            'unreconciled_count' => $wallets->where('is_reconciled', false)->count(),
        ];
    }

    public function recentTransactions(User $account, int $perPage = 15): LengthAwarePaginator
    {
        return $this->ledger($account)
            ->select([
                'id', 'user_id', 'trx_id', 'trx_type', 'amount', 'amount_flow', 'currency',
                'trx_reference', 'wallet_reference', 'status', 'created_at',
            ])
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return LazyCollection<int, Transaction> */
    public function exportRows(User $account): LazyCollection
    {
        return $this->ledger($account)
            ->select([
                'id', 'user_id', 'trx_id', 'trx_type', 'amount', 'amount_flow', 'currency',
                'trx_reference', 'wallet_reference', 'status', 'created_at',
            ])
            ->lazyById(500);
    }

    /**
     * @return Collection<string, array{currency: string, credited: string, refunded: string, net: string, entries: int}>
     */
    private function totals(User $account, ?CarbonInterface $since = null): Collection
    {
        return $this->ledger($account)
            ->when($since, fn (Builder $query) => $query->where('created_at', '>=', $since))
            ->select('currency')
            ->selectRaw('SUM(CASE WHEN trx_type = ? THEN amount ELSE 0 END) AS credited', [TrxType::MARKETPLACE_COMMISSION->value])
            ->selectRaw('SUM(CASE WHEN trx_type = ? THEN amount ELSE 0 END) AS refunded', [TrxType::MARKETPLACE_REFUND->value])
            ->selectRaw('COUNT(*) AS entries')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->mapWithKeys(function (Transaction $row): array {
                $credited = DecimalMoney::normalize((string) ($row->getAttribute('credited') ?? 0));
                $refunded = DecimalMoney::normalize((string) ($row->getAttribute('refunded') ?? 0));

                return [(string) $row->currency => [
                    'currency' => (string) $row->currency,
                    'credited' => $credited,
                    'refunded' => $refunded,
                    'net'      => DecimalMoney::subtract($credited, $refunded, DecimalMoney::CALCULATION_SCALE),
                    'entries'  => (int) $row->getAttribute('entries'),
                ]];
            });
    }

    /**
     * @param  Collection<string, array{currency: string, credited: string, refunded: string, net: string, entries: int}> $lifetime
     * @return Collection<int, array<string, mixed>>
     */
    private function walletRows(User $account, Collection $lifetime): Collection
    {
        $wallets = Wallet::query()
            ->with('currency:id,code,symbol,status')
            ->where('user_id', $account->id)
            ->get()
            ->keyBy(fn (Wallet $wallet): string => (string) $wallet->currency->code);

        return $wallets->keys()
            ->merge($lifetime->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $currency) use ($wallets, $lifetime): array {
                $wallet = $wallets->get($currency);
                $totals = $lifetime->get($currency, [
                    'credited' => '0',
                    'refunded' => '0',
                    'net'      => '0',
                    'entries'  => 0,
                ]);
                $balance    = DecimalMoney::normalize((string) ($wallet?->balance ?? 0));
                $difference = DecimalMoney::subtract($balance, $totals['net'], DecimalMoney::CALCULATION_SCALE);

                return [
                    'currency'         => $currency,
                    'symbol'           => $wallet?->currency?->symbol,
                    'balance'          => $balance,
                    'credited'         => $totals['credited'],
                    'refunded'         => $totals['refunded'],
                    'ledger_net'       => $totals['net'],
                    'difference'       => $difference,
                    'is_reconciled'    => DecimalMoney::compare($difference, '0') === 0,
                    'wallet_status'    => (bool) ($wallet?->status ?? false),
                    'currency_status'  => (bool) ($wallet?->currency?->status ?? false),
                    'wallet_reference' => $wallet?->uuid,
                ];
            });
    }

    private function ledger(User $account): Builder
    {
        return Transaction::query()
            ->without('user')
            ->where('user_id', $account->id)
            ->where('provider', 'marketplace')
            ->where('status', TrxStatus::COMPLETED)
            ->whereIn('trx_type', [
                TrxType::MARKETPLACE_COMMISSION,
                TrxType::MARKETPLACE_REFUND,
            ]);
    }
}
