<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\BillPayment\BillPaymentFulfilment;
use App\Enums\BillPayment\BillPaymentStatus;
use App\Enums\KycStatus;
use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Enums\MerchantStatus;
use App\Enums\MethodType;
use App\Enums\P2P\DisputeStatus;
use App\Enums\TicketStatus;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Enums\VirtualCard\CardholderStatus;
use App\Enums\VirtualCard\VirtualCardRequestStatus;
use App\Models\BillPayment;
use App\Models\Cardholders;
use App\Models\KycSubmission;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentRow;
use App\Models\Merchant;
use App\Models\P2P\Dispute;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\VirtualCardRequest;
use App\Services\CurrencyService;
use App\Services\FeatureManager;
use App\Services\MassPayment\MassPaymentActor;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * The six review queues, with how long the oldest item has been waiting and
 * how much money is sitting behind it.
 *
 * The previous dashboard showed only a count, which cannot distinguish "23
 * deposits, oldest four minutes old" from "23 deposits, oldest six hours old
 * and 48,000 unreleased". Same six sources as the old $quickRequests — the
 * counts are deliberately identical, so nothing that reconciled against them
 * moves.
 */
class DashboardQueueService
{
    /**
     * Above this many decisions in a day the median wait is not offered. See
     * medianWaitHours().
     */
    private const MEDIAN_SAMPLE_CAP = 2000;

    /**
     * Past this many days the oldest wait is shown as a date rather than a
     * duration. See waitLabel().
     */
    private const DATE_AFTER_DAYS = 30;

    /**
     * When a mass payment batch started waiting: its last progress, else its
     * confirmation. A batch only counts once it has stalled or been held, so
     * the day it was drafted says nothing about how long it has been stuck.
     */
    private const MASS_PAYMENT_WAITING_SINCE = 'COALESCE(last_progress_at, confirmed_at, created_at)';

    /** Memo for billPaymentsInstalled(). */
    private ?bool $billPaymentsInstalled = null;

    /** Memo for massPaymentsInstalled(). */
    private ?bool $massPaymentsInstalled = null;

    public function __construct(
        private readonly DashboardSla $sla,
        private readonly CurrencyService $currencies,
        private readonly FeatureManager $features,
    ) {}

    /**
     * Every queue this service knows, in the order the panel lists them.
     *
     * DashboardSla reads its targets by the same keys, so a queue and its SLA
     * cannot drift apart by being named differently in two places.
     */
    public const KEYS = [
        'deposit',
        'withdraw',
        'kyc',
        'merchant',
        'ticket',
        'vc_request',
        'dispute',
        'cardholder',
        'bill_payment',
        'mass_payment',
    ];

