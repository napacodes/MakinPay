<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend\P2P;

use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\P2P\StorePaymentAccountRequest;
use App\Http\Requests\P2P\UpdatePaymentAccountRequest;
use App\Models\P2P\PaymentAccount;
use App\Models\P2P\PaymentMethod;
use App\Services\P2P\P2PPaymentAccountService;
use App\Support\P2PPaymentFileDisk;
use App\Support\P2PPaymentMethodManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentAccountController extends Controller
{
    public function index(): View
    {
        $userCountryCode = P2PPaymentMethodManager::resolveCountryCode(auth()->user());
        $accounts        = PaymentAccount::query()
            ->with('paymentMethod')
            ->where('user_id', auth()->id())
            ->latest('id')
            ->get();
        $accounts = P2PPaymentMethodManager::sortPaymentAccounts($accounts, $userCountryCode);

        $savedMethodIds = $accounts
            ->pluck('payment_method_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $methods = P2PPaymentMethodManager::sortMethods(
            PaymentMethod::query()
                ->where('status', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            $userCountryCode,
            $savedMethodIds
        );

        return view('frontend.user.p2p.payment_accounts.index', compact('accounts', 'methods', 'userCountryCode'));
    }

    public function store(StorePaymentAccountRequest $request, P2PPaymentAccountService $accounts): RedirectResponse
    {
        $accounts->create($request->user(), $request);

        notifyEvs('success', __('P2P payment account added successfully.'));

        return redirect()->route('user.p2p.payment-accounts.index');
    }

    public function update(UpdatePaymentAccountRequest $request, PaymentAccount $paymentAccount, P2PPaymentAccountService $accounts): RedirectResponse
    {
        $this->authorize('update', $paymentAccount);

        $accounts->update($request->user(), $paymentAccount, $request);

        notifyEvs('success', __('P2P payment account updated successfully.'));

        return back();
    }

    public function destroy(PaymentAccount $paymentAccount, P2PPaymentAccountService $accounts): RedirectResponse
    {
        $this->authorize('delete', $paymentAccount);

        try {
            $accounts->delete(auth()->user(), $paymentAccount);
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());

            return back();
        }

        notifyEvs('success', __('P2P payment account removed successfully.'));

        return back();
    }

    /**
     * A file on one of the customer's own accounts, from the private disk.
     */
    public function viewFile(PaymentAccount $paymentAccount, string $field): StreamedResponse
    {
        $this->authorize('update', $paymentAccount);

        return P2PPaymentFileDisk::response(P2PPaymentFileDisk::fromDetails($paymentAccount->renderedDetails(), $field));
    }
}
