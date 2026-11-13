<?php

namespace App\Http\Controllers\Backend;

use App\Enums\EnvironmentMode;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Http\Requests\Backend\TransactionActionRequest;
use App\Http\Requests\Backend\TransactionExportRequest;
use App\Http\Requests\Backend\TransactionRefundRequest;
use App\Http\Resources\Backend\TransactionDetailResource;
use App\Models\Merchant;
use App\Models\Transaction as TransactionModel;
use App\Services\MerchantRefundService;
use App\Support\Money;
use App\Support\PdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Transaction;

class TransactionController extends BaseController
{
    public static function permissions(): array
    {
        return [
            /*
             * The export carries no column the list does not already show, so
             * it rides on the same permission. A separate `transaction-export`
             * would read stricter but would 403 for every existing role until
             * the permission seeder had been re-run on each installation.
             */
            'index|export' => 'transaction-list',

            /*
             * The detail view and the receipt show the same columns the list
             * already shows, one row at a time, so they ride on the same
             * permission for the same reason the export does.
             */
            'show|receipt' => 'transaction-list',

            'refund' => 'transaction-refund',

            /*
             * `action` is missing on purpose. Approving a manual deposit needs
             * `deposit-action` and approving a manual withdrawal needs
             * `withdraw-action`, and which one applies is not known until the
             * row has been loaded — so it is authorized inside the method
             * rather than by a middleware that would have to pick one.
             */
        ];
    }

    public function index(): View
    {
        $filters = $this->filters();

        $transactions = Transaction::getTransactions(
            trx_type: request('type'),
            status: request('status'),
            search: request('search'),
            dateRange: request('daterange')
        );

        return view('backend.transaction.index', [
            'transactions' => $transactions,
            'headerStats'  => $this->headerStats($filters),
        ]);
    }

    /**
     * Stream the filtered ledger as CSV.
     *
     * Built from the same `$filters` array the list uses, so the file always
     * describes exactly the rows the operator was looking at. Streamed and
     * chunked rather than collected: this table runs to hundreds of thousands
     * of rows on a live install and must never be held in memory at once.
     */
    public function export(TransactionExportRequest $request): StreamedResponse
    {
        $query = TransactionModel::query()
            ->with('user:id,first_name,last_name,username')
            ->applyFilters($this->filters());

        $filename = 'transactions-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                throw new RuntimeException('Unable to open the CSV output stream.');
            }

            // Excel reads a BOM-less UTF-8 file as the local codepage, which
            // turns every non-Latin customer name into mojibake.
            fwrite($stream, "\xEF\xBB\xBF");

            fputcsv($stream, [
                'Transaction ID',
                'Created At',
                'Type',
                'Status',
                'Customer',
                'Username',
                'Amount',
                'Fee',
                'Currency',
                'Direction',
                'Provider',
                'Processing Type',
                'Description',
            ], escape: '');