    /**
     * The one place a review queue is named, tinted and given its glyph.
     *
     * These strings used to be written out three times: the panel rows built
     * them inline below, the panel footer kept a short-word map of its own,
     * and the SLA modal kept a third list with its own wording and its own
     * copy of the default hours. They agreed on the day each was written and
     * had no reason to go on agreeing — renaming the queues for the redesign
     * left the modal calling two of them something the panel behind it no
     * longer did, on the same screen.
     *
     * `title` and `subtitle` name the row, `short` is the word the footer uses
     * for it in a running list, and `icon` is the FontAwesome class every
     * surface draws it with. The hours are deliberately not here:
     * DashboardSla::TARGETS owns those, keyed the same way.
     *
     * @return array<string, array{title: string, subtitle: string, short: string, icon: string}>
     */
    public static function naming(): array
    {
        return [
            'deposit' => [
                'title'    => __('Deposit approvals'),
                'subtitle' => __('Bank transfer proofs to confirm'),
                'short'    => __('deposits'),
                'icon'     => 'fa-circle-down',
            ],
            'withdraw' => [
                'title'    => __('Withdrawal approvals'),
                'subtitle' => __('Payouts to send'),
                'short'    => __('withdrawals'),
                'icon'     => 'fa-circle-up',
            ],
            'kyc' => [
                'title'    => __('Identity (KYC) checks'),
                'subtitle' => __('ID documents to verify'),
                'short'    => __('KYC'),
                'icon'     => 'fa-id-card',
            ],
            'merchant' => [
                'title'    => __('Merchant applications'),
                'subtitle' => __('New merchant accounts to approve'),
                'short'    => __('merchants'),
                'icon'     => 'fa-building',
            ],
            'ticket' => [
                'title'    => __('Support tickets'),
                'subtitle' => __('Tickets not yet assigned'),
                'short'    => __('tickets'),
                'icon'     => 'fa-headset',
            ],
            'vc_request' => [
                'title'    => __('Virtual card requests'),
                'subtitle' => __('New card requests to approve'),
                'short'    => __('cards'),
                'icon'     => 'fa-credit-card',
            ],
            'dispute' => [
                'title'    => __('Trade disputes'),
                'subtitle' => __('P2P disputes to resolve'),
                'short'    => __('disputes'),
                'icon'     => 'fa-scale-balanced',
            ],
            'cardholder' => [
                'title'    => __('Cardholder checks'),
                'subtitle' => __('Cardholder profiles to approve'),
                'short'    => __('cardholders'),
                'icon'     => 'fa-address-card',
            ],
            'bill_payment' => [
                'title'    => __('Bill payments'),
                'subtitle' => __('Bills to pay at the biller'),
                'short'    => __('bills'),
                'icon'     => 'fa-receipt',
            ],
            'mass_payment' => [
                'title'    => __('Mass payments'),
                'subtitle' => __('Batches held or stalled mid-payout'),
                'short'    => __('mass payments'),
                'icon'     => 'fa-users',
            ],
        ];
    }

    /**
     * What is waiting, per queue — the one definition of it.
     *
     * The dashboard panel and the sidebar badges both read this. They used to
     * hold a copy each: the same eight scopes written out twice, in two
     * files, with nothing tying them together. They agreed at the time and
     * would have gone on agreeing right up until somebody changed one of
     * them — at which point one number on the page would quietly stop
     * matching the other, and neither would look wrong on its own.
     *
     * A builder rather than a count, because the two callers want different
     * things from the same scope: the badge counts it, while the panel also
     * sums the money behind it and finds its oldest row. Nothing is executed
     * here, and every call returns a fresh builder for the caller to own.
     *
     * @return Builder<*>
     */
    public static function pendingScope(string $key): Builder
    {
        return match ($key) {
            'deposit'    => self::manualTransactions(TrxType::DEPOSIT),
            'withdraw'   => self::manualTransactions(TrxType::WITHDRAW),
            'kyc'        => KycSubmission::query()->where('status', KycStatus::PENDING),
            'merchant'   => Merchant::query()->where('status', MerchantStatus::PENDING),
            'ticket'     => Ticket::query()->where('status', TicketStatus::PENDING),
            'vc_request' => VirtualCardRequest::query()->where('status', VirtualCardRequestStatus::Pending),
            'dispute'    => Dispute::query()->where('status', DisputeStatus::OPEN),
            'cardholder' => Cardholders::query()->where('status', CardholderStatus::PENDING),
            /*
             * The one queue definition lives on the model, because the bill
             * payment screens read it too; this hands back the same scope.
             */
            'bill_payment' => BillPayment::query()->awaitingAction(),
            /*
             * Held for review, or queued or processing with no progress for
             * MassPaymentBatch::STALLED_AFTER_MINUTES. The admin list's
             * "needs attention" filter reads the same model scope.
             */
            'mass_payment' => MassPaymentBatch::query()->awaitingAction(),
            default        => throw new InvalidArgumentException("Unknown queue [{$key}]."),
        };
    }

    /**
     * @return array<string, Builder<*>>
     */
    public static function pendingScopes(): array
    {
        $scopes = [];

        foreach (self::KEYS as $key) {
            $scopes[$key] = self::pendingScope($key);
        }

        return $scopes;
    }

