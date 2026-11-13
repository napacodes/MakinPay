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
use App\Http\Requests\MassPayment\StoreMassPayoutRequest;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentRow;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawMethod;
use App\Services\FeatureManager;
use App\Services\MassPayment\MassPaymentActionResult;
use App\Services\MassPayment\MassPaymentCsvParser;
use App\Services\MassPayment\MassPaymentReport;
use App\Services\MassPayment\MassPaymentService;
use App\Services\MassPayment\MassPayoutAccount;
use App\Services\MassPayment\MassPayoutMethods;
use App\Services\MassPayment\MassPayoutService;
use App\Services\Media\MediaSettings;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The customer's side of mass payouts: the history, choosing the wallet and
 * the withdraw method, building a draft from a CSV or the manual grid laid
 * out by that method's account fields, the preview (with a way back into the
 * grid to fix it), confirming it, following the payouts, stopping them, and
 * the report.
 *
 * Every route sits behind `feature.enabled:mass_payout` and
 * `feature.enabled:withdraw_money` (the modules and the customer's panel;
 * merchants only by default) and the per-user `feature:withdraw` and
 * `feature:mass_payout`.
 *
 * Every batch is loaded through the signed-in customer's own relation and
 * only as a payout batch, so another customer's batch, or a wallet batch, is
 * a 404 on every route. Account details reach a page only when the owner who
 * typed them rebuilds their own draft; the preview, the history and the
 * report show the masked label.
 *
 * No money is computed here. The service prices, reserves and sends; this
 * controller only shows the strings it stored.
 */
class MassPayoutController extends Controller
{
    private const HISTORY_PER_PAGE = 15;

    /** How long a confirmed batch may show no progress before the "still going" note. */
    public const STALE_PROGRESS_MINUTES = 5;

    /** How often the progress page asks for the status, in milliseconds. */
    public const POLL_INTERVAL_MS = 5000;

    /** Grid rows shown on a fresh form. */
    private const BLANK_ROWS = 3;

    public function __construct(
        private readonly MassPayoutService $service,
        private readonly MassPaymentService $walletBatches,
        private readonly MassPayoutMethods $methods,
        private readonly MassPayoutAccount $accounts,
    ) {}

    public function index(Request $request): View
    {
        $batches = $request->user()->massPaymentBatches()
            ->ofKind(MassPaymentKind::Payout)
            ->latest('id')
            ->paginate(self::HISTORY_PER_PAGE);

        return view('frontend.user.mass_payout.index', [
            'batches' => $batches,
        ]);
    }

    /**
     * Step one picks the wallet and the method; step two, once both are
     * chosen and the method is offered for the wallet, lays the form out by
     * the method's account fields. `?from=` reopens one of the owner's own
     * drafts in the grid, filled in, so a line can be fixed and the batch
     * built again.
     */
    public function create(Request $request): View
    {
        $user    = $request->user();
        $wallets = $user->activeWallets()
            ->filter(fn (Wallet $wallet): bool => $wallet->currency !== null)
            ->values();

        $from   = $this->rebuildFrom($request, $user);
        $wallet = $this->chosenWallet($request, $wallets, $from);

        $methods = $wallet === null ? collect() : $this->methods->forWallet($user, $wallet);
        $method  = $this->chosenMethod($request, $methods, $from);

        $rows = $this->gridRows($from, $method);

        return view('frontend.user.mass_payout.create', [
            'wallets'     => $wallets,
            'wallet'      => $wallet,
            'methods'     => $methods,
            'method'      => $method,
            'fields'      => $method === null ? [] : $this->accounts->fields($method),
            'rows'        => $rows,
            'from'        => $from,
            'walletsOpen' => $this->walletsOpen($request),
            'maxRows'     => $this->walletBatches->maxRows(),
            'maxUploadKb' => min(MediaSettings::effectiveMaxUploadSizeKb(), MassPaymentCsvParser::MAX_UPLOAD_KB),
            'source'      => $from !== null || old('source') === MassPaymentService::SOURCE_MANUAL
                ? MassPaymentService::SOURCE_MANUAL
                : MassPaymentService::SOURCE_CSV,
        ]);
    }

    /**
     * The blank file for one method this customer may use from one of their
     * wallets; any other method is a 404, never a file naming its fields.
     */
    public function template(Request $request, MassPaymentReport $report): StreamedResponse
    {
        $user   = $request->user();
        $wallet = $user->wallets()->where('uuid', (string) $request->query('wallet'))->with('currency')->first();
        $method = $wallet === null ? null : $this->methods->findForWallet($user, $wallet, (int) $request->query('method'));

        abort_if($method === null, 404);

        return $report->payoutTemplate($this->accounts->fieldNames($method), (string) $method->name);
    }