            $query->chunkById(500, function ($transactions) use ($stream): void {
                foreach ($transactions as $transaction) {
                    fputcsv($stream, [
                        $this->csvCell(strtoupper((string) $transaction->trx_id)),
                        $transaction->created_at?->toIso8601String(),
                        $transaction->trx_type->label(),
                        $transaction->status->label(),
                        $this->csvCell($transaction->user?->name),
                        $this->csvCell($transaction->user?->username),
                        // Quantised, unformatted: the currency's own precision
                        // with no grouping separator, so a spreadsheet reads it
                        // as a number rather than as text.
                        Money::quantize($transaction->amount, $transaction->currency),
                        Money::quantize($transaction->fee, $transaction->currency),
                        $this->csvCell($transaction->currency),
                        $transaction->amount_flow->value,
                        $this->csvCell($transaction->provider),
                        $transaction->processing_type->label(),
                        $this->csvCell($transaction->description),
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

    /**
     * One transaction, as JSON for the detail modal and as a page for a deep
     * link or a browser with no JavaScript. Both render the same array, so the
     * two can never drift apart.
     */
    public function show(Request $request, string $trxId): JsonResponse|View
    {
        $transaction = $this->findTransaction($trxId);

        $detail = (new TransactionDetailResource($transaction))->toArray($request);

        if ($request->expectsJson()) {
            return response()->json(['data' => $detail]);
        }

        return view('backend.transaction.show', [
            'transaction' => $transaction,
            'detail'      => $detail,
        ]);
    }

    /**
     * The branded receipt, rendered from the same template the customer's own
     * download uses so the two sides of a dispute are looking at one document.
     */
    public function receipt(Request $request, string $trxId): Response|View
    {
        $transaction = $this->findTransaction($trxId);

        PdfBranding::configureRenderer();

        $payload = [
            'transaction' => $transaction,
            'siteLogo'    => PdfBranding::siteLogo(),
        ];

        /*
         * Printing and downloading are the same document, not two documents.
         * The print path renders the template as a page that raises the print
         * dialog itself: an operator who wants paper should not have to
         * download a file, find it and open it before they can press print.
         */
        if ($request->boolean('print')) {
            return view('general.pdf.transaction_receipt', $payload + ['autoPrint' => true]);
        }

        return Pdf::loadView('general.pdf.transaction_receipt', $payload)
            ->download('receipt-'.$transaction->trx_id.'.pdf');
    }

    /**
     * Approve or reject a manual deposit or withdrawal from the detail modal.
     *
     * Every rule the modal used to decide whether to draw these buttons is
     * asked again here, because frontend visibility is not authorization and
     * this endpoint is reachable without the modal. The money itself moves
     * through the same `completeTransaction`/`cancelTransaction` calls the
     * deposit and withdraw screens make — including the `refund: true` on a
     * rejected withdrawal, which is what returns the held funds to the wallet.
     */
    public function action(TransactionActionRequest $request, string $trxId): JsonResponse|RedirectResponse
    {
        $transaction = $this->findTransaction($trxId);

        $permission = TransactionDetailResource::actionPermission($transaction->trx_type);

        abort_if($permission === null || $request->user('admin')?->can($permission) !== true, 403);

        if ($transaction->status !== TrxStatus::PENDING || $transaction->processing_type !== MethodType::MANUAL) {
            return $this->rejectAction($request, __('Only a pending manual transaction can be approved or rejected.'));
        }

        $remarks = $request->input('remarks');

        if ($request->input('action') === 'approve') {
            Transaction::completeTransaction($transaction->trx_id, $remarks);
            $message = __('Transaction approved successfully.');
        } else {
            Transaction::cancelTransaction(
                $transaction->trx_id,
                $remarks,
                $transaction->trx_type === TrxType::WITHDRAW,
            );
            $message = __('Transaction rejected successfully.');
        }

        return $this->respondWithFreshDetail($request, $message);
    }

    /**
     * Refund all or part of a received merchant payment.
     *
     * The service owns every rule that matters — the merchant binding, the
     * completed-status check, the refundable ceiling and the wallet debit, all
     * under a row lock. This method's whole job is to find the merchant the
     * payment was made to and hand the request over.
     */
    public function refund(TransactionRefundRequest $request, string $trxId): JsonResponse|RedirectResponse
    {
        $transaction = $this->findTransaction($trxId);

        if ($transaction->trx_type !== TrxType::RECEIVE_PAYMENT || $transaction->status !== TrxStatus::COMPLETED) {
            return $this->rejectAction($request, __('Only a completed received payment can be refunded.'));
        }

        $merchant = Merchant::query()->find((int) ($transaction->trx_data['merchant_id'] ?? 0));

        if (! $merchant) {
            return $this->rejectAction($request, __('The merchant this payment belongs to could not be found.'));
        }

        try {
            app(MerchantRefundService::class)->refund(
                merchant: $merchant,
                trxId: $transaction->trx_id,
                amount: (float) $request->input('amount'),
                reason: $request->input('reason'),
                environment: (string) ($transaction->trx_data['environment'] ?? EnvironmentMode::PRODUCTION->value),
            );
        } catch (NotifyErrorException $e) {
            return $this->rejectAction($request, $e->getMessage());
        }

        return $this->respondWithFreshDetail($request, __('Refund issued successfully.'));
    }

    /**
     * The row, or a 404 — never a silent null that the caller then renders as
     * an empty receipt. Eager-loads exactly what the detail payload reads.
     */
    private function findTransaction(string $trxId): TransactionModel
    {
        return TransactionModel::query()
            ->with(['user.kycSubmission', 'refunds'])
            ->where('trx_id', $trxId)
            ->firstOrFail();
    }

    /**
     * A state the operator is not allowed to move the transaction into. 422
     * rather than 403: they have the permission, the row simply is not in a
     * state where the action means anything.
     */
    private function rejectAction(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        notifyEvs('error', $message);

        return redirect()->back();
    }

    /**
     * Hand the caller the transaction as it now stands rather than a bare
     * "ok", so the modal repaints from the authoritative row instead of
     * guessing what the action did to it.
     */
    private function respondWithFreshDetail(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if (! $request->expectsJson()) {
            notifyEvs('success', $message);

            return redirect()->back();
        }

        $transaction = $this->findTransaction((string) $request->route('trx_id'));

        return response()->json([
            'message' => $message,
            'data'    => (new TransactionDetailResource($transaction))->toArray($request),
        ]);
    }

    /**
     * The one filter set both the list and the export read.
     *
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return [
            'user_id'   => request('user_id'),
            'trx_type'  => request('type'),
            'status'    => request('status'),
            'search'    => request('search'),
            'dateRange' => request('daterange'),
        ];
    }

    /**
     * A spreadsheet treats a cell opening with `=`, `+`, `-` or `@` as a
     * formula, so anything an end user typed is neutralised before it reaches
     * the file. Customer names, providers and descriptions all qualify.
     */
    private function csvCell(mixed $value): string
    {
        $value = str_replace("\0", '', trim((string) $value));

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * Counts for the summary cards.
     *
     * The cards are facets: each one counts as though only its own status were
     * applied, so all four stay readable while the list is drilled into one of
     * them — a card reading 0 that you can click to get rows is a lie. Only
     * the status is dropped; type, search and date range still narrow every
     * card, because that is the set the operator actually chose.
     *
     * One grouped query rather than one per status.
     *
     * @param  array<string, mixed>                                                                                        $filters
     * @return list<array{label: string, value: string, count: int, share: float, key: string, tone: string, sub: string}>
     */
    private function headerStats(array $filters): array
    {
        $counts = TransactionModel::query()
            ->applyFilters(array_merge($filters, ['status' => null]))
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $countFor = static fn (TrxStatus ...$statuses): int => array_sum(
            array_map(static fn (TrxStatus $status): int => (int) $counts->get($status->value, 0), $statuses)
        );

        $completed = $countFor(TrxStatus::COMPLETED);
        $pending   = $countFor(TrxStatus::PENDING);
        $canceled  = $countFor(TrxStatus::CANCELED);
        $failed    = $countFor(TrxStatus::FAILED, TrxStatus::CANCELED);

        // Summed from the same grouped rows, so Total can never disagree with
        // the three facets printed beside it.
        $total = (int) $counts->sum();

        // Each card's share of the total, so the row shows the mix as well as
        // the counts. Total anchors the scale at full width.
        $share = static fn (int $count): float => $total > 0 ? round($count / $total * 100, 1) : 0.0;

        return [
            [
                'label' => __('Total'),
                'value' => number_format($total),
                'count' => $total,
                'share' => $total > 0 ? 100.0 : 0.0,
                'key'   => '',
                'tone'  => 'primary',
                'sub'   => filled(request('daterange'))
                    ? str_replace(',', ' – ', (string) request('daterange'))
                    : __('All time'),
            ],
            [
                'label' => __('Completed'),
                'value' => number_format($completed),
                'count' => $completed,
                'share' => $share($completed),
                'key'   => TrxStatus::COMPLETED->value,
                'tone'  => 'success',
                // An empty ledger has no success rate; "0% success rate" reads
                // like a failure rather than like nothing having happened.
                'sub' => $total > 0
                    ? __(':rate% success rate', ['rate' => $share($completed)])
                    : __('No transactions yet'),
            ],
            [
                'label' => __('Pending'),
                'value' => number_format($pending),
                'count' => $pending,
                'share' => $share($pending),
                'key'   => TrxStatus::PENDING->value,
                'tone'  => 'warning',
                'sub'   => $pending > 0 ? __('awaiting review') : __('Nothing waiting'),
            ],
            [
                'label' => __('Failed'),
                'value' => number_format($failed),
                'count' => $failed,
                'share' => $share($failed),
                'key'   => TrxStatus::FAILED->value,
                'tone'  => 'danger',
                // "incl. 0 canceled" beside a zero was two dead numbers in one
                // card; when there is nothing to break down, say so instead.
                'sub' => $failed > 0
                    ? __('incl. :count canceled', ['count' => number_format($canceled)])
                    : __('All clear'),
            ],
        ];
    }
}