    /**
     * Manual deposits and withdrawals differ only by type.
     *
     * `user` is globally eager-loaded on Transaction; an aggregate has no use
     * for it, and without() keeps each read to a single query.
     *
     * @return Builder<*>
     */
    private static function manualTransactions(TrxType $type): Builder
    {
        return Transaction::query()
            ->without('user')
            ->where('trx_type', $type)
            ->where('status', TrxStatus::PENDING)
            ->where('processing_type', MethodType::MANUAL);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function queues(): Collection
    {
        $now  = now();
        $name = self::naming();

        $queues = collect([
            $this->transactionQueue('deposit', $name['deposit'], route('admin.deposit.manual-request'), $now),
            $this->transactionQueue('withdraw', $name['withdraw'], route('admin.withdraw.manual-request'), $now),
            $this->simpleQueue('kyc', $name['kyc'], route('admin.kyc.pending'), $now),
            $this->simpleQueue('merchant', $name['merchant'], route('admin.merchant.pending'), $now),
            $this->simpleQueue('ticket', $name['ticket'], route('admin.support-ticket.new'), $now),
            $this->cardRequestQueue($now),
        ]);

        /*
         * The disputes screen sits behind `admin.feature:p2p_marketplace`, so
         * on a site with the module switched off this row would be a Review
         * button that 404s. A queue nobody can open is not a queue.
         */
        if ($this->features->isEnabled('p2p_marketplace')) {
            $queues->push($this->disputeQueue($now));
        }

        $queues->push($this->simpleQueue('cardholder', $name['cardholder'], route('admin.virtual-card.cardholders.index', ['status' => CardholderStatus::PENDING->value]), $now));

        $billPayments = $this->billPaymentQueue($now);

        if ($billPayments !== null) {
            $queues->push($billPayments);
        }

        $massPayments = $this->massPaymentQueue($now);

        if ($massPayments !== null) {
            $queues->push($massPayments);
        }

        return $queues->values();
    }

    /**
     * Bills waiting to be paid at the biller.
     *
     * The module ships switched off, so the row stays out of the panel until
     * it is on: a queue that cannot fill is noise. Bills already waiting are
     * the exception. Switching the module off does not pay them, and the
     * queue screen is deliberately not behind admin.feature, so the Review
     * link still opens the only screen that can settle or refund them.
     *
     * @return array<string, mixed>|null
     */
    private function billPaymentQueue(CarbonInterface $now): ?array
    {
        if (! $this->billPaymentsInstalled()) {
            return null;
        }

        $row = $this->simpleQueue('bill_payment', self::naming()['bill_payment'], route('admin.bill-payment.queue'), $now);

        return $row['count'] > 0 || $this->features->isEnabled('bill_payment') ? $row : null;
    }

    /**
     * Whether the bill payment tables exist yet.
     *
     * The dashboard is the first page after sign-in, and an installation that
     * has the new code but has not run its migrations must still open it.
     * Asked at most once per instance, since queues() and clearedSummary()
     * can both need it on the same request.
     */
    private function billPaymentsInstalled(): bool
    {
        return $this->billPaymentsInstalled ??= Schema::hasTable('bill_payments');
    }

    /**
     * Mass payment batches an operator has to look at, with the money still
     * reserved behind them.
     *
     * Same rule as bills: the row stays off the panel while the module is off
     * and nothing waits, but a batch already held keeps its row, because
     * switching the module off releases nobody's money and the admin screens
     * are deliberately not behind admin.feature.
     *
     * @return array<string, mixed>|null
     */
    private function massPaymentQueue(CarbonInterface $now): ?array
    {
        if (! $this->massPaymentsInstalled()) {
            return null;
        }

        $base  = self::pendingScope('mass_payment');
        $stats = $this->pendingStats($base, $this->sla->hoursFor('mass_payment'), $now, self::MASS_PAYMENT_WAITING_SINCE);

        if ($stats['count'] === 0 && ! $this->features->isEnabled('mass_payment')) {
            return null;
        }

        $value = null;

        if ($stats['count'] > 0) {
            /*
             * Still reserved: the payable of every pending line, flagged or
             * not, as the batch detail page counts it. The batch's own
             * paid_payable and released_amount are written only at finalize,
             * which a waiting batch has not reached, so they showed a held
             * batch that had paid nine lines of ten as holding all ten. SUM
             * runs on the decimal column, so the per-currency figure is exact
             * before it is converted for display.
             */
            $rows = MassPaymentRow::query()
                ->join('mass_payment_batches', 'mass_payment_batches.id', '=', 'mass_payment_rows.mass_payment_batch_id')
                ->whereIn('mass_payment_rows.mass_payment_batch_id', (clone $base)->select('mass_payment_batches.id'))
                ->where('mass_payment_rows.status', MassPaymentRowStatus::Pending->value)
                ->selectRaw('mass_payment_batches.currency_code as currency, SUM(COALESCE(mass_payment_rows.payable_amount, 0)) as amount')
                ->groupBy('mass_payment_batches.currency_code')
                ->toBase()
                ->get();

            $value = (float) $this->currencies->sumInBaseCurrency($rows, 'amount');
        }

        return $this->row(
            'mass_payment',
            self::naming()['mass_payment'],
            route('admin.mass-payment.index', ['attention' => 1]),
            $stats,
            $value,
            $now,
        );
    }

    /**
     * Whether the mass payment tables exist yet. Same reasoning as
     * billPaymentsInstalled(): an upgrade that has not migrated must still
     * open the dashboard.
     */
    private function massPaymentsInstalled(): bool
    {
        return $this->massPaymentsInstalled ??= Schema::hasTable('mass_payment_batches');
    }

    /**
     * SLA state first (over, then near, then in), count second. A queue with
     * nothing in it always sorts last regardless of mode: an empty queue has
     * no urgency to rank.
     *
     * @param  Collection<int, array<string, mixed>> $queues
     * @return Collection<int, array<string, mixed>>
     */
    public function sort(Collection $queues, string $mode = 'sla'): Collection
    {
        $rank = [
            DashboardSla::STATE_OVER => 0,
            DashboardSla::STATE_NEAR => 1,
            DashboardSla::STATE_IN   => 2,
        ];

        return $queues
            ->sort(function (array $a, array $b) use ($mode, $rank): int {
                $emptiness = ($a['count'] === 0 ? 1 : 0) <=> ($b['count'] === 0 ? 1 : 0);

                if ($emptiness !== 0) {
                    return $emptiness;
                }

                /*
                 * Urgency ties break on how much of the target the oldest item
                 * has used, not on how many are queued. Two queues both past
                 * SLA are not equally late, and the browser's own re-sort has
                 * always ranked them this way — the server disagreeing meant
                 * the first paint and the first click on Urgency produced
                 * different orders.
                 */
                $used = static fn (array $queue): float => $queue['sla_hours'] > 0
                    ? $queue['elapsed_hours'] / $queue['sla_hours']
                    : 0.0;

                return match ($mode) {
                    'count' => $b['count']               <=> $a['count'],
                    'value' => ($b['value_base'] ?? 0)   <=> ($a['value_base'] ?? 0),
                    default => ($rank[$a['state']] ?? 3) <=> ($rank[$b['state']] ?? 3)
                        ?: $used($b)                     <=> $used($a),
                };
            })
            ->values();
    }

    /**
     * What the operator cleared, for the all-clear banner. Only asked for when
     * every queue is empty, so the extra queries never run on a busy site.
     *
     * @return array{last_cleared_at: CarbonInterface|null, reviews_today: int, median_wait_hours: float|null}
     */
    public function clearedSummary(): array
    {
        $startOfDay = now()->startOfDay();
        $sources    = $this->decidedSources();

        $count  = 0;
        $lastAt = null;

        foreach ($sources as $source) {
            $row = (clone $source)
                ->where('updated_at', '>=', $startOfDay)
                ->selectRaw('COUNT(*) as decided, MAX(updated_at) as last_at')
                ->first();

            $count += (int) ($row->decided ?? 0);

            if (($row->last_at ?? null) !== null) {
                $decidedAt = Carbon::parse($row->last_at);
                $lastAt    = $lastAt === null || $decidedAt->greaterThan($lastAt) ? $decidedAt : $lastAt;
            }
        }

        if ($count === 0) {
            return ['last_cleared_at' => null, 'reviews_today' => 0, 'median_wait_hours' => null];
        }

        return [
            'last_cleared_at'   => $lastAt,
            'reviews_today'     => $count,
            'median_wait_hours' => $this->medianWaitHours($sources, $startOfDay, $count),
        ];
    }

    /**
     * The median time an item waited before it was decided today.
     *
     * A median needs every value, and a platform can clear tens of thousands
     * of items in a day — so above the cap the figure is simply not offered.
     * The banner already renders without it, which is a better answer than
     * loading the whole day into memory to produce one number.
     *
     * @param list<\Illuminate\Database\Eloquent\Builder<*>> $sources
     */
    private function medianWaitHours(array $sources, CarbonInterface $startOfDay, int $decidedCount): ?float
    {
        if ($decidedCount > self::MEDIAN_SAMPLE_CAP) {
            return null;
        }

        $waits = collect();

        foreach ($sources as $source) {
            $waits = $waits->concat(
                (clone $source)
                    ->where('updated_at', '>=', $startOfDay)
                    ->reorder()
                    ->get(['created_at', 'updated_at'])
                    ->map(fn ($row): ?float => $row->created_at === null || $row->updated_at === null
                        ? null
                        : max(0, $row->created_at->floatDiffInHours($row->updated_at)))
            );
        }

        $waits = $waits->filter(fn (?float $hours): bool => $hours !== null)->sort()->values();

        return $waits->isEmpty() ? null : $this->median($waits->all());
    }

    /**
     * Every queue's decided-item query, in one place so the count and the
     * median can never be measured over different sets.
     *
     * @return list<\Illuminate\Database\Eloquent\Builder<*>>
     */
    private function decidedSources(): array
    {
        $sources = [
            // `user` is globally eager-loaded on Transaction and nothing here
            // reads it; without() keeps the aggregate to one query.
            Transaction::query()
                ->without('user')
                ->whereIn('trx_type', [TrxType::DEPOSIT, TrxType::WITHDRAW])
                ->where('processing_type', MethodType::MANUAL)
                ->whereIn('status', [TrxStatus::COMPLETED, TrxStatus::CANCELED, TrxStatus::FAILED]),
            KycSubmission::query()->whereIn('status', [KycStatus::APPROVED, KycStatus::REJECTED]),
            Merchant::query()->whereIn('status', [MerchantStatus::APPROVED, MerchantStatus::REJECTED]),
            Cardholders::query()->whereIn('status', [CardholderStatus::APPROVED, CardholderStatus::REJECTED]),
            VirtualCardRequest::query()->whereIn('status', [
                VirtualCardRequestStatus::AdminApproved,
                VirtualCardRequestStatus::Issued,
                VirtualCardRequestStatus::Rejected,
            ]),
            Ticket::query()->whereIn('status', [TicketStatus::REPLIED, TicketStatus::CLOSED]),
            Dispute::query()->whereIn('status', [DisputeStatus::RESOLVED, DisputeStatus::REJECTED]),
        ];

        /*
         * Only the bills an operator paid or refunded. A provider settles its
         * own rows, and counting those would credit the operator with work
         * nobody did.
         */
        if ($this->billPaymentsInstalled()) {
            $sources[] = BillPayment::query()
                ->where('fulfilment', BillPaymentFulfilment::Operator->value)
                ->whereIn('status', [BillPaymentStatus::Completed->value, BillPaymentStatus::Failed->value]);
        }

        /*
         * Only the batches an operator acted on: resumed, closed after review
         * or had their remaining rows cancelled. A batch that paid out on its
         * own was nobody's review, and one still held is not decided yet.
         */
        if ($this->massPaymentsInstalled()) {
            $sources[] = MassPaymentBatch::query()
                ->where(fn (Builder $decided): Builder => $decided
                    ->whereNotNull('reviewed_by_admin_id')
                    ->orWhere('stop_requested_by_type', MassPaymentActor::TYPE_ADMIN))
                ->where('status', '!=', MassPaymentBatchStatus::NeedsReview->value);
        }

        return $sources;
    }

    /**
     * @param list<float> $values sorted ascending
     */
    private function median(array $values): float
    {
        $count  = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    /**
     * Count, oldest, arrivals today and how many are already late — in one
     * pass.
     *
     * These were four separate round trips per queue, and the late count did
     * not exist at all. Conditional aggregation answers all of it at once, so
     * the panel got a new figure and six fewer queries at the same time.
     *
     * `$waitingSince` is the column an item's wait is measured from: a fixed
     * expression from this class, never input.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*> $query
     * @return array{count: int, oldest: mixed, added_today: int, over_count: int}
     */
    private function pendingStats($query, int $slaHours, CarbonInterface $now, string $waitingSince = 'created_at'): array
    {
        $stats = (clone $query)
            ->selectRaw(
                'COUNT(*) as pending_count,'
                ." MIN({$waitingSince}) as oldest_at,"
                ." SUM(CASE WHEN {$waitingSince} >= ? THEN 1 ELSE 0 END) as added_today,"
                ." SUM(CASE WHEN {$waitingSince} <= ? THEN 1 ELSE 0 END) as over_count",
                [$now->copy()->startOfDay(), $now->copy()->subHours($slaHours)]
            )
            ->first();

        return [
            'count'       => (int) ($stats->pending_count ?? 0),
            'oldest'      => $stats->oldest_at ?? null,
            'added_today' => (int) ($stats->added_today ?? 0),
            'over_count'  => (int) ($stats->over_count ?? 0),
        ];
    }

    /**
     * @param  array{title: string, subtitle: string, short: string, icon: string} $name
     * @return array<string, mixed>
     */
    private function transactionQueue(string $key, array $name, string $link, CarbonInterface $now): array
    {
        $base  = self::pendingScope($key);
        $stats = $this->pendingStats($base, $this->sla->hoursFor($key), $now);

        $rows = $stats['count'] === 0
            ? collect()
            : (clone $base)->selectRaw('currency, SUM(amount) as amount')->groupBy('currency')->get();

        return $this->row(
            $key,
            $name,
            $link,
            $stats,
            $stats['count'] === 0 ? null : (float) $this->currencies->sumInBaseCurrency($rows, 'amount'),
            $now,
        );
    }

    /**
     * A queue whose rows carry no money to total.
     *
     * @param  array{title: string, subtitle: string, short: string, icon: string} $name
     * @return array<string, mixed>
     */
    private function simpleQueue(string $key, array $name, string $link, CarbonInterface $now): array
    {
        return $this->row(
            $key,
            $name,
            $link,
            $this->pendingStats(self::pendingScope($key), $this->sla->hoursFor($key), $now),
            null,
            $now,
        );
    }

    /**
     * Escalated P2P trades, with the escrowed order total behind them.
     *
     * The money is held in the order's wallet currency, so the wallet has to
     * come along for the total to be comparable with the other queues.
     *
     * @return array<string, mixed>
     */
    private function disputeQueue(CarbonInterface $now): array
    {
        $base  = self::pendingScope('dispute');
        $stats = $this->pendingStats($base, $this->sla->hoursFor('dispute'), $now);

        $value = null;

        if ($stats['count'] > 0) {
            $rows = (clone $base)
                ->with('order.wallet.currency')
                ->get(['id', 'order_id'])
                ->map(fn (Dispute $dispute): object => (object) [
                    'amount'   => (float) ($dispute->order?->total ?? 0),
                    'currency' => $dispute->order?->wallet?->currency?->code,
                ]);

            $value = (float) $this->currencies->sumInBaseCurrency($rows, 'amount');
        }

        return $this->row(
            'dispute',
            self::naming()['dispute'],
            route('admin.p2p.disputes.index'),
            $stats,
            $value,
            $now,
        );
    }

    /**
     * Card requests carry their figure in the funding wallet's currency, so
     * the wallet has to come along for the amount to mean anything.
     *
     * @return array<string, mixed>
     */
    private function cardRequestQueue(CarbonInterface $now): array
    {
        $base  = self::pendingScope('vc_request');
        $stats = $this->pendingStats($base, $this->sla->hoursFor('vc_request'), $now);

        $value = null;

        if ($stats['count'] > 0) {
            $rows = (clone $base)
                ->with('wallet.currency')
                ->get(['id', 'wallet_id', 'initial_load_amount'])
                ->map(fn (VirtualCardRequest $request): object => (object) [
                    'amount'   => (float) ($request->initial_load_amount ?? 0),
                    'currency' => $request->wallet?->currency?->code,
                ]);

            $value = (float) $this->currencies->sumInBaseCurrency($rows, 'amount');
        }

        return $this->row(
            'vc_request',
            self::naming()['vc_request'],
            route('admin.virtual-card.requests.awaiting'),
            $stats,
            $value,
            $now,
        );
    }

    /**
     * @param  array{title: string, subtitle: string, short: string, icon: string} $name
     * @param  array{count: int, oldest: mixed, added_today: int, over_count: int} $stats
     * @return array<string, mixed>
     */
    private function row(
        string $key,
        array $name,
        string $link,
        array $stats,
        ?float $valueBase,
        CarbonInterface $now,
    ): array {
        $count    = $stats['count'];
        $oldestAt = $stats['oldest'] === null ? null : Carbon::parse($stats['oldest']);
        $target   = $this->sla->hoursFor($key);

        /*
         * floatDiffInHours() is absolute, so a row timestamped in the future —
         * clock skew on a replica, a bad import — would come back as a large
         * positive age and light the queue up as over SLA. Nothing has been
         * waiting yet if it has not happened yet.
         */
        $elapsed = $oldestAt === null || $oldestAt->greaterThan($now)
            ? 0.0
            : $oldestAt->floatDiffInHours($now);

        return [
            'key'         => $key,
            'title'       => $name['title'],
            'subtitle'    => $name['subtitle'],
            'icon'        => $name['icon'],
            'link'        => $link,
            'count'       => $count,
            'added_today' => $stats['added_today'],
            /*
             * How many of them are actually late, not just how many are
             * waiting. "4 pending" beside a red row read as four late items;
             * it is usually one late item and three that arrived this morning.
             */
            'over_count'    => min($stats['over_count'], $count),
            'oldest_at'     => $oldestAt,
            'elapsed_hours' => $elapsed,
            'sla_hours'     => $target,
            'state'         => $count === 0 ? DashboardSla::STATE_IN : $this->sla->state($elapsed, $target),
            'sla_percent'   => $count === 0 ? 0 : $this->sla->percentUsed($elapsed, $target),
            'value_base'    => $valueBase,
            /*
             * Compact on the row, exact on hover. The column is 80px wide and
             * "18,420.00" does not fit it; "18.4k" does, and the figure an
             * operator would reconcile against is one hover away.
             */
            'value_display' => $valueBase === null ? null : Money::compact($valueBase, $this->baseCode()),
            'value_full'    => $valueBase === null ? null : Money::format($valueBase, $this->baseCode()),
            'oldest_label'  => $count     === 0 ? null : $this->waitLabel($elapsed, $oldestAt),
        ];
    }

    /**
     * How long the oldest item has been waiting.
     *
     * Past a month a duration stops being information. "156d 19h" reads as a
     * bug in the dashboard rather than a fact about the queue, and nobody
     * converts it back into a date in their head — so past the cutoff the
     * date is what gets shown.
     */
    private function waitLabel(float $hours, ?CarbonInterface $oldestAt): string
    {
        if ($oldestAt !== null && $hours >= self::DATE_AFTER_DAYS * 24) {
            return __('since :date', ['date' => $oldestAt->translatedFormat('j M')]);
        }

        return $this->durationLabel($hours);
    }

    /**
     * "1d 4h", "6h 12m", "46m" — the way an operator says it out loud, and
     * short enough to sit in a table cell without wrapping.
     */
    private function durationLabel(float $hours): string
    {
        $minutes = (int) round($hours * 60);

        if ($minutes < 60) {
            return $minutes.'m';
        }

        $wholeHours = intdiv($minutes, 60);

        if ($wholeHours < 24) {
            $rest = $minutes % 60;

            return $rest === 0 ? $wholeHours.'h' : $wholeHours.'h '.$rest.'m';
        }

        $days = intdiv($wholeHours, 24);
        $rest = $wholeHours % 24;

        return $rest === 0 ? $days.'d' : $days.'d '.$rest.'h';
    }

    private function baseCode(): string
    {
        return strtoupper((string) (siteCurrency('code') ?: config('app.default_currency', 'USD')));
    }
}
