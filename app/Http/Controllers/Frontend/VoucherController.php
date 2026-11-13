<?php

namespace App\Http\Controllers\Frontend;

use App\Constants\CurrencyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\StoreVoucherRequest;
use App\Models\Voucher;
use App\Models\Wallet as WalletModel;
use App\Services\VoucherService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class VoucherController extends Controller
{
    public function myVouchers()
    {
        $vouchers = Voucher::where('user_id', auth()->id())->latest()->get();

        return view('frontend.user.vouchers.index', compact('vouchers'));
    }

    /**
     * @throws Throwable
     */
    public function store(StoreVoucherRequest $request, VoucherService $vouchers)
    {
        $validated = $request->validated();

        $wallet = WalletModel::query()
            ->with(['currency.activeRoles'])
            ->where('user_id', Auth::id())
            ->findOrFail($validated['wallet_id']);

        $vouchers->issue($request->user(), $wallet, $validated['amount']);

        notifyEvs('success', __('Voucher created successfully.'));

        return redirect()->back();
    }

    public function create()
    {
        $wallets = WalletModel::where('user_id', Auth::id())
            ->whereHas('currency.roles', fn ($query) => $query->where('role_name', CurrencyRole::VOUCHER)->where('is_active', true))
            ->with(['currency.roles' => fn ($query) => $query->where('role_name', CurrencyRole::VOUCHER)->where('is_active', true)])
            ->get();

        return view('frontend.user.vouchers.create', compact('wallets'));
    }

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function redeem(Request $request, VoucherService $vouchers)
    {
        $request->validate([
            'voucher_code' => 'required|string',
        ]);

        $vouchers->redeem($request->user(), (string) $request->input('voucher_code'));

        notifyEvs('success', __('Voucher redeemed successfully.'));

        return redirect()->back();
    }
}
