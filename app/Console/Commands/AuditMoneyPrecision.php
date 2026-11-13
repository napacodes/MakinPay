<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\CurrencyType;
use App\Enums\TrxStatus;
use App\Models\Admin;
use App\Models\Currency;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Notifications\MoneyAuditAlert;
use App\Support\DecimalMoney;
use App\Support\Money;
use App\Support\RateDivergence;
use App\Support\RateGuard;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only audit of money records that predate, or contradict, the precision
 * migrations.
 *
 * This command NEVER writes to the ledger. Rows truncated by the old
 * decimal(15,2) columns cannot be repaired — `transactions` is the only record
 * of what the amount was, so any "fix" would be an unverifiable financial
 * write. The operator decides what to do with the report.
 */
class AuditMoneyPrecision extends Command
{
    /** Where the last alert's fingerprint and timestamp are remembered. */
    private const ALERT_CACHE_KEY = 'money_audit.last_alert';

    protected $signature = 'money:audit-precision
                            {--tolerance=0.00000001 : Allowed drift when reconciling payable_amount against net_amount + fee}
                            {--rate-tolerance= : Percent a method rate may sit away from its currency rate before it is reported}
                            {--csv : Write the findings to storage/app/money-audit}
                            {--notify : Alert currency administrators when findings appear. Used by the scheduled run.}';

    protected $description = 'Report transactions and currency rates that look damaged by the old 2-decimal ledger';

    public function handle(): int
    {
        $migratedAt = $this->migratedAt();

        $this->components->info($migratedAt === null
            ? 'Precision migration timestamp not recorded; auditing every row.'
            : 'Auditing rows created before '.$migratedAt->toDayDateTimeString().'.');

        $findings = collect()
            ->concat($this->zeroedAmounts($migratedAt))
            ->concat($this->imbalancedTransactions())
            ->concat($this->driftedWallets())
            ->concat($this->dustyWallets())
            ->concat($this->suspiciousRates())
            ->concat($this->staleManualCryptoRates())
            ->concat($this->missingFxSnapshots($migratedAt))
            ->concat($this->convertedMethodLimits())
            ->concat($this->divergentMethodRates());

        if ($findings->isEmpty()) {
            $this->components->info('No suspicious money records found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Check', 'Record', 'Detail'],
            $findings->map(fn (array $row): array => [$row['check'], $row['record'], $row['detail']])->all()
        );

        if ($this->option('csv')) {
            $this->writeCsv($findings->all());
        }

        if ($this->option('notify')) {
            $this->alertAdministrators($findings->all());
        }

        $this->components->warn($findings->count().' record(s) need review. Nothing was modified.');

        // Deliberately SUCCESS even with findings. The command did its job; it
        // is the ledger that needs attention, and a non-zero exit would have
        // the background-task log mark the run itself as failed.
        return self::SUCCESS;
    }

    /**
     * Notify currency administrators, at most once per finding set.
     *
     * A scheduled report writes to stdout, which under cron is nowhere — so
     * without this the schedule would be theatre. It follows
     * `remittance:alert-stuck`: database notifications to the admins who hold
     * the permission to act, deduplicated so a standing problem does not
     * re-alert every night.
     *
     * Deduplication is by fingerprint rather than by timestamp alone: an
     * unchanged finding set stays quiet until the repeat window elapses, but a
     * *new* kind of problem appearing tomorrow alerts immediately rather than
     * waiting out the window. The state lives in the cache, which this
     * application backs with the database, so it survives a restart. Losing it
     * costs one duplicate notification, never a missed one.
     *
     * @param list<array{check: string, record: string, detail: string}> $findings
     */
    private function alertAdministrators(array $findings): void
    {
        $checks = collect($findings)
            ->countBy('check')
            ->sortDesc()
            ->keys()
            ->all();

        $fingerprint = sha1(implode('|', $checks).':'.count($findings));
        $repeatAfter = max(1, (int) setting('money_audit_alert_repeat_minutes', 1440));
        $last        = Cache::get(self::ALERT_CACHE_KEY);

        if (is_array($last)
            && ($last['fingerprint'] ?? null) === $fingerprint
            && isset($last['at'])
            && Carbon::parse($last['at'])->greaterThan(now()->subMinutes($repeatAfter))) {
            $this->components->info('Findings unchanged since the last alert; administrators were not notified again.');

            return;
        }

        $admins = Admin::permission('currency-manage')->where('status', true)->get();

        if ($admins->isEmpty()) {
            $this->components->warn('No active administrator holds currency-manage, so no audit alert could be delivered.');

            return;
        }

        Notification::sendNow($admins, new MoneyAuditAlert(count($findings), $checks));

        Cache::put(self::ALERT_CACHE_KEY, [
            'fingerprint' => $fingerprint,
            'at'          => now()->toIso8601String(),
        ], now()->addMinutes($repeatAfter * 2));

        $this->components->info(sprintf('Alerted %d administrator(s).', $admins->count()));
    }

