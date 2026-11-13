<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\MassPayment;

use App\Enums\MassPayment\MassPaymentDispatchState;
use App\Enums\MassPayment\MassPaymentKind;
use App\Enums\Payout\PayoutOutcome;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Backend\BaseController;
use App\Http\Requests\Backend\MassPayment\MarkMassPayoutRowNotPaidRequest;
use App\Http\Requests\Backend\MassPayment\MarkMassPayoutRowPaidRequest;
use App\Http\Requests\Backend\MassPayment\RejectMassPayoutRequest;
use App\Models\Admin;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentRow;
use App\Services\MassPayment\MassPaymentActionResult;
use App\Services\MassPayment\MassPayoutAccount;
use App\Services\MassPayment\MassPayoutService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The operator's actions that only a payout batch has: approving or
 * rejecting a batch that waits for approval, and resolving one line by hand -
 * recording it paid with a reference, not paid with a reason, asking its
 * provider now, or revealing the account it pays. Recording a line under
 * review, whose outcome at the provider is unknown, also needs
 * mass-payment-manage.
 *
 * Approving and rejecting need mass-payment-approve; everything on a line
 * needs mass-payment-action. Each action goes through MassPayoutService,
 * which re-reads the batch or the line under its lock and decides there.
 * A batch is found only as a payout batch and a line only inside its batch,
 * so a wallet batch, or a line of another batch, is a 404 here.
 *
 * Like the rest of the mass payment admin, not behind admin.feature: money
 * reserved for a confirmed batch must stay reachable with the module off.
 */
class MassPayoutController extends BaseController
{
    public function __construct(private readonly MassPayoutService $service) {}

    public static function permissions(): array
    {
        return [
            'approve|reject'                                    => 'mass-payment-approve',
            'markRowPaid|markRowNotPaid|checkRow|revealAccount' => 'mass-payment-action',
        ];
    }

    public function approve(Request $request, MassPaymentBatch $batch): RedirectResponse
    {
        $this->payoutBatch($batch);

        return $this->respond(
            fn (): MassPaymentActionResult => $this->service->approve($batch, $this->admin($request)),
            __('Mass payout :reference approved. Its payouts are being sent.', ['reference' => $batch->reference]),
        );
    }

    public function reject(RejectMassPayoutRequest $request, MassPaymentBatch $batch): RedirectResponse
    {
        $this->payoutBatch($batch);

        return $this->respond(
            fn (): MassPaymentActionResult => $this->service->reject($batch, $this->admin($request), (string) $request->validated('reason')),
            __('Mass payout :reference rejected. Every payout was refunded to the merchant\'s wallet.', ['reference' => $batch->reference]),
        );
    }

    public function markRowPaid(MarkMassPayoutRowPaidRequest $request, MassPaymentBatch $batch, int $row): RedirectResponse
    {
        $line = $this->line($batch, $row);
        $this->authorizeResolution($request, $line);

        return $this->respond(
            fn (): MassPaymentActionResult => $this->service->markRowPaid($line, $this->admin($request), (string) $request->validated('reference')),
            __('Line :line recorded as paid.', ['line' => $line->line_no]),
        );
    }

    public function markRowNotPaid(MarkMassPayoutRowNotPaidRequest $request, MassPaymentBatch $batch, int $row): RedirectResponse
    {
        $line = $this->line($batch, $row);
        $this->authorizeResolution($request, $line);

        return $this->respond(
            fn (): MassPaymentActionResult => $this->service->markRowNotPaid($line, $this->admin($request), (string) $request->validated('reason')),
            __('Line :line recorded as not paid and refunded to the merchant\'s wallet.', ['line' => $line->line_no]),
        );
    }

    public function checkRow(MassPaymentBatch $batch, int $row): RedirectResponse
    {
        $line = $this->line($batch, $row);

        try {
            $outcome = $this->service->checkRow($line);
        } catch (NotifyErrorException $exception) {
            notifyEvs('error', $exception->getMessage());

            return redirect()->back();
        }

        notifyEvs('info', match ($outcome) {
            PayoutOutcome::Completed => __('The provider confirms line :line was paid. It is now settled.', ['line' => $line->line_no]),
            PayoutOutcome::Failed    => __('The provider says line :line will not be paid. It was refunded.', ['line' => $line->line_no]),
            PayoutOutcome::Pending   => __('The provider is still processing line :line.', ['line' => $line->line_no]),
            PayoutOutcome::NotFound  => __('The provider holds no payout for line :line.', ['line' => $line->line_no]),
            default                  => __('The provider did not give a clear answer about line :line. Nothing was changed.', ['line' => $line->line_no]),
        });

        return redirect()->back();
    }

    /**
     * The account a line pays, decrypted, for the admin who asked. Answered
     * as JSON so the details are shown once and never stored in the session
     * or the page; each reveal is on the batch's audit trail.
     */
    public function revealAccount(Request $request, MassPaymentBatch $batch, int $row, MassPayoutAccount $accounts): JsonResponse
    {
        $line    = $this->line($batch, $row);
        $account = $this->service->revealAccount($line, $this->admin($request));
        $labels  = collect(is_array($batch->metadata['payout']['fields'] ?? null) ? $batch->metadata['payout']['fields'] : [])
            ->mapWithKeys(fn (mixed $field): array => is_array($field) && isset($field['name']) ? [(string) $field['name'] => (string) ($field['label'] ?? $field['name'])] : []);

        $details = [];

        foreach ($account as $name => $value) {
            $details[] = ['label' => (string) ($labels[$name] ?? $name), 'value' => $value];
        }

        return response()
            ->json(['line' => (int) $line->line_no, 'label' => $accounts->label($account), 'details' => $details])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * One flash per outcome, as the wallet batch actions answer.
     *
     * @param Closure(): MassPaymentActionResult $action
     */
    private function respond(Closure $action, string $doneMessage): RedirectResponse
    {
        try {
            $result = $action();
        } catch (NotifyErrorException $exception) {
            notifyEvs('error', $exception->getMessage());

            return redirect()->back();
        }

        match ($result->outcome) {
            MassPaymentActionResult::DONE             => notifyEvs('success', $doneMessage),
            MassPaymentActionResult::ALREADY_RESOLVED => notifyEvs('info', __('This was already resolved. Nothing was changed.')),
            default                                   => notifyEvs('error', __('This mass payout\'s ledger does not add up. No money was moved; see the notice on this page.')),
        };

        return redirect()->back();
    }

    /**
     * A line waiting for an operator is recorded with mass-payment-action,
     * which the route already requires. A line under review has an unknown
     * outcome at its provider, and settling or refunding it on an admin's
     * word needs mass-payment-manage as well, as the permission says.
     */
    private function authorizeResolution(Request $request, MassPaymentRow $line): void
    {
        if ($line->dispatchState() === MassPaymentDispatchState::NeedsReview) {
            abort_unless($this->admin($request)->can('mass-payment-manage'), 403);
        }
    }

    private function payoutBatch(MassPaymentBatch $batch): void
    {
        abort_unless($batch->kind() === MassPaymentKind::Payout, 404);
    }

    /** The line, only as a line of this payout batch. */
    private function line(MassPaymentBatch $batch, int $row): MassPaymentRow
    {
        $this->payoutBatch($batch);

        return MassPaymentRow::query()
            ->where('mass_payment_batch_id', $batch->id)
            ->whereKey($row)
            ->firstOrFail();
    }

    private function admin(Request $request): Admin
    {
        $admin = $request->user('admin');

        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }
}
