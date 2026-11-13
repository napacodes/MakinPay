<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\WithdrawMethod;
use App\Models\WithdrawSchedule;
use App\Services\Money\WithdrawMoneyService;
use App\Support\AmountEntry;
use Exception;
use Illuminate\Http\Request;

class WithdrawController extends Controller
{
    public function __construct(private readonly WithdrawMoneyService $withdrawals) {}

    public function create()
    {
        $isWithdrawEnabledToday = WithdrawSchedule::isWithdrawEnabledToday();

        $withdrawOffDays = WithdrawSchedule::where('status', false)->pluck('day')->toArray();

        $wallets = auth()->user()->wallets()
            ->active()
            ->where('balance', '>', 0)
            ->get();

        return view('frontend.user.withdraw.create', compact('wallets', 'isWithdrawEnabledToday', 'withdrawOffDays'));
    }

    /**
     * @throws Exception
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'account_id' => 'required|exists:withdraw_accounts,id',
            'wallet_id'  => 'required|exists:wallets,id',
            // Typed in the payout method's currency, which must match the
            // wallet's. A flat floor of 1 meant one whole BTC.
            'amount' => AmountEntry::rules(
                AmountEntry::currencyForWalletId($request->input('wallet_id'), $request->user()?->getAuthIdentifier())
                    ?? siteCurrency()
            ),
        ]);

        /*
         * The PIN field is validated here rather than in the service because
         * a browser expects a field error under the input it belongs to, not
         * a flash message at the top of the page. The service checks whether
         * the PIN is CORRECT - that part belongs with the money, and a caller
         * with no form request still needs it.
         */
        if ($request->user()->hasWalletPin()) {
            $validated['wallet_pin'] = $request->validate([
                'wallet_pin' => ['required', 'digits:6'],
            ])['wallet_pin'];
        }

        $this->withdrawals->withdraw($request->user(), $validated);

        notifyEvs('success', __('Withdrawal Requested and will be processed shortly.'));

        return redirect()->route('user.transaction.index');
    }

    public function credentialsFields($method_id)
    {
        $method = WithdrawMethod::active()->findOrFail($method_id);

        $view = view('frontend.user.withdraw.partials.credentials_fields', compact('method'));

        return response()->json(['html' => $view->render(), 'method_name' => $method->name]);
    }
}