    /**
     * A completed transaction whose amount is exactly zero is the signature of
     * a sub-cent crypto amount rounded away by the old decimal(15,2) column.
     *
     * @return list<array{check: string, record: string, detail: string}>
     */
    private function zeroedAmounts(?CarbonInterface $migratedAt): array
    {
        return Transaction::query()
            ->where('status', TrxStatus::COMPLETED->value)
            ->where('amount', 0)
            ->when($migratedAt !== null, fn ($query) => $query->where('created_at', '<', $migratedAt))
            ->limit(500)
            ->get(['trx_id', 'trx_type', 'currency', 'created_at'])
            ->map(fn (Transaction $transaction): array => [
                'check'  => 'zero-amount',
                'record' => (string) $transaction->trx_id,
                'detail' => sprintf(
                    'Completed %s of 0 %s on %s — likely truncated by the old 2-decimal ledger.',
                    $transaction->trx_type->value,
                    $transaction->currency,
                    $transaction->created_at?->toDateString() ?? 'unknown date',
                ),
            ])
            ->all();
    }

    /**
     * A converted entry with no recorded rate cannot be reconciled or restated
     * (IAS 21). The historical rate is unrecoverable, so these are reported
     * rather than backfilled with a guess.
     *
     * @return list<array{check: string, record: string, detail: string}>
     */
    private function missingFxSnapshots(?CarbonInterface $migratedAt): array
    {
        $count = Transaction::query()
            ->whereNull('exchange_rate')
            ->whereColumn('currency', '!=', 'payable_currency')
            ->when($migratedAt !== null, fn ($query) => $query->where('created_at', '<', $migratedAt))
            ->count();

        if ($count === 0) {
            return [];
        }

        return [[
            'check'  => 'missing-fx-rate',
            'record' => 'transactions',
            'detail' => sprintf(
                '%d cross-currency entr%s carry no exchange_rate. The rate used is not recoverable; these rows cannot be restated.',
                $count,
                $count === 1 ? 'y' : 'ies',
            ),
        ]];
    }

