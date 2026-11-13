<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\VirtualCard\CardholderStatus;
use App\Enums\VirtualCard\VirtualCardNetwork;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\VirtualCard\StoreVirtualCardRequest;
use App\Models\Cardholders;
use App\Models\VirtualCardProvider;
use App\Models\VirtualCardRequest;
use App\Models\Wallet;
use App\Services\VirtualCard\VirtualCardRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VirtualCardRequestController extends Controller
{
    /**
     * User dashboard: Show card requests, wallets, modal data.
     */
    public function index()
    {
        $user = Auth::user();

        $wallets = $user->wallets()
            ->active()
            ->with(['currency'])
            ->get();

        // All virtual card requests for user
        $cardRequests = VirtualCardRequest::where('user_id', $user->id)
            ->with(['wallet.currency', 'card'])
            ->latest('id')
            ->get();

        // Fee info (min/max fixed fee and % surcharge from providers)
        $reqData = [
            'min_issue_fee'     => VirtualCardProvider::min('issue_fee'),
            'max_issue_fee'     => VirtualCardProvider::max('issue_fee'),
            'min_issue_fee_pct' => VirtualCardProvider::min('issue_fee_pct'),
            'max_issue_fee_pct' => VirtualCardProvider::max('issue_fee_pct'),
        ];

        // All supported networks (for modal select)
        $networks = VirtualCardNetwork::cases();

        // Only approved cardholders can be issued a card (the store request enforces
        // the same rule). The pending count lets the modal tell a user whose profile
        // is still under review why the list is empty, instead of a bare "no options".
        $cardholders = Cardholders::where('user_id', $user->id)->where('status', CardholderStatus::APPROVED)->get();

        $pendingCardholderCount = Cardholders::where('user_id', $user->id)->where('status', CardholderStatus::PENDING)->count();

        return view('frontend.user.virtual_card.request.index', compact('wallets', 'cardRequests', 'reqData', 'networks', 'cardholders', 'pendingCardholderCount'));
    }

    /**
     * Store a new virtual card request (from modal form).
     */
    public function store(StoreVirtualCardRequest $request, VirtualCardRequestService $requests)
    {
        $validated = $request->validated();
        $user      = $request->user();

        $wallet = Wallet::where('id', $validated['wallet_id'])
            ->where('user_id', $user->id)
            ->with('currency')
            ->firstOrFail();

        try {
            $requests->submit(
                user: $user,
                wallet: $wallet,
                cardholderId: (int) $validated['cardholder_id'],
                network: $validated['network'],
                initialLoad: $validated['initial_load_amount'],
                theme: $validated['theme'] ?? null,
            );
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());

            return back()->withInput();
        } catch (\Exception $e) {
            logger()->error('Failed to create virtual card request: '.$e->getMessage());
            notifyEvs('error', 'Failed to submit card request. Please try again.');

            return back();
        }

        notifyEvs('success', 'Your card request has been submitted for admin review.');

        return redirect()->route('user.virtual-card.request.index');
    }

    /**
     * (Optional) API endpoint for AJAX filtering eligible wallets (network+currency)
     */
    public function eligibleWallets(Request $request)
    {
        $network = $request->get('network');
        $user    = Auth::user();

        // Get all providers that support the selected network
        $providers = VirtualCardProvider::active()
            ->whereJsonContains('supported_networks', $network)
            ->get();

        // Collect all currencies supported by these providers
        $supportedCurrencies = $providers->flatMap(function ($provider) {
            return $provider->supported_currencies ?: [];
        })->unique()->values();

        // User wallets that have supported currency
        $wallets = $user->wallets()
            ->active()
            ->with('currency')
            ->whereHas('currency', function ($q) use ($supportedCurrencies) {
                $q->whereIn('code', $supportedCurrencies);
            })
            ->get();

        // Prepare for dropdown (include code & symbol for UI badges)
        return response()->json($wallets->map(function ($wallet) {
            return [
                'id' => $wallet->id,
                // Formatted at the wallet's own precision. Hardcoding two
                // decimals rendered every crypto wallet in this picker as
                // "BTC Wallet — ₿0.00", whatever it actually held.
                'text'   => "{$wallet->currency->code} Wallet — ".money($wallet->balance, $wallet->currency->code),
                'code'   => $wallet->currency->code,
                'symbol' => $wallet->currency->symbol,
            ];
        }));
    }
}
