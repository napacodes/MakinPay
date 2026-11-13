<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Enums\MassPayment\MassPaymentKind;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\MassPaymentChangedException;
use App\Exceptions\MassPaymentNotConfirmableException;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MassPayment\ConfirmMassPaymentRequest;
use App\Http\Requests\MassPayment\StoreMassPaymentRequest;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentRow;
use App\Models\Wallet;
use App\Services\FeatureManager;
use App\Services\MassPayment\MassPaymentActionResult;
use App\Services\MassPayment\MassPaymentActor;
use App\Services\MassPayment\MassPaymentCsvParser;
use App\Services\MassPayment\MassPaymentReport;
use App\Services\MassPayment\MassPaymentService;
use App\Services\Media\MediaSettings;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use App\Support\HomeCountry;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The customer's side of mass payments: the history, building a draft from a
 * CSV or the manual grid, the preview, confirming it, following the payout,
 * stopping it, and the report.
 *
 * Every route sits behind `feature.enabled:mass_payment` (the module and the
 * customer's panel; merchants only by default) and both per-user switches,
 * `feature:send_money` and `feature:mass_payment`.
 *
 * Every batch is loaded through the signed-in customer's own relation, so
 * another customer's batch is a 404 on every route. Confirm is the one
 * exception in form only: it hands the uuid to MassPaymentService::confirm(),
 * which checks the PIN first and then looks the batch up in the same scope.
 * That scope is wallet batches only, so a payout batch, even the customer's
 * own, is a 404 here as well.
 *
 * No money is computed here. The service prices, reserves and pays; this
 * controller only shows the strings it stored.
 */
class MassPaymentController extends Controller
{
    private const HISTORY_PER_PAGE = 15;

    /**
     * A queued or processing batch that has made no progress for this long
     * shows the "still queued" banner. Payments carry on by themselves: the
     * resume command picks a stalled batch up after two minutes.
     */
    public const STALE_PROGRESS_MINUTES = 5;

    /** How often the progress page asks for the status, in milliseconds. */
    public const POLL_INTERVAL_MS = 5000;

    public function __construct(private readonly MassPaymentService $service) {}

    public function index(Request $request): View
    {
        $batches = $request->user()->massPaymentBatches()
            ->ofKind(MassPaymentKind::Wallet)
            ->latest('id')
            ->paginate(self::HISTORY_PER_PAGE);

        return view('frontend.user.mass_payment.index', [
            'batches' => $batches,
        ]);
    }

    /**
     * The new-batch form. `?from=` reopens one of the owner's own wallet
     * drafts in the grid, filled in, so a line can be fixed and the batch
     * built again; the old draft is discarded once the new one exists.
     */
    public function create(Request $request): View
    {
        $wallets = $request->user()->activeWallets()
            ->filter(fn (Wallet $wallet): bool => $wallet->currency !== null)
            ->values();

        $from = $this->rebuildFrom($request);

        return view('frontend.user.mass_payment.create', [
            'wallets'     => $wallets,
            'from'        => $from,
            'gridRows'    => $this->gridRows($from),
            'home'        => HomeCountry::country(),
            'payoutsOpen' => $this->payoutsOpen($request),
            'maxRows'     => $this->service->maxRows(),
            'maxUploadKb' => min(MediaSettings::effectiveMaxUploadSizeKb(), MassPaymentCsvParser::MAX_UPLOAD_KB),
            'source'      => $from !== null || old('source', MassPaymentService::SOURCE_CSV) === MassPaymentService::SOURCE_MANUAL
                ? MassPaymentService::SOURCE_MANUAL
                : MassPaymentService::SOURCE_CSV,
        ]);
    }

    public function template(MassPaymentReport $report): StreamedResponse
    {
        return $report->template();
    }

    /**
     * Build the draft and show its preview. No money moves and no PIN is
     * asked; a file or grid that cannot be read goes back as a field error.
     */
    public function store(StoreMassPaymentRequest $request): RedirectResponse
    {
        try {
            $batch = $this->service->createDraft(
                $request->user(),
                (string) $request->validated('wallet_id'),
                $request->draftInput(),
                $request->source(),
                MassPaymentService::CHANNEL_WEB,
                $request->batchName(),
            );
        } catch (ValidationException $exception) {
            return back()->withInput($request->except('file'))->withErrors($exception->errors());
        } catch (NotifyErrorException $exception) {
            notifyEvs('error', $exception->getMessage());

            return back()->withInput($request->except('file'));
        }

        $this->discardReplaced($request, $batch);

        notifyEvs('success', __('Batch ready. Review it before you confirm.'));

        return redirect()->route('user.mass-payment.show', $batch);
    }

    public function show(Request $request, string $batch): View
    {
        $batch = $this->ownedBatch($request, $batch, ['wallet.currency']);
        $rows  = $batch->rows()->get();

        $currency = (string) $batch->currency_code;
        $isDraft  = $batch->status() === MassPaymentBatchStatus::Draft;

        return view('frontend.user.mass_payment.show', [
            'batch'        => $batch,
            'rows'         => $rows,
            'currency'     => $currency,
            'isDraft'      => $isDraft,
            'counts'       => $this->rowCounts($batch),
            'funding'      => $isDraft ? $this->funding($batch) : null,
            'isStale'      => $this->isStale($batch),
            'hasWalletPin' => $request->user()->hasWalletPin(),
            'pollInterval' => self::POLL_INTERVAL_MS,
        ]);
    }

    /**
     * The batch's progress, for the page to poll while it pays out.
     */
    public function status(Request $request, string $batch): JsonResponse
    {
        $batch    = $this->ownedBatch($request, $batch);
        $status   = $batch->status();
        $counts   = $this->rowCounts($batch);
        $currency = (string) $batch->currency_code;

        $payable  = $counts['pending'] + $counts['paid'] + $counts['failed'] + $counts['reviewed'];
        $resolved = $counts['paid']    + $counts['failed'] + $counts['reviewed'];

        return response()->json([
            'status'           => $status->value,
            'status_label'     => $status->customerLabel(),
            'color'            => $status->color(),
            'terminal'         => $status->isTerminal(),
            'stop_requested'   => $batch->stop_requested_at !== null,
            'stale'            => $this->isStale($batch),
            'counts'           => $counts,
            'progress'         => $payable === 0 ? 0 : (int) floor($resolved * 100 / $payable),
            'released'         => ApiMoney::amount($batch->released_amount, $currency),
            'currency'         => $currency,
            'last_progress_at' => ($batch->last_progress_at ?? $batch->confirmed_at)?->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Reserve the total and queue the payout, exactly once.
     */
    public function confirm(ConfirmMassPaymentRequest $request, string $batch): RedirectResponse
    {
        $show = route('user.mass-payment.show', $batch);

        try {
            $confirmed = $this->service->confirm($request->user(), $batch, $request->confirmation());
        } catch (MassPaymentChangedException $exception) {
            notifyEvs('warning', __('The batch changed since you reviewed it. Review it again.'));

            return redirect()->route('user.mass-payment.show', $exception->batch);
        } catch (MassPaymentNotConfirmableException|InsufficientBalanceException|NotifyErrorException $exception) {
            notifyEvs('error', $exception->getMessage());

            return redirect()->to($show)->withInput($request->except(ConfirmMassPaymentRequest::NEVER_FLASHED));
        }

        notifyEvs('success', $confirmed->replayed
            ? __('This batch was already confirmed.')
            : __('Batch confirmed. The payments are on their way.'));

        return redirect()->route('user.mass-payment.show', $confirmed->batch);
    }

    public function discard(Request $request, string $batch): RedirectResponse
    {
        try {
            $this->service->discard($request->user(), $batch);
        } catch (NotifyErrorException $exception) {
            notifyEvs('error', $exception->getMessage());

            return redirect()->route('user.mass-payment.show', $batch);
        }

        notifyEvs('success', __('Batch discarded.'));

        return redirect()->route('user.mass-payment.index');
    }

    /**
     * Stop a queued or paying batch: every line not yet paid is refunded.
     */
    public function stop(Request $request, string $batch): RedirectResponse
    {
        $owned = $this->ownedBatch($request, $batch);

        try {
            $result = $this->service->requestStop($owned, MassPaymentActor::user($request->user()));
        } catch (NotifyErrorException $exception) {
            notifyEvs('error', $exception->getMessage());

            return redirect()->route('user.mass-payment.show', $owned);
        }

        if ($result->is(MassPaymentActionResult::ALREADY_RESOLVED)) {
            notifyEvs('info', __('This batch is already finished or being stopped.'));
        } else {
            notifyEvs('success', __('Batch stopped. Every payment not yet made is being returned to your wallet.'));
        }

        return redirect()->route('user.mass-payment.show', $owned);
    }

    public function report(Request $request, string $batch, MassPaymentReport $report): StreamedResponse
    {
        return $report->download($this->ownedBatch($request, $batch));
    }

    /**
     * One of the signed-in customer's batches, or a 404.
     *
     * @param list<string> $with
     */
    private function ownedBatch(Request $request, string $uuid, array $with = []): MassPaymentBatch
    {
        return $request->user()->massPaymentBatches()
            ->ofKind(MassPaymentKind::Wallet)
            ->where('uuid', $uuid)
            ->with($with)
            ->firstOrFail();
    }

    /** The owner's own wallet draft named by `?from=` (or the posted `replaces`), or null. */
    private function rebuildFrom(Request $request): ?MassPaymentBatch
    {
        $uuid = (string) ($request->query('from') ?? old('replaces', ''));

        if ($uuid === '') {
            return null;
        }

        return $request->user()->massPaymentBatches()
            ->ofKind(MassPaymentKind::Wallet)
            ->where('uuid', $uuid)
            ->where('status', MassPaymentBatchStatus::Draft->value)
            ->with('wallet:id,uuid')
            ->first();
    }

    /**
     * The grid's rows: what was posted last, else the draft being fixed, else
     * three blank lines.
     *
     * @return list<array{recipient: string, amount: string, note: string, reference: string}>
     */
    private function gridRows(?MassPaymentBatch $from): array
    {
        $old = old('rows');

        if (is_array($old) && $old !== []) {
            return array_values(array_map(fn (mixed $row): array => $this->gridRow(is_array($row) ? $row : []), $old));
        }

        if ($from !== null) {
            return $from->rows()->get()->map(fn (MassPaymentRow $row): array => $this->gridRow([
                'recipient' => (string) $row->recipient_input,
                'amount'    => (string) $row->amount_input,
                'note'      => (string) $row->note,
                'reference' => (string) $row->client_reference,
            ]))->values()->all();
        }

        return array_fill(0, 3, $this->gridRow([]));
    }

    /**
     * @param  array<string, mixed>                                                      $row
     * @return array{recipient: string, amount: string, note: string, reference: string}
     */
    private function gridRow(array $row): array
    {
        $text = fn (string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';

        return [
            'recipient' => $text('recipient'),
            'amount'    => $text('amount'),
            'note'      => $text('note'),
            'reference' => $text('reference'),
        ];
    }

    /**
     * The draft a rebuilt one replaces, discarded once the new one exists. A
     * draft already confirmed, already discarded or not the customer's is
     * left exactly as it is.
     */
    private function discardReplaced(StoreMassPaymentRequest $request, MassPaymentBatch $replacement): void
    {
        $uuid = (string) $request->validated('replaces', '');

        if ($uuid === '' || $uuid === (string) $replacement->uuid) {
            return;
        }

        try {
            $this->service->discard($request->user(), $uuid);
        } catch (NotifyErrorException|ModelNotFoundException) {
            return;
        }
    }

    /**
     * Whether the "Bank & mobile money" choice can lead anywhere for this
     * customer: the sidebar's own rule for showing Mass Payouts, plus the two
     * per-user switches the payout routes check. The routes still carry
     * every gate; this only decides whether the card is a link or left out.
     */
    private function payoutsOpen(Request $request): bool
    {
        $features = app(FeatureManager::class);
        $user     = $request->user();

        return $features->isVisible('mass_payout')
            && $features->isVisible('withdraw_money')
            && $user->hasFeature('withdraw')
            && $user->hasFeature('mass_payout');
    }

    /**
     * How many lines are in each status, every status present.
     *
     * @return array{rows: int, invalid: int, valid: int, skipped: int, pending: int, paid: int, failed: int, reviewed: int}
     */
    private function rowCounts(MassPaymentBatch $batch): array
    {
        $byStatus = MassPaymentRow::query()
            ->where('mass_payment_batch_id', $batch->id)
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = ['rows' => (int) $byStatus->sum()];

        foreach (MassPaymentRowStatus::cases() as $status) {
            $counts[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        return $counts;
    }

    /**
     * The source wallet's balance against the draft's total, as advice only:
     * confirm's guarded debit is what actually refuses a short balance.
     *
     * @return array{balance: string, after: string, short_by: string|null}
     */
    private function funding(MassPaymentBatch $batch): array
    {
        $currency = (string) $batch->currency_code;
        $decimals = Money::decimals($currency);
        $balance  = DecimalMoney::normalize((string) ($batch->wallet?->balance ?? '0'), $decimals);
        $total    = DecimalMoney::normalize((string) $batch->total_payable, $decimals);

        $short = DecimalMoney::compare($balance, $total, $decimals) < 0
            ? DecimalMoney::subtract($total, $balance, $decimals)
            : null;

        return [
            'balance'  => $balance,
            'after'    => $short === null ? DecimalMoney::subtract($balance, $total, $decimals) : '0',
            'short_by' => $short,
        ];
    }

    /**
     * Whether a confirmed batch has gone quiet for longer than
     * {@see self::STALE_PROGRESS_MINUTES}.
     */
    private function isStale(MassPaymentBatch $batch): bool
    {
        if (! in_array($batch->status(), [MassPaymentBatchStatus::Queued, MassPaymentBatchStatus::Processing], true)) {
            return false;
        }

        $since = $batch->last_progress_at ?? $batch->confirmed_at;

        return $since !== null && $since->lt(now()->subMinutes(self::STALE_PROGRESS_MINUTES));
    }
}