    /**
     * Deposit and withdraw limits are denominated in the base currency. A limit
     * that equals a round number multiplied by the method's own conversion rate
     * is the signature of an operator who read the admin list — which used to
     * label these with the method's currency symbol — and typed a converted
     * amount. The limit then silently applies at the wrong value.
     *
     * Both limits have to divide back to the base figures the seeder started
     * from, not merely to round numbers. Against a satoshi-scale rate every
     * round pair divides evenly, so the weaker test reported a hand-set
     * "500 to 2000 USD" BTC method as converted — and the repair it implied
     * was a minimum deposit of 31,250,000. This is the same signature
     * `money:fix-method-limits` acts on, kept deliberately identical so the
     * audit never reports something the repair would then decline to touch.
     *
     * @return list<array{check: string, record: string, detail: string}>
     */
    private function convertedMethodLimits(): array
    {
        $findings = [];

        $tables = [
            'deposit_methods'  => ['min_deposit', 'max_deposit', '10', '5000'],
            'withdraw_methods' => ['min_withdraw', 'max_withdraw', '20', '2500'],
        ];

        foreach ($tables as $table => [$minColumn, $maxColumn, $seededMin, $seededMax]) {
            $rows = DB::table($table)
                ->select('name', 'currency', 'conversion_rate', $minColumn, $maxColumn)
                ->where('conversion_rate', '<>', 1)
                ->where('conversion_rate', '>', 0)
                ->get();

            foreach ($rows as $row) {
                // A small rate arrives from a float-typed column as "1.6E-5",
                // which bcmath rejects outright — and it did, throwing a
                // ValueError that took the whole audit down rather than
                // skipping one method. DecimalMoney::rate() expands it.
                $rate = $this->plainDecimal($row->conversion_rate);
                $min  = $this->plainDecimal($row->{$minColumn});
                $max  = $this->plainDecimal($row->{$maxColumn});

                if (bccomp($min, '0', 8) <= 0 || bccomp($max, '0', 8) <= 0) {
                    continue;
                }

                // Undo the conversion on both limits. Either one alone can look
                // round by chance; both dividing evenly by the same rate is the
                // signature of values typed in the method's currency, because a
                // correctly entered base limit divided by a non-unit rate
                // essentially never lands on a round number.
                $impliedMin = bcdiv($min, $rate, 8);
                $impliedMax = bcdiv($max, $rate, 8);

                if (! $this->looksTyped($impliedMin) || ! $this->looksTyped($impliedMax)) {
                    continue;
                }

                // Round is not enough; it has to be the seeder's own pair.
                if (bccomp($impliedMin, $seededMin, 8) !== 0 || bccomp($impliedMax, $seededMax, 8) !== 0) {
                    continue;
                }

                $findings[] = [
                    'check'  => 'limit-currency-mismatch',
                    'record' => $table.': '.$row->name,
                    'detail' => sprintf(
                        '%s/%s = %s/%s, exactly %s x the %s rate %s. Limits are denominated in base currency, so these appear to have been typed in %s.',
                        $minColumn,
                        $maxColumn,
                        $this->tidy($min),
                        $this->tidy($max),
                        $this->tidy($impliedMin).'/'.$this->tidy($impliedMax),
                        $row->currency,
                        $this->tidy($rate),
                        $row->currency,
                    ),
                ];
            }
        }

        return $findings;
    }

    /**
     * A payment method priced away from the currency it settles in.
     *
     * The override itself is legitimate — a processor prices its own FX and
     * adds a spread. Two BTC methods 6% apart, with the currency's own rate
     * agreeing with neither, is not a spread; it is one of them left behind
     * when the other was updated, and the customer pays whichever they happen
     * to click.
     *
     * @return list<array{check: string, record: string, detail: string}>
     */
    private function divergentMethodRates(): array
    {
        $divergence = app(RateDivergence::class);
        $tolerance  = $this->option('rate-tolerance');

        $findings = $divergence
            ->beyondTolerance($tolerance === null ? null : (float) $tolerance)
            ->map(fn (array $row): array => [
                'check'  => 'rate-divergence',
                'record' => $row['kind'].': '.$row['name'],
                'detail' => sprintf(
                    'Prices %s at %s while the currency is set to %s — %s%% apart. One of the two is stale.',
                    $row['currency'],
                    $row['method_rate'],
                    $row['currency_rate'],
                    $this->tidy($row['percent']),
                ),
            ])
            ->all();

        return array_merge($findings, $divergence->unknownCurrencies()
            ->map(fn (array $row): array => [
                'check'  => 'unknown-method-currency',
                'record' => $row['kind'].': '.$row['name'],
                'detail' => sprintf(
                    'Settles in %s, which is not a configured currency. The method cannot be used and its limits reconcile against nothing.',
                    $row['currency'],
                ),
            ])
            ->all());
    }

