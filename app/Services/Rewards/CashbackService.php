<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Models\CashbackClaim;
use App\Models\User;
use App\Models\VirtualCardActivity;
use App\Models\Wallet;
use App\Services\CurrencyService;
use App\Services\TransactionService;
use App\Services\WalletService;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Card-spend cashback: an admin-set share of what a customer spent on
 * their cards in a calendar month, claimable into the default wallet for a
 * set number of days after the month ends.
 *
 * Spend is read from the cards' own ledger (settled purchases less
 * refunds), converted to the site currency at today's cross rate when the
 * month closes and frozen on the claim row from then on. The programme is
 * off unless the admin switches it on, so an upgrade changes nothing.
 */
final class CashbackService
{
    public function __construct(
        private readonly CurrencyService $currencies,
        private readonly WalletService $wallets,
        private readonly TransactionService $transactions,
    ) {}

    public function enabled(): bool
    {
        return (bool) setting('cashback_enabled', false);
    }

    public function ratePct(): string
    {
        return (string) setting('cashback_rate_pct', '0');
    }

    public function claimDays(): int
    {
        return max(1, (int) setting('cashback_claim_days', 30));
    }

    public function minSpend(): string
    {
        return (string) setting('cashback_min_spend', '0');
    }

    /**
     * What the hub shows: the last closed month's claim, if any is still
     * open or was claimed, and the current month accruing.
     *
     * @return array{enabled: bool, rate_pct: string, currency: ?string, ready: ?array<string, mixed>, accruing: array<string, mixed>}
     */
    public function describe(User $user): array
    {
        $currency = siteCurrency();
        if (! $this->enabled()) {
            return ['enabled' => false, 'rate_pct' => $this->ratePct(), 'currency' => $currency, 'ready' => null, 'accruing' => $this->accruing($user, $currency)];
        }

        $last  = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        $claim = $this->claimFor($user, $last, $currency);

        return [
            'enabled'  => true,
            'rate_pct' => $this->ratePct(),
            'currency' => $currency,
            'ready'    => $claim === null || DecimalMoney::compare((string) $claim->amount, '0') <= 0 ? null : $this->row($claim),
            'accruing' => $this->accruing($user, $currency),
        ];
    }

    /**
     * Credits a pending claim into the default wallet, once.
     *
     * @throws NotifyErrorException
     */
    public function claim(User $user, string $period): CashbackClaim
    {
        if (! $this->enabled()) {
            throw new NotifyErrorException(__('Cashback is not available right now.'));
        }

        return DB::transaction(function () use ($user, $period): CashbackClaim {
            $claim = CashbackClaim::query()
                ->where('user_id', $user->id)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            if ($claim === null) {
                throw new NotifyErrorException(__('There is no cashback to claim for that month.'));
            }
            if ($claim->status === CashbackClaim::STATUS_CLAIMED) {
                return $claim;
            }
            if ($claim->effectiveStatus() === CashbackClaim::STATUS_EXPIRED) {
                $claim->update(['status' => CashbackClaim::STATUS_EXPIRED]);
                throw new NotifyErrorException(__('The claim window for that month has closed.'));
            }
            if (DecimalMoney::compare((string) $claim->amount, '0') <= 0) {
                throw new NotifyErrorException(__('There is no cashback to claim for that month.'));
            }

            $wallet = Wallet::query()->with('currency')->whereKey($user->defaultWallet?->id)->lockForUpdate()->first();
            if ($wallet === null || ! $wallet->status || ! $wallet->currency?->status) {
                throw new NotifyErrorException(__('Your default wallet is not available to receive the cashback.'));
            }

            $rate = $this->currencies->crossRate($claim->currency, $wallet->currency->code);
            if ($rate === null) {
                throw new NotifyErrorException(__('Unable to convert currency.'));
            }
            $credit = Money::quantize(DecimalMoney::multiply((string) $claim->amount, $rate, DecimalMoney::CALCULATION_SCALE), $wallet->currency->code);

            $this->wallets->creditSettlement($wallet, $credit);
            $transaction = $this->transactions->create(new TransactionData(
                user_id: $user->id,
                trx_type: TrxType::CASHBACK,
                amount: (float) $credit,
                amount_flow: AmountFlow::PLUS,
                fee: 0,
                currency: $wallet->currency->code,
                provider: 'cashback',
                processing_type: MethodType::SYSTEM,
                net_amount: (float) $credit,
                payable_amount: (float) $claim->amount,
                payable_currency: $claim->currency,
                wallet_reference: $wallet->uuid,
                trx_reference: 'cashback_'.$claim->id,
                trx_data: ['claim_id' => $claim->id, 'period' => $claim->period, 'rate_pct' => (string) $claim->rate_pct, 'spend' => (string) $claim->spend],
                remarks: __('Cashback for :period claimed.', ['period' => $this->periodLabel($claim->period)]),
                description: 'cashback',
                status: TrxStatus::COMPLETED,
                exchange_rate: \Wallet::fxRate($wallet),
            ));

            $claim->update(['status' => CashbackClaim::STATUS_CLAIMED, 'claimed_at' => now(), 'trx_id' => $transaction->trx_id]);

            return $claim->refresh();
        }, 3);
    }

