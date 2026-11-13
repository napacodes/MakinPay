<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\P2P;

use App\Http\Controllers\Backend\BaseController;
use App\Models\P2P\PaymentAccount;
use App\Notifications\P2PPaymentAccountReviewedNotification;
use App\Support\P2PPaymentFileDisk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The administrator's side of a P2P payment account's ownership proof.
 *
 * Uploading a proof file puts the account at PENDING, and the app shows a
 * "Verified" badge once it is VERIFIED - but until this screen existed no
 * administrator could ever get it there, so every proof waited forever.
 */
class P2PPaymentAccountReviewController extends BaseController
{
    public const FILTERS = ['pending', 'verified', 'all'];

    public static function permissions(): array
    {
        return [
            'index|proofFile' => 'p2p-manage',
            'verify|reject'   => 'p2p-manage',
        ];
    }

    public function index(Request $request): View
    {
        $filter = in_array($request->query('status'), self::FILTERS, true) ? (string) $request->query('status') : 'pending';

        $accounts = PaymentAccount::query()
            ->with(['user', 'paymentMethod'])
            ->when($filter === 'pending', fn ($query) => $query->where('verification_status', PaymentAccount::VERIFICATION_PENDING))
            ->when($filter === 'verified', fn ($query) => $query->where('verification_status', PaymentAccount::VERIFICATION_VERIFIED))
            ->when($filter === 'all', fn ($query) => $query->whereIn('verification_status', [PaymentAccount::VERIFICATION_PENDING, PaymentAccount::VERIFICATION_VERIFIED]))
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString();

        $pendingCount = PaymentAccount::query()->where('verification_status', PaymentAccount::VERIFICATION_PENDING)->count();

        return view('backend.p2p.payment_accounts.index', compact('accounts', 'filter', 'pendingCount'));
    }

    /**
     * One of the account's files, through the same sandboxed response the
     * owner and their trade partners get.
     */
    public function proofFile(PaymentAccount $paymentAccount, string $field): StreamedResponse
    {
        return P2PPaymentFileDisk::response(P2PPaymentFileDisk::fromDetails($paymentAccount->renderedDetails(), $field));
    }

    public function verify(PaymentAccount $paymentAccount): RedirectResponse
    {
        if ($paymentAccount->verification_status !== PaymentAccount::VERIFICATION_PENDING) {
            notifyEvs('error', __('Only an account waiting for review can be verified.'));

            return back();
        }

        $paymentAccount->forceFill([
            'verification_status' => PaymentAccount::VERIFICATION_VERIFIED,
            'verified_at'         => now(),
        ])->save();

        $paymentAccount->user?->notify(new P2PPaymentAccountReviewedNotification($paymentAccount, approved: true));

        notifyEvs('success', __('Payment account verified.'));

        return back();
    }

    public function reject(Request $request, PaymentAccount $paymentAccount): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (! in_array($paymentAccount->verification_status, [PaymentAccount::VERIFICATION_PENDING, PaymentAccount::VERIFICATION_VERIFIED], true)) {
            notifyEvs('error', __('This account has no proof under review.'));

            return back();
        }

        // Back to "no proof on file": the customer uploads a better one,
        // which puts it in front of an administrator again.
        $paymentAccount->forceFill([
            'verification_status' => PaymentAccount::VERIFICATION_NONE,
            'verified_at'         => null,
        ])->save();

        $paymentAccount->user?->notify(new P2PPaymentAccountReviewedNotification($paymentAccount, approved: false, reason: $validated['reason'] ?? null));

        notifyEvs('success', __('Payment account proof declined.'));

        return back();
    }
}
