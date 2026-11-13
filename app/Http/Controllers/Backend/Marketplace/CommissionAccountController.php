<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\Marketplace;

use App\Http\Controllers\Backend\BaseController;
use App\Models\MarketplaceOrder;
use App\Services\Marketplace\MarketplaceCommissionReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommissionAccountController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'show|export' => 'marketplace-manage',
        ];
    }

    public function show(MarketplaceCommissionReportService $reports): View
    {
        $account           = $reports->account();
        $summary           = $account ? $reports->summary($account) : null;
        $transactions      = $account ? $reports->recentTransactions($account) : null;
        $ordersByReference = $transactions
            ? MarketplaceOrder::query()
                ->with(['vendor:id,display_name', 'currency:id,code'])
                ->whereIn('trx_reference', $transactions->pluck('trx_reference')->filter()->unique())
                ->get()
                ->keyBy('trx_reference')
            : collect();

        return view('backend.marketplace.commission-account.show', compact(
            'account',
            'summary',
            'transactions',
            'ordersByReference',
        ));
    }

    public function export(MarketplaceCommissionReportService $reports): StreamedResponse|RedirectResponse
    {
        $account = $reports->account();

        if (! $account) {
            return redirect()
                ->route('admin.marketplace.settings.edit')
                ->with('notifyevs', ['type' => 'error', 'message' => __('Configure the protected commission account before exporting.')]);
        }

        $filename = 'marketplace-commission-ledger-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($account, $reports): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'Transaction ID', 'Order Reference', 'Entry Type', 'Direction',
                'Amount', 'Currency', 'Wallet Reference', 'Status', 'Recorded At (UTC)',
            ]);

            foreach ($reports->exportRows($account) as $transaction) {
                fputcsv($stream, [
                    $transaction->trx_id,
                    $transaction->trx_reference,
                    $transaction->trx_type->label(),
                    $transaction->amount_flow->value,
                    $transaction->amount,
                    $transaction->currency,
                    $transaction->wallet_reference,
                    $transaction->status->value,
                    $transaction->created_at?->utc()->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
