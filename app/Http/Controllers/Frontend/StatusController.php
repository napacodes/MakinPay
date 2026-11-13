<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Facades\TransactionFacade as Transaction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class StatusController extends Controller
{
    /**
     * The only ledger rows the public cancel page may fail.
     *
     * The page is reachable by anyone and takes the transaction id from the
     * request, and failTransaction() asks nothing of its caller outside an
     * IPN, so without this list a visitor holding any trx id could fail any
     * PENDING row: a withdrawal, a recharge or a card top-up still waiting
     * on its provider. A gateway names this page as its cancel URL for two
     * kinds of row only: deposits (PaymentService::depositWithPaymentMethod)
     * and merchant receipts, from hosted checkout (MerchantCheckoutService)
     * or a payment link (PaymentLinkService). Both are gateway (automatic)
     * rows: a manual deposit is also a DEPOSIT, but it waits on an admin to
     * review the customer's proof of an offline transfer, and failing it here
     * would leave the admin nothing to approve. Any other row is left as it
     * is and the visitor gets the redirect an unknown id gets, so the page
     * gives away nothing about the row it was named.
     *
     * @var list<TrxType>
     */
    private const CANCELLABLE_TYPES = [TrxType::DEPOSIT, TrxType::RECEIVE_PAYMENT];

    /**
     * Handle a successful payment.
     */
    public function success(Request $request): RedirectResponse
    {
        $trxId = $request->input('trx_id');

        if ($trxId) {
            $transaction = Transaction::findTransaction($trxId);

            if ($transaction && $transaction->trx_type === TrxType::RECEIVE_PAYMENT) {
                $redirectUrl = $transaction->trx_data['success_redirect'] ?? null;

                if ($redirectUrl) {
                    return redirect()->away($redirectUrl);
                }
            }
        }

        notifyEvs('success', __('Successful Requested and will be processed shortly.'));

        return redirect()->route('user.transaction.index');
    }

    /**
     * Handle a payment that is awaiting asynchronous gateway confirmation.
     */
    public function pending(Request $request): RedirectResponse
    {
        $trxId       = trim((string) $request->input('trx_id', ''));
        $transaction = $trxId !== '' ? Transaction::findTransaction($trxId) : null;

        if ($transaction?->status === TrxStatus::COMPLETED) {
            return $this->success($request);
        }

        if ($transaction && in_array($transaction->status, [TrxStatus::FAILED, TrxStatus::CANCELED], true)) {
            notifyEvs('error', __('Payment could not be completed. Check the transaction status for details.'));
        } else {
            notifyEvs('info', __('Payment is pending confirmation. You can track it from your transaction history.'));
        }

        if (! auth()->check()) {
            return redirect()->route('home');
        }

        return redirect()->route('user.transaction.index', array_filter([
            'status' => $transaction?->status->value ?? TrxStatus::PENDING->value,
            'search' => $trxId !== '' ? $trxId : null,
        ]));
    }

    /**
     * Handle a payment cancellation.
     */
    public function cancel(Request $request): RedirectResponse
    {
        // Retrieve and remove the cancel transaction ID from session if not provided in the request.
        $trxId = $request->input('trx_id') ?: Session::pull('cancel_tnx');

        if (! $trxId) {
            notifyEvs('warning', __('Payment Canceled'));

            return redirect()->route('user.transaction.index');
        }

        $transaction = Transaction::findTransaction($trxId);

        if ($transaction
            && in_array($transaction->trx_type, self::CANCELLABLE_TYPES, true)
            && $transaction->processing_type === MethodType::AUTOMATIC) {
            Transaction::failTransaction($trxId);
        }

        if ($transaction && $transaction->trx_type === TrxType::RECEIVE_PAYMENT) {
            $redirectUrl = $transaction->trx_data['cancel_redirect'] ?? null;

            if ($redirectUrl) {
                return redirect()->away($redirectUrl);
            }
        }

        notifyEvs('warning', __('Payment Canceled'));

        return redirect()->route('user.transaction.index');
    }

    public function callback(Request $request): RedirectResponse
    {
        $gateway = $request->input('gateway');
        $trxId   = $request->input('trx');

        if (! $trxId || ! $gateway) {
            notifyEvs('error', __('Invalid callback parameters.'));

            return redirect()->route('user.transaction.index');
        }

        $transaction = Transaction::findTransaction($trxId);

        if (! $transaction) {
            notifyEvs('error', __('Transaction not found.'));

            return redirect()->route('user.transaction.index');
        }

        // Optionally verify status with gateway here, or trust webhook/IPN.
        if ($transaction->status === TrxStatus::COMPLETED) {
            notifyEvs('success', __('Payment Successful'));
        } elseif ($transaction->status === TrxStatus::FAILED) {
            notifyEvs('error', __('Payment Failed'));
        } else {
            notifyEvs('info', __('Payment is pending confirmation.'));
        }

        // Support merchant redirect if set
        if ($transaction->trx_type === TrxType::RECEIVE_PAYMENT) {
            $redirectUrl = $transaction->trx_data['success_redirect'] ?? null;
            if ($redirectUrl) {
                return redirect()->away($redirectUrl);
            }
        }

        return redirect()->route('user.transaction.index');
    }
}
