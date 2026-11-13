<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Enums\AmountFlow;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;

/**
 * Give a seeded wallet balance a ledger entry to stand on.
 *
 * Two demo seeders stage balances by writing the `balance` column directly —
 * the quickest way to set up a demo, and the reason `money:audit-precision`
 * reported twenty-eight `wallet-drift` findings on a freshly seeded database.
 * The money was in the wallets but nothing in the ledger accounted for it, so
 * the product failed its own integrity check out of the box and a buyer
 * evaluating it saw a broken install.
 *
 * Shared rather than copied because it was already wrong in two places: fixing
 * `DemoAccountSeeder` alone left the P2P traders' crypto wallets drifting, and
 * a third seeder staging balances would have reintroduced it again.
 */
trait BooksOpeningBalances
{
    /**
     * Book the difference between the wallet's balance and its ledger.
     *
     * The entry is the remainder, not the whole balance, so a seeder that also
     * writes narrative transactions composes with this instead of double
     * counting. Re-seeding recomputes against a stable reference rather than
     * stacking another entry, and a wallet that already reconciles has its
     * opening entry removed rather than left at zero.
     */
    protected function bookOpeningBalance(Wallet $wallet): void
    {
        $wallet->loadMissing('currency');

        $reference = 'DEMO-OPENING-'.$wallet->uuid;

        // The wallet's other completed entries. Excluding this wallet's own
        // opening balance is what stops each re-seed booking the shortfall a
        // second time.
        //
        // A credit moves `net_amount` and a debit moves `payable_amount`,
        // which is what every handler does: you receive the net after the fee
        // and you pay the payable including it. This summed `payable_amount`
        // in both directions, and so did `money:audit-precision` — the two
        // agreed with each other and therefore looked right, while a real
        // deposit through the real handler did not match either of them. The
        // shared mistake is why a seeded install reconciled and a live one
        // reported drift on the one wallet that was actually correct.
        $booked = (string) Transaction::query()
            ->where('wallet_reference', $wallet->uuid)
            ->where('status', TrxStatus::COMPLETED)
            ->where(fn ($query) => $query->where('trx_reference', '!=', $reference)->orWhereNull('trx_reference'))
            ->sum(DB::raw("CASE WHEN amount_flow = 'minus' THEN -COALESCE(payable_amount, 0) ELSE COALESCE(net_amount, payable_amount, 0) END"));

        $opening = DecimalMoney::subtract((string) $wallet->balance, $booked, DecimalMoney::CALCULATION_SCALE);

        if (DecimalMoney::compare($opening, '0') === 0) {
            Transaction::query()->where('trx_reference', $reference)->delete();

            return;
        }

        // Legitimately negative when the narrative entries credit more than
        // the wallet is meant to hold.
        $flow      = DecimalMoney::compare($opening, '0') > 0 ? AmountFlow::PLUS : AmountFlow::MINUS;
        $magnitude = ltrim($opening, '-');
        $rate      = app(WalletService::class)->fxRate($wallet);
        $base      = (string) (siteCurrency() ?: 'USD');

        Transaction::query()->updateOrCreate(
            ['user_id' => $wallet->user_id, 'trx_reference' => $reference],
            [
                'trx_type'        => TrxType::ADD_BALANCE,
                'description'     => __('Opening balance'),
                'provider'        => 'system',
                'processing_type' => MethodType::SYSTEM,

                // `amount` is base-denominated and `currency` names that unit;
                // `payable_amount` is what actually moves in the wallet.
                // Stamping the wallet's code on the base figure is the mismatch
                // the ledger work removed, so it is not reintroduced here.
                'amount'           => DecimalMoney::divide($magnitude, $rate),
                'amount_flow'      => $flow,
                'fee'              => 0,
                'currency'         => $base,
                'net_amount'       => $magnitude,
                'payable_amount'   => $magnitude,
                'payable_currency' => $wallet->currency->code,
                'wallet_reference' => $wallet->uuid,
                'trx_data'         => ['seeded' => true, 'opening_balance' => true],
                'remarks'          => 'Seeded opening balance',
                'status'           => TrxStatus::COMPLETED,

                // FX snapshot, so the row reconciles the way a real one does
                // and is not reported as an unrestatable entry.
                'exchange_rate'    => $rate,
                'base_amount'      => DecimalMoney::divide($magnitude, $rate),
                'base_fee'         => 0,
                'base_currency'    => $base,
                'wallet_amount'    => $magnitude,
                'wallet_fee'       => 0,
                'entered_amount'   => $magnitude,
                'entered_currency' => $wallet->currency->code,
            ]
        );
    }
}
