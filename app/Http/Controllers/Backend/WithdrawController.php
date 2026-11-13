<?php

namespace App\Http\Controllers\Backend;

use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Transaction as TransactionModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Transaction;

class WithdrawController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'manualRequest|history' => 'withdraw-list',
            'requestAction'         => 'withdraw-action',
        ];
    }

    public function manualRequest()
    {
        $withdrawRequests = Transaction::getTransactions(
            trx_type: TrxType::WITHDRAW,
            status: TrxStatus::PENDING,
            search: request('search'),
            dateRange: request('daterange'),
            processing_type: MethodType::MANUAL
        );

        return view('backend.withdraw.manual_request', compact('withdrawRequests'));
    }

    /**
     * Approve or reject a manual withdrawal.
     *
     * Only a withdrawal is found here: any other trx_id is a 404, so this
     * endpoint can never complete or refund another module's row, such as a
     * bill payment's debit, which has the same PENDING, MANUAL, MINUS shape.
     * The row must also still be PENDING and MANUAL, the same rules
     * TransactionController::action applies. Rejecting refunds the hold, so a
     * second rejection would credit the wallet twice, and a pending automatic
     * payout may still be paid out by its gateway.
     *
     * This check is not made under a lock. Serialising two concurrent
     * decisions on one row is cancelTransaction's job.
     */
    public function requestAction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'trx_id'         => 'required',
            'action'         => 'required|in:approve,reject',
            'remarks'        => 'nullable|string',
            'payout_tx_hash' => 'nullable|string|max:120',
        ]);

        $transaction = TransactionModel::query()
            ->where('trx_id', $validated['trx_id'])
            ->where('trx_type', TrxType::WITHDRAW)
            ->firstOrFail();

        if ($transaction->status !== TrxStatus::PENDING || $transaction->processing_type !== MethodType::MANUAL) {
            notifyEvs('error', __('Only a pending manual transaction can be approved or rejected.'));

            return redirect()->back();
        }

        if ($request->action === 'approve') {
            $this->recordPayoutTxHash($transaction->trx_id, $validated['payout_tx_hash'] ?? null);
            Transaction::completeTransaction($transaction->trx_id, $request->remarks);
        } else {
            Transaction::cancelTransaction($transaction->trx_id, $request->remarks, true);
        }

        notifyEvs('success', 'Withdraw request updated successfully');

        return redirect()->back();
    }

    /**
     * Persist the on-chain payout hash on the transaction so crypto
     * withdrawals stay reconcilable against the blockchain explorer.
     */
    protected function recordPayoutTxHash(string $trxId, ?string $txHash): void
    {
        if ($txHash === null || trim($txHash) === '') {
            return;
        }

        $transaction = TransactionModel::query()->where('trx_id', $trxId)->first();

        if (! $transaction) {
            return;
        }

        $transaction->update([
            'trx_data' => array_merge($transaction->trx_data ?? [], [
                'payout_tx_hash' => trim($txHash),
            ]),
        ]);
    }

    public function history()
    {
        $withdrawHistories = Transaction::getTransactions(
            trx_type: TrxType::WITHDRAW,
            status: request('status'),
            search: request('search'),
            dateRange: request('daterange')
        );

        return view('backend.withdraw.history', compact('withdrawHistories'));
    }
}
