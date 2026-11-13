<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Enums\MethodType;
use App\Models\Transaction as TransactionModel;
use App\Models\User;
use App\Services\Handlers\DepositHandler;
use App\Traits\FileManageTrait;
use Illuminate\Http\UploadedFile;
use Payment;
use Wallet;

/**
 * Filing a deposit against one of the customer's own wallets.
 *
 * Lifted out of DepositController so the app and the panel ask the same
 * questions. The controller keeps what a browser needs - whether to redirect
 * to a gateway or render its HTML back - and this decides everything else.
 *
 * Nothing about the money moves here. PaymentService::depositWithPaymentMethod
 * still matches the method's currency against the wallet's, converts through
 * the method's own rate, compares the base-denominated limits and writes the
 * pending row. A manual deposit is a claim until an administrator agrees with
 * it, and the credit happens later through
 * TransactionService::completeTransaction - which is also where the guard
 * against crediting one deposit twice lives.
 *
 * The result is returned as a pair rather than a redirect, because the second
 * half of it is a gateway's answer: sometimes a URL to send the customer to,
 * sometimes a block of HTML to render. Deciding between those is presentation,
 * and presentation belongs in the controller.
 */
final class DepositMoneyService
{
    use FileManageTrait;

    public function __construct(private readonly DepositHandler $deposits) {}

    /**
     * Merge the customer's answers into a pending manual deposit's stored
     * fields, in the shape the administrator's review screen reads. A field
     * left out keeps what it had; a file replaces its stored path.
     *
     * @param array<string, mixed> $answers
     * @param array<string, mixed> $files
     */
    public function attachProof(TransactionModel $transaction, array $answers, array $files): void
    {
        $data   = $transaction->trx_data ?? [];
        $fields = collect($data)->filter(fn ($field, $key) => is_int($key) && is_array($field) && isset($field['name']))->values();
        $other  = collect($data)->filter(fn ($field, $key) => ! is_int($key));

        $merged = $fields->map(function (array $field) use ($answers, $files): array {
            $name = (string) $field['name'];

            if (($files[$name] ?? null) instanceof UploadedFile) {
                $field['value'] = $this->uploadPaymentProof($files[$name]);
            } elseif (array_key_exists($name, $answers) && $answers[$name] !== null && ! ($answers[$name] instanceof UploadedFile)) {
                $field['value'] = (string) $answers[$name];
            }

            return $field;
        });

        $transaction->update(['trx_data' => array_merge($merged->all(), $other->all())]);
    }

    /**
     * @param  array{wallet_id: mixed, payment_method: mixed, amount: mixed, customer_phone?: ?string} $validated
     * @return array{0: TransactionModel, 1: string}
     *
     * @throws \Exception
     */
    public function deposit(User $user, array $validated): array
    {
        $wallet = $user->wallets()
            ->with('currency.activeRoles')
            ->findOrFail($validated['wallet_id']);

        Wallet::assertOperational($wallet);

        [$transaction, $redirectUrl] = Payment::depositWithPaymentMethod(
            $validated['payment_method'],
            $validated['amount'],
            $validated['wallet_id'],
            $validated['customer_phone'] ?? null,
        );

        // A manual deposit needs somebody told about it; an automatic one is
        // announced by its gateway when the callback lands.
        if ($transaction->processing_type === MethodType::MANUAL) {
            $this->deposits->handleSubmitted($transaction);
        }

        return [$transaction, $redirectUrl];
    }
}
