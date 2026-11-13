<?php

declare(strict_types=1);

namespace App\Services\Vaults;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultEntry;
use App\Models\Wallet;
use App\Models\WalletEarnPlan;
use App\Notifications\VaultNotification;
use App\Services\CurrencyService;
use App\Services\TransactionService;
use App\Services\WalletService;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A vault is money set aside from a wallet into a balance of the same
 * currency that the spending screens cannot reach. Every move in or out
 * is one wallet transaction and one vault entry, written together under
 * a lock, so the two ledgers cannot disagree.
 */
class VaultService
{
    private const ROUND_UP_TYPES = [TrxType::PAYMENT, TrxType::SEND_MONEY, TrxType::MOBILE_RECHARGE, TrxType::SUBSCRIPTION, TrxType::SUBSCRIPTION_RENEWAL];

    public function __construct(
        private readonly WalletService $wallets,
        private readonly TransactionService $transactions,
        private readonly CurrencyService $currencies,
    ) {}

    /**
     * Money from the wallet into the vault. Refused, not partially done,
     * when the wallet cannot cover it.
     */
    public function deposit(User $user, Vault $vault, string $amount, string $kind = 'deposit', ?string $note = null): VaultEntry
    {
        $this->assertOpen($vault);

        return DB::transaction(function () use ($user, $vault, $amount, $kind, $note): VaultEntry {
            $vault = Vault::query()->whereKey($vault->id)->lockForUpdate()->firstOrFail();
            // Again under the lock: a close that won the race would
            // otherwise leave this money in a vault nobody can reach.
            $this->assertOpen($vault);
            $wallet = $vault->wallet()->with('currency')->firstOrFail();
            $code   = (string) $wallet->currency->code;
            $amount = DecimalMoney::normalize($amount, ApiMoney::decimals($code));
            if (DecimalMoney::compare($amount, '0') <= 0) {
                throw new NotifyErrorException(__('Amount must be greater than zero.'));
            }
            $this->wallets->assertOperational($wallet);
            if (! $this->wallets->isWalletBalanceSufficient($wallet->uuid, $amount)) {
                throw new NotifyErrorException(__('Insufficient balance.'));
            }
            $this->wallets->subtractMoney($wallet, $amount);
            $vault->update(['balance' => DecimalMoney::add((string) $vault->balance, $amount, DecimalMoney::CALCULATION_SCALE)]);

            $trx = $this->ledger($user, $vault, $wallet, $amount, TrxType::VAULT_DEPOSIT, AmountFlow::MINUS, $kind, __('Saved to :vault', ['vault' => $vault->name]));

            $entry = $vault->entries()->create(['user_id' => $user->id, 'kind' => $kind, 'amount' => $amount, 'currency' => $code, 'transaction_id' => $trx->id, 'note' => $note]);
            $this->celebrate($user, $vault->refresh());

            return $entry;
        }, 3);
    }

    /**
     * Money back to the wallet, instantly.
     */
    public function withdraw(User $user, Vault $vault, string $amount, string $kind = 'withdraw', ?string $note = null): VaultEntry
    {
        return DB::transaction(function () use ($user, $vault, $amount, $kind, $note): VaultEntry {
            $vault  = Vault::query()->whereKey($vault->id)->lockForUpdate()->firstOrFail();
            $wallet = $vault->wallet()->with('currency')->firstOrFail();
            $code   = (string) $wallet->currency->code;
            $amount = DecimalMoney::normalize($amount, ApiMoney::decimals($code));
            if (DecimalMoney::compare($amount, '0') <= 0) {
                throw new NotifyErrorException(__('Amount must be greater than zero.'));
            }
            if (DecimalMoney::compare($amount, (string) $vault->balance) > 0) {
                throw new NotifyErrorException(__('The vault does not hold that much.'));
            }
            $vault->update(['balance' => DecimalMoney::subtract((string) $vault->balance, $amount, DecimalMoney::CALCULATION_SCALE)]);
            // The obligation is the vault's own ledger: this is money the
            // customer already had, coming back.
            $this->wallets->creditSettlement($wallet, $amount);

            $trx = $this->ledger($user, $vault, $wallet, $amount, TrxType::VAULT_WITHDRAW, AmountFlow::PLUS, $kind, __('Withdrawn from :vault', ['vault' => $vault->name]));

            return $vault->entries()->create(['user_id' => $user->id, 'kind' => $kind, 'amount' => $amount, 'currency' => $code, 'transaction_id' => $trx->id, 'note' => $note]);
        }, 3);
    }

