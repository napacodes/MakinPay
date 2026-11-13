<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\MassPayment\MassPaymentKind;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MassPayment\StoreMassPaymentScheduleRequest;
use App\Models\MassPaymentBatch;
use App\Models\ScheduledPayment;
use App\Models\User;
use App\Services\MassPayment\MassPaymentSchedules;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The merchant's scheduled batches: set one up from a batch they already
 * built, see when each runs next and how the last runs went, pause,
 * resume and cancel. Every schedule is read through the signed-in
 * merchant's own; the service checks it again.
 */
final class MassPaymentScheduleController extends Controller
{
    public function __construct(private readonly MassPaymentSchedules $schedules) {}

    public function index(Request $request): View
    {
        $schedules = $this->schedules->forUser($this->merchant($request));

        $batches = MassPaymentBatch::query()
            ->whereIn('scheduled_payment_id', $schedules->pluck('id'))
            ->get()
            ->keyBy('reference');

        return view('frontend.user.mass_payment.schedules', [
            'schedules' => $schedules,
            'batches'   => $batches,
        ]);
    }

    public function store(StoreMassPaymentScheduleRequest $request): RedirectResponse
    {
        $user  = $this->merchant($request);
        $batch = $user->massPaymentBatches()->ofKind(MassPaymentKind::Wallet)->where('uuid', (string) $request->input('batch'))->with('wallet.currency')->first();

        if ($batch === null || $batch->wallet === null) {
            notifyEvs('error', __('Choose the batch to pay again.'));

            return back()->withInput();
        }

        try {
            $schedule = $this->schedules->create(
                $user,
                $batch->wallet,
                $this->schedules->rowsOfBatch($batch),
                $request->input('name') !== null && trim((string) $request->input('name')) !== '' ? (string) $request->input('name') : $batch->name,
                $request->schedule(),
                (string) $request->input('pin'),
            );
        } catch (NotifyErrorException $refused) {
            notifyEvs('error', $refused->getMessage());

            return back()->withInput($request->except('pin'));
        }

        notifyEvs('success', $schedule->next_run_at === null
            ? __('Scheduled. It has no run ahead of it, so it is already over.')
            : __('Scheduled. It runs next on :date.', ['date' => $schedule->next_run_at->translatedFormat('D j M Y')]));

        return redirect()->route('user.mass-payment.schedules.index');
    }

    public function pause(Request $request, ScheduledPayment $schedule): RedirectResponse
    {
        return $this->act(fn () => $this->schedules->pause($this->merchant($request), $schedule), __('Paused. Nothing runs until you resume it.'));
    }

    public function resume(Request $request, ScheduledPayment $schedule): RedirectResponse
    {
        return $this->act(fn () => $this->schedules->resume($this->merchant($request), $schedule), __('Resumed.'));
    }

    public function cancel(Request $request, ScheduledPayment $schedule): RedirectResponse
    {
        return $this->act(fn () => $this->schedules->cancel($this->merchant($request), $schedule), __('Cancelled. Batches already paid are unaffected.'));
    }

    /**
     * @param callable(): ScheduledPayment $action
     */
    private function act(callable $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (NotifyErrorException $refused) {
            if ($refused->getCode() === 404) {
                abort(404);
            }

            notifyEvs('error', $refused->getMessage());

            return back();
        }

        notifyEvs('success', $done);

        return back();
    }

    private function merchant(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
