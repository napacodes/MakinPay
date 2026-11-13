<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Enums\VirtualCard\CardholderStatus;
use App\Enums\VirtualCard\VirtualCardFeeOperation;
use App\Enums\VirtualCard\VirtualCardStatus;
use App\Enums\VirtualCard\VirtualCardTopupStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\VirtualCard\VirtualCardTransactionRequest;
use App\Models\Cardholders;
use App\Models\Transaction;
use App\Models\VirtualCard;
use App\Models\VirtualCardFeeSetting;
use App\Models\VirtualCardProvider;
use App\Models\VirtualCardTopup;
use App\Services\VirtualCard\VirtualCardControls;
use App\Services\VirtualCard\VirtualCardManager;
use App\Services\VirtualCard\VirtualCardProviderFactory;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VirtualCardController extends Controller
{
    public function index()
    {
        $demoMode = config('app.demo') ?? false;

        $user = Auth::user();
        // Eager-load `request.cardholder.business` so the card visual can
        // render the actual cardholder's legal name (and business name for
        // company cards) — not the wallet currency code.
        $cards = $user->virtualCards()
            ->with(['wallet.currency', 'provider', 'request.cardholder.business'])
            ->latest()
            ->get();
        $wallets = $user->wallets()
            ->active()
            ->with('currency')
            ->get();

        $reqData = [
            'min_issue_fee'      => VirtualCardProvider::min('issue_fee'),
            'max_issue_fee'      => VirtualCardProvider::max('issue_fee'),
            'min_issue_fee_pct'  => VirtualCardProvider::min('issue_fee_pct'),
            'max_issue_fee_pct'  => VirtualCardProvider::max('issue_fee_pct'),
            'min_wallet_balance' => VirtualCardProvider::min('min_balance'),
            'max_wallet_balance' => VirtualCardProvider::max('min_balance'),
        ];

        $cardholders = Cardholders::where('user_id', $user->id)
            ->where('status', CardholderStatus::APPROVED)
            ->get();

        $stats       = $this->buildStats($user, $cards);
        $spendChart  = $this->buildSpendChart($user);
        $providerMix = $this->buildProviderMix($user);
        $networkList = $cards->pluck('network')
            ->filter()
            ->map(fn ($n) => is_object($n) ? ($n->value ?? (string) $n) : (string) $n)
            ->map(fn ($n) => Str::upper($n))
            ->unique()
            ->values()
            ->all();

        // Recent card-related transactions (top up + withdraw across all of the user's cards)
        $recentTxns = Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('trx_type', [TrxType::CARD_TOPUP, TrxType::CARD_WITHDRAW])
            ->latest()
            ->limit(10)
            ->get();

        // Per-card currency facts for the mini-cards and the hero: a USD card
        // funded from an NGN wallet must show its balance in USD, and the
        // wallet-per-card rate lets the page relate the two figures.
        $cardFx = $cards->mapWithKeys(fn (VirtualCard $card): array => [$card->id => $this->cardFxForList($card)]);

        return view('frontend.user.virtual_card.index', compact(
            'cards', 'wallets', 'reqData', 'demoMode', 'cardholders',
            'stats', 'spendChart', 'providerMix', 'networkList', 'recentTxns', 'cardFx'
        ));
    }

    /**
     * `cardFxForView()` for a listing, where one card with a missing rate must
     * not take the whole page down: the card's currency is still known from
     * the provider, only the rate is left null.
     *
     * @return array{card_currency: ?string, rate: ?string, symbol: string, decimals: int}
     */
    private function cardFxForList(VirtualCard $card): array
    {
        try {
            return $this->cardFxForView($card);
        } catch (NotifyErrorException) {
            $cardCurrency = $card->provider?->cardCurrency();
            $currency     = $cardCurrency ?? $card->wallet?->currency?->code;

            return [
                'card_currency' => $cardCurrency,
                'rate'          => null,
                'symbol'        => Money::symbol($currency),
                'decimals'      => Money::decimals($currency),
            ];
        }
    }

    /**
     * Build the stats strip data: total balance, spent this month, pending auth,
     * total cards. Each headline number ships with an optional `trend` array
     * (`dir`, `pct`) when there is a previous-period number to compare against.
     */
    private function buildStats($user, $cards): array
    {
        $totalBalance = (float) $cards->sum(fn ($c) => (float) ($c->wallet->balance ?? 0));

        $thisMonthSpend = (float) Transaction::query()
            ->where('user_id', $user->id)
            ->where('trx_type', TrxType::CARD_TOPUP)
            ->whereBetween('created_at', [now()->startOfMonth(), now()])
            ->sum('amount');

        $lastMonthSpend = (float) Transaction::query()
            ->where('user_id', $user->id)
            ->where('trx_type', TrxType::CARD_TOPUP)
            ->whereBetween('created_at', [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
            ])
            ->sum('amount');

        $pendingQuery = Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('trx_type', [TrxType::CARD_TOPUP, TrxType::CARD_WITHDRAW])
            ->where('status', TrxStatus::PENDING);

        $pendingAuth      = (float) (clone $pendingQuery)->sum('amount');
        $pendingAuthCount = (int) (clone $pendingQuery)->count();

        $activeCount = $cards->where('status', VirtualCardStatus::Active)->count();
        $frozenCount = $cards->whereIn('status', [VirtualCardStatus::Inactive, VirtualCardStatus::Blocked])->count();

        return [
            'total_balance'   => $totalBalance,
            'monthly_spend'   => $thisMonthSpend,
            'monthly_trend'   => $this->trend($thisMonthSpend, $lastMonthSpend),
            'pending_auth'    => $pendingAuth,
            'pending_count'   => $pendingAuthCount,
            'total_cards'     => $cards->count(),
            'active_cards'    => $activeCount,
            'frozen_cards'    => $frozenCount,
            'providers_count' => $cards->pluck('provider_id')->unique()->count(),
        ];
    }

    private function trend(float $current, float $previous): ?array
    {
        if ($previous <= 0) {
            return null;
        }
        $delta = (($current - $previous) / $previous) * 100;

        return [
            'dir' => $delta >= 0 ? 'up' : 'down',
            'pct' => round(abs($delta), 1),
        ];
    }

    /**
     * Daily CARD_TOPUP totals for the last 90 days. The view exposes 7d/30d/90d
     * windows over the same dataset (no extra round trips).
     */
    private function buildSpendChart($user): array
    {
        $rows = Transaction::query()
            ->where('user_id', $user->id)
            ->where('trx_type', TrxType::CARD_TOPUP)
            ->where('status', TrxStatus::COMPLETED)
            ->whereBetween('created_at', [now()->subDays(89)->startOfDay(), now()->endOfDay()])
            ->selectRaw('DATE(created_at) as day, SUM(amount) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($v, $k) => [(string) $k => (float) $v])
            ->all();

        $series = [];
        for ($i = 89; $i >= 0; $i--) {
            $day      = now()->subDays($i)->toDateString();
            $series[] = [
                'date'  => $day,
                'label' => now()->subDays($i)->format('M d'),
                'value' => (float) ($rows[$day] ?? 0),
            ];
        }

        return [
            'series' => $series,
            'totals' => [
                '7d'  => array_sum(array_column(array_slice($series, -7), 'value')),
                '30d' => array_sum(array_column(array_slice($series, -30), 'value')),
                '90d' => array_sum(array_column($series, 'value')),
            ],
        ];
    }

    /**
     * "By provider" donut data — month-to-date CARD_TOPUP grouped by provider name
     * (already populated on every Transaction by VirtualCardManager).
     */
    private function buildProviderMix($user): array
    {
        $rows = Transaction::query()
            ->where('user_id', $user->id)
            ->where('trx_type', TrxType::CARD_TOPUP)
            ->whereBetween('created_at', [now()->startOfMonth(), now()])
            ->selectRaw('provider, SUM(amount) as total')
            ->groupBy('provider')
            ->pluck('total', 'provider')
            ->all();

        $palette = ['#3B6FE0', '#7C5CFF', '#10B981', '#F59E0B', '#9AA3B2', '#EF4444'];
        $total   = (float) array_sum($rows);

        $items = [];
        $i     = 0;
        foreach ($rows as $name => $value) {
            $value   = (float) $value;
            $items[] = [
                'name'  => $name ?: __('Unknown'),
                'value' => $value,
                'pct'   => $total > 0 ? round(($value / $total) * 100, 1) : 0,
                'color' => $palette[$i % count($palette)],
            ];
            $i++;
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Provider-agnostic card details endpoint.
     * Delegates to the resolved provider's getCardDetails() and returns JSON.
     */
    public function cardDetails(Request $request, VirtualCardProviderFactory $factory, $id, ?string $providerName = null)
    {
        // Force JSON for the entire response cycle — including any
        // middleware redirect (e.g. unauthenticated users would otherwise
        // get an HTML login page back, which the front-end JS then can't
        // parse, surfacing as "Unexpected token '<'" in the console).
        $request->headers->set('Accept', 'application/json');

        if (! Auth::check()) {
            return response()->json(['error' => __('Unauthenticated.')], 401);
        }

        $card = VirtualCard::with('provider')
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (! $card) {
            return response()->json(['error' => __('Card not found.')], 404);
        }

        $providerCode = $card->provider->code ?? $providerName;

        if (! $providerCode) {
            return response()->json(['error' => __('No virtual card provider is linked to this card.')], 422);
        }

        try {
            $provider = $factory->getProvider($providerCode);
            $payload  = $provider->getCardDetails($card);

            // Normalize: providers may return either an array or pre-rendered HTML.
            if (is_string($payload)) {
                return response()->json(['html' => $payload]);
            }

            return response()->json(['data' => $payload]);
        } catch (\Throwable $e) {
            Log::error('Virtual card details fetch failed', [
                'card_id'  => $card->id,
                'provider' => $providerCode,
                'error'    => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function topup($card)
    {
        $card = VirtualCard::query()
            ->with(['wallet.currency', 'provider'])
            ->where('user_id', Auth::id())
            ->findOrFail($card);
        $fx           = $this->cardFxForView($card);
        $cardSettings = VirtualCardFeeSetting::where('provider_id', $card->provider_id)
            ->where('currency_id', $card->wallet->currency_id)
            ->where('operation', VirtualCardFeeOperation::Topup->value)
            ->first();

        return view('frontend.user.virtual_card.topup.index', compact('card', 'cardSettings', 'fx'));
    }

    /**
     * What the top-up and withdraw pages need to preview a conversion: the
     * card's currency, its symbol and precision, and the wallet-per-card rate.
     * `card_currency` is null when the card is kept in the wallet's currency.
     *
     * @return array{card_currency: ?string, rate: string, symbol: string, decimals: int}
     */
    private function cardFxForView(VirtualCard $card): array
    {
        $fx = app(VirtualCardManager::class)->cardCurrencyInfo($card->provider, $card->wallet);

        return [
            'card_currency' => $fx['card_currency'],
            'rate'          => $fx['rate'],
            'symbol'        => Money::symbol($fx['card_currency'] ?? $card->wallet?->currency?->code),
            'decimals'      => Money::decimals($fx['card_currency'] ?? $card->wallet?->currency?->code),
        ];
    }

    public function withdraw($card)
    {
        $card = VirtualCard::query()
            ->with(['wallet.currency', 'provider'])
            ->where('user_id', Auth::id())
            ->findOrFail($card);
        $fx           = $this->cardFxForView($card);
        $cardSettings = VirtualCardFeeSetting::where('provider_id', $card->provider_id)
            ->where('currency_id', $card->wallet->currency_id)
            ->where('operation', VirtualCardFeeOperation::Withdrawal->value)
            ->first();

        return view('frontend.user.virtual_card.withdraw.index', compact('card', 'cardSettings', 'fx'));
    }

    public function topupStore(VirtualCardTransactionRequest $request, VirtualCardManager $cardManager)
    {
        $validated = $request->validated();

        // No transaction is opened here on purpose. The manager reserves and
        // debits inside its own committed transaction, then calls the provider
        // with nothing open — wrapping the whole thing meant a gateway's
        // 30-second timeout held a database connection, and a commit that
        // failed after a successful funding left no local record at all.
        try {
            $attempt = $cardManager->topup(
                (int) $validated['card_id'],
                (string) $validated['amount'],
                $validated['idempotency_key'] ?? null,
            );

            [$level, $message, $httpStatus, $state] = $this->topupOutcome($attempt);

            notifyEvs($level, $message);

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(
                    $state === 'failed'
                        ? ['ok' => false, 'state' => $state, 'error' => $message]
                        : ['ok' => $state === 'completed', 'state' => $state, 'message' => $message],
                    $httpStatus
                );
            }

            return redirect()->route('user.virtual-card.topup', ['card' => $validated['card_id']]);
        } catch (IdempotencyConflictException $e) {
            Log::warning('Virtual Card Top-up Idempotency Conflict', [
                'user_id' => auth()->id(),
                'card_id' => $validated['card_id'],
            ]);

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], 409);
            }

            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        } catch (NotifyErrorException $e) {
            Log::warning('Virtual Card Top-up Validation Error', [
                'user_id' => auth()->id(),
                'card_id' => $validated['card_id'],
                'error'   => $e->getMessage(),
            ]);

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        } catch (\Throwable $e) {
            Log::error('Virtual Card Top-up Error', [
                'user_id' => auth()->id(),
                'card_id' => $validated['card_id'],
                'error'   => $e->getMessage(),
            ]);

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => __('Something went wrong. Please try again.')], 500);
            }

            return back()->withErrors(['amount' => __('Something went wrong. Please try again.')])->withInput();
        }
    }

    /**
     * How a top-up attempt should be reported back to the customer.
     *
     * `topup()` returns the stored attempt, and on an idempotent replay that
     * attempt may already have been declined and refunded, or be sitting in
     * reconciliation. Announcing success for those told customers a top-up had
     * gone through when it had not.
     *
     * Only the local status is consulted. `failure_reason` carries whatever the
     * gateway said and is never surfaced.
     *
     * @return array{0: string, 1: string, 2: int, 3: string} level, message, HTTP status, coarse state
     */
    private function topupOutcome(VirtualCardTopup $attempt): array
    {
        return match ($attempt->status) {
            VirtualCardTopupStatus::Completed => [
                'success',
                __('Top-up successful.'),
                200,
                'completed',
            ],
            // Failed is only ever reached through compensation, which credits
            // the wallet back before it sets the status — so the money really
            // is where this says it is.
            VirtualCardTopupStatus::Failed => [
                'error',
                __('The top-up did not go through. The amount has been returned to your wallet.'),
                422,
                'failed',
            ],
            // Still pending: submitted and unconfirmed, or parked for review.
            // We do not know the outcome, and saying either way would be a
            // guess about the customer's money.
            default => [
                'warning',
                __('Your top-up is being confirmed. Its final status will appear in your transactions shortly.'),
                202,
                'pending',
            ],
        };
    }

    public function withdrawStore(VirtualCardTransactionRequest $request, VirtualCardManager $cardManager)
    {
        $validated = $request->validated();

        // No transaction is opened here, as for the top-up. The manager writes
        // the pending row and takes the amount off the card in its own
        // committed transaction before it asks the provider; wrapping the call
        // rolled that record back on a "not confirmed" answer, erasing the
        // trace reconciliation needs while the card may already be unloaded.
        try {
            $cardManager->withdraw($validated['card_id'], $validated['amount']);

            notifyEvs('success', __('Withdraw successful.'));

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['ok' => true]);
            }

            return redirect()->route('user.virtual-card.withdraw', ['card' => $validated['card_id']]);
        } catch (NotifyErrorException $e) {
            Log::warning('Virtual Card Withdraw Validation Error', [
                'user_id' => auth()->id(),
                'card_id' => $validated['card_id'],
                'error'   => $e->getMessage(),
            ]);

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        } catch (\Throwable $e) {
            Log::error('Virtual Card Withdraw Error', [
                'user_id' => auth()->id(),
                'card_id' => $validated['card_id'],
                'error'   => $e->getMessage(),
            ]);

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['error' => __('Something went wrong. Please try again.')], 500);
            }

            return back()->withErrors(['amount' => __('Something went wrong. Please try again.')])->withInput();
        }
    }

    /**
     * Freeze (block) a virtual card. Provider-agnostic — falls back to a soft DB
     * status flip when the provider's gateway has no freeze API.
     */
    public function freeze(Request $request, VirtualCard $card, VirtualCardControls $controls)
    {
        $this->authorizeCard($card);

        $payload = $request->validate([
            'reason'   => ['nullable', 'in:lost,suspicious,pause'],
            'duration' => ['nullable', 'in:1h,24h,7d,indefinite'],
        ]);

        try {
            $result = $controls->freeze($card, $payload['reason'] ?? null, $payload['duration'] ?? null);

            return response()->json([
                'ok'      => true,
                'soft'    => $result['soft'],
                'status'  => VirtualCardStatus::Inactive->value,
                'message' => __('Card frozen successfully.'),
            ]);
        } catch (NotifyErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Virtual card freeze failed', ['error' => $e->getMessage(), 'card_id' => $card->id]);

            return response()->json(['error' => __('Failed to freeze card.')], 500);
        }
    }

    public function unfreeze(VirtualCard $card, VirtualCardControls $controls)
    {
        $this->authorizeCard($card);

        try {
            $result = $controls->unfreeze($card);

            return response()->json([
                'ok'      => true,
                'soft'    => $result['soft'],
                'status'  => VirtualCardStatus::Active->value,
                'message' => __('Card unfrozen successfully.'),
            ]);
        } catch (NotifyErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Virtual card unfreeze failed', ['error' => $e->getMessage(), 'card_id' => $card->id]);

            return response()->json(['error' => __('Failed to unfreeze card.')], 500);
        }
    }

    /**
     * Save spend limits to the card's `meta` blob. Limits are advisory by default;
     * a provider that wires `setLimits()` into its API can extend this later.
     */
    public function updateLimits(Request $request, VirtualCard $card, VirtualCardControls $controls)
    {
        $this->authorizeCard($card);

        $data = $request->validate([
            'per_transaction' => ['nullable', 'numeric', 'gte:0'],
            'daily'           => ['nullable', 'numeric', 'gte:0'],
            'monthly'         => ['nullable', 'numeric', 'gte:0'],
            'alert_at'        => ['nullable', 'integer', 'between:0,100'],
            'auto_freeze'     => ['nullable', 'boolean'],
        ]);

        $controls->saveLimits($card, $data, $request->boolean('auto_freeze'));

        return response()->json(['ok' => true, 'message' => __('Limits saved.')]);
    }

    /**
     * Save card controls (online/atm/intl/contactless) to the card's `meta` blob.
     */
    public function updateControls(Request $request, VirtualCard $card, VirtualCardControls $controls)
    {
        $this->authorizeCard($card);

        $request->validate([
            'online'      => ['nullable', 'boolean'],
            'atm'         => ['nullable', 'boolean'],
            'intl'        => ['nullable', 'boolean'],
            'contactless' => ['nullable', 'boolean'],
        ]);

        $controls->saveControls($card, $request->boolean('online'), $request->boolean('atm'), $request->boolean('intl'), $request->boolean('contactless'));

        return response()->json(['ok' => true, 'message' => __('Controls updated.')]);
    }

    private function authorizeCard(VirtualCard $card): void
    {
        if ($card->user_id !== Auth::id()) {
            abort(403);
        }
    }
}
