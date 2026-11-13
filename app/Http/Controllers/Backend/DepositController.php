<?php

namespace App\Http\Controllers\Backend;

use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Transaction as TransactionModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Transaction;

class DepositController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'manualRequest|history' => 'deposit-list',
            'requestAction'         => 'deposit-action',

        ];
    }

    public function manualRequest()
    {
        $depositRequests = Transaction::getTransactions(
            trx_type: TrxType::DEPOSIT,
            status: TrxStatus::PENDING,
            search: request('search'),
            dateRange: request('daterange'),
            processing_type: MethodType::MANUAL
        );

        return view('backend.deposit.manual_request', compact('depositRequests'));
    }

    /**
     * Approve or reject a manual deposit.
     *
     * Only a deposit is found here: any other trx_id is a 404, so this
     * endpoint can never complete or cancel another module's row, such as a
     * withdrawal (whose hold a rejection here would not return) or a bill
     * payment's debit. The row must also still be PENDING and MANUAL, the
     * same rules TransactionController::action applies, so an approved
     * deposit cannot be marked cancelled afterwards with its credit kept.
     */
    public function requestAction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'trx_id'  => 'required',
            'action'  => 'required|in:approve,reject',
            'remarks' => 'nullable|string',
        ]);

        $transaction = TransactionModel::query()
            ->where('trx_id', $validated['trx_id'])
            ->where('trx_type', TrxType::DEPOSIT)
            ->firstOrFail();

        if ($transaction->status !== TrxStatus::PENDING || $transaction->processing_type !== MethodType::MANUAL) {
            notifyEvs('error', __('Only a pending manual transaction can be approved or rejected.'));

            return redirect()->back();
        }

        if ($request->action === 'approve') {
            Transaction::completeTransaction($transaction->trx_id, $request->remarks);
        } else {
            Transaction::cancelTransaction($transaction->trx_id, $request->remarks);
        }

        notifyEvs('success', 'Deposit request updated successfully');

        return redirect()->back();

    }

    public function history()
    {

        $depositHistories = Transaction::getTransactions(
            trx_type: TrxType::DEPOSIT,
            status: request('status'),
            search: request('search'),
            dateRange: request('daterange')
        );

        return view('backend.deposit.history', compact('depositHistories'));
    }
}