    /**
     * Build the draft and show its preview. No money moves and no PIN is
     * asked. A draft reopened to be fixed is discarded once its replacement
     * exists.
     */
    public function store(StoreMassPayoutRequest $request): RedirectResponse
    {
        try {
            $batch = $this->service->createDraft(
                $request->user(),
                (string) $request->validated('wallet_id'),
                $request->methodId(),
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

        return redirect()->route('user.mass-payout.show', $batch);
    }

    public function show(Request $request, string $batch): View
    {
        $batch = $this->ownedBatch($request, $batch, ['wallet.currency']);
        $rows  = $batch->rows()->get();

        $currency = (string) $batch->currency_code;
        $isDraft  = $batch->status() === MassPaymentBatchStatus::Draft;

        return view('frontend.user.mass_payout.show', [
            'batch'        => $batch,
            'rows'         => $rows,
            'currency'     => $currency,
            'isDraft'      => $isDraft,
            'counts'       => $this->rowCounts($batch),
            'funding'      => $isDraft ? $this->funding($batch) : null,
            'isStale'      => $this->isStale($batch),
            'hasWalletPin' => $request->user()->hasWalletPin(),
            'pollInterval' => self::POLL_INTERVAL_MS,
            'methodName'   => (string) ($batch->metadata['payout']['method_name'] ?? __('Payout method')),
        ]);
    }

    /** The batch's progress, for the page to poll while it pays out. */
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

    /** Reserve the total and send the batch on, or hold it for approval, exactly once. */
    public function confirm(ConfirmMassPaymentRequest $request, string $batch): RedirectResponse
    {
        $show = route('user.mass-payout.show', $batch);

        try {
            $confirmed = $this->service->confirm($request->user(), $batch, $request->confirmation());
        } catch (MassPaymentChangedException $exception) {
            notifyEvs('warning', __('The batch changed since you reviewed it. Review it again.'));

            return redirect()->route('user.mass-payout.show', $exception->batch);
        } catch (MassPaymentNotConfirmableException|InsufficientBalanceException|NotifyErrorException $exception) {
            notifyEvs('error', $exception->getMessage());

            return redirect()->to($show)->withInput($request->except(ConfirmMassPaymentRequest::NEVER_FLASHED));
        }

        notifyEvs('success', match (true) {
            $confirmed->replayed                                                     => __('This batch was already confirmed.'),
            $confirmed->batch->status() === MassPaymentBatchStatus::AwaitingApproval => __('Batch confirmed. The total is reserved and the payouts are sent once they are approved.'),
            default                                                                  => __('Batch confirmed. The payouts are on their way.'),
        });

        return redirect()->route('user.mass-payout.show', $confirmed->batch);
    }

    public function discard(Request $request, string $batch): RedirectResponse
    {
        try {
            $this->service->discard($request->user(), $batch);
        } catch (NotifyErrorException $exception) {
            notifyEvs('error', $exception->getMessage());

            return redirect()->route('user.mass-payout.show', $batch);
        }

        notifyEvs('success', __('Batch discarded.'));

        return redirect()->route('user.mass-payout.index');
    }

    /** Stop the batch: payouts not yet sent come back to the wallet. */
    public function stop(Request $request, string $batch): RedirectResponse
    {
        try {
            $result = $this->service->stop($request->user(), $batch);
        } catch (NotifyErrorException $exception) {
            notifyEvs('error', $exception->getMessage());

            return redirect()->route('user.mass-payout.show', $batch);
        }

        notifyEvs($result->is(MassPaymentActionResult::ALREADY_RESOLVED) ? 'info' : 'success', $result->is(MassPaymentActionResult::ALREADY_RESOLVED)
            ? __('This batch is already finished or being stopped.')
            : __('Batch stopped. Every payout not yet sent is being returned to your wallet. Payouts already sent finish with the provider.'));

        return redirect()->route('user.mass-payout.show', $batch);
    }

    public function report(Request $request, string $batch, MassPaymentReport $report): StreamedResponse
    {
        return $report->download($this->ownedBatch($request, $batch));
    }

    /**
     * One of the signed-in customer's payout batches, or a 404.
     *
     * @param list<string> $with
     */
    private function ownedBatch(Request $request, string $uuid, array $with = []): MassPaymentBatch
    {
        return $request->user()->massPaymentBatches()
            ->ofKind(MassPaymentKind::Payout)
            ->where('uuid', $uuid)
            ->with($with)
            ->firstOrFail();
    }

    /**
     * Whether the "wallets" choice can lead anywhere for this customer: the
     * sidebar's rule for showing Mass Payments plus the two per-user switches
     * its routes check. Only decides whether the card is a link.
     */
    private function walletsOpen(Request $request): bool
    {
        $user = $request->user();

        return app(FeatureManager::class)->isVisible('mass_payment')
            && $user->hasFeature('send_money')
            && $user->hasFeature('mass_payment');
    }

    /** The owner's own payout draft named by `?from=` (or the posted `replaces`), or null. */
    private function rebuildFrom(Request $request, User $user): ?MassPaymentBatch
    {
        $uuid = (string) ($request->query('from') ?? old('replaces', ''));

        if ($uuid === '') {
            return null;
        }

        return $user->massPaymentBatches()
            ->ofKind(MassPaymentKind::Payout)
            ->where('uuid', $uuid)
            ->where('status', MassPaymentBatchStatus::Draft->value)
            ->first();
    }

    /** @param Collection<int, Wallet> $wallets */
    private function chosenWallet(Request $request, Collection $wallets, ?MassPaymentBatch $from): ?Wallet
    {
        $uuid = (string) ($from?->wallet?->uuid ?? $request->query('wallet') ?? old('wallet_id', ''));

        if ($uuid === '' && $wallets->count() === 1) {
            return $wallets->first();
        }

        return $wallets->first(fn (Wallet $wallet): bool => (string) $wallet->uuid === $uuid);
    }

    /** @param Collection<int, WithdrawMethod> $methods */
    private function chosenMethod(Request $request, Collection $methods, ?MassPaymentBatch $from): ?WithdrawMethod
    {
        $id = (int) ($from?->withdraw_method_id ?? $request->query('method') ?? old('method_id', 0));

        if ($id === 0 && $methods->count() === 1) {
            return $methods->first();
        }

        return $methods->first(fn (WithdrawMethod $method): bool => (int) $method->id === $id);
    }

    /**
     * The grid's rows: what was posted back after a refusal, the lines of a
     * draft being fixed (its owner's own account details, decrypted), or
     * blank rows.
     *
     * @return list<array{amount: string, note: string, reference: string, account: array<string, string>}>
     */
    private function gridRows(?MassPaymentBatch $from, ?WithdrawMethod $method): array
    {
        $old = old('rows');

        if (is_array($old) && $old !== []) {
            return array_values(array_map(fn (mixed $row): array => $this->gridRow(is_array($row) ? $row : []), $old));
        }

        if ($from !== null && $method !== null) {
            return $from->rows()->get()->map(fn (MassPaymentRow $row): array => $this->gridRow([
                'amount'    => (string) $row->amount_input,
                'note'      => (string) $row->note,
                'reference' => (string) $row->client_reference,
                'account'   => is_array($row->payout_account) ? $row->payout_account : [],
            ]))->values()->all();
        }

        return array_fill(0, self::BLANK_ROWS, $this->gridRow([]));
    }

    /**
     * @param  array<string, mixed>                                                                   $row
     * @return array{amount: string, note: string, reference: string, account: array<string, string>}
     */
    private function gridRow(array $row): array
    {
        $account = is_array($row['account'] ?? null) ? $row['account'] : [];

        return [
            'amount'    => is_scalar($row['amount'] ?? null) ? (string) $row['amount'] : '',
            'note'      => is_scalar($row['note'] ?? null) ? (string) $row['note'] : '',
            'reference' => is_scalar($row['reference'] ?? null) ? (string) $row['reference'] : '',
            'account'   => array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $account),
        ];
    }

    /**
     * The draft a rebuilt one replaces, discarded once the new one exists. A
     * draft already confirmed, already discarded or not the customer's is
     * left exactly as it is.
     */
    private function discardReplaced(StoreMassPayoutRequest $request, MassPaymentBatch $replacement): void
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
     * How many lines are in each status, every status present.
     *
     * @return array<string, int>
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
     * The source wallet's balance against the draft's total, as advice only.
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

    private function isStale(MassPaymentBatch $batch): bool
    {
        if (! in_array($batch->status(), [MassPaymentBatchStatus::Queued, MassPaymentBatchStatus::Processing], true)) {
            return false;
        }

        $since = $batch->last_progress_at ?? $batch->confirmed_at;

        return $since !== null && $since->lt(now()->subMinutes(self::STALE_PROGRESS_MINUTES));
    }
}
