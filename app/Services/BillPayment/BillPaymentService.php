<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Contracts\BillPayment\ChecksBillPaymentStatus;
use App\Contracts\BillPayment\FulfilledByOperator;
use App\Enums\BillPayment\BillCustomerLookupStatus;
use App\Enums\BillPayment\BillPaymentActorType;
use App\Enums\BillPayment\BillPaymentDispatchState;
use App\Enums\BillPayment\BillPaymentFulfilment;
use App\Enums\BillPayment\BillPaymentOutcome;
use App\Enums\BillPayment\BillPaymentStatus;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Events\TransactionUpdated;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\NotifyErrorException;
use App\Models\Admin;
use App\Models\Biller;
use App\Models\BillerItem;
use App\Models\BillPayment;
use App\Models\BillPaymentEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Support\DecimalMoney;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Takes a customer's bill payment from request to fulfiller: the locked,
 * idempotent reservation of the money, the hand-off to the driver, and the one
 * place every answer a fulfiller gives is recorded.
 *
 * Lock order. submit() locks the wallet and only inserts new payment and
 * ledger rows; it never locks an existing payment. Every later transition
 * locks the payment row first and decides from that locked re-read, never from
 * a model a caller passed in, so a stale model cannot move a payment twice.
 * settle() then locks the biller and the debit row, refund() the debit row
 * and the wallet: payment, biller, transaction, wallet, so no two of them
 * wait on each other in a cycle.
 *
 * The token never passes through here except on its way to the encrypted
 * column: no event payload, ledger row or log line carries it.
 */
class BillPaymentService
{
    /** A Completed answer whose amount or currency is not what was billed. */
    public const REVIEW_AMOUNT_OR_CURRENCY_MISMATCH = 'amount_or_currency_mismatch';

    /** The fulfiller gave no clear answer and cannot be asked again. */
    public const REVIEW_OUTCOME_UNKNOWN = 'outcome_unknown';

    /** The fulfiller's Completed or Failed answer conflicts with what is already recorded. */
    public const REVIEW_PROVIDER_RESULT_REFUSED = 'provider_result_refused';

    /** The provider named a different id for a payment already bound to one. */
    public const REVIEW_PROVIDER_REFERENCE_CHANGED = 'provider_reference_changed';

    /** The provider named an id another payment of the same provider already holds. */
    public const REVIEW_PROVIDER_REFERENCE_CONFLICT = 'provider_reference_conflict';

    /** The provider never gave a final answer before the deadline. */
    public const REVIEW_PROVIDER_UNRESOLVED = 'provider_unresolved';

    /** `metadata.provider_contradiction.kind`: a Failed answer for a payment already settled. */
    public const CONTRADICTION_REVERSED_AFTER_SETTLE = 'reversed_after_settle';

    /** `metadata.provider_contradiction.kind`: a Completed answer for a payment already refunded. */
    public const CONTRADICTION_PAID_AFTER_REFUND = 'paid_after_refund';

    /** How recent an admin's check with the provider must be before "confirm not charged". */
    public const CONFIRM_NOT_PAID_CHECK_MINUTES = 15;

    /** The shortest note an admin may give when confirming a payment was not charged. */
    public const CONFIRM_NOT_PAID_NOTE_MIN = 10;

    /** `metadata.ledger_anomaly` when the payment's debit row no longer exists. */
    public const LEDGER_ANOMALY_MISSING = 'missing';

    /** `metadata.ledger_anomaly` when the debit row no longer carries the payment's total. */
    public const LEDGER_ANOMALY_AMOUNT_MISMATCH = 'amount_mismatch';

    public function __construct(
        private readonly WalletService $wallets,
        private readonly BillPaymentPricer $pricer,
        private readonly BillerRules $rules,
        private readonly BillPaymentLedger $ledger,
        private readonly BillPaymentProviderManager $providers,
        private readonly BillPaymentNotifier $notifier,
        private readonly ?BillCustomerVerifier $customers = null,
    ) {}