    /**
     * Claimed cashback, all time, in the site currency.
     */
    public function totalClaimed(User $user): string
    {
        return ApiMoney::amount((string) CashbackClaim::query()->where('user_id', $user->id)->where('status', CashbackClaim::STATUS_CLAIMED)->sum('amount'), siteCurrency());
    }

    /**
     * The claim row for a closed month, made on first sight so the spend
     * is frozen at the rate of the day the month was first looked at.
     */
    private function claimFor(User $user, Carbon $monthStart, ?string $currency): ?CashbackClaim
    {
        if ($currency === null) {
            return null;
        }
        $period = $monthStart->format('Y-m');
        $found  = CashbackClaim::query()->where('user_id', $user->id)->where('period', $period)->where('currency', $currency)->first();
        if ($found !== null) {
            return $found;
        }

        $spend = $this->spendBetween($user, $monthStart, $monthStart->copy()->endOfMonth(), $currency);
        if (DecimalMoney::compare($spend, $this->minSpend()) < 0) {
            $spend = '0';
        }
        $amount = Money::quantize(DecimalMoney::divide(DecimalMoney::multiply($spend, $this->ratePct(), DecimalMoney::CALCULATION_SCALE), '100', DecimalMoney::CALCULATION_SCALE), $currency);

        return CashbackClaim::query()->firstOrCreate(
            ['user_id' => $user->id, 'period' => $period, 'currency' => $currency],
            ['spend' => $spend, 'rate_pct' => $this->ratePct(), 'amount' => $amount, 'status' => CashbackClaim::STATUS_PENDING, 'claim_by' => $monthStart->copy()->endOfMonth()->addDays($this->claimDays())->toDateString()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function accruing(User $user, ?string $currency): array
    {
        $start = Carbon::today()->startOfMonth();
        $spend = $currency === null ? '0' : $this->spendBetween($user, $start, now(), $currency);
        $rate  = $this->enabled() ? $this->ratePct() : '0';

        return [
            'period'   => $start->format('Y-m'),
            'label'    => $this->periodLabel($start->format('Y-m')),
            'currency' => $currency,
            'spend'    => ApiMoney::amount($spend, $currency),
            'amount'   => ApiMoney::amount(DecimalMoney::divide(DecimalMoney::multiply($spend, $rate, DecimalMoney::CALCULATION_SCALE), '100', DecimalMoney::CALCULATION_SCALE), $currency),
            'claim_by' => $start->copy()->endOfMonth()->addDays($this->claimDays())->toDateString(),
        ];
    }

    /**
     * Settled purchases less refunds on every card, in the site currency.
     */
    private function spendBetween(User $user, Carbon $from, Carbon $to, string $currency): string
    {
        $rows = VirtualCardActivity::query()
            ->where('user_id', $user->id)
            ->whereIn('kind', [VirtualCardActivity::KIND_PURCHASE, VirtualCardActivity::KIND_REFUND])
            ->where('status', VirtualCardActivity::STATUS_SETTLED)
            ->whereBetween('occurred_at', [$from, $to])
            ->get(['kind', 'amount', 'currency']);

        $total = '0';
        foreach ($rows as $row) {
            $rate = $this->currencies->crossRate((string) $row->currency, $currency);
            if ($rate === null) {
                continue;
            }
            $amount = DecimalMoney::multiply((string) $row->amount, $rate, DecimalMoney::CALCULATION_SCALE);
            $total  = $row->kind === VirtualCardActivity::KIND_REFUND
                ? DecimalMoney::subtract($total, $amount, DecimalMoney::CALCULATION_SCALE)
                : DecimalMoney::add($total, $amount, DecimalMoney::CALCULATION_SCALE);
        }

        return DecimalMoney::compare($total, '0') < 0 ? '0' : $total;
    }

    /**
     * @return array<string, mixed>
     */
    public function row(CashbackClaim $claim): array
    {
        return [
            'period'     => $claim->period,
            'label'      => $this->periodLabel($claim->period),
            'spend'      => ApiMoney::amount((string) $claim->spend, $claim->currency),
            'rate_pct'   => (string) $claim->rate_pct,
            'amount'     => ApiMoney::amount((string) $claim->amount, $claim->currency),
            'currency'   => $claim->currency,
            'status'     => $claim->effectiveStatus(),
            'claim_by'   => $claim->claim_by?->toDateString(),
            'claimed_at' => $claim->claimed_at?->toIso8601String(),
        ];
    }

    public function periodLabel(string $period): string
    {
        $month = Carbon::createFromFormat('Y-m', $period);

        return $month === false || $month === null ? $period : $month->format('F Y');
    }
}
