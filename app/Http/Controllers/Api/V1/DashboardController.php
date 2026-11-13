<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TransactionResource;
use App\Http\Resources\Api\V1\WalletResource;
use App\Models\Currency;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Api\DashboardAttention;
use App\Services\CurrencyService;
use App\Services\UserAccountSummaryService;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\DecimalMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Everything the app's home screen needs, in one call.
 *
 * Six round trips on a phone's first screen is six chances to be on a train.
 *
 * UserAccountSummaryService::headerStats is deliberately NOT reused, although
 * it computes most of these numbers. It returns presentation: translated
 * labels, money already formatted with a symbol, a tone name and a sparkline.
 * That is right for the panel's stat strip and wrong here - the app decides
 * its own wording, and a formatted string is not a number it can do anything
 * with. counts() and recentTransactions() are reused as they are, because
 * they return facts.
 *
 * There are no "quick actions" in this payload either. Which modules a
 * customer may reach is already answered, per role and per installation, by
 * GET v1/features; inventing a second vocabulary for it here would give the
 * app two sources that could disagree.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly UserAccountSummaryService $summary,
        private readonly CurrencyService $currencies,
        private readonly DashboardAttention $attention,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $wallets = $user->wallets()->with('currency.roles')->active()->get();

        return ApiResponse::ok([
            'role'    => $user->role?->value,
            'totals'  => $this->totals($wallets, $this->displayCurrency($request, $user)),
            'wallets' => WalletResource::collection($wallets),

            'recent_transactions' => TransactionResource::collection(
                $this->summary->recentTransactions($user, 5)
            ),

            'counts' => $this->summary->counts($user) + [
                'unread_notifications' => $user->unreadNotifications()->count(),
            ],

            // What is waiting on the customer, each with the key its button acts with.
            'attention' => $this->attention->for($user),

            'verification' => [
                'email' => $user->email_verified_at !== null,
                'phone' => $user->phone_verified_at !== null,
                'kyc'   => $user->isKycVerified(),
            ],
        ]);
    }

    /**
     * The currency the headline is shown in: the one the query names when it
     * is a convertible active currency, else the customer's saved preference
     * (which itself falls back to the site base). An unusable code reverts
     * silently for the same reason displayCurrency() does - a total through a
     * rate nobody trusts is worse than a total in the ledger's own unit.
     */
    private function displayCurrency(Request $request, User $user): string
    {
        $code = strtoupper(trim((string) $request->query('currency', '')));

        if ($code !== '' && $code !== $user->displayCurrency()) {
            $currency = $this->currencies->getCurrencyByCode($code);

            if ($currency instanceof Currency && $currency->status && $currency->isConvertible()) {
                return strtoupper((string) $currency->code);
            }
        }

        return $user->displayCurrency();
    }

    /**
     * Every wallet restated in the display currency and added up, with each
     * wallet's share of that total.
     *
     * A headline figure the app can show above a list of wallets in different
     * currencies. Converted through the same CurrencyService the panel uses,
     * at calculation scale, and only quantized at the end - adding figures
     * that have each already been rounded is how a total stops matching the
     * rows above it. The shares are percentages of the unrounded total, so
     * they add up to 100 within a tenth.
     *
     * @param  Collection<int, Wallet>                                                                                                                                                                        $wallets
     *                                                                                                                                                                                                                 Each row also carries its rate - how much of the display currency one
     *                                                                                                                                                                                                                 unit of the wallet's currency buys - so a wallet screen can print the
     *                                                                                                                                                                                                                 same line without a second call.
     * @return array{balance: string, currency: string, decimals: int|null, by_currency: list<array{code: string|null, balance: string, decimals: int|null, converted: string, share: string, rate: string}>}
     */
    /**
     * A rate as the app prints it: eight places at most, trailing zeros gone.
     */
    private static function trimRate(string $rate): string
    {
        $trimmed = rtrim(rtrim(DecimalMoney::round($rate, 8), '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    private function totals($wallets, string $display): array
    {
        $base   = strtoupper((string) siteCurrency());
        $rates  = $this->currencies->ratesByCode($wallets->map(fn ($wallet) => $wallet->currency?->code)->push($display));
        $factor = $display === $base ? '1' : ($rates->get($display) ?? '1');

        $rows = $wallets->map(fn ($wallet): array => [
            'wallet'    => $wallet,
            'converted' => DecimalMoney::multiply(
                $this->currencies->toBaseAmount($wallet->balance, $wallet->currency?->code, $rates),
                $factor,
            ),
        ]);

        $balance = $rows->reduce(
            fn (string $carry, array $row): string => DecimalMoney::add($carry, $row['converted'], DecimalMoney::CALCULATION_SCALE),
            DecimalMoney::normalize(0),
        );

        $byCurrency = $rows
            ->sortByDesc(fn (array $row): float => (float) $row['converted'])
            ->values()
            ->map(fn (array $row): array => [
                'code'      => $row['wallet']->currency?->code,
                'balance'   => ApiMoney::amount($row['wallet']->balance, $row['wallet']->currency?->code),
                'decimals'  => ApiMoney::decimals($row['wallet']->currency?->code),
                'converted' => ApiMoney::amount($row['converted'], $display),
                'rate'      => self::trimRate(DecimalMoney::divide($factor, $rates->get((string) $row['wallet']->currency?->code) ?: '1')),
                'share'     => DecimalMoney::compare($balance, '0') > 0
                    ? DecimalMoney::round(DecimalMoney::multiply(DecimalMoney::divide($row['converted'], $balance), 100), 1)
                    : '0.0',
            ])
            ->all();

        return [
            'balance'     => ApiMoney::amount($balance, $display),
            'currency'    => $display,
            'decimals'    => ApiMoney::decimals($display),
            'by_currency' => $byCurrency,
        ];
    }
}