    /**
     * Whatever is left goes back to the wallet and the vault is closed.
     */
    public function close(User $user, Vault $vault): Vault
    {
        $this->assertOpen($vault);
        if (DecimalMoney::compare((string) $vault->balance, '0') > 0) {
            $this->withdraw($user, $vault, (string) $vault->balance, 'close');
        }
        $vault->update(['status' => 'closed', 'closed_at' => now(), 'next_auto_at' => null]);

        return $vault->refresh();
    }

    /**
     * The rule's next top-up, or a skip the customer hears about.
     */
    public function runAutoSave(Vault $vault): ?VaultEntry
    {
        $user = $vault->user;
        if ($user === null || $vault->status !== 'active' || $vault->auto_amount === null || $vault->auto_frequency === null) {
            return null;
        }
        $next = self::nextAutoAt($vault, CarbonImmutable::now());

        // Claim this run before any money moves: only the process that
        // moves next_auto_at off the value it read may save, so a rerun or
        // a crash between the deposit and the reschedule never saves twice.
        $claimed = Vault::query()
            ->whereKey($vault->id)
            ->where('status', 'active')
            ->where('next_auto_at', $vault->getRawOriginal('next_auto_at'))
            ->update(['next_auto_at' => $next]);
        if ($claimed !== 1) {
            return null;
        }
        $vault->next_auto_at = $next;

        try {
            return $this->deposit($user, $vault, (string) $vault->auto_amount, 'auto_save');
        } catch (NotifyErrorException $e) {
            $user->notify(new VaultNotification($vault, 'vault_autosave_skipped', ['amount' => ApiMoney::amount((string) $vault->auto_amount, $vault->wallet?->currency?->code ?? ''), 'reason' => $e->getMessage()]));

            return null;
        }
    }

    /**
     * The change on every spend from the wallet since the last run, rounded
     * up to the vault's unit, saved as one entry.
     */
    public function runRoundUps(Vault $vault): ?VaultEntry
    {
        $user = $vault->user;
        if ($user === null || $vault->status !== 'active' || ! $vault->round_ups || $vault->round_up_to === null || DecimalMoney::compare((string) $vault->round_up_to, '0') <= 0) {
            return null;
        }
        $wallet = $vault->wallet;
        $since  = $vault->last_round_up_at ?? $vault->created_at ?? now();
        $now    = now();
        $unit   = (string) $vault->round_up_to;
        $code   = strtoupper((string) $wallet?->currency?->code);
        $total  = '0';
        Transaction::query()
            ->where('user_id', $user->id)
            ->where('wallet_reference', $wallet?->uuid)
            ->where('amount_flow', AmountFlow::MINUS)
            ->where('status', TrxStatus::COMPLETED)
            ->whereIn('trx_type', self::ROUND_UP_TYPES)
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', $now)
            ->get(['amount', 'currency', 'net_amount', 'payable_amount', 'payable_currency'])
            ->each(function (Transaction $row) use (&$total, $unit, $code): void {
                $spent = self::spentFromWallet($row, $code);
                if ($spent === null || DecimalMoney::compare($spent, '0') <= 0) {
                    return;
                }
                $units = DecimalMoney::divide($spent, $unit);
                $whole = explode('.', $units)[0];
                $up    = DecimalMoney::compare($units, $whole) > 0 ? DecimalMoney::multiply(DecimalMoney::add($whole, '1', 0), $unit) : DecimalMoney::multiply($whole, $unit);
                $total = DecimalMoney::add($total, DecimalMoney::subtract($up, $spent, DecimalMoney::CALCULATION_SCALE), DecimalMoney::CALCULATION_SCALE);
            });
        $vault->update(['last_round_up_at' => $now]);
        if (DecimalMoney::compare($total, '0') <= 0) {
            return null;
        }
        try {
            return $this->deposit($user, $vault, $total, 'round_up');
        } catch (NotifyErrorException) {
            return null;
        }
    }

