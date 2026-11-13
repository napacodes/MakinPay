<?php

namespace App\Http\Controllers\Frontend;

use App\Constants\CurrencyRole;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\GiftCardStoreRequest;
use App\Models\Currency;
use App\Models\GiftCard;
use App\Models\GiftCardTemplate;
use App\Models\User;
use App\Models\Wallet as WalletModel;
use App\Services\GiftCardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class GiftCardController extends Controller
{
    public function index(Request $request): View
    {
        $tab    = $request->input('tab', 'sent');
        $userId = Auth::id();

        $sentQuery = GiftCard::query()
            ->with(['template', 'currency', 'recipient'])
            ->where('user_id', $userId)
            ->latest();

        $receivedQuery = GiftCard::query()
            ->with(['template', 'currency', 'sender'])
            ->where(function ($q) use ($userId) {
                $q->where('recipient_user_id', $userId)
                    ->orWhere('recipient_email', Auth::user()?->email);
            })
            ->latest();

        $stats = [
            'sent'        => (clone $sentQuery)->count(),
            'received'    => (clone $receivedQuery)->count(),
            'total_value' => $this->sentValuePerCurrency((int) $userId),
            'unredeemed'  => (clone $receivedQuery)->whereIn('status', ['pending', 'delivered'])->count(),
        ];

        $giftCards = match ($tab) {
            'received' => $receivedQuery->paginate(8)->withQueryString(),
            'redeemed' => GiftCard::query()
                ->with(['template', 'currency'])
                ->where(function ($q) use ($userId) {
                    $q->where('redeemed_by', $userId)
                        ->orWhere('user_id', $userId);
                })
                ->where('status', 'redeemed')
                ->latest('redeemed_at')
                ->paginate(8)
                ->withQueryString(),
            default => $sentQuery->paginate(8)->withQueryString(),
        };

        // Wallets feed the redeem modal that lives on this same page —
        // the legacy standalone /redeem screen has been folded into a
        // Bootstrap modal so the page never reloads to redeem.
        $redeemWallets = WalletModel::where('user_id', $userId)
            ->where('status', true)
            ->with('currency')
            ->get();

        return view('frontend.user.gift-cards.index', compact('giftCards', 'tab', 'stats', 'redeemWallets'));
    }

    /**
     * What the customer has sent in gift cards, one line per currency - each
     * card is in its own currency, so the amounts are never added across.
     *
     * @return list<array{currency: ?string, amount: string}>
     */
    private function sentValuePerCurrency(int $userId): array
    {
        $totals = GiftCard::query()
            ->where('user_id', $userId)
            ->selectRaw('currency_id, SUM(amount) as total')
            ->groupBy('currency_id')
            ->pluck('total', 'currency_id');

        $codes = Currency::query()->whereIn('id', $totals->keys()->filter())->pluck('code', 'id');

        return $totals
            ->map(fn ($total, $currencyId): array => [
                'currency' => $codes[$currencyId] ?? null,
                'amount'   => (string) $total,
            ])
            ->values()
            ->all();
    }

    public function create(): View
    {
        $templates = GiftCardTemplate::query()
            ->active()
            ->orderBy('sort_order')
            ->get();

        $categories = ['All', ...GiftCardTemplate::CATEGORIES];

        $wallets = WalletModel::where('user_id', Auth::id())
            ->whereHas('currency.roles', fn ($q) => $q->where('role_name', CurrencyRole::GIFT_CARD)->where('is_active', true))
            ->with(['currency.roles' => fn ($q) => $q->where('role_name', CurrencyRole::GIFT_CARD)->where('is_active', true)])
            ->get();

        if ($wallets->isEmpty()) {
            $wallets = WalletModel::where('user_id', Auth::id())
                ->whereHas('currency.roles', fn ($q) => $q->where('role_name', CurrencyRole::VOUCHER)->where('is_active', true))
                ->with(['currency.roles' => fn ($q) => $q->where('role_name', CurrencyRole::VOUCHER)->where('is_active', true)])
                ->get();
        }

        return view('frontend.user.gift-cards.create', compact('templates', 'categories', 'wallets'));
    }

    /**
     * Explicit redirect destinations so the user never gets bounced
     * to an unexpected URL via referer-based `back()`:
     *   · success                       → user.gift-card.index
     *   · validation / business failure → user.gift-card.create
     * Both legs flash a notifyevs message that the destination's
     * _notify_evs.blade.php partial renders as a toast.
     *
     * @throws Throwable
     */
    public function store(GiftCardStoreRequest $request, GiftCardService $giftCards): RedirectResponse
    {
        $data = $request->validated();

        $template = GiftCardTemplate::findOrFail($data['gift_card_template_id']);
        $wallet   = WalletModel::with('currency.roles')->findOrFail($data['wallet_id']);

        try {
            $giftCards->issue($request->user(), $wallet, $template, $data['amount'], $data);
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());

            return redirect()->route('user.gift-card.create')->withInput();
        }

        notifyEvs('success', __('Gift card sent successfully.'));

        return redirect()->route('user.gift-card.index');
    }

    /**
     * @throws Throwable
     */
    public function redeem(Request $request, GiftCardService $giftCards): RedirectResponse
    {
        $request->validate([
            'code'      => ['required', 'string'],
            'wallet_id' => ['required', 'integer', 'exists:wallets,id'],
        ]);

        $giftCards->redeem($request->user(), (string) $request->input('code'), (int) $request->input('wallet_id'));

        notifyEvs('success', __('Gift card redeemed successfully.'));

        return redirect()->route('user.gift-card.index');
    }

    public function preview(string $code): View
    {
        $giftCard = GiftCard::with(['template', 'currency', 'sender'])
            ->where('code', $code)
            ->firstOrFail();

        /*
         * Mark as delivered the first time someone OTHER than the
         * sender opens the public preview. The sender opening their
         * own card (to inspect it) must NOT flip the status — that
         * was the bug the user reported. Guests (no auth) always
         * count as non-sender, so an emailed recipient who clicks
         * the link still triggers the transition.
         */
        $viewerId = Auth::id();
        $senderId = (int) $giftCard->user_id;
        $isSender = $viewerId !== null && (int) $viewerId === $senderId;

        if (! $isSender && $giftCard->status === 'pending') {
            $giftCard->update([
                'status'       => 'delivered',
                'delivered_at' => now(),
            ]);
            $giftCard->refresh();
        }

        /*
         * Tell the view who is watching so it can hide the Redeem
         * CTA for the sender (they can't redeem their own card) and
         * show an explanatory note instead.
         */
        $isOwnCard = $isSender;

        return view('frontend.user.gift-cards.preview', compact('giftCard', 'isOwnCard'));
    }

    public function cancel(GiftCard $giftCard, GiftCardService $giftCards): RedirectResponse
    {
        if ((int) $giftCard->user_id !== (int) Auth::id()) {
            throw new NotifyErrorException(__('You cannot cancel this gift card.'));
        }

        if (! in_array($giftCard->status, ['pending', 'scheduled'], true)) {
            throw new NotifyErrorException(__('Only pending or scheduled cards can be cancelled.'));
        }

        $giftCards->cancel($giftCard, ['pending', 'scheduled']);

        notifyEvs('success', __('Gift card cancelled. Its value has been returned to your wallet.'));

        return back();
    }
}
