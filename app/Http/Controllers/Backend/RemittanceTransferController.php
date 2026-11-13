<?php

namespace App\Http\Controllers\Backend;

use App\Enums\RemittanceStatus;
use App\Http\Requests\Backend\RemittanceTransferActionRequest;
use App\Http\Requests\Backend\RemittanceTransferFilterRequest;
use App\Models\RemittanceCorridor;
use App\Models\RemittanceTransfer;
use App\Services\Remittance\RemittanceService;
use App\Services\Remittance\RemittanceTransferQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RemittanceTransferController extends BaseController
{
    public function __construct(
        protected RemittanceService $remittance,
        protected RemittanceTransferQueryService $transferQuery,
    ) {}

    public static function permissions(): array
    {
        return [
            'index|show|export'   => 'remittance-transfer-list',
            'approve|fail|refund' => 'remittance-transfer-manage',
        ];
    }

    public function index(RemittanceTransferFilterRequest $request): View
    {
        $filters   = $request->validated();
        $transfers = $this->transferQuery
            ->filtered($filters)
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
        $statuses       = RemittanceStatus::options();
        $gateways       = (array) config('remittance.gateways', []);
        $dispatchStates = (array) config('remittance.dispatch_states', []);
        $operations     = $this->transferQuery->operationalSummary();
        $corridors      = RemittanceCorridor::query()->orderBy('name')->get(['id', 'name']);

        return view('backend.remittance.transfers.index', compact(
            'transfers',
            'statuses',
            'gateways',
            'dispatchStates',
            'operations',
            'corridors',
        ));
    }

    public function show(RemittanceTransfer $transfer): View
    {
        $transfer->load([
            'user',
            'beneficiary',
            'corridor',
            'transaction',
            'quote',
            'statusEvents' => fn ($query) => $query->orderBy('occurred_at')->orderBy('id'),
        ]);

        return view('backend.remittance.transfers.show', compact('transfer'));
    }

    public function export(RemittanceTransferFilterRequest $request): StreamedResponse
    {
        $query    = $this->transferQuery->filtered($request->validated());
        $filename = 'remittance-transfers-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                throw new RuntimeException('Unable to open the CSV output stream.');
            }

            fputcsv($stream, [
                'Reference',
                'Created At',
                'Sender',
                'Sender Email',
                'Recipient',
                'Recipient Country',
                'Source Amount',
                'Fee',
                'Total Paid',
                'Source Currency',
                'Receive Amount',
                'Destination Currency',
                'Payout Method',
                'Gateway',
                'Gateway Reference',
                'Status',
                'Failure Reason',
            ], escape: '');

            $query->chunkById(500, function ($transfers) use ($stream): void {
                foreach ($transfers as $transfer) {
                    fputcsv($stream, [
                        $this->csvCell($transfer->reference),
                        $transfer->created_at?->toIso8601String(),
                        $this->csvCell($transfer->user?->name),
                        $this->csvCell($transfer->user?->email),
                        $this->csvCell($transfer->recipient_name),
                        $this->csvCell($transfer->recipient_country),
                        $transfer->send_amount,
                        $transfer->fee_amount,
                        $transfer->total_paid,
                        $transfer->source_currency,
                        $transfer->receive_amount,
                        $transfer->destination_currency,
                        $transfer->payout_method->label(),
                        $this->csvCell($transfer->payout_gateway),
                        $this->csvCell($transfer->gateway_reference),
                        $transfer->status->label(),
                        $this->csvCell($transfer->failure_reason),
                    ], escape: '');
                }
            });

            fclose($stream);
        }, $filename, [
            'Content-Type'           => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store, private',
            'Pragma'                 => 'no-cache',
        ]);
    }

    public function approve(RemittanceTransferActionRequest $request, RemittanceTransfer $transfer): RedirectResponse
    {
        try {
            $this->remittance->completeManually($transfer, $this->adminActor($request));

            notifyEvs('success', __('Transfer marked as completed.'));
        } catch (Throwable $e) {
            report($e);
            notifyEvs('error', $e->getMessage());
        }

        return back();
    }

    public function fail(RemittanceTransferActionRequest $request, RemittanceTransfer $transfer): RedirectResponse
    {
        try {
            $reason = trim((string) $request->validated('reason')) ?: __('Marked failed by admin.');
            $this->remittance->failManually($transfer, $reason, $this->adminActor($request));

            notifyEvs('success', __('Transfer marked as failed and refunded.'));
        } catch (Throwable $e) {
            report($e);
            notifyEvs('error', $e->getMessage());
        }

        return back();
    }

    public function refund(RemittanceTransferActionRequest $request, RemittanceTransfer $transfer): RedirectResponse
    {
        try {
            $this->remittance->refund($transfer, $request->validated('reason'));

            notifyEvs('success', __('Transfer refunded to sender wallet.'));
        } catch (Throwable $e) {
            report($e);
            notifyEvs('error', $e->getMessage());
        }

        return back();
    }

    /**
     * @return array<string, int|string>
     */
    protected function adminActor(RemittanceTransferActionRequest $request): array
    {
        return [
            'actor_type' => 'admin',
            'actor_id'   => (int) $request->user('admin')->id,
        ];
    }

    protected function csvCell(mixed $value): string
    {
        $value = str_replace("\0", '', trim((string) $value));

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
