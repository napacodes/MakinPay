<?php

namespace App\Http\Controllers\Frontend;

use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Models\Transaction as TransactionModel;
use App\Services\Money\RequestMoneyService;
use App\Services\Money\WalletPinGuard;
use App\Support\PdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Http\Request;
use Log;
use Transaction;

class TransactionController extends Controller
{
    public function index()
    {
        $transactions = Transaction::getTransactions(
            user_id: auth()->user()->id,
            trx_type: request('type'),
            status: request('status'),
            search: request('search'),
            dateRange: request('daterange')
        );

        return view('frontend.user.transaction.index', compact('transactions'));
    }

    /**
     * Answer a money request: pay it, or say no.
     *
     * Who may do which is RequestMoneyService's to decide, not this
     * controller's - the mobile API asks the same question and must get the
     * same answer. What stays here is what a browser needs: a flash and a
     * redirect back.
     *
     * This method used to look the transaction up by trx_id alone, with no
     * owner check, so any signed-in customer could approve a request between
     * two other people or cancel a stranger's pending row. The regression
     * tests are in tests/Feature/Money/RequestMoneyApprovalAuthorizationTest.
     *
     * @throws NotifyErrorException
     */
    public function handleAction(Request $request, RequestMoneyService $requests, WalletPinGuard $pins)
    {
        $validated = $request->validate([
            'trx_id'     => 'required|exists:transactions,trx_id',
            'remarks'    => 'nullable|string|max:255',
            'action'     => 'required|in:approve,reject',
            'wallet_pin' => 'nullable|string',
        ]);

        try {
            if ($validated['action'] === 'approve') {
                // Approving pays the request: the payer's wallet PIN, when one
                // is set, as the API and a transfer ask. Rejecting moves nothing.
                $pins->assert($request->user(), $validated['wallet_pin'] ?? null, context: ['request_money' => $validated['trx_id']]);

                $requests->approve($request->user(), $validated['trx_id'], $validated['remarks'] ?? null);

                notifyEvs('success', 'Transaction approved successfully.');

                return redirect()->back();
            }

            $requests->decline($request->user(), $validated['trx_id'], $validated['remarks'] ?? null);

            notifyEvs('success', 'Transaction rejected successfully.');

            return redirect()->back();
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());

            return redirect()->back();
        } catch (Exception $e) {
            Log::error('Transaction handling error: '.$e->getMessage());

            throw new NotifyErrorException(__('An error occurred while processing the transaction.'));
        }
    }

    /**
     * The customer's copy of the same receipt the admin panel downloads.
     *
     * The logo resolution this method used to carry inline now lives in
     * `PdfBranding`, so both sides of the product render one document from one
     * pipeline rather than two that drift.
     *
     * SEC1: only the signed-in customer's own row. Each side of a transfer
     * has a row of its own, so a recipient downloads their copy from their
     * own leg; anyone else's trx_id - or one that does not exist - is a 404,
     * never another customer's amounts, counterparty and wallet ids.
     */
    public function downloadPdf(Request $request, string $trx_id)
    {
        $transaction = TransactionModel::query()
            ->where('trx_id', $trx_id)
            ->where('user_id', $request->user()->id)
            ->first();

        abort_if($transaction === null, 404);

        PdfBranding::configureRenderer();

        $pdf = Pdf::loadView('general.pdf.transaction_receipt', [
            'transaction' => $transaction,
            'siteLogo'    => PdfBranding::siteLogo(),
        ]);

        return $pdf->download('transaction_receipt_'.$transaction->trx_id.'.pdf');
    }
}