    /**
     * Reserve the money for one bill payment and hand it to its fulfiller.
     *
     * Phase 0, replay first. A request whose idempotency key already names a
     * payment returns that payment before anything is validated, so a retry
     * still answers after the biller was switched off or its fee changed. The
     * same key with different details is refused. `expected_total` is a guard,
     * not part of the request's identity, so it stays out of the hash.
     *
     * Phase 1, validate and price, with no transaction and no lock. Last, the
     * provider is asked who owns the customer number (BillCustomerVerifier),
     * so a definite "no such account" is refused before anything is debited.
     * A replay never gets here, so it never asks.
     *
     * Phase 2, one transaction: lock the wallet, debit it with the balance
     * check in the same statement, then write the payment, its PENDING debit
     * row and the first event. The unique (user_id, client_idempotency_key)
     * index is what decides a race between two first submissions: the loser's
     * insert fails, its transaction (and so its debit) rolls back, and it
     * replays the winner. A collision on any other unique column is a real
     * error and is rethrown.
     *
     * Phase 3, after commit: announce it and dispatch it.
     *
     * @throws IdempotencyConflictException the key was already used with different details
     * @throws ValidationException          on customer_number, biller_item_id, wallet_id, amount or expected_total
     * @throws NotifyErrorException         the wallet cannot pay, the balance is short, the biller cannot be paid right now, or a required account lookup is unavailable
     * @throws ModelNotFoundException       the biller is not payable, or the wallet is not the user's
     */
    public function submit(User $user, BillPaymentSubmission $submission): SubmittedBillPayment
    {
        $number = $this->rules->normalizeCustomerNumber($submission->customerNumber);
        $hash   = $this->requestHash($user, $submission, $number);
        $key    = $this->idempotencyKey($submission);

        if ($key !== null) {
            $existing = $this->findByIdempotencyKey((int) $user->id, $key);

            if ($existing !== null) {
                return $this->replay($existing, $hash);
            }
        }

        $biller = Biller::query()->payable()->with(['provider', 'category'])->findOrFail($submission->billerId);
        $wallet = Wallet::query()->where('user_id', $user->id)->findOrFail($submission->walletId);
        $this->wallets->assertOperational($wallet);

        $this->rules->assertCustomerNumber($biller, $number);
        $item  = $this->rules->resolveItem($biller, $submission->billerItemId);
        $quote = $this->pricer->quote($biller, $wallet, $item, $submission->amount);
        $this->assertExpectedTotal($submission->expectedTotal, $quote);

        $customerName = $this->assertCustomerAccount($biller, $item, $number);

        $fulfilment = $this->fulfilmentFor($biller);
        $isReplay   = false;

        try {
            $payment = DB::transaction(function () use (
                $user, $submission, $wallet, $biller, $item, $quote, $number, $key, $hash, $fulfilment, $customerName, &$isReplay
            ): BillPayment {
                $isReplay = false;

                $locked = Wallet::query()
                    ->whereKey($wallet->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->wallets->assertOperational($locked);

                if ($key !== null) {
                    $existing = $this->findByIdempotencyKey((int) $user->id, $key);

                    if ($existing !== null) {
                        $isReplay = true;

                        return $existing;
                    }
                }

                if ($this->wallets->tryDebit($locked, $quote->total) !== 1) {
                    throw new NotifyErrorException(__('Insufficient wallet balance.'));
                }

                $uuid = (string) Str::uuid();

                $payment = BillPayment::query()->create([
                    'uuid'                     => $uuid,
                    'reference'                => $this->newReference(),
                    'user_id'                  => $user->id,
                    'wallet_id'                => $locked->id,
                    'biller_id'                => $biller->id,
                    'biller_item_id'           => $item?->id,
                    'bill_payment_provider_id' => $biller->provider->id,
                    'biller_name'              => $biller->name,
                    'category_code'            => $biller->category->code,
                    'item_name'                => $item?->name,
                    'item_code'                => $item?->external_code,
                    'country'                  => $biller->country,
                    'customer_number_label'    => (string) ($biller->customer_number_label ?: __('Account number')),
                    'customer_number'          => $number,
                    'provider_code'            => $biller->provider->code,
                    'driver'                   => $biller->provider->driver,
                    'fulfilment'               => $fulfilment,
                    'bill_amount'              => $quote->billAmount,
                    'bill_currency'            => $quote->billCurrency,
                    'amount'                   => $quote->amount,
                    'fee'                      => $quote->fee,
                    'tax_amount'               => $quote->taxQuote()->isEmpty() ? null : $quote->taxQuote()->total,
                    'tax_breakdown'            => $quote->taxQuote()->breakdown(),
                    'total_amount'             => $quote->total,
                    'currency'                 => $quote->currency,
                    'exchange_rate'            => null,
                    'status'                   => BillPaymentStatus::Pending,
                    'dispatch_state'           => BillPaymentDispatchState::Reserved->value,
                    'provider_request_id'      => 'bp_'.$uuid,
                    'client_idempotency_key'   => $key,
                    'client_request_hash'      => $hash,
                    'channel'                  => $submission->channel,
                    'metadata'                 => $customerName !== null ? ['customer_name' => $customerName] : null,
                ]);

                $debit = Transaction::query()->create($this->ledger->debitRow($payment, $locked));

                $payment->forceFill(['transaction_id' => $debit->id])->save();

                $this->recordEvent($payment, null, null, BillPaymentActor::user($user), __('Submitted.'));

                return $payment;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $winner = $key === null ? null : $this->findByIdempotencyKey((int) $user->id, $key);

            if ($winner === null) {
                throw $exception;
            }

            return $this->replay($winner, $hash);
        }

        if ($isReplay) {
            return $this->replay($payment, $hash);
        }

        $this->notifier->submitted($payment);

        return new SubmittedBillPayment($this->dispatch($payment), false);
    }

    /**
     * Hand a reserved payment to its driver, once.
     *
     * The claim is a conditional update from `reserved` to `submitting`, so of
     * two callers (the request that reserved it and a replay of that request)
     * exactly one calls the driver; the other gets the payment as it stands.
     * The driver is then called with no transaction open and no lock held. A
     * throw is an Unknown outcome, never a refusal: the biller may have been
     * paid.
     */
    public function dispatch(BillPayment $payment): BillPayment
    {
        $claimed = DB::transaction(function () use ($payment): bool {
            $changed = BillPayment::query()
                ->whereKey($payment->id)
                ->where('dispatch_state', BillPaymentDispatchState::Reserved->value)
                ->update([
                    'dispatch_state' => BillPaymentDispatchState::Submitting->value,
                    'submitted_at'   => now(),
                    'updated_at'     => now(),
                ]);

            if ($changed !== 1) {
                return false;
            }

            $claimedPayment = BillPayment::query()->findOrFail($payment->id);

            $this->recordEvent(
                $claimedPayment,
                $claimedPayment->status?->value,
                BillPaymentDispatchState::Reserved->value,
                BillPaymentActor::system(),
                __('Handed to the fulfiller.'),
            );

            return true;
        }, 3);

        if (! $claimed) {
            return $payment->fresh() ?? $payment;
        }

        $claimedPayment = BillPayment::query()->with(['provider', 'biller'])->findOrFail($payment->id);

        try {
            $result = $this->providers->driverFor($claimedPayment->provider)->pay(BillPaymentOrder::from($claimedPayment));
        } catch (Throwable $exception) {
            report($exception);

            $result = BillPaymentResult::unknown(__('The provider did not give a clear answer.'));
        }

        $applied = $this->applyResult((int) $payment->id, $result, BillPaymentActor::provider())->payment;

        /*
         * BP1: a refusal that is the provider account's own fault - an empty
         * balance, refused credentials - would refuse every payment after
         * this one too. The breaker stops new payments for its billers and
         * tells the admins once; this payment was refunded above as any
         * Failed one is, and payments already handed over are untouched.
         */
        if ($result->providerFault !== null && $claimedPayment->provider !== null
                                            && $claimedPayment->provider->pauseFor($result->providerFault, $result->message)) {
            $this->notifier->providerPaused($claimedPayment->provider);
        }

        return $applied;
    }

    /**
     * Record what a fulfiller said about a payment. Dispatch lands here, and
     * so will provider status checks and webhooks.
     *
     *   Pending    submitting moves to awaiting_operator (an operator pays) or
     *              awaiting_provider (a provider will report back).
     *   Unknown    submitting moves to awaiting_provider when the driver can be
     *              asked for the status later, otherwise to needs_review. Never
     *              refunded, never retried on its own.
     *   Completed  an amount or currency other than the one billed parks the
     *              payment for review; otherwise it is settled.
     *   Failed     the payment is refunded, once, with the fulfiller's reason.
     *
     * A Completed or Failed answer that the payment's recorded state refuses
     * (already resolved the other way, a receipt another payment of the same
     * biller holds, a state it cannot settle from) parks the payment for a
     * person rather than throwing: on the dispatch path the money is already
     * committed, and a throw would leave the payment in `submitting`, where no
     * queue shows it.
     *
     * Two steps run first. A Failed answer for a settled payment, or a
     * Completed one for a refunded payment, is a contradiction: it is flagged
     * on the payment for a person and no money moves. Then, on an open
     * payment, the provider's id in the answer is bound to it once; an id that
     * differs from the one already bound, or that another payment of the same
     * provider holds, parks the payment instead of being applied.
     */
    public function applyResult(int $paymentId, BillPaymentResult $result, BillPaymentActor $actor): BillPaymentActionResult
    {
        $payment = BillPayment::query()->with('provider')->findOrFail($paymentId);
        $payload = $this->resultPayload($result);
        $from    = [
            BillPaymentDispatchState::Submitting,
            BillPaymentDispatchState::AwaitingProvider,
            BillPaymentDispatchState::NeedsReview,
        ];

        $contradiction = $this->contradictionKind($payment, $result);

        if ($contradiction !== null) {
            return $this->flagProviderContradiction($paymentId, $contradiction, $result, $actor);
        }

        $reference = $this->filled($result->providerTransactionId);

        if ($reference !== null && ! $this->isResolved($payment)) {
            $parked = $this->bindProviderTransaction($paymentId, $reference, $actor, $payload);

            if ($parked !== null) {
                return $this->rememberUnappliedFinalAnswer($paymentId, $result, $actor, $parked);
            }
        }

        $action = match ($result->outcome) {
            BillPaymentOutcome::Completed => $this->matchesBill($payment, $result)
                ? $this->resolveOrPark($paymentId, $actor, $result, $payload, fn (): BillPaymentActionResult => $this->settle(
                    $paymentId,
                    $this->settlementFrom($result),
                    $actor,
                    $from,
                    $payload,
                ))
                : $this->parkForReview($paymentId, self::REVIEW_AMOUNT_OR_CURRENCY_MISMATCH, $actor, $result->message, $payload),
            BillPaymentOutcome::Failed => $this->resolveOrPark($paymentId, $actor, $result, $payload, fn (): BillPaymentActionResult => $this->refund(
                $paymentId,
                $result->message ?? __('The biller did not accept the payment.'),
                $actor,
                $from,
                null,
                $payload,
            )),
            BillPaymentOutcome::Pending => $this->leaveSubmitting(
                $paymentId,
                $payment->fulfilment === BillPaymentFulfilment::Operator
                    ? BillPaymentDispatchState::AwaitingOperator
                    : BillPaymentDispatchState::AwaitingProvider,
                $actor,
                $result,
            ),
            BillPaymentOutcome::Unknown => $this->driverChecksStatus($payment)
                ? $this->leaveSubmitting($paymentId, BillPaymentDispatchState::AwaitingProvider, $actor, $result)
                : $this->leaveSubmitting($paymentId, BillPaymentDispatchState::NeedsReview, $actor, $result, self::REVIEW_OUTCOME_UNKNOWN),
        };

        return $this->rememberUnappliedFinalAnswer($paymentId, $result, $actor, $action);
    }

    /**
     * A Completed or Failed answer that left the payment open (parked as a
     * mismatch, a changed or conflicting reference, or a refused result)
     * becomes the payment's last provider check, whoever asked. "Confirm not
     * charged" follows that check, so it can never refund a payment the
     * provider's latest answer said was delivered on the strength of an
     * earlier unclear one. No money moves and no event is written; the park
     * already wrote one.
     */
    private function rememberUnappliedFinalAnswer(
        int $paymentId,
        BillPaymentResult $result,
        BillPaymentActor $actor,
        BillPaymentActionResult $action,
    ): BillPaymentActionResult {
        if (! in_array($result->outcome, [BillPaymentOutcome::Completed, BillPaymentOutcome::Failed], true)) {
            return $action;
        }

        $payment = DB::transaction(function () use ($paymentId, $result, $actor): ?BillPayment {
            $payment = BillPayment::query()->lockForUpdate()->findOrFail($paymentId);

            if ($this->isResolved($payment)) {
                return null;
            }

            $metadata = is_array($payment->metadata) ? $payment->metadata : [];

            $payment->forceFill([
                'metadata' => array_merge($metadata, [
                    'last_provider_check' => [
                        'at'       => now()->toIso8601String(),
                        'outcome'  => $result->outcome->value,
                        'admin_id' => $this->adminId($actor),
                    ],
                ]),
            ])->save();

            return $payment;
        }, 3);

        return $payment === null ? $action : new BillPaymentActionResult($payment, $action->outcome);
    }

    /**
     * Stop a payment where it is and put it in front of a person.
     *
     * Under the payment's lock: a payment already settled or refunded is left
     * alone; anything else moves to needs_review (status processing), with the
     * reason merged into `metadata.review_reason`. No money moves.
     *
     * @param array<string, mixed> $payload sanitised detail for the event; never the token
     */
    public function parkForReview(
        int $paymentId,
        string $reason,
        ?BillPaymentActor $actor = null,
        ?string $note = null,
        array $payload = [],
    ): BillPaymentActionResult {
        $actor ??= BillPaymentActor::system();
        $note  ??= __('Parked for review.');

        return DB::transaction(function () use ($paymentId, $reason, $actor, $note, $payload): BillPaymentActionResult {
            $payment = BillPayment::query()->lockForUpdate()->findOrFail($paymentId);

            if ($payment->status?->isTerminal() || $payment->dispatchState()->isTerminal()) {
                return BillPaymentActionResult::alreadyResolved($payment);
            }

            $fromStatus        = $payment->status?->value;
            $fromDispatchState = (string) $payment->dispatch_state;

            $payment->forceFill([
                'status'         => BillPaymentStatus::Processing,
                'dispatch_state' => BillPaymentDispatchState::NeedsReview->value,
                'metadata'       => $this->withReviewReason($payment, $reason),
            ])->save();

            $this->recordEvent(
                $payment,
                $fromStatus,
                $fromDispatchState,
                $actor,
                $note,
                array_merge($payload, ['review_reason' => $reason]),
            );

            return BillPaymentActionResult::done($payment);
        }, 3);
    }

    /**
     * Record a provider's notice about a payment, once per provider event id.
     *
     * Writes one audit row with the state unchanged; the unique
     * (bill_payment_id, external_event_id) index is what makes a resent
     * notice a duplicate. No money moves and nothing is applied: a notice only
     * leads to a status check. Its own transaction (a savepoint inside a
     * caller's), so a duplicate rolls back only its own insert and never
     * spoils the caller's transaction.
     *
     * @param  array<string, mixed> $payload audit detail; secret-named keys are dropped
     * @return bool                 true for a new notice, false for one already recorded
     */
    public function recordProviderNotice(int $paymentId, string $externalEventId, array $payload): bool
    {
        $eventId = mb_substr(trim($externalEventId), 0, 191);

        if ($eventId === '') {
            throw new InvalidArgumentException('A provider notice needs its event id.');
        }

        try {
            DB::transaction(function () use ($paymentId, $eventId, $payload): void {
                $payment = BillPayment::query()->lockForUpdate()->findOrFail($paymentId);

                $this->recordEvent(
                    $payment,
                    $payment->status?->value,
                    (string) $payment->dispatch_state,
                    BillPaymentActor::provider(),
                    __('Provider notice received.'),
                    BillPaymentResult::sanitisedPayload($payload),
                    $eventId,
                );
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Record that an admin checked a payment with its provider and the answer
     * left it where it is (no final answer yet, or no clear one). The time
     * and the outcome are what "confirm not charged" later insists on.
     */
    public function recordProviderCheck(int $paymentId, BillPaymentOutcome $outcome, BillPaymentActor $actor): void
    {
        DB::transaction(function () use ($paymentId, $outcome, $actor): void {
            $payment  = BillPayment::query()->lockForUpdate()->findOrFail($paymentId);
            $metadata = is_array($payment->metadata) ? $payment->metadata : [];

            $payment->forceFill([
                'metadata' => array_merge($metadata, [
                    'last_provider_check' => [
                        'at'       => now()->toIso8601String(),
                        'outcome'  => $outcome->value,
                        'admin_id' => $this->adminId($actor),
                    ],
                ]),
            ])->save();

            $this->recordEvent(
                $payment,
                $payment->status?->value,
                (string) $payment->dispatch_state,
                $actor,
                __('Checked with the provider: :outcome', ['outcome' => $outcome->value]),
                ['outcome' => $outcome->value],
            );
        }, 3);
    }

    /**
     * Record that the biller was paid: the debit row completes, the receipt
     * and the token are stored, and the customer is told. No money moves; it
     * left the wallet when the payment was reserved.
     *
     * Exactly once, under the payment's lock and decided from that locked
     * re-read. Settling a paid payment again with the same receipt (or none)
     * is `already_resolved`; with another receipt it is refused. A refunded
     * payment cannot be settled. A debit row that is missing or no longer
     * PENDING was changed outside this module, so nothing is settled: the
     * payment is parked as a ledger anomaly, which commits, and every later
     * settle or refund is refused until a developer reconciles it.
     *
     * A provider id other than the one already bound to the payment is
     * refused and never overwrites it; applyResult() binds the id first, so
     * this is the second line behind that.
     *
     * Lock order: the payment, then its biller, then its debit row. The
     * biller's lock serialises settles of one biller, so two payments marked
     * paid at the same moment cannot both pass the duplicate-receipt check;
     * that check is a plain read under it.
     *
     * @param list<BillPaymentDispatchState> $allowedFrom the dispatch states this caller may settle from
     * @param array<string, mixed>           $payload     sanitised detail for the event; never the token
     *
     * @throws NotifyErrorException the payment's state refuses it
     */
    public function settle(
        int $paymentId,
        BillPaymentSettlement $settlement,
        BillPaymentActor $actor,
        array $allowedFrom,
        array $payload = [],
    ): BillPaymentActionResult {
        $receipt               = $this->filled($settlement->receiptReference);
        $token                 = $this->filled($settlement->token);
        $providerTransactionId = $this->filled($settlement->providerTransactionId);
        $note                  = $this->filled($settlement->note);

        return DB::transaction(function () use (
            $paymentId, $receipt, $token, $providerTransactionId, $note, $actor, $allowedFrom, $payload
        ): BillPaymentActionResult {
            $payment = BillPayment::query()->lockForUpdate()->findOrFail($paymentId);

            if ($payment->provider_transaction_id !== null
                && $providerTransactionId         !== null
                && $providerTransactionId         !== (string) $payment->provider_transaction_id) {
                throw new NotifyErrorException(__('The provider reported a different reference for this payment.'));
            }

            if ($payment->status === BillPaymentStatus::Completed) {
                if ($receipt !== null && $receipt !== $payment->receipt_reference) {
                    throw new NotifyErrorException($payment->receipt_reference === null
                        ? __('This payment was already marked paid.')
                        : __('Already marked paid with receipt :ref.', ['ref' => $payment->receipt_reference]));
                }

                return BillPaymentActionResult::alreadyResolved($payment);
            }

            if ($payment->status === BillPaymentStatus::Failed) {
                throw new NotifyErrorException(__('This payment was refunded and cannot be marked paid.'));
            }

            $this->assertNoLedgerAnomaly($payment);

            if (! in_array($payment->dispatchState(), $allowedFrom, true)) {
                throw new NotifyErrorException(__('This payment cannot be marked paid from its current state.'));
            }

            $this->assertSettlementFits($receipt, $providerTransactionId);

            Biller::query()->lockForUpdate()->findOrFail($payment->biller_id);

            $this->assertReceiptIsFree($payment, $receipt);
            $this->assertProviderTransactionIsFree($payment, $providerTransactionId);

            $debit = Transaction::query()->lockForUpdate()->find($payment->transaction_id);

            if ($debit === null || $debit->status !== TrxStatus::PENDING) {
                return $this->recordLedgerAnomaly(
                    $payment,
                    $debit,
                    $debit?->status?->value ?? self::LEDGER_ANOMALY_MISSING,
                    $actor,
                );
            }

            $debit->forceFill([
                'status'  => TrxStatus::COMPLETED,
                'remarks' => $receipt !== null ? __('Receipt: :ref', ['ref' => $receipt]) : null,
            ])->save();

            $fromStatus        = $payment->status?->value;
            $fromDispatchState = (string) $payment->dispatch_state;

            $payment->forceFill(array_filter([
                'status'                  => BillPaymentStatus::Completed,
                'dispatch_state'          => BillPaymentDispatchState::Settled->value,
                'receipt_reference'       => $receipt,
                'token'                   => $token,
                'provider_transaction_id' => $providerTransactionId,
                'admin_note'              => $note,
                'processed_at'            => now(),
                'resolved_by_admin_id'    => $this->adminId($actor),
            ], static fn (mixed $value): bool => $value !== null))->save();

            $this->recordEvent(
                $payment,
                $fromStatus,
                $fromDispatchState,
                $actor,
                $note ?? __('Paid at the biller.'),
                array_merge($payload, array_filter([
                    'receipt_reference'       => $receipt,
                    'provider_transaction_id' => $providerTransactionId,
                ], static fn (?string $value): bool => $value !== null)),
            );

            $this->notifier->completed($payment);

            $user = $payment->user;

            if ($user !== null) {
                DB::afterCommit(static fn () => event(new TransactionUpdated($user)));
            }

            return BillPaymentActionResult::done($payment);
        }, 3);
    }

    /**
     * Give the customer their money back for a bill that was not paid: the
     * full total, fee included, credited to the wallet that paid it, a
     * COMPLETED REFUND row pointing at the debit, and the debit marked FAILED.
     *
     * Exactly once. A payment already refunded is `already_resolved`; a paid
     * one is refused. Under the payment's lock, a REFUND row that already
     * points at the debit (by `trx_reference`) is bound instead of a second
     * credit being made, so a failed payment always names its refund. A debit
     * that is missing, no longer PENDING or no longer for the payment's total
     * parks the payment as a ledger anomaly instead of moving money.
     *
     * `reserved` counts as allowed only while the driver was never called
     * (`submitted_at` is null): after that the biller may have been paid.
     *
     * The credit skips the wallet's operational check on purpose, so a wallet
     * disabled since the payment still gets its money back.
     *
     * Lock order: the payment, then its debit row, then the wallet. The
     * REFUND-exists check is a plain read under the payment's lock, so it takes
     * no gap locks on the unindexed `trx_reference`.
     *
     * @param list<BillPaymentDispatchState> $allowedFrom the dispatch states this caller may refund from
     * @param array<string, mixed>           $payload     sanitised detail for the event; never the token
     *
     * @throws NotifyErrorException the payment's state refuses it
     */
    public function refund(
        int $paymentId,
        string $reason,
        BillPaymentActor $actor,
        array $allowedFrom,
        ?string $note = null,
        array $payload = [],
    ): BillPaymentActionResult {
        $reason = mb_substr($this->filled($reason) ?? __('The biller did not accept the payment.'), 0, 500);
        $note   = $this->filled($note);

        return DB::transaction(function () use ($paymentId, $reason, $note, $actor, $allowedFrom, $payload): BillPaymentActionResult {
            $payment = BillPayment::query()->lockForUpdate()->findOrFail($paymentId);

            if ($payment->status === BillPaymentStatus::Failed) {
                return BillPaymentActionResult::alreadyResolved($payment);
            }

            if ($payment->status === BillPaymentStatus::Completed) {
                throw new NotifyErrorException(__('This payment was marked paid and cannot be refunded here.'));
            }

            $this->assertNoLedgerAnomaly($payment);

            $state = $payment->dispatchState();

            if (! in_array($state, $allowedFrom, true)
                || ($state === BillPaymentDispatchState::Reserved && $payment->submitted_at !== null)) {
                throw new NotifyErrorException(__('This payment cannot be refunded from its current state.'));
            }

            $debit = Transaction::query()->lockForUpdate()->find($payment->transaction_id);

            if ($debit === null || $debit->status !== TrxStatus::PENDING) {
                return $this->recordLedgerAnomaly(
                    $payment,
                    $debit,
                    $debit?->status?->value ?? self::LEDGER_ANOMALY_MISSING,
                    $actor,
                );
            }

            if (DecimalMoney::compare((string) $debit->payable_amount, (string) $payment->total_amount) !== 0) {
                return $this->recordLedgerAnomaly($payment, $debit, self::LEDGER_ANOMALY_AMOUNT_MISMATCH, $actor);
            }

            $refundId = Transaction::query()
                ->where('trx_type', TrxType::REFUND->value)
                ->where('trx_reference', $debit->trx_id)
                ->value('id');

            if ($refundId === null) {
                $wallet = Wallet::query()->lockForUpdate()->findOrFail($payment->wallet_id);

                $wallet = $this->wallets->creditSettlement($wallet, (string) $payment->total_amount);

                $refundId = Transaction::query()->create($this->ledger->refundRow($payment, $debit, $wallet, $reason))->id;
            }

            $debit->forceFill([
                'status'  => TrxStatus::FAILED,
                'remarks' => $reason,
            ])->save();

            $fromStatus        = $payment->status?->value;
            $fromDispatchState = (string) $payment->dispatch_state;

            $payment->forceFill(array_filter([
                'status'                => BillPaymentStatus::Failed,
                'dispatch_state'        => BillPaymentDispatchState::Refunded->value,
                'refund_transaction_id' => (int) $refundId,
                'failure_reason'        => $reason,
                'admin_note'            => $note,
                'processed_at'          => now(),
                'resolved_by_admin_id'  => $this->adminId($actor),
            ], static fn (mixed $value): bool => $value !== null))->save();

            $this->recordEvent(
                $payment,
                $fromStatus,
                $fromDispatchState,
                $actor,
                $note ?? $reason,
                array_merge($payload, [
                    'reason'                => $reason,
                    'refund_transaction_id' => (int) $refundId,
                ]),
            );

            $this->notifier->failed($payment);

            return BillPaymentActionResult::done($payment);
        }, 3);
    }

    /**
     * An admin records that the biller was paid.
     *
     * An operator-fulfilled payment can be marked paid from any open state. A
     * provider-fulfilled one only from needs_review, where the admin has
     * confirmed the delivery at the provider; while the provider may still
     * report back it is the provider's to settle.
     *
     * @throws ValidationException  on `receipt_reference`, or on `token` when the biller issues one
     * @throws NotifyErrorException the payment's state refuses it
     */
    public function markPaid(
        BillPayment $payment,
        Admin $admin,
        string $receiptReference,
        ?string $token = null,
        ?string $note = null,
    ): BillPaymentActionResult {
        $receipt = $this->filled($receiptReference);

        if ($receipt === null) {
            throw ValidationException::withMessages([
                'receipt_reference' => __('Enter the receipt reference.'),
            ]);
        }

        $tokenLabel = $payment->biller()->value('token_label');

        if ($this->filled(is_string($tokenLabel) ? $tokenLabel : null) !== null && $this->filled($token) === null) {
            throw ValidationException::withMessages([
                'token' => __('Enter the :label.', ['label' => $tokenLabel]),
            ]);
        }

        return $this->settle(
            (int) $payment->id,
            new BillPaymentSettlement($receipt, $token, null, $note),
            BillPaymentActor::admin($admin),
            $this->markPaidStates($payment),
        );
    }

    /**
     * The dispatch states an admin may mark this payment paid from: any open
     * one for an operator-fulfilled payment, only needs_review for a
     * provider-fulfilled one.
     *
     * @return list<BillPaymentDispatchState>
     */
    public function markPaidStates(BillPayment $payment): array
    {
        return $payment->fulfilment === BillPaymentFulfilment::Operator
            ? $this->operatorOpenStates()
            : [BillPaymentDispatchState::NeedsReview];
    }

    /**
     * The dispatch states an admin may mark this payment failed (and refund
     * it) from: any open one for an operator-fulfilled payment, only reserved
     * (and never handed to the driver) for a provider-fulfilled one.
     *
     * @return list<BillPaymentDispatchState>
     */
    public function markFailedStates(BillPayment $payment): array
    {
        return $payment->fulfilment === BillPaymentFulfilment::Operator
            ? $this->operatorOpenStates()
            : [BillPaymentDispatchState::Reserved];
    }

    /**
     * Whether "Mark paid" should be offered for this payment as it stands.
     * A display hint for the admin screens: settle() decides again under the
     * payment's lock. A debit row no longer PENDING is the anomaly settle()
     * would park, so it is not offered before the operator pays the biller.
     */
    public function canMarkPaid(BillPayment $payment): bool
    {
        return ! ($payment->status?->isTerminal() ?? false)
            && ! $this->hasLedgerAnomaly($payment)
            && in_array($payment->dispatchState(), $this->markPaidStates($payment), true)
            && $this->debitAnomaly($payment) === null;
    }

    /**
     * Whether "Mark failed and refund" should be offered for this payment as
     * it stands. A display hint for the admin screens: refund() decides again
     * under the payment's lock, with the same submitted_at rule and the same
     * PENDING debit rule.
     */
    public function canMarkFailed(BillPayment $payment): bool
    {
        $state = $payment->dispatchState();

        return ! ($payment->status?->isTerminal() ?? false)
            && ! $this->hasLedgerAnomaly($payment)
            && in_array($state, $this->markFailedStates($payment), true)
            && ! ($state === BillPaymentDispatchState::Reserved && $payment->submitted_at !== null)
            && $this->debitAnomaly($payment) === null;
    }

    /**
     * Whether "Check with provider" should be offered for this payment as it
     * stands: a provider-fulfilled payment whose driver can be asked for a
     * status, either waiting on the provider or in review, or resolved within
     * `recheck_terminal_days`. A display hint for the admin screens:
     * BillPaymentStatusChecker decides again under its own lock.
     */
    public function canCheckWithProvider(BillPayment $payment): bool
    {
        if ($payment->fulfilment !== BillPaymentFulfilment::Provider) {
            return false;
        }

        $eligible = $this->isResolved($payment)
            ? ! BillPaymentPollingSchedule::resolvedTooLongAgo($payment)
            : in_array($payment->dispatchState(), [BillPaymentDispatchState::AwaitingProvider, BillPaymentDispatchState::NeedsReview], true);

        return $eligible && $this->driverChecksStatus($payment);
    }

    /**
     * Whether "Confirm not charged and refund" should be offered for this
     * payment as it stands: a provider-fulfilled payment in review, with its
     * debit still PENDING, that an admin checked with the provider within the
     * last CONFIRM_NOT_PAID_CHECK_MINUTES minutes without a final answer. A
     * display hint for the admin screens: confirmNotPaidAndRefund() and
     * refund() decide again.
     */
    public function canConfirmNotPaid(BillPayment $payment): bool
    {
        return $payment->fulfilment === BillPaymentFulfilment::Provider
            && ! ($payment->status?->isTerminal() ?? false)
            && $payment->dispatchState() === BillPaymentDispatchState::NeedsReview
            && ! $this->hasLedgerAnomaly($payment)
            && $this->debitAnomaly($payment)                   === null
            && $this->recentInconclusiveCheckProblem($payment) === null;
    }

    /**
     * The ledger anomaly settle() and refund() would record for this
     * payment's debit row as it reads now: its status when it is no longer
     * PENDING, LEDGER_ANOMALY_MISSING when there is none, or null while it is
     * still PENDING. Read without a lock, for the admin screens only.
     */
    public function debitAnomaly(BillPayment $payment): ?string
    {
        $status = $payment->transaction?->status;

        if ($status === TrxStatus::PENDING) {
            return null;
        }

        return $status?->value ?? self::LEDGER_ANOMALY_MISSING;
    }

    /**
     * An admin records that the biller was not paid, and the customer is
     * refunded.
     *
     * An operator-fulfilled payment can be refunded from any open state. A
     * provider-fulfilled one only while it is still reserved and the provider
     * was never called. One the provider may have paid (in review) is refused
     * here: it needs the provider's own Failed answer from a status check, or
     * confirmNotPaidAndRefund() after a recent check with the provider.
     *
     * @throws ValidationException  on `reason`
     * @throws NotifyErrorException the payment's state refuses it
     */
    public function markFailed(BillPayment $payment, Admin $admin, string $reason, ?string $note = null): BillPaymentActionResult
    {
        $reason = $this->filled($reason);

        if ($reason === null) {
            throw ValidationException::withMessages([
                'reason' => __('Enter the reason the customer will see.'),
            ]);
        }

        if ($payment->fulfilment !== BillPaymentFulfilment::Operator) {
            /* Only picks the message; refund() decides again under the lock. */
            $currentState = BillPayment::query()->whereKey($payment->id)->value('dispatch_state');

            if ($currentState === BillPaymentDispatchState::NeedsReview->value) {
                throw new NotifyErrorException(__('Check the payment with the provider first, then confirm it was not charged.'));
            }
        }

        return $this->refund((int) $payment->id, $reason, BillPaymentActor::admin($admin), $this->markFailedStates($payment), $note);
    }

    /**
     * An admin confirms that a provider payment in review was not charged at
     * the provider, and the customer is refunded.
     *
     * Only for a provider-fulfilled payment in needs_review, and only after
     * the admin checked it with the provider within the last
     * CONFIRM_NOT_PAID_CHECK_MINUTES minutes and that check gave no Completed
     * or Failed answer (a Failed one refunds on its own). The note says how
     * it was confirmed. A payment already refunded is `already_resolved`.
     * These checks only pick the message; refund() decides the state again
     * under the payment's lock.
     *
     * @throws NotifyErrorException the payment, the last check or the note refuses it
     */
    public function confirmNotPaidAndRefund(BillPayment $payment, Admin $admin, string $reason, string $note): BillPaymentActionResult
    {
        $current = BillPayment::query()->findOrFail($payment->id);

        if ($current->fulfilment !== BillPaymentFulfilment::Provider) {
            throw new NotifyErrorException(__('Only a payment sent to a provider can be confirmed not charged.'));
        }

        if ($current->status === BillPaymentStatus::Failed) {
            return BillPaymentActionResult::alreadyResolved($current);
        }

        if ($current->dispatchState() !== BillPaymentDispatchState::NeedsReview) {
            throw new NotifyErrorException(__('Only a payment in review can be confirmed not charged.'));
        }

        $this->assertRecentInconclusiveCheck($current);

        $reason = $this->filled($reason);

        if ($reason === null) {
            throw new NotifyErrorException(__('Enter the reason the customer will see.'));
        }

        $note = $this->filled($note);

        if ($note === null || mb_strlen($note) < self::CONFIRM_NOT_PAID_NOTE_MIN) {
            throw new NotifyErrorException(__('Write a note of at least :min characters on how you confirmed the payment was not charged.', [
                'min' => self::CONFIRM_NOT_PAID_NOTE_MIN,
            ]));
        }

        return $this->refund(
            (int) $current->id,
            $reason,
            BillPaymentActor::admin($admin),
            [BillPaymentDispatchState::NeedsReview],
            $note,
            ['confirmation' => 'admin_confirmed_not_charged'],
        );
    }

    /**
     * Mark a payment as being worked on by this admin, so two operators do not
     * both pay the same bill at the counter.
     *
     * Advisory only: the status does not change, no money moves, and a claim
     * never stops anyone from resolving the payment. Another admin's claim
     * holds for `bill_payments.claim_ttl_minutes`; within that it can be taken
     * only by choosing to take it over.
     *
     * @throws NotifyErrorException the payment is resolved, or another admin holds the claim
     */
    public function claim(BillPayment $payment, Admin $admin, bool $takeOver = false): BillPaymentActionResult
    {
        return DB::transaction(function () use ($payment, $admin, $takeOver): BillPaymentActionResult {
            $locked = BillPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status?->isTerminal() || $locked->dispatchState()->isTerminal()) {
                throw new NotifyErrorException(__('Already resolved.'));
            }

            $holderId     = $locked->claimed_by_admin_id === null ? null : (int) $locked->claimed_by_admin_id;
            $ttl          = max(0, (int) config('bill_payments.claim_ttl_minutes', 30));
            $isOtherAdmin = $holderId !== null && $holderId !== (int) $admin->getKey();
            $heldByOther  = $isOtherAdmin      && $locked->claimed_at?->greaterThan(now()->subMinutes($ttl)) === true;

            if ($heldByOther && ! $takeOver) {
                throw new NotifyErrorException(__('Claimed by :name :ago.', [
                    'name' => (string) (Admin::query()->whereKey($holderId)->value('name') ?? __('another admin')),
                    'ago'  => $locked->claimed_at->diffForHumans(),
                ]));
            }

            $locked->forceFill([
                'claimed_by_admin_id' => $admin->getKey(),
                'claimed_at'          => now(),
            ])->save();

            $this->recordEvent(
                $locked,
                $locked->status?->value,
                (string) $locked->dispatch_state,
                BillPaymentActor::admin($admin),
                $heldByOther ? __('Taken over.') : __('Claimed.'),
                array_filter([
                    'claimed_by_admin_id' => (int) $admin->getKey(),
                    'previous_admin_id'   => $isOtherAdmin ? $holderId : null,
                ], static fn (?int $value): bool => $value !== null),
            );

            return BillPaymentActionResult::done($locked);
        }, 3);
    }

    /**
     * The payment this user already made with this idempotency key. A plain
     * read: the unique index, not a lock, settles a race. Protected so a test
     * can stand in for it and stage that race.
     */
    protected function findByIdempotencyKey(int $userId, string $key): ?BillPayment
    {
        return BillPayment::query()
            ->where('user_id', $userId)
            ->where('client_idempotency_key', $key)
            ->first();
    }

    /**
     * A fresh BP reference. Protected so a test can force a collision on the
     * unique `reference` index, which must never be mistaken for a replay.
     */
    protected function newReference(): string
    {
        return BillPayment::generateReference();
    }

    /**
     * Return the payment an earlier request with this key made, dispatching it
     * if that request never got as far. The details must be the same ones.
     *
     * @throws IdempotencyConflictException
     */
    private function replay(BillPayment $payment, string $hash): SubmittedBillPayment
    {
        $storedHash = $payment->client_request_hash;

        if (! is_string($storedHash) || ! hash_equals($storedHash, $hash)) {
            throw new IdempotencyConflictException(__('This request was already submitted with different details.'));
        }

        return new SubmittedBillPayment($this->dispatch($payment), true);
    }

    /**
     * Move a payment out of `submitting`, under its lock. A payment that is
     * no longer submitting was already moved by someone else and is left as
     * it is, with no audit row, so a status check that hears nothing new
     * writes nothing.
     *
     * Moving to awaiting_provider schedules the first status check for
     * bill-payment:reconcile, and keeps the provider's final-status time
     * (`final_status_at` in the answer's payload) when it is a parseable,
     * future time, capped at the reconcile deadline.
     */
    private function leaveSubmitting(
        int $paymentId,
        BillPaymentDispatchState $target,
        BillPaymentActor $actor,
        BillPaymentResult $result,
        ?string $reviewReason = null,
    ): BillPaymentActionResult {
        return DB::transaction(function () use ($paymentId, $target, $actor, $result, $reviewReason): BillPaymentActionResult {
            $payment = BillPayment::query()->lockForUpdate()->findOrFail($paymentId);

            if ($payment->dispatchState() !== BillPaymentDispatchState::Submitting) {
                return BillPaymentActionResult::alreadyResolved($payment);
            }

            $fromStatus        = $payment->status?->value;
            $fromDispatchState = (string) $payment->dispatch_state;
            $payload           = $this->resultPayload($result);
            $changes           = [
                'status'         => BillPaymentStatus::Processing,
                'dispatch_state' => $target->value,
            ];

            if ($reviewReason !== null) {
                $changes['metadata']      = $this->withReviewReason($payment, $reviewReason);
                $payload['review_reason'] = $reviewReason;
            }

            if ($target === BillPaymentDispatchState::AwaitingProvider) {
                $changes['provider_next_check_at']   = BillPaymentPollingSchedule::nextCheckAt(0);
                $changes['provider_final_status_at'] = BillPaymentPollingSchedule::finalStatusAt(
                    $result->payload['final_status_at'] ?? null,
                    $payment->submitted_at,
                );
            }

            $payment->forceFill($changes)->save();

            $this->recordEvent($payment, $fromStatus, $fromDispatchState, $actor, $result->message, $payload);

            return BillPaymentActionResult::done($payment);
        }, 3);
    }

    /**
     * Append one row to the payment's audit trail, inside the caller's
     * transaction. The `to_` side is read from the payment as it now stands.
     * `$externalEventId` is the provider's id for the notice the row records,
     * unique per payment.
     *
     * @param array<string, mixed> $payload sanitised detail; never the token
     */
    private function recordEvent(
        BillPayment $payment,
        ?string $fromStatus,
        ?string $fromDispatchState,
        BillPaymentActor $actor,
        ?string $note = null,
        array $payload = [],
        ?string $externalEventId = null,
    ): BillPaymentEvent {
        $note = trim((string) $note);

        return BillPaymentEvent::query()->create([
            'external_event_id'   => $externalEventId === null ? null : mb_substr($externalEventId, 0, 191),
            'bill_payment_id'     => $payment->id,
            'from_status'         => $fromStatus,
            'to_status'           => $payment->status?->value,
            'from_dispatch_state' => $fromDispatchState,
            'to_dispatch_state'   => (string) $payment->dispatch_state,
            'actor_type'          => $actor->type,
            'actor_id'            => $actor->id,
            'note'                => $note    === '' ? null : mb_substr($note, 0, 500),
            'payload'             => $payload === [] ? null : $payload,
        ]);
    }

    /**
     * What identifies a request, for comparing a replay against the request
     * that first used its key. A malformed amount is hashed as typed rather
     * than normalised, because this runs before anything is validated.
     */
    private function requestHash(User $user, BillPaymentSubmission $submission, string $number): string
    {
        $amount = trim((string) $submission->amount);

        if ($amount !== '' && is_numeric($amount)) {
            $amount = DecimalMoney::normalize($amount, DecimalMoney::CALCULATION_SCALE);
        }

        return hash('sha256', implode('|', [
            $user->id,
            $submission->walletId,
            $submission->billerId,
            $submission->billerItemId ?? '',
            $number,
            $amount,
        ]));
    }

    private function idempotencyKey(BillPaymentSubmission $submission): ?string
    {
        $key = trim((string) $submission->idempotencyKey);

        return $key === '' ? null : $key;
    }

    /**
     * Refuse a submission whose customer saw a different total from the one
     * that would now be charged.
     *
     * @throws ValidationException on `expected_total`
     */
    private function assertExpectedTotal(?string $expectedTotal, BillPaymentQuote $quote): void
    {
        $expected = trim((string) $expectedTotal);

        if ($expected === '') {
            return;
        }

        if (is_numeric($expected)
            && DecimalMoney::compare(DecimalMoney::normalize($expected, DecimalMoney::CALCULATION_SCALE), $quote->total) === 0) {
            return;
        }

        throw ValidationException::withMessages([
            'expected_total' => __('The total changed to :total. Review it and pay again.', [
                'total' => Money::format($quote->total, $quote->currency),
            ]),
        ]);
    }

    /**
     * Ask the provider who owns the customer number, before anything is
     * debited, and return the account name to keep on the payment.
     *
     * A definite "no such account" is refused on `customer_number`. An
     * unavailable lookup is refused only under the provider's `required`
     * policy; under `advisory` the payment goes ahead, and a wrong number is
     * then refused by the provider at pay and refunded. A biller that is not
     * looked up at all answers with no name.
     *
     * @throws ValidationException  on `customer_number`
     * @throws NotifyErrorException the lookup is required and unavailable
     */
    private function assertCustomerAccount(Biller $biller, ?BillerItem $item, string $number): ?string
    {
        // Optional in the constructor so a subclass built with the original
        // six collaborators still works; the container always passes it.
        $customers = $this->customers ?? app(BillCustomerVerifier::class);
        $lookup    = $customers->verify($biller, $item, $number);

        return match ($lookup->status) {
            BillCustomerLookupStatus::NotFound => throw ValidationException::withMessages([
                'customer_number' => __('The provider could not find this account.'),
            ]),
            BillCustomerLookupStatus::Unavailable => $customers->policyFor($biller->provider) === 'required'
                ? throw new NotifyErrorException(__('We could not confirm this account right now. Try again in a minute.'))
                : null,
            BillCustomerLookupStatus::Verified => $lookup->customerName,
        };
    }

    /**
     * Who will pay the biller, fixed when the money is reserved. A provider
     * whose driver is no longer registered cannot take payments, so nothing is
     * debited for it.
     *
     * @throws NotifyErrorException
     */
    private function fulfilmentFor(Biller $biller): BillPaymentFulfilment
    {
        try {
            $driver = $this->providers->driverFor($biller->provider);
        } catch (InvalidArgumentException) {
            Log::warning('Bill payment provider driver is not available', [
                'biller_id'                => $biller->id,
                'bill_payment_provider_id' => $biller->bill_payment_provider_id,
            ]);

            throw new NotifyErrorException(__('This biller cannot be paid right now.'));
        }

        return $driver instanceof FulfilledByOperator
            ? BillPaymentFulfilment::Operator
            : BillPaymentFulfilment::Provider;
    }

    /**
     * Whether the payment's driver can be asked later what became of it. A
     * driver that cannot even be built cannot be asked.
     */
    private function driverChecksStatus(BillPayment $payment): bool
    {
        if ($payment->provider === null) {
            return false;
        }

        try {
            return $this->providers->driverFor($payment->provider) instanceof ChecksBillPaymentStatus;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Whether a Completed answer delivered exactly what was billed. A figure
     * the provider left out is not a mismatch; a figure that is not a number
     * is.
     */
    private function matchesBill(BillPayment $payment, BillPaymentResult $result): bool
    {
        if ($result->amount !== null) {
            $delivered = trim($result->amount);

            if (! is_numeric($delivered) || DecimalMoney::compare($delivered, (string) $payment->bill_amount) !== 0) {
                return false;
            }
        }

        if ($result->currency !== null) {
            return strcasecmp(trim($result->currency), (string) $payment->bill_currency) === 0;
        }

        return true;
    }

    /**
     * The audit detail of a fulfiller's answer. The result's payload is
     * already sanitised, and the token is deliberately left out.
     *
     * @return array<string, mixed>
     */
    private function resultPayload(BillPaymentResult $result): array
    {
        return array_filter([
            'outcome'                 => $result->outcome->value,
            'provider_transaction_id' => $result->providerTransactionId,
            'receipt_reference'       => $result->receiptReference,
            'amount'                  => $result->amount,
            'currency'                => $result->currency,
            'response'                => $result->payload === [] ? null : $result->payload,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function withReviewReason(BillPayment $payment, string $reason): array
    {
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];

        return array_merge($metadata, ['review_reason' => $reason]);
    }

    /**
     * Settle or refund on a fulfiller's say-so, or park the payment for a
     * person when its recorded state refuses that.
     *
     * @param array<string, mixed>               $payload
     * @param Closure(): BillPaymentActionResult $resolve
     */
    private function resolveOrPark(
        int $paymentId,
        BillPaymentActor $actor,
        BillPaymentResult $result,
        array $payload,
        Closure $resolve,
    ): BillPaymentActionResult {
        try {
            return $resolve();
        } catch (NotifyErrorException $exception) {
            Log::warning('Bill payment provider result could not be applied', [
                'bill_payment_id' => $paymentId,
                'outcome'         => $result->outcome->value,
            ]);

            return $this->parkForReview($paymentId, self::REVIEW_PROVIDER_RESULT_REFUSED, $actor, $exception->getMessage(), $payload);
        }
    }

    /** Whether the payment is settled or refunded. */
    private function isResolved(BillPayment $payment): bool
    {
        return ($payment->status?->isTerminal() ?? false) || $payment->dispatchState()->isTerminal();
    }

    /**
     * The contradiction a provider's answer makes with a resolved payment: a
     * Failed answer for a settled one, a Completed answer for a refunded one.
     * Null for anything else.
     */
    private function contradictionKind(BillPayment $payment, BillPaymentResult $result): ?string
    {
        return match (true) {
            $payment->status === BillPaymentStatus::Completed && $result->outcome === BillPaymentOutcome::Failed    => self::CONTRADICTION_REVERSED_AFTER_SETTLE,
            $payment->status === BillPaymentStatus::Failed    && $result->outcome === BillPaymentOutcome::Completed => self::CONTRADICTION_PAID_AFTER_REFUND,
            default                                                                                                 => null,
        };
    }

    /**
     * Bind the provider's id to an open payment, once.
     *
     * Under the payment's lock: no id yet, one that fits its column and that
     * no other payment of the same provider holds, is written with an audit
     * row; the same id again is nothing to do. A different id from the one
     * bound parks the payment (reference changed), as does an id another
     * payment of this provider holds (reference conflict) or one too long to
     * store. Null means carry on applying the answer; otherwise the park's
     * result is the answer. Never throws on the dispatch path: a unique-index
     * clash with a concurrent writer is a conflict too.
     *
     * @param array<string, mixed> $payload sanitised detail for the event; never the token
     */
    private function bindProviderTransaction(int $paymentId, string $reference, BillPaymentActor $actor, array $payload): ?BillPaymentActionResult
    {
        try {
            return DB::transaction(function () use ($paymentId, $reference, $actor, $payload): ?BillPaymentActionResult {
                $payment = BillPayment::query()->lockForUpdate()->findOrFail($paymentId);

                if ($payment->status?->isTerminal() || $payment->dispatchState()->isTerminal()) {
                    return null;
                }

                $bound = $this->filled($payment->provider_transaction_id === null ? null : (string) $payment->provider_transaction_id);

                if ($bound === $reference) {
                    return null;
                }

                if ($bound !== null) {
                    return $this->parkForReview(
                        $paymentId,
                        self::REVIEW_PROVIDER_REFERENCE_CHANGED,
                        $actor,
                        __('The provider reported a different reference for this payment.'),
                        $payload,
                    );
                }

                try {
                    $this->assertSettlementFits(null, $reference);
                } catch (NotifyErrorException $exception) {
                    return $this->parkForReview($paymentId, self::REVIEW_PROVIDER_RESULT_REFUSED, $actor, $exception->getMessage(), $payload);
                }

                $holder = BillPayment::query()
                    ->where('bill_payment_provider_id', $payment->bill_payment_provider_id)
                    ->where('provider_transaction_id', $reference)
                    ->whereKeyNot($payment->id)
                    ->value('reference');

                if ($holder !== null) {
                    return $this->parkForReview(
                        $paymentId,
                        self::REVIEW_PROVIDER_REFERENCE_CONFLICT,
                        $actor,
                        __('Provider transaction :id is already recorded on payment :other.', ['id' => $reference, 'other' => $holder]),
                        $payload,
                    );
                }

                $fromStatus        = $payment->status?->value;
                $fromDispatchState = (string) $payment->dispatch_state;

                $payment->forceFill(['provider_transaction_id' => $reference])->save();

                $this->recordEvent(
                    $payment,
                    $fromStatus,
                    $fromDispatchState,
                    $actor,
                    __('Provider reference recorded.'),
                    ['provider_transaction_id' => $reference],
                );

                return null;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            Log::warning('Bill payment provider reference is held by another payment', [
                'bill_payment_id' => $paymentId,
            ]);

            return $this->parkForReview(
                $paymentId,
                self::REVIEW_PROVIDER_REFERENCE_CONFLICT,
                $actor,
                __('Another payment of this provider already holds the reference the provider reported.'),
                $payload,
            );
        }
    }

    /**
     * Flag a provider answer that contradicts a resolved payment, once, and
     * move no money: a person decides what to do about it.
     *
     * Under the payment's lock: a payment already flagged is
     * `already_resolved`; otherwise the contradiction is written to
     * `metadata.provider_contradiction`, one audit row records it and a
     * warning with ids only is logged.
     */
    private function flagProviderContradiction(int $paymentId, string $kind, BillPaymentResult $result, BillPaymentActor $actor): BillPaymentActionResult
    {
        return DB::transaction(function () use ($paymentId, $kind, $result, $actor): BillPaymentActionResult {
            $payment  = BillPayment::query()->lockForUpdate()->findOrFail($paymentId);
            $metadata = is_array($payment->metadata) ? $payment->metadata : [];

            if (($metadata['provider_contradiction'] ?? null) !== null) {
                return BillPaymentActionResult::alreadyResolved($payment);
            }

            $message = $this->filled($result->message);

            $payment->forceFill([
                'metadata' => array_merge($metadata, [
                    'provider_contradiction' => [
                        'kind'    => $kind,
                        'at'      => now()->toIso8601String(),
                        'message' => $message === null ? null : mb_substr($message, 0, 500),
                    ],
                ]),
            ])->save();

            $this->recordEvent(
                $payment,
                $payment->status?->value,
                (string) $payment->dispatch_state,
                $actor,
                $kind === self::CONTRADICTION_REVERSED_AFTER_SETTLE
                    ? __('The provider reported this payment as reversed after it was settled. No money was moved.')
                    : __('The provider reported this payment as paid after it was refunded. No money was moved.'),
                array_merge($this->resultPayload($result), ['provider_contradiction' => $kind]),
            );

            Log::warning('Bill payment provider contradicts a resolved payment', [
                'bill_payment_id'          => (int) $payment->id,
                'bill_payment_provider_id' => $payment->bill_payment_provider_id === null ? null : (int) $payment->bill_payment_provider_id,
                'kind'                     => $kind,
            ]);

            return BillPaymentActionResult::anomaly($payment);
        }, 3);
    }

    /**
     * The admin checked this payment with the provider recently enough, and
     * that check gave no final answer.
     *
     * @throws NotifyErrorException
     */
    private function assertRecentInconclusiveCheck(BillPayment $payment): void
    {
        $problem = $this->recentInconclusiveCheckProblem($payment);

        if ($problem !== null) {
            throw new NotifyErrorException($problem);
        }
    }

    /**
     * Why the last check with the provider does not allow "confirm not
     * charged": no check, one older than CONFIRM_NOT_PAID_CHECK_MINUTES, or
     * one that gave a final answer (an admin's check, or a Completed or
     * Failed answer from any check that left the payment open). Null when it
     * allows it.
     */
    private function recentInconclusiveCheckProblem(BillPayment $payment): ?string
    {
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];
        $check    = $metadata['last_provider_check'] ?? null;
        $checkAt  = null;

        if (is_array($check) && is_string($check['at'] ?? null)) {
            try {
                $checkAt = CarbonImmutable::parse($check['at']);
            } catch (Throwable) {
                $checkAt = null;
            }
        }

        if ($checkAt === null) {
            return __('Check the payment with the provider first, then confirm it was not charged.');
        }

        if ($checkAt->lessThan(now()->subMinutes(self::CONFIRM_NOT_PAID_CHECK_MINUTES))) {
            return __('The last check with the provider is more than :minutes minutes old. Check again, then confirm.', [
                'minutes' => self::CONFIRM_NOT_PAID_CHECK_MINUTES,
            ]);
        }

        $outcome = $check['outcome'] ?? null;

        if (! in_array($outcome, [BillPaymentOutcome::Unknown->value, BillPaymentOutcome::Pending->value], true)) {
            return __('The provider\'s last answer was :outcome, so this payment cannot be confirmed not charged.', [
                'outcome' => is_scalar($outcome) ? (string) $outcome : __('unclear'),
            ]);
        }

        return null;
    }

    /** What a Completed answer records on the payment. */
    private function settlementFrom(BillPaymentResult $result): BillPaymentSettlement
    {
        return new BillPaymentSettlement(
            receiptReference: $result->receiptReference,
            token: $result->token,
            providerTransactionId: $result->providerTransactionId,
        );
    }

    /**
     * Park a payment whose debit row was changed outside this module, and
     * move no money. It commits, so the flag survives, and from then on every
     * settle or refund is refused until a developer reconciles the ledger.
     * The log carries ids and the status found, nothing about the customer.
     */
    private function recordLedgerAnomaly(
        BillPayment $payment,
        ?Transaction $debit,
        string $anomaly,
        BillPaymentActor $actor,
    ): BillPaymentActionResult {
        $fromStatus        = $payment->status?->value;
        $fromDispatchState = (string) $payment->dispatch_state;
        $metadata          = is_array($payment->metadata) ? $payment->metadata : [];

        $payment->forceFill([
            'status'         => BillPaymentStatus::Processing,
            'dispatch_state' => BillPaymentDispatchState::NeedsReview->value,
            'metadata'       => array_merge($metadata, ['ledger_anomaly' => $anomaly]),
        ])->save();

        $this->recordEvent(
            $payment,
            $fromStatus,
            $fromDispatchState,
            $actor,
            __('The ledger entry was changed outside Bill payments.'),
            ['ledger_anomaly' => $anomaly],
        );

        Log::warning('Bill payment ledger anomaly', [
            'bill_payment_id' => (int) $payment->id,
            'transaction_id'  => $payment->transaction_id === null ? null : (int) $payment->transaction_id,
            'status'          => $debit?->status?->value ?? self::LEDGER_ANOMALY_MISSING,
            'anomaly'         => $anomaly,
        ]);

        return BillPaymentActionResult::anomaly($payment);
    }

    /**
     * @throws NotifyErrorException the payment was parked as a ledger anomaly
     */
    private function assertNoLedgerAnomaly(BillPayment $payment): void
    {
        if ($this->hasLedgerAnomaly($payment)) {
            throw new NotifyErrorException(__('This payment\'s ledger entry was changed outside Bill payments. It needs a developer to reconcile it.'));
        }
    }

    private function hasLedgerAnomaly(BillPayment $payment): bool
    {
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];

        return ($metadata['ledger_anomaly'] ?? null) !== null;
    }

    /**
     * Every state an operator-fulfilled payment can still be resolved from.
     *
     * @return list<BillPaymentDispatchState>
     */
    private function operatorOpenStates(): array
    {
        return [
            BillPaymentDispatchState::Reserved,
            BillPaymentDispatchState::Submitting,
            BillPaymentDispatchState::AwaitingOperator,
            BillPaymentDispatchState::NeedsReview,
        ];
    }

    /**
     * The receipt and the provider's id each fit their column, so a long one
     * is refused rather than cut short or failed by the database mid-write.
     *
     * @throws NotifyErrorException
     */
    private function assertSettlementFits(?string $receipt, ?string $providerTransactionId): void
    {
        if ($receipt !== null && mb_strlen($receipt) > 128) {
            throw new NotifyErrorException(__('The receipt reference may not be longer than :max characters.', ['max' => 128]));
        }

        if ($providerTransactionId !== null && mb_strlen($providerTransactionId) > 128) {
            throw new NotifyErrorException(__('The provider transaction id may not be longer than :max characters.', ['max' => 128]));
        }
    }

    /**
     * A receipt another payment of the same biller already carries is a
     * pasted duplicate. Different billers may share receipt numbers.
     *
     * @throws NotifyErrorException
     */
    private function assertReceiptIsFree(BillPayment $payment, ?string $receipt): void
    {
        if ($receipt === null) {
            return;
        }

        $other = BillPayment::query()
            ->where('biller_id', $payment->biller_id)
            ->where('receipt_reference', $receipt)
            ->whereKeyNot($payment->id)
            ->value('reference');

        if ($other !== null) {
            throw new NotifyErrorException(__('Receipt :ref is already recorded on payment :other.', [
                'ref'   => $receipt,
                'other' => $other,
            ]));
        }
    }

    /**
     * A provider's id names one payment at that provider, which the unique
     * (provider, provider_transaction_id) index also insists on. Checked
     * first so a clash is refused with a message, not a failed write.
     *
     * @throws NotifyErrorException
     */
    private function assertProviderTransactionIsFree(BillPayment $payment, ?string $providerTransactionId): void
    {
        if ($providerTransactionId === null) {
            return;
        }

        $other = BillPayment::query()
            ->where('bill_payment_provider_id', $payment->bill_payment_provider_id)
            ->where('provider_transaction_id', $providerTransactionId)
            ->whereKeyNot($payment->id)
            ->value('reference');

        if ($other !== null) {
            throw new NotifyErrorException(__('Provider transaction :id is already recorded on payment :other.', [
                'id'    => $providerTransactionId,
                'other' => $other,
            ]));
        }
    }

    /** The admin who resolved the payment, when an admin did. */
    private function adminId(BillPaymentActor $actor): ?int
    {
        return $actor->type === BillPaymentActorType::Admin ? $actor->id : null;
    }

    /** A trimmed string, or null when nothing is left. */
    private function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
