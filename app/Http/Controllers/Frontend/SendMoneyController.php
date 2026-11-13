<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendMoney\SendMoneyRequest;
use App\Models\FeatureAccessRule;
use App\Services\FeatureManager;
use App\Services\Money\SendMoneyService;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class SendMoneyController extends Controller
{
    public function __construct(private readonly SendMoneyService $transfers) {}

    public function create(Request $request): View
    {
        $prefilledRecipient = $request->string('recipient')->trim()->toString();

        $featureManager = app(FeatureManager::class);
        $accessRule     = $featureManager->find('send_money')
            ?->accessRules
            ->firstWhere('panel', $featureManager->currentPanel());

        $recipientPlaceholder = $this->buildRecipientPlaceholder($accessRule);

        return view('frontend.user.send_money.create', compact('prefilledRecipient', 'recipientPlaceholder'));
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
     * Handle the submission of the send money form.
     *
     * Everything that decides what happens to the money lives in
     * SendMoneyService now - including the fee, which is derived there and
     * cannot be supplied by a caller.
     *
     * The catch-all is kept because this form has always answered every
     * failure with a flash and a redirect back, never a server error page. A
     * NotifyErrorException renders itself that way already; anything else is
     * unexpected, and turning it into a 500 here would be a change in what a
     * customer sees.
     */
    public function store(SendMoneyRequest $request, SendMoneyService $transfers): RedirectResponse
    {
        try {
            $transfers->send($request->user(), $request->validated());
        } catch (Throwable $e) {
            report($e);
            notifyEvs('error', __('An error occurred: :message', ['message' => $e->getMessage()]));

            return back();
        }

        notifyEvs('success', __('Money sent successfully.'));

        return back();
    }
}
