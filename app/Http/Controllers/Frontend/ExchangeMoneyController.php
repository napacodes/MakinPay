<?php

namespace App\Http\Controllers\Frontend;

use App\Constants\CurrencyRole;
use App\Enums\Tax\TaxableType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\ExchangeMoneyRequest;
use App\Models\Wallet as WalletModel;
use App\Services\Money\ExchangeMoneyService;
use App\Services\Tax\TaxEngine;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ExchangeMoneyController extends Controller
{
    public function __construct(private readonly ExchangeMoneyService $exchange) {}

    /**
     * Handle the exchange money request.
     *
     * Everything that decides what happens to the money lives in
     * ExchangeMoneyService now. What is left here is what a browser needs: a
     * flash message and somewhere to go. A refusal still arrives as a
     * NotifyErrorException, which renders itself as a flash and a redirect
     * back, exactly as it did when the logic was inline.
     *
     * @throws Throwable
     */
    public function store(ExchangeMoneyRequest $request): RedirectResponse
    {
        $this->exchange->exchange($request->user(), $request->validated());

        notifyEvs('success', __('Money exchanged successfully.'));

        return redirect()->route('user.transaction.index');
    }

    /**
     * Show the exchange money form.
     */
    public function create(): View
    {
        $userWallets = WalletModel::where('user_id', Auth::id())
            ->active(CurrencyRole::EXCHANGE)
            ->with(['currency.roles' => fn ($query) => $query->where('role_name', CurrencyRole::EXCHANGE)->where('is_active', true)])
            ->get();

        // GF6: the exchange tax rules for this customer, the fixed part in
        // the site currency; the preview converts it at the source wallet's
        // rate. The server prices the real charge.
        $taxRules = app(TaxEngine::class)->previewRules(TaxableType::Exchange, Auth::user(), '1');

        return view('frontend.user.exchange_money.create', compact('userWallets', 'taxRules'));
    }
}
