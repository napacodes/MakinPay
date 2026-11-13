<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Enums\BillPayment\BillPaymentStatus;
use App\Models\BillPayment;
use App\Models\SavedBill;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "My bills": the bills a customer kept so the next payment is one tap.
 *
 * A bill is kept only from a payment the customer made and asked to save, so
 * every saved account number is one the biller's own rules already accepted;
 * a payment that failed outright is never kept, since its number may be the
 * reason. The book holds no money and no status: the last payment beside a
 * bill is read from bill_payments every time.
 *
 * Only ever reached through the customer's own relation, so one customer
 * never reads, keeps or removes another's.
 */
class SavedBillBook
{
    /** More than anyone pays by hand; a cap so the list stays a list. */
    public const MAX_PER_USER = 50;

    public const NICKNAME_MAX = 60;

    /**
     * Keep the bill this payment paid, under [nickname] when one is given.
     * Saving it again refreshes it and renames it only when a new name is
     * given. Returns null when the payment failed or the book is full.
     */
    public function keep(User $user, BillPayment $payment, ?string $nickname = null): ?SavedBill
    {
        if ((int) $payment->user_id !== (int) $user->id || $payment->biller_id === null || $payment->status === BillPaymentStatus::Failed) {
            return null;
        }

        $name     = self::cleanNickname($nickname);
        $existing = $this->forPayment($user, $payment);

        if ($existing !== null) {
            $existing->forceFill(array_filter([
                'nickname'     => $name,
                'last_used_at' => now(),
            ], fn (mixed $value): bool => $value !== null))->save();

            return $existing;
        }

        if ($user->savedBills()->count() >= self::MAX_PER_USER) {
            return null;
        }

        return $user->savedBills()->create([
            'biller_id'       => $payment->biller_id,
            'customer_number' => (string) $payment->customer_number,
            'nickname'        => $name,
            'last_used_at'    => now(),
        ]);
    }

    /**
     * The customer's kept bills, most recently used first, each with the last
     * payment made to it, or null. Bills whose biller can no longer be paid
     * are left out rather than offered and refused.
     *
     * @return Collection<int, array{bill: SavedBill, last: BillPayment|null}>
     */
    public function forUser(User $user): Collection
    {
        $bills = $user->savedBills()
            ->whereHas('biller', fn (Builder $billers): Builder => $billers->payable())
            ->with('biller.category')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->limit(self::MAX_PER_USER)
            ->get();

        if ($bills->isEmpty()) {
            return collect();
        }

        $payments = $user->billPayments()
            ->whereIn('biller_id', $bills->pluck('biller_id')->unique()->all())
            ->whereIn('customer_number', $bills->pluck('customer_number')->unique()->all())
            ->latest()
            ->latest('id')
            ->get(['id', 'uuid', 'biller_id', 'customer_number', 'status', 'total_amount', 'currency', 'biller_item_id', 'item_name', 'created_at']);

        return $bills->map(fn (SavedBill $bill): array => [
            'bill' => $bill,
            'last' => $payments->first(fn (BillPayment $payment): bool => (int) $payment->biller_id === (int) $bill->biller_id && (string) $payment->customer_number === (string) $bill->customer_number),
        ])->values();
    }

    /** One of the customer's kept bills, or null. */
    public function find(User $user, int $id): ?SavedBill
    {
        return $user->savedBills()->with('biller')->find($id);
    }

    /** The kept bill a payment of the customer's paid, or null. */
    public function forPayment(User $user, BillPayment $payment): ?SavedBill
    {
        if ((int) $payment->user_id !== (int) $user->id || $payment->biller_id === null) {
            return null;
        }

        return $user->savedBills()
            ->where('biller_id', $payment->biller_id)
            ->where('customer_number', (string) $payment->customer_number)
            ->first();
    }

    /**
     * BP3: remind the customer of one of their kept bills on a day of each
     * month, or stop (null). A changed day starts afresh, so the new day is
     * reminded even in a month already reminded on the old one. Null when
     * the bill is not theirs.
     */
    public function setReminder(User $user, int $id, ?int $day): ?SavedBill
    {
        $bill = $user->savedBills()->with('biller')->find($id);

        if ($bill === null) {
            return null;
        }

        $bill->forceFill(['remind_day' => $day, 'reminded_for' => null])->save();

        return $bill;
    }

    /** Forget one of the customer's kept bills; false when it was not theirs. */
    public function forget(User $user, int $id): bool
    {
        return $user->savedBills()->whereKey($id)->delete() > 0;
    }

    /** A name cleaned of markup and control characters, or null for none. */
    public static function cleanNickname(?string $nickname): ?string
    {
        if ($nickname === null) {
            return null;
        }

        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', strip_tags($nickname))));

        return $clean === '' ? null : mb_substr($clean, 0, self::NICKNAME_MAX);
    }
}