    /**
     * When the rule fires next: the vault's weekday or day of the month,
     * strictly after the moment given, at six in the morning.
     */
    public static function nextAutoAt(Vault $vault, CarbonImmutable $from): ?CarbonImmutable
    {
        if ($vault->auto_frequency === null || $vault->auto_amount === null) {
            return null;
        }
        if ($vault->auto_frequency === 'weekly') {
            $day  = max(1, min(7, (int) ($vault->auto_day ?? 1)));
            $next = $from->startOfDay()->setHour(6);
            for ($i = 0; $i < 8; $i++) {
                $candidate = $next->addDays($i);
                if ($candidate->isoWeekday() === $day && $candidate > $from) {
                    return $candidate;
                }
            }
        }
        $day  = max(1, min(28, (int) ($vault->auto_day ?? 1)));
        $next = $from->startOfMonth()->setDay($day)->setHour(6);

        return $next > $from ? $next : $next->addMonthNoOverflow();
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(Vault $vault, string $display, bool $withEntries = false): array
    {
        $wallet     = $vault->wallet;
        $code       = (string) ($wallet?->currency?->code ?? '');
        $saved      = (string) $vault->balance;
        $target     = $vault->target_amount === null ? null : (string) $vault->target_amount;
        $toGo       = $target               === null ? null : DecimalMoney::subtract($target, $saved, DecimalMoney::CALCULATION_SCALE);
        $toGo       = $toGo !== null && DecimalMoney::compare($toGo, '0') < 0 ? '0' : $toGo;
        $percent    = $target             === null || DecimalMoney::compare($target, '0') <= 0 ? 0 : min(100, (int) DecimalMoney::round(DecimalMoney::multiply(DecimalMoney::divide($saved, $target), '100'), 0));
        $weekly     = $vault->auto_amount === null ? '0' : ($vault->auto_frequency === 'monthly' ? DecimalMoney::multiply(DecimalMoney::divide((string) $vault->auto_amount, '52'), '12') : (string) $vault->auto_amount);
        $rate       = $this->currencies->crossRate($code, $display) ?? '1';
        $now        = CarbonImmutable::now();
        $eta        = null;
        $onTrack    = null;
        $behindBy   = null;
        $earlyWeeks = null;
        if ($toGo !== null && DecimalMoney::compare($toGo, '0') > 0 && DecimalMoney::compare($weekly, '0') > 0) {
            $weeks = (int) ceil((float) DecimalMoney::divide($toGo, $weekly));
            $eta   = $now->addWeeks($weeks);
            if ($vault->target_date !== null) {
                $deadline = CarbonImmutable::parse($vault->target_date)->endOfDay();
                $onTrack  = $eta <= $deadline;
                if ($onTrack) {
                    $earlyWeeks = (int) $eta->diffInWeeks($deadline);
                } else {
                    $weeksLeft = max(0, (int) $now->diffInWeeks($deadline, false));
                    $behindBy  = DecimalMoney::subtract($toGo, DecimalMoney::multiply($weekly, (string) $weeksLeft), DecimalMoney::CALCULATION_SCALE);
                }
            }
        } elseif ($toGo !== null && DecimalMoney::compare($toGo, '0') <= 0 && $target !== null) {
            $onTrack = true;
        }
        $earn = $wallet === null ? null : WalletEarnPlan::query()->active()->where(fn ($q) => $q->where('currency_id', $wallet->currency_id)->orWhereNull('currency_id'))->orderByDesc('currency_id')->first();

        $out = [
            'id'            => $vault->id,
            'name'          => $vault->name,
            'icon'          => $vault->icon,
            'status'        => $vault->status,
            'wallet_id'     => $wallet?->uuid,
            'currency'      => $code,
            'decimals'      => ApiMoney::decimals($code),
            'saved'         => ApiMoney::amount($saved, $code),
            'saved_display' => ApiMoney::amount(DecimalMoney::multiply($saved, $rate), $display),
            'target'        => $target === null ? null : ApiMoney::amount($target, $code),
            'target_date'   => $vault->target_date?->toDateString(),
            'to_go'         => $toGo === null ? null : ApiMoney::amount($toGo, $code),
            'percent'       => $percent,
            'reached'       => $target !== null && DecimalMoney::compare($saved, $target) >= 0,
            'auto'          => $vault->auto_amount === null ? null : ['amount' => ApiMoney::amount((string) $vault->auto_amount, $code), 'frequency' => $vault->auto_frequency, 'day' => $vault->auto_day, 'next_at' => $vault->next_auto_at?->toIso8601String(), 'weekly' => ApiMoney::amount($weekly, $code)],
            'round_ups'     => $vault->round_ups ? ['to' => ApiMoney::amount((string) $vault->round_up_to, $code), 'saved' => ApiMoney::amount((string) $vault->entries()->where('kind', 'round_up')->sum('amount'), $code)] : null,
            'projection'    => ['eta' => $eta?->toDateString(), 'on_track' => $onTrack, 'behind_by' => $behindBy === null || DecimalMoney::compare($behindBy, '0') <= 0 ? null : ApiMoney::amount($behindBy, $code), 'early_weeks' => $earlyWeeks],
            'earn'          => $earn === null ? null : ['rate' => (string) $earn->profit_rate, 'type' => $earn->profit_type?->value ?? null, 'frequency' => $earn->payout_frequency?->value ?? null, 'plan_id' => $earn->id],
            'interest'      => ApiMoney::amount('0', $code),
            'created_at'    => $vault->created_at?->toIso8601String(),
            'paused_at'     => $vault->paused_at?->toIso8601String(),
        ];
        if ($withEntries) {
            $out['entries'] = $vault->entries()->where(fn ($q) => $q->whereNull('note')->orWhere('note', '!=', 'goal_reached'))->latest('id')->limit(50)->get()->map(fn (VaultEntry $e): array => [
                'id' => $e->id, 'kind' => $e->kind, 'amount' => ApiMoney::amount((string) $e->amount, $code), 'credit' => $e->isCredit(), 'note' => $e->note, 'reference' => $e->transaction?->trx_id, 'created_at' => $e->created_at?->toIso8601String(),
            ])->values()->all();
        }

        return $out;
    }

    /**
     * Everything saved, in the display currency, with what this month
     * added and what the rules put away each week.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user, string $display): array
    {
        $vaults     = $user->vaults()->where('status', '!=', 'closed')->with('wallet.currency')->orderBy('id')->get();
        $total      = '0';
        $month      = '0';
        $weekly     = '0';
        $next       = null;
        $monthStart = CarbonImmutable::now()->startOfMonth();
        foreach ($vaults as $vault) {
            $code  = (string) ($vault->wallet?->currency?->code ?? '');
            $rate  = $this->currencies->crossRate($code, $display) ?? '1';
            $total = DecimalMoney::add($total, DecimalMoney::multiply((string) $vault->balance, $rate), DecimalMoney::CALCULATION_SCALE);
            $added = (string) $vault->entries()->whereIn('kind', ['deposit', 'auto_save', 'round_up'])->where('created_at', '>=', $monthStart)->sum('amount');
            $taken = (string) $vault->entries()->whereIn('kind', ['withdraw', 'close'])->where('created_at', '>=', $monthStart)->sum('amount');
            $month = DecimalMoney::add($month, DecimalMoney::multiply(DecimalMoney::subtract($added, $taken, DecimalMoney::CALCULATION_SCALE), $rate), DecimalMoney::CALCULATION_SCALE);
            if ($vault->status === 'active' && $vault->auto_amount !== null) {
                $perWeek = $vault->auto_frequency === 'monthly' ? DecimalMoney::multiply(DecimalMoney::divide((string) $vault->auto_amount, '52'), '12') : (string) $vault->auto_amount;
                $weekly  = DecimalMoney::add($weekly, DecimalMoney::multiply($perWeek, $rate), DecimalMoney::CALCULATION_SCALE);
                if ($vault->next_auto_at !== null && ($next === null || $vault->next_auto_at < $next)) {
                    $next = $vault->next_auto_at;
                }
            }
        }
        $featured = $vaults->filter(fn (Vault $v) => $v->status === 'active' && $v->target_date !== null)->sortBy(fn (Vault $v) => $v->target_date)->first();

        return [
            'currency'     => $display,
            'decimals'     => ApiMoney::decimals($display),
            'count'        => $vaults->count(),
            'saved'        => ApiMoney::amount($total, $display),
            'this_month'   => ApiMoney::amount($month, $display),
            'interest'     => ApiMoney::amount('0', $display),
            'auto_weekly'  => DecimalMoney::compare($weekly, '0') > 0 ? ApiMoney::amount($weekly, $display) : null,
            'next_auto_at' => $next?->toIso8601String(),
            'featured_id'  => $featured?->id,
        ];
    }

    /**
     * What a spend took out of the wallet, in the wallet's own currency.
     *
     * A row's `amount` is in the site's base currency for most flows (a
     * send, a payment); the wallet-side figure is `payable_amount` when its
     * currency is the wallet's, else `net_amount` when the row itself is
     * denominated in it - the same reading statements make. Rounding the
     * base figure rounded a euro wallet's spending in dollars.
     */
    private static function spentFromWallet(Transaction $row, string $code): ?string
    {
        if (strtoupper((string) $row->getRawOriginal('payable_currency')) === $code && $row->getRawOriginal('payable_amount') !== null) {
            return (string) $row->getRawOriginal('payable_amount');
        }

        if (strtoupper((string) $row->getRawOriginal('currency')) === $code) {
            return (string) ($row->getRawOriginal('net_amount') ?? $row->getRawOriginal('amount'));
        }

        return null;
    }

    private function assertOpen(Vault $vault): void
    {
        if ($vault->status === 'closed') {
            throw new NotifyErrorException(__('This vault is closed.'));
        }
    }

    private function ledger(User $user, Vault $vault, Wallet $wallet, string $amount, TrxType $type, AmountFlow $flow, string $kind, string $description): Transaction
    {
        $code = (string) $wallet->currency->code;

        return $this->transactions->create(new TransactionData(
            user_id: $user->id,
            trx_type: $type,
            amount: (float) $amount,
            amount_flow: $flow,
            fee: 0,
            currency: $code,
            processing_type: MethodType::AUTOMATIC,
            net_amount: (float) $amount,
            payable_amount: (float) $amount,
            payable_currency: $code,
            wallet_reference: $wallet->uuid,
            trx_data: ['vault_id' => $vault->id, 'vault_name' => $vault->name, 'kind' => $kind],
            description: $description,
            status: TrxStatus::COMPLETED,
            exchange_rate: \Wallet::fxRate($code),
        ));
    }

    /**
     * The moment a goal is met, once.
     */
    private function celebrate(User $user, Vault $vault): void
    {
        if ($vault->target_amount === null || DecimalMoney::compare((string) $vault->balance, (string) $vault->target_amount) < 0) {
            return;
        }
        if ($vault->entries()->where('note', 'goal_reached')->exists()) {
            return;
        }
        $vault->entries()->create(['user_id' => $user->id, 'kind' => 'deposit', 'amount' => '0', 'currency' => (string) ($vault->wallet?->currency?->code ?? ''), 'note' => 'goal_reached']);
        $user->notify(new VaultNotification($vault, 'vault_goal_reached', ['amount' => ApiMoney::amount((string) $vault->target_amount, (string) ($vault->wallet?->currency?->code ?? ''))]));
    }
}