    /**
     * A whole number — what an operator actually types into a limit field.
     * Deliberately strict: this heuristic accuses a live configuration of
     * being wrong, so a false positive costs more than a missed one.
     */
    private function looksTyped(string $value): bool
    {
        return bccomp($value, '0', 8) > 0 && ! str_contains($this->tidy($value), '.');
    }

    private function tidy(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /**
     * A database value as a decimal string bcmath can actually parse.
     *
     * MySQL hands back a decimal column already in that form; SQLite returns a
     * native float, and casting a small one to string produces exponent
     * notation. bcmath does not accept it and throws rather than returning
     * anything, so a single BTC-rate method aborted the entire audit.
     */
    private function plainDecimal(mixed $value): string
    {
        $string = (string) $value;

        return stripos($string, 'e') === false ? $string : DecimalMoney::rate($string);
    }

    /**
     * payable_amount has to reconcile against the other two columns, but there
     * are two legitimate ways it can.
     *
     * A fee is either charged on top of the amount or taken out of it, and the
     * platform does both: a deposit asks the customer for `net + fee` so the
     * wallet receives the round figure, while a P2P release credits the seller
     * `net` with the fee already deducted. Testing only the first shape
     * reported every deduct-style entry as broken — a release of 100 USDT less
     * a 0.40 fee was flagged as "payable 99.60 != net 99.60 + fee 0.40", which
     * is the arithmetic working exactly as intended.
     *
     * So the check is that payable matches one of the two, and the finding is
     * that it matches neither: independent rounding of the three columns lands
     * between them, which is the drift this exists to catch.
     *
     * @return list<array{check: string, record: string, detail: string}>
     */
    private function imbalancedTransactions(): array
    {
        // Inlined rather than bound: a small float binds as text on SQLite
        // ("1.0E-8"), and SQLite orders every numeric below every string, so
        // the comparison would silently never match. %F on a float can only
        // produce digits and a dot, so this cannot carry an injection.
        $tolerance = sprintf('%.12F', (float) $this->option('tolerance'));

        // `wallet_fee` before `fee`. Both `payable_amount` and `net_amount` are
        // denominated in the wallet's currency, and `fee` is the base-currency
        // figure — comparing them adds a USD fee to a SAR net and reports the
        // difference as damage. A SAR deposit of 100 with a 0.80 USD fee
        // reconciles exactly against `wallet_fee` of 3.00 SAR and not at all
        // against 0.80, which is how a correct row was flagged every night.
        //
        // The fallback keeps rows that predate the FX snapshot working: there
        // `wallet_fee` is null, and for a same-currency row — which is what
        // almost all of them are — `fee` is the right figure anyway.
        $walletFee = 'COALESCE(wallet_fee, fee, 0)';

        // GF6: a taxed row carries its tax inside the payable as well, so a
        // balanced row is payable = net + fee + tax. A database the tax
        // migration has not reached yet has no such column and no such tax.
        $walletTax = Schema::hasColumn('transactions', 'wallet_tax') ? ' + COALESCE(wallet_tax, 0)' : '';

        return Transaction::query()
            ->whereNotNull('payable_amount')
            ->whereRaw("ABS(payable_amount - (net_amount + {$walletFee}{$walletTax})) > {$tolerance}")
            ->whereRaw("ABS(payable_amount - net_amount) > {$tolerance}")
            ->limit(500)
            ->get(['trx_id', 'net_amount', 'fee', 'wallet_fee', 'payable_amount', 'payable_currency'])
            ->map(fn (Transaction $transaction): array => [
                'check'  => 'imbalanced',
                'record' => (string) $transaction->trx_id,
                'detail' => sprintf(
                    'payable %s is neither net %s nor net + fee %s (%s)',
                    $transaction->payable_amount,
                    $transaction->net_amount,
                    $transaction->wallet_fee       ?? $transaction->fee ?? '0',
                    $transaction->payable_currency ?? '—',
                ),
            ])
            ->all();
    }

    /**
     * A wallet balance that no longer equals the sum of its signed ledger
     * deltas is the durable symptom of the wallet/ledger scale mismatch.
     *
     * The two directions move different columns, and every handler agrees on
     * which: a credit lands `net_amount` — what you receive after the fee — and
     * a debit takes `payable_amount`, which is what you pay including it. This
     * used to sum `payable_amount` in both directions, so any wallet that had
     * ever received a deposit appeared to be short by exactly the deposit
     * charge. A wallet with a 5.00 bonus, a 100.00 deposit charged 10.00, and a
     * 2.00 reward holds 107.00 and was reported as 117.00.
     *
     * @return list<array{check: string, record: string, detail: string}>
     */
    private function driftedWallets(): array
    {
        $tolerance = (float) $this->option('tolerance');

        return Wallet::query()
            ->limit(2000)
            ->get(['uuid', 'balance'])
            ->map(function (Wallet $wallet) use ($tolerance): ?array {
                $ledger = (float) DB::table('transactions')
                    ->where('wallet_reference', $wallet->uuid)
                    ->where('status', TrxStatus::COMPLETED->value)
                    ->sum(DB::raw("CASE WHEN amount_flow = 'minus' THEN -COALESCE(payable_amount, 0) ELSE COALESCE(net_amount, payable_amount, 0) END"));

                if (abs((float) $wallet->balance - $ledger) <= $tolerance) {
                    return null;
                }

                return [
                    'check'  => 'wallet-drift',
                    'record' => (string) $wallet->uuid,
                    'detail' => sprintf('balance %s vs ledger %s', $wallet->balance, $ledger),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Balances holding a fraction their own currency cannot express.
     *
     * `wallets.balance` is decimal(20,8), which is right — it has to hold a
     * satoshi. What it must never hold is a fraction of a cent in a USD
     * wallet, and it did: fees were computed at the ledger's eight places and
     * debited unquantized, so a 1.5% fee on 33.33 left 0.00005 behind. The
     * dust is invisible at the currency's own precision, and it means the
     * wallet can never reach zero — "withdraw everything" always leaves
     * something, and the balance shown and the balance stored disagree.
     *
     * Reported rather than swept: the residue is real money in a real
     * customer's account, and the operator decides whether it is written off,
     * credited up or left alone. A wallet whose currency row is missing is
     * skipped — that is a different fault, and `wallet-drift` finds it.
     *
     * @return list<array{check: string, record: string, detail: string}>
     */
    private function dustyWallets(): array
    {
        return Wallet::query()
            ->with('currency:id,code,decimal_places')
            ->limit(2000)
            ->get(['id', 'uuid', 'currency_id', 'balance'])
            ->map(function (Wallet $wallet): ?array {
                $code = $wallet->currency?->code;

                if ($code === null) {
                    return null;
                }

                $balance   = (string) $wallet->getRawOriginal('balance');
                $quantized = Money::quantize($balance, $code);

                if (bccomp($balance, $quantized, DecimalMoney::CALCULATION_SCALE) === 0) {
                    return null;
                }

                return [
                    'check'  => 'wallet-dust',
                    'record' => (string) $wallet->uuid,
                    'detail' => sprintf(
                        '%s holds %s, which is finer than %s can express (%d decimal(s)); %s is the representable balance',
                        $code,
                        rtrim(rtrim($balance, '0'), '.'),
                        $code,
                        Money::decimals($code),
                        $quantized,
                    ),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Rates that cannot be right, and rates that probably are not.
     *
     * A rate of 0 destroys every conversion through it. A non-stablecoin crypto
     * left at exactly 1 is the "100 USD buys 100 BTC" configuration — but a
     * stablecoin at 1 against a USD base is correct, which is why this only
     * reports and never repairs.
     *
     * @return list<array{check: string, record: string, detail: string}>
     */
    private function suspiciousRates(): array
    {
        $base = strtoupper((string) (siteCurrency() ?: ''));

        return Currency::query()
            ->get(['code', 'type', 'exchange_rate', 'default'])
            ->map(function (Currency $currency) use ($base): ?array {
                $rate = (float) $currency->getRawOriginal('exchange_rate');
                $code = strtoupper((string) $currency->code);

                if ($rate <= 0) {
                    return [
                        'check'  => 'zero-rate',
                        'record' => $code,
                        'detail' => 'Exchange rate is '.$rate.' — every conversion through this currency yields nothing.',
                    ];
                }

                $isPeggedToBase = $base === 'USD' && in_array($code, ['USDT', 'USDC', 'BUSD', 'DAI', 'TUSD'], true);

                if ($currency->type === CurrencyType::CRYPTO && $rate === 1.0 && ! $currency->default && ! $isPeggedToBase) {
                    return [
                        'check'  => 'placeholder-rate',
                        'record' => $code,
                        'detail' => sprintf('Crypto rate is exactly 1, so 1 %s buys 1 %s. Set a real rate or enable live rates.', $base ?: 'base unit', $code),
                    ];
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * A crypto asset priced by hand, and not repriced for weeks.
     *
     * RateGuard's staleness rule deliberately exempts manual rates: an
     * operator who typed a rate meant it, and a pegged fiat cross such as
     * SAR/USD has legitimately read 3.75 for decades. Applying an age limit
     * there would block working configurations.
     *
     * No crypto asset holds a price, though. A hand-set crypto rate that has
     * not moved in weeks is neglect rather than intent, and nothing else in
     * the system says so — which is how BTC on this very install sat 11%
     * away from the market for thirteen days while every check passed. The
     * rate was usable, so RateGuard cleared it; it was manual, so staleness
     * did not apply; it was not 1, so the placeholder rule missed it.
     *
     * Reported, never blocked. A fixed internal rate is a legitimate if
     * unusual choice, and the operator is the one who decides. Pegged
     * stablecoins are excluded for the same reason they are excluded from the
     * placeholder rule — a manual 1 for USDC against a USD base is correct,
     * and is exactly what a provider that cannot price USDC leaves you with.
     *
     * @return list<array{check: string, record: string, detail: string}>
     */
    private function staleManualCryptoRates(): array
    {
        $maxAgeDays = max(1, (int) setting('manual_crypto_rate_max_age_days', 7));
        $cutoff     = now()->subDays($maxAgeDays);

        return Currency::query()
            ->where('type', CurrencyType::CRYPTO)
            ->where('rate_live', false)
            ->where('default', false)
            ->get(['code', 'rate_live', 'rate_updated_at', 'default'])
            ->reject(fn (Currency $currency): bool => in_array(
                strtoupper((string) $currency->code),
                RateGuard::PEGGED,
                true,
            ))
            ->map(function (Currency $currency) use ($cutoff, $maxAgeDays): ?array {
                $updatedAt = $currency->rate_updated_at;

                // A rate with no recorded age predates the provenance columns.
                // Reported too: "never repriced" is the worst version of this.
                if ($updatedAt !== null && $updatedAt->greaterThan($cutoff)) {
                    return null;
                }

                return [
                    'check'  => 'stale-manual-crypto',
                    'record' => strtoupper((string) $currency->code),
                    'detail' => sprintf(
                        'Crypto rate is set by hand and was last changed %s (older than %d day(s)). Crypto prices move; enable live rates or reprice it.',
                        $updatedAt?->diffForHumans() ?? 'never',
                        $maxAgeDays,
                    ),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @param list<array{check: string, record: string, detail: string}> $findings */
    private function writeCsv(array $findings): void
    {
        $path = 'money-audit/precision-'.now()->format('Ymd-His').'.csv';

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['check', 'record', 'detail']);

        foreach ($findings as $finding) {
            fputcsv($handle, [$finding['check'], $finding['record'], $finding['detail']]);
        }

        rewind($handle);
        Storage::disk('local')->put($path, stream_get_contents($handle));
        fclose($handle);

        $this->components->info('Report written to storage/app/'.$path);
    }

    private function migratedAt(): ?CarbonInterface
    {
        $recorded = Setting::get('money_precision_migrated_at');

        return $recorded ? Carbon::parse((string) $recorded) : null;
    }
}
