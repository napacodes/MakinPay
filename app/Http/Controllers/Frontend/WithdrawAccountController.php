<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\Tax\TaxableType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\StoreWithdrawAccountRequest;
use App\Models\Wallet;
use App\Models\WithdrawAccount;
use App\Models\WithdrawMethod;
use App\Services\Money\WithdrawAccountService;
use App\Services\Tax\TaxEngine;
use App\Support\DecimalMoney;
use App\Support\WithdrawFieldNormalizer;
use Illuminate\Http\Request;

class WithdrawAccountController extends Controller
{
    public function __construct(private readonly WithdrawAccountService $accounts) {}

    public function index()
    {
        if (request()->ajax()) {
            $walletId = request('wallet_id');

            // Validate and fetch the wallet with only necessary fields
            $withdrawWallet = Wallet::select('id', 'user_id', 'currency_id')
                ->where('id', $walletId)
                ->where('user_id', auth()->id())
                ->with([
                    'currency:id,code', // Load only necessary fields for the related model
                ])
                ->firstOrFail();

            // Fetch withdraw accounts with optimized query
            $withdrawAccounts = WithdrawAccount::select('id', 'user_id', 'name')
                ->with([
                    'withdrawMethod:id,currency', // Load only necessary fields
                ])
                ->where('user_id', auth()->id())
                ->whereHas('withdrawMethod', function ($query) use ($withdrawWallet) {
                    $query->where('currency', $withdrawWallet->currency->code); // Filter by currency
                })
                ->get();

            return response()->json($withdrawAccounts);
        }

        // Fetch withdraw accounts for the view
        $withdrawAccounts = WithdrawAccount::with('withdrawMethod')->where('user_id', auth()->id())->get();

        return view('frontend.user.withdraw.account.index', compact('withdrawAccounts'));
    }

    public function store(StoreWithdrawAccountRequest $request)
    {
        $validated      = $request->validated();
        $withdrawMethod = $request->withdrawMethod() ?? WithdrawMethod::active()->findOrFail($validated['method_id']);

        $this->accounts->create(
            $request->user(),
            $withdrawMethod,
            $validated['account_name'],
            $request->input('credentials', []),
            $request->file('credentials', []),
        );

        notifyEvs('success', __('Withdraw Account Added Successfully'));

        return redirect()->route('user.withdraw.account.index');
    }

    public function create()
    {
        $withdrawMethods = WithdrawMethod::active()->orderBy('name')->get();
        $currencyOptions = auth()->user()
            ->wallets()
            ->with('currency:id,code,name,symbol,flag')
            ->active()
            ->orderBy('currency_id')
            ->get()
            ->pluck('currency')
            ->unique('code')
            ->sortBy('code')
            ->values();

        return view('frontend.user.withdraw.account.create', compact('withdrawMethods', 'currencyOptions'));
    }

    public function edit($id)
    {
        $withdrawAccount = WithdrawAccount::where('user_id', auth()->id())->findOrFail($id);

        return view('frontend.user.withdraw.account.edit', compact('withdrawAccount'));
    }

    public function update(Request $request, $id)
    {
        $withdrawAccount = WithdrawAccount::where('user_id', auth()->id())->findOrFail($id);

        $validated = $request->validate([
            'account_name' => 'required|string|max:255',
        ]);

        $request->validate(WithdrawFieldNormalizer::rules($withdrawAccount->credentials, preserveExistingFiles: true));

        // Update the withdrawal account details.
        $this->accounts->update(
            $withdrawAccount,
            $validated['account_name'],
            $request->input('credentials', []),
            $request->file('credentials', []),
        );

        notifyEvs('success', __('Withdraw Account Updated Successfully'));

        return redirect()->route('user.withdraw.account.index');
    }

    public function accountInfo($id)
    {
        $withdrawAccount = WithdrawAccount::with(['withdrawMethod'])
            ->where('user_id', auth()->id())
            ->findOrFail($id);

        $method = $withdrawAccount->withdrawMethod;

        $data = [
            // Where the platform's own limit and the processor's overlap —
            // the range that will actually be accepted, rather than the
            // platform's figure alone.
            'min_limit'           => $method->effectiveMinAmount(),
            'max_limit'           => $method->effectiveMaxAmount(),
            'limited_by_provider' => $method->isLimitedByProvider(),

            // Legacy single amount + type, kept for one release.
            'charge'      => $method->charge,
            'charge_type' => $method->charge_type,

            // Role-aware components the browser preview computes from, so it
            // matches what the server will charge.
            'charge_fixed'   => $method->charge_fixed,
            'charge_percent' => $method->charge_percent,
            'charge_min'     => $method->charge_min,
            'charge_max'     => $method->charge_max,

            'currency'        => $method->currency,
            'decimals'        => currencyDecimals($method->currency),
            'processing_time' => $method->processing_time,
            'conversion_rate' => DecimalMoney::rate($method->conversion_rate ?? 1),
            'destination'     => $this->accounts->maskedDestination($withdrawAccount),

            // GF6: the tax rules this payout would carry, the fixed part
            // already in the method's currency, for the preview to mirror.
            // The server prices the real charge.
            'tax_rules' => app(TaxEngine::class)->previewRules(TaxableType::Withdraw, auth()->user(), DecimalMoney::rate($method->conversion_rate ?? 1)),
        ];

        return response()->json($data);
    }
}
