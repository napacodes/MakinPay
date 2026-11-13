<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\FeatureAccessRule;
use App\Services\FeatureManager;
use App\Services\Money\RequestMoneyService;
use App\Services\WalletService;
use App\Support\AmountEntry;
use Illuminate\Http\Request;
use Throwable;

class RequestMoneyController extends Controller
{
    /**
     * Displays the form for requesting money from another user.
     */
    public function create()
    {
        $featureManager = app(FeatureManager::class);
        $accessRule     = $featureManager->find('request_money')
            ?->accessRules
            ->firstWhere('panel', $featureManager->currentPanel());

        $recipientPlaceholder = $this->buildRecipientPlaceholder($accessRule);

        return view('frontend.user.request_money.create', compact('recipientPlaceholder'));
    }

    private function buildRecipientPlaceholder(?FeatureAccessRule $rule): string
    {
        $methods = $rule?->allowedIdentifierMethods() ?? [
            WalletService::IDENTIFIER_WALLET_ID,
            WalletService::IDENTIFIER_EMAIL,
            WalletService::IDENTIFIER_MOBILE,
        ];

        $labels = [
            WalletService::IDENTIFIER_WALLET_ID => __('Wallet ID'),
            WalletService::IDENTIFIER_EMAIL     => __('Email'),
            WalletService::IDENTIFIER_MOBILE    => __('Mobile Number'),
        ];

        $parts = array_values(array_intersect_key($labels, array_flip($methods)));

        return $parts === []
            ? __('Enter recipient')
            : __('Enter :methods', ['methods' => implode(' or ', $parts)]);
    }

    /**
     * Handle the submission of the request-money form.
     *
     * Everything that decides what is written - the fee, the recipient
     * resolution, the two pending legs - lives in RequestMoneyService now, so
     * the app and this form cannot mean different things by a money request.
     *
     * The catch-all is kept because this form has always answered every
     * failure with a flash and a redirect back, never a server error page.
     */
    public function store(Request $request, RequestMoneyService $requests)
    {
        $validated = $request->validate([
            'recipient' => ['required'],
            'wallet_id' => ['required', 'exists:wallets,id'],
            // Typed in the requesting wallet's own currency, so the floor is
            // one unit of that currency rather than a flat 0.01 that rejected
            // every BTC request below roughly six hundred dollars.
            'amount' => AmountEntry::rules(
                AmountEntry::currencyForWalletId($request->input('wallet_id'), $request->user()?->getAuthIdentifier())
                    ?? siteCurrency()
            ),
            'note' => ['nullable', 'max:255'],
        ]);

        try {
            $requests->request($request->user(), $validated);
        } catch (Throwable $e) {
            report($e);
            notifyEvs('error', __('An error occurred: :message', ['message' => $e->getMessage()]));

            return redirect()->back();
        }

        notifyEvs('success', __('Money request sent successfully.'));

        return redirect()->back();
    }
}
