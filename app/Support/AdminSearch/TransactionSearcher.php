<?php

declare(strict_types=1);

namespace App\Support\AdminSearch;

use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ledger entries — deposits, withdrawals and everything between, one scope.
 *
 * Matched on the transaction id and the provider reference, which are what a
 * customer quotes and what a gateway's dashboard shows; an exact amount also
 * finds a row for the operator who only has a figure. Every hit opens the
 * transaction detail, so the one permission that page needs is the one this
 * scope needs.
 */
final class TransactionSearcher implements Searcher
{
    public const KEY = 'transactions';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Transactions');
    }

    public function permission(): ?string
    {
        return 'transaction-list';
    }

    /**
     * @return list<array{type: string, id: string, title: string, sub: string, url: string, icon: string|null, initials: string|null, badge: string|null, tone: string}>
     */
    public function search(string $query, int $limit): array
    {
        $needle = trim($query);

        if ($needle === '') {
            return [];
        }

        $like = '%'.$needle.'%';

        $transactions = Transaction::query()
            ->whereNotNull('trx_id')
            ->where(static function (Builder $where) use ($needle, $like): void {
                $where->where('trx_id', 'like', $like)
                    ->orWhere('trx_reference', 'like', $like);

                if (is_numeric($needle)) {
                    $where->orWhere('amount', $needle);
                }
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $transactions->map(static function (Transaction $transaction): array {
            $type   = $transaction->trx_type instanceof TrxType ? $transaction->trx_type->value : (string) $transaction->trx_type;
            $status = $transaction->status instanceof TrxStatus ? $transaction->status : TrxStatus::tryFrom((string) $transaction->status);

            return [
                'type'  => 'transaction',
                'id'    => 'transaction:'.$transaction->trx_id,
                'title' => $transaction->trx_id.' · '.Money::format($transaction->amount, $transaction->currency),
                'sub'   => implode(' · ', array_filter([
                    __(ucfirst(str_replace('_', ' ', $type))),
                    $transaction->provider ?: $transaction->processing_type,
                    $transaction->user?->name,
                    $transaction->created_at?->diffForHumans(),
                ])),
                'url'      => route('admin.transaction.show', $transaction->trx_id),
                'icon'     => asset('general/static/svg/'.self::iconFor($type).'.svg'),
                'initials' => null,
                'badge'    => $status === null ? null : (string) $status->label(),
                'tone'     => match ($status) {
                    TrxStatus::COMPLETED => 'success',
                    TrxStatus::PENDING   => 'warn',
                    null                 => 'neutral',
                    default              => 'danger',
                },
            ];
        })->values()->all();
    }

    private static function iconFor(string $type): string
    {
        return match ($type) {
            TrxType::DEPOSIT->value  => 'sidebar-deposit',
            TrxType::WITHDRAW->value => 'sidebar-withdraw',
            default                  => 'transaction',
        };
    }
}
