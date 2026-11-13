<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\MerchantStatus;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CurrencyService;
use App\Support\LedgerReporting;
use App\Support\Money;
use App\Support\UserDashboardKycNotice;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    /** Resolved once per request; see presentationCurrencyCode(). */
    private ?string $presentationCurrency = null;

    public function __construct(public UserDashboardKycNotice $kycNotice) {}

    /**
     * Display the dashboard view.
     */
    public function index(): View
    {
        $user = auth()->user();

        $relevantTypes  = $this->getRelevantTransactionTypes($user);
        $financialStats = $this->getFinancialStats($user, $relevantTypes);
        $staticStats    = $this->getStaticStats($user);
        $statistics     = array_merge($financialStats, $staticStats);

        // Define week day order to maintain consistent order in the view.
        $dayOrder          = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $weeklyStats       = $this->getWeeklyTransactionStats($user, $dayOrder);
        $sortedDeposits    = $weeklyStats['deposits'];
        $sortedWithdrawals = $weeklyStats['withdrawals'];

        // Calculate totals for the past 7 days.
        $totalSuccessDeposit  = $this->getTotalAmountForTrx($user, TrxType::DEPOSIT, 7);
        $totalSuccessWithdraw = $this->getTotalAmountForTrx($user, TrxType::WITHDRAW, 7);

        $transactions = $user->transactions()->latest()->take(3)->get();
        $user->loadMissing('kycSubmission');
        $kycNoticeCard = $this->kycNotice->forUser($user);

        return view('frontend.user.dashboard.index', compact(
            'statistics',
            'sortedDeposits',
            'sortedWithdrawals',
            'totalSuccessDeposit',
            'totalSuccessWithdraw',
            'transactions',
            'kycNoticeCard'
        ));
    }

    /**
     * Get the transaction types relevant for the user.
     */
    private function getRelevantTransactionTypes(User $user): array
    {
        $types = [
            TrxType::DEPOSIT,
            TrxType::WITHDRAW,
            TrxType::SEND_MONEY,
            TrxType::REQUEST_MONEY,
            TrxType::EXCHANGE_MONEY,
            TrxType::RECEIVE_MONEY,
            TrxType::REWARD,
        ];

        if ($user->isMerchant()) {
            $types[] = TrxType::RECEIVE_PAYMENT;
        }

        return $types;
    }

    /**
     * Retrieve financial statistics by grouping completed transactions.
     * Sandbox payments are left out: a merchant's test charges were never
     * credited, so they are not money received.
     */
    private function getFinancialStats(User $user, array $relevantTypes): array
    {
        $transactions = $user->transactions()
            ->where('status', TrxStatus::COMPLETED)
            ->withoutSandbox()
            ->whereIn('trx_type', $relevantTypes)
            ->selectRaw('trx_type, currency, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('trx_type', 'currency')
            ->get();

        $stats         = [];
        $currencyRates = $this->currencyRatesFor($transactions);

        foreach ($relevantTypes as $trxType) {
            // Filter the transactions for the given type.
            $filtered = $transactions->where('trx_type', $trxType);

            // Format the total amount using a helper function.
            $formattedValue = $this->presentAmount(
                $this->sumRowsInDefaultCurrency($filtered, 'total_amount', $currencyRates)
            );

            $stats[] = [
                'title'       => $trxType->label(),
                'value'       => $formattedValue,
                'icon'        => $trxType->icon(),
                'color_class' => $trxType->kebabCase(),
                'link'        => route('user.transaction.index'),
            ];
        }

        return $stats;
    }

    /**
     * Retrieve static statistics for the user.
     */
    private function getStaticStats(User $user): array
    {
        $stats = [
            [
                'title'       => __('Total Tickets'),
                'value'       => $user->tickets()->count(),
                'icon'        => 'tickets',
                'color_class' => 'tickets',
                'link'        => '#',
            ],
            [
                'title'       => __('Total Referrals'),
                'value'       => $user->referrals()->count(),
                'icon'        => 'referrals',
                'color_class' => 'referrals',
                'link'        => '#',
            ],
        ];

        if ($user->isMerchant()) {
            $stats[] = [
                'title'       => __('Merchant Shop'),
                'value'       => $user->merchants()->count(),
                'icon'        => 'merchant',
                'color_class' => 'merchant',
                'link'        => '#',
            ];

            $stats[] = [
                'title'       => __('Awaiting Merchant'),
                'value'       => $user->merchants()->where('status', MerchantStatus::PENDING)->count(),
                'icon'        => 'merchant-2',
                'color_class' => 'merchant-pending',
                'link'        => '#',
            ];
        }

        return $stats;
    }

    /**
     * Get weekly deposit and withdrawal statistics.
     */
    private function getWeeklyTransactionStats(User $user, array $dayOrder): array
    {
        $transactions = $user->transactions()
            ->whereIn('trx_type', [TrxType::DEPOSIT, TrxType::WITHDRAW])
            ->whereDate('created_at', '>=', Carbon::now()->subDays(7))
            ->selectRaw('
                DATE(created_at) as trx_date,
                trx_type,
                '.LedgerReporting::currency('amount').' as currency,
                SUM(CASE WHEN status = ? THEN '.LedgerReporting::amount('amount').' ELSE 0 END) as success_total,
                SUM(CASE WHEN status IN (?, ?) THEN '.LedgerReporting::amount('amount').' ELSE 0 END) as fail_total
            ', [
                TrxStatus::COMPLETED->value,
                TrxStatus::FAILED->value,
                TrxStatus::CANCELED->value,
            ])
            // Grouped by the expression rather than its alias: under
            // ONLY_FULL_GROUP_BY, MySQL validates the columns the expression
            // actually reads, and `base_amount` is not one of them via an alias.
            ->groupByRaw('DATE(created_at), trx_type, '.LedgerReporting::currency('amount'))
            ->get();

        $currencyRates = $this->currencyRatesFor($transactions);
        $symbol        = $this->presentationCurrencySymbol();

        // The chart is drawn in whatever currency the user reads the dashboard
        // in, so the tooltip needs that currency's precision travelling with
        // the symbol. Without it the tooltip fell back to two decimals and a
        // BTC week showed as "₿0.00" on every day of the chart.
        $decimals = Money::decimals($this->presentationCurrencyCode());

        // Map the deposits and withdrawals to the provided day order.
        $deposits = collect($dayOrder)->map(function (string $day) use ($transactions, $currencyRates, $symbol, $decimals) {
            $data = $transactions
                ->where('trx_type', TrxType::DEPOSIT)
                ->filter(fn ($row): bool => Carbon::parse($row->trx_date)->format('D') === $day);

            return [
                'day'           => $day,
                'success_total' => $this->presentValue($this->sumRowsInDefaultCurrency($data, 'success_total', $currencyRates)),
                'fail_total'    => $this->presentValue($this->sumRowsInDefaultCurrency($data, 'fail_total', $currencyRates)),
                'symbol'        => $symbol,
                'decimals'      => $decimals,
            ];
        });

        $withdrawals = collect($dayOrder)->map(function (string $day) use ($transactions, $currencyRates, $symbol, $decimals) {
            $data = $transactions
                ->where('trx_type', TrxType::WITHDRAW)
                ->filter(fn ($row): bool => Carbon::parse($row->trx_date)->format('D') === $day);

            return [
                'day'                    => $day,
                'withdraw_success_total' => $this->presentValue($this->sumRowsInDefaultCurrency($data, 'success_total', $currencyRates)),
                'withdraw_fail_total'    => $this->presentValue($this->sumRowsInDefaultCurrency($data, 'fail_total', $currencyRates)),
                'symbol'                 => $symbol,
                'decimals'               => $decimals,
            ];
        });

        return [
            'deposits'    => $deposits,
            'withdrawals' => $withdrawals,
        ];
    }

    /**
     * Get the total successful transaction amount for a given transaction type
     * over the past number of days.
     */
    private function getTotalAmountForTrx(User $user, TrxType $trxType, int $days): string
    {
        $totals = $user->transactions()
            ->where('trx_type', $trxType)
            ->where('status', TrxStatus::COMPLETED)
            ->whereDate('created_at', '>=', Carbon::now()->subDays($days))
            ->selectRaw(LedgerReporting::currency('amount').' as currency, SUM('.LedgerReporting::amount('amount').') as total')
            ->groupByRaw(LedgerReporting::currency('amount'))
            ->get();

        return $this->presentAmount(
            $this->sumRowsInDefaultCurrency($totals, 'total', $this->currencyRatesFor($totals))
        );
    }

    /**
     * Both delegate to CurrencyService, which owns the base-currency
     * arithmetic — the bodies were a byte-for-byte copy of the admin
     * dashboard's.
     */
    private function currencyRatesFor(Collection $rows): Collection
    {
        return app(CurrencyService::class)->ratesByCode($rows->pluck('currency'));
    }

    private function sumRowsInDefaultCurrency(Collection $rows, string $amountColumn, Collection $currencyRates): float
    {
        return (float) app(CurrencyService::class)->sumInBaseCurrency($rows, $amountColumn, $currencyRates);
    }

    /**
     * Present a base-currency total in the currency this customer reads in.
     *
     * The sums arrive in base because that is the unit the ledger is kept in
     * and the only one every row can be reduced to. Which unit the *reader*
     * wants it back in is a separate question, and until now it was never
     * asked — a customer holding BDT and NGN was shown their totals in USD
     * because that is what the platform happens to report in.
     *
     * Only aggregates go through here. A transaction, a wallet balance or a
     * fee is already denominated in a real currency and is shown in it.
     */
    private function presentAmount(float $amount): string
    {
        $code = $this->presentationCurrencyCode();

        return Money::format(
            app(CurrencyService::class)->fromBaseAmount($amount, $code),
            $code,
        );
    }

    /**
     * The same conversion without formatting, for figures the chart plots
     * rather than prints.
     */
    private function presentValue(float $amount): float
    {
        return (float) Money::quantize(
            app(CurrencyService::class)->fromBaseAmount($amount, $this->presentationCurrencyCode()),
            $this->presentationCurrencyCode(),
        );
    }

    /**
     * Resolved once per request: `displayCurrency()` consults the currency
     * table and RateGuard, and the dashboard asks for it on every stat card
     * and every day of the chart.
     */
    private function presentationCurrencyCode(): string
    {
        return $this->presentationCurrency ??= (auth()->user()?->displayCurrency() ?: null)
            ?? $this->defaultCurrencyCode();
    }

    private function defaultCurrencyCode(): string
    {
        return strtoupper((string) (siteCurrency() ?: config('app.default_currency', 'USD')));
    }

    private function presentationCurrencySymbol(): string
    {
        return Money::symbol($this->presentationCurrencyCode())
            ?: (string) (siteCurrency('symbol') ?: config('app.default_currency_symbol', '$'));
    }
}
