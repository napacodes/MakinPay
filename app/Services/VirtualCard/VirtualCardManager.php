<?php

declare(strict_types=1);

namespace App\Services\VirtualCard;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Enums\VirtualCard\VirtualCardFeeOperation;
use App\Enums\VirtualCard\VirtualCardRequestStatus;
use App\Enums\VirtualCard\VirtualCardStatus;
use App\Enums\VirtualCard\VirtualCardTopupDispatchState;
use App\Enums\VirtualCard\VirtualCardTopupStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\NotifyErrorException;
use App\Exceptions\ProviderOutcomeUnknownException;
use App\Models\Currency;
use App\Models\Transaction as TransactionModel;
use App\Models\VirtualCard;
use App\Models\VirtualCardFeeSetting;
use App\Models\VirtualCardProvider;
use App\Models\VirtualCardRequest;
use App\Models\VirtualCardTopup;
use App\Models\Wallet;
use App\Services\VirtualCard\Drivers\SupportsFundingMinimum;
use App\Services\VirtualCard\Drivers\SupportsIdempotentTopUp;
use App\Services\VirtualCard\Drivers\VirtualCardProviderInterface;
use App\Services\WalletService;
use App\Support\DecimalMoney;
use App\Support\FeeCalculator;
use App\Support\Money;
use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class VirtualCardManager
{
    /**
     * What a driver that funds the initial load after issuing reports as
     * `initial_load`: the provider loaded the card, refused to, or never
     * answered. A driver whose provider loads the card while issuing it
     * reports nothing.
     */
    public const INITIAL_LOAD_FUNDED = 'funded';

    public const INITIAL_LOAD_REFUSED = 'refused';

    public const INITIAL_LOAD_UNCONFIRMED = 'unconfirmed';

    public function __construct(
        protected VirtualCardProviderFactory $providerFactory,
        protected WalletService $wallets,
    ) {}

    /**
     * Issue a virtual card using the selected provider.
     *
     * @throws Exception
     */
    public function issueProviderCard(VirtualCardRequest $request, string $providerCode): VirtualCard
    {
        $request->loadMissing('wallet.currency');
        $this->wallets->assertOperational($request->wallet);

        $providerModel = VirtualCardProvider::query()
            ->where('code', $providerCode)
            ->first();

        if ($providerModel && (int) $request->provider_id !== (int) $providerModel->id) {
            $request->forceFill(['provider_id' => $providerModel->id]);
        }

        $provider = $this->providerFactory->getProvider($providerCode);

        // The initial load is real money the provider puts on the card, so it
        // leaves the request's wallet before the provider is asked — locked,
        // and with its own ledger row. It used to leave nowhere: the card was
        // prefunded from the operator's provider float while the customer had
        // paid only the issuing fee.
        $loadTransaction = $this->debitInitialLoad($request, $providerModel);

        try {
            $cardInfo = $provider->issueCard($request);
        } catch (Throwable $e) {
            // A refusal the provider actually sent means nothing was issued and
            // the load can go back. A call that never completed means a card
            // may exist with the load on it: the debit stays, the request stays
            // open, and the operator checks the provider before approving again
            // — at which point the same debit is reused rather than repeated.
            if ($this->isAmbiguousProviderFailure($e)) {
                $this->recordUnsettledIssuance($request, $e);

                throw new ProviderOutcomeUnknownException(__('The card provider did not answer the issuance request (:reason). The fee and initial load remain debited and will be reused when this request is approved again; confirm at the provider whether a card was created before doing so.', [
                    'reason' => $e->getMessage(),
                ]), 0, $e);
            }

            $this->refundInitialLoad($request, $loadTransaction, $e->getMessage());

            throw $e;
        }

        // Carry the user-chosen design forward into the issued card so the
        // dashboard can honour it instead of falling back to the rotating
        // theme wheel keyed on card index.
        $cardMeta          = is_array($cardInfo['meta'] ?? null) ? $cardInfo['meta'] : [];
        $cardMeta['theme'] = $request->theme ?: ($cardMeta['theme'] ?? null);

        // Async-issuing providers (Bitnob) can return status=`failed`
        // when the card was allocated an id but never provisioned. We
        // still save the row so the dashboard has a record the user
        // can inspect and retry from. `last4`, `expiry_month`, and
        // `expiry_year` may all be null on failed rows — the schema
        // was relaxed in the matching migration to allow this.
        $providerStatus = $cardInfo['status'] ?? 'pending';
        $isFailedIssue  = $providerStatus === 'failed';
        $providerId     = $providerModel?->id ?: $request->provider_id;

        // Most providers put the load on the card as part of issuing it. A
        // driver whose provider cannot (BSICARDS) issues first and funds
        // second, and reports that second call apart: a refusal means the card
        // exists without the load, so the load goes back; no answer means the
        // load may be on the card, so the debit stays for an operator to
        // settle. A driver that reports nothing keeps today's behaviour.
        $initialLoadOutcome = $loadTransaction && ! $isFailedIssue
            ? ($cardInfo['initial_load'] ?? null)
            : null;
        $initialLoadReason = trim((string) ($cardInfo['initial_load_reason'] ?? ''));

        if ($isFailedIssue) {
            $this->refundInitialLoad(
                $request,
                $loadTransaction,
                (string) ($cardMeta['failure_reason'] ?? __('Provider declined the card creation.'))
            );
        } elseif ($initialLoadOutcome === self::INITIAL_LOAD_REFUSED) {
            $reason = $initialLoadReason !== '' ? $initialLoadReason : __('The provider refused to load the card.');

            $this->refundInitialLoad($request, $loadTransaction, $reason);

            $cardMeta['balance']      = Money::quantize((string) ($cardMeta['balance'] ?? '0'), $providerModel?->cardCurrency() ?? $request->wallet?->currency?->code);
            $cardMeta['initial_load'] = ['status' => self::INITIAL_LOAD_REFUSED, 'reason' => $reason];
        } elseif ($initialLoadOutcome === self::INITIAL_LOAD_UNCONFIRMED) {
            $reason = $initialLoadReason !== '' ? $initialLoadReason : __('The provider did not answer the funding request.');

            $cardMeta['initial_load'] = [
                'status' => self::INITIAL_LOAD_UNCONFIRMED,
                'reason' => $reason,
                'amount' => $request->providerLoadDecimal(),
            ];

            $this->recordUnconfirmedInitialLoad($request, $reason);
        } elseif ($initialLoadOutcome === self::INITIAL_LOAD_FUNDED) {
            // Written down so a later "failed" from the provider is never
            // taken as licence to hand back a load that reached the card.
            $cardMeta['initial_load'] = ['status' => self::INITIAL_LOAD_FUNDED];

            if (! array_key_exists('balance', $cardMeta)) {
                $cardMeta['balance'] = Money::quantize(
                    $request->providerLoadDecimal(),
                    $providerModel?->cardCurrency() ?? $request->wallet?->currency?->code,
                );
            }
        } elseif ($loadTransaction && ! array_key_exists('balance', $cardMeta)) {
            // A decimal string, like every other write of the card's balance.
            $cardMeta['balance'] = Money::quantize(
                $request->providerLoadDecimal(),
                $providerModel?->cardCurrency() ?? $request->wallet?->currency?->code,
            );
        }

        try {
            $card = DB::transaction(function () use ($request, $providerId, $cardInfo, $providerStatus, $cardMeta, $isFailedIssue, $loadTransaction, $initialLoadOutcome): VirtualCard {
                $card = VirtualCard::create([
                    'virtual_card_request_id' => $request->id,
                    'wallet_id'               => $request->wallet_id,
                    'user_id'                 => $request->user_id,
                    'provider_id'             => $providerId,
                    'network'                 => $request->network,
                    'provider_card_id'        => $cardInfo['id'],
                    'last4'                   => $cardInfo['last4'] ?: null,
                    'brand'                   => $cardInfo['brand'],
                    'expiry_month'            => $cardInfo['expiry_month'] ?: null,
                    'expiry_year'             => $cardInfo['expiry_year'] ?: null,
                    'status'                  => $providerStatus,
                    'meta'                    => $cardMeta,
                ]);

                $request->update([
                    'status' => $isFailedIssue
                        ? VirtualCardRequestStatus::Failed
                        : VirtualCardRequestStatus::Issued,
                    'provider_id'        => $providerId,
                    'provider_issued_at' => now(),
                    'provider_response'  => $cardInfo['raw'] ?? null,
                ]);

                if ($loadTransaction && ! $isFailedIssue && $initialLoadOutcome !== self::INITIAL_LOAD_REFUSED) {
                    $loadTransaction->update([
                        'trx_data' => array_merge($loadTransaction->trx_data ?? [], ['virtual_card_id' => $card->id]),
                    ]);
                }

                return $card;
            }, 3);
        } catch (Throwable $e) {
            // The provider issued the card and the local write failed. Nothing
            // is refunded: the card exists and the load is on it.
            $this->recordUnsettledIssuance($request, $e);

            throw new ProviderOutcomeUnknownException(__('The provider issued the card but it could not be recorded locally (:reason). Nothing has been refunded; approve the request again once the cause is fixed.', [
                'reason' => $e->getMessage(),
            ]), 0, $e);
        }

        // We don't throw on `failed` here — the controller wraps this
        // call in a DB transaction and catches Throwable. Throwing
        // would roll back the failed-card row we just saved (defeating
        // the whole point of the row). Instead, the caller checks
        // `$card->status === Failed` and chooses the toast accordingly.
        return $card;
    }

    /**
     * Whether a driver failure means the provider never answered.
     *
     * Transport failures and gateway error pages are ambiguous; anything else
     * is a message the provider sent, so nothing was issued or funded.
     */
    private function isAmbiguousProviderFailure(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ProviderOutcomeUnknownException || $current instanceof ConnectException || $current instanceof ConnectionException) {
                return true;
            }

            if ($current instanceof RequestException && ($current->getResponse() === null || $current->getResponse()->getStatusCode() >= 500)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Leave a visible note on a request whose issuance outcome is unknown.
     */
    private function recordUnsettledIssuance(VirtualCardRequest $request, Throwable $e): void
    {
        Log::error('Virtual card issuance outcome unknown', [
            'virtual_card_request_id' => $request->id,
            'provider_id'             => $request->provider_id,
            'error'                   => $e->getMessage(),
        ]);

        try {
            $request->forceFill([
                'admin_note' => trim((string) $request->admin_note.' '.__('[:time] Provider did not confirm issuance: :reason. Fee and initial load kept; verify at the provider before approving again.', [
                    'time'   => now()->toDateTimeString(),
                    'reason' => Str::limit($e->getMessage(), 160),
                ])),
            ])->save();
        } catch (Throwable $noteFailure) {
            report($noteFailure);
        }
    }

    /**
     * Leave a visible note on a request whose card was issued but whose
     * initial load the provider never confirmed.
     *
     * The load stays debited and attached to the card: refunding a load that
     * reached the card would pay the customer twice. The operator checks the
     * card's balance at the provider and settles it by hand.
     */
    private function recordUnconfirmedInitialLoad(VirtualCardRequest $request, string $reason): void
    {
        Log::warning('Virtual card initial load outcome unknown', [
            'virtual_card_request_id' => $request->id,
            'provider_id'             => $request->provider_id,
        ]);

        try {
            $request->forceFill([
                'admin_note' => trim((string) $request->admin_note.' '.__('[:time] The card was issued but the provider did not confirm the initial load of :amount (:reason). The load stays debited; check the card balance at the provider and settle it by hand.', [
                    'time'   => now()->toDateTimeString(),
                    'amount' => $request->providerLoadDecimal(),
                    'reason' => Str::limit($reason, 160),
                ])),
            ])->save();
        } catch (Throwable $noteFailure) {
            report($noteFailure);
        }
    }

    /**
     * Leave a visible note, once, on a request whose card the provider now
     * reports failed after its initial load was sent.
     */
    private function noteFailedCardWithLoadSent(VirtualCardRequest $request, string $reason): void
    {
        $marker = __('Initial load not returned automatically');

        if (str_contains((string) $request->admin_note, $marker)) {
            return;
        }

        Log::warning('Virtual card reported failed after its initial load was sent', [
            'virtual_card_request_id' => $request->id,
            'provider_id'             => $request->provider_id,
        ]);

        $request->forceFill([
            'admin_note' => trim((string) $request->admin_note.' '.__('[:time] :marker: the provider reports the card failed (:reason) after the load was sent to it. Confirm with the provider whether the load came back to your account before refunding the customer.', [
                'time'   => now()->toDateTimeString(),
                'marker' => $marker,
                'reason' => Str::limit($reason !== '' ? $reason : __('no reason given'), 160),
            ])),
        ])->save();
    }

    /**
     * Take the initial load out of the request's wallet and write it down.
     *
     * Under a row lock, and with `tryDebit`'s own `balance >= amount` in the
     * WHERE clause, so two approvals of two requests on one wallet cannot both
     * pass a stale balance check. The amount is quantized to the wallet's
     * currency first — the figure debited is one the currency can hold — and
     * is sent to the provider unchanged, in the wallet's currency, which is
     * the contract every provider driver already relies on for top-ups.
     *
     * Returns null when the request carries no load, so a legacy request from
     * before the amount was required issues exactly as it always did.
     *
     * @throws NotifyErrorException when the wallet cannot cover the load
     */
    private function debitInitialLoad(VirtualCardRequest $request, ?VirtualCardProvider $providerModel): ?TransactionModel
    {
        $wallet       = $request->wallet;
        $currencyCode = $wallet?->currency?->code;
        $amount       = Money::quantize((string) ($request->initial_load_amount ?? 0), $currencyCode);

        if (! $wallet || DecimalMoney::compare($amount, 0) <= 0) {
            return null;
        }

        // Price the load in the card's currency before anything moves, so the
        // provider is handed a USD figure for a USD card while the wallet is
        // debited in its own currency — and both, plus the rate, are written
        // on the request for the ledger to reproduce.
        $fx         = $this->cardCurrencyInfo($providerModel, $wallet);
        $cardAmount = $fx['card_currency'] !== null
            ? $this->toCardAmount($amount, $fx['rate'], $fx['card_currency'])
            : null;

        if ($cardAmount !== null && DecimalMoney::compare($cardAmount, 0) <= 0) {
            throw new NotifyErrorException(__('The initial load of :amount :currency is too small to fund a :card card.', [
                'amount'   => $amount,
                'currency' => $currencyCode,
                'card'     => $fx['card_currency'],
            ]));
        }

        // An earlier approval whose provider call never completed left the
        // load debited and unattached. It is reused, never taken twice.
        $existing = $this->findInitialLoadTransaction($request, unattachedOnly: true);

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($request, $wallet, $providerModel, $currencyCode, $amount, $fx, $cardAmount): TransactionModel {
            $lockedWallet = Wallet::query()->lockForUpdate()->find($wallet->id);
            $this->wallets->assertOperational($lockedWallet);

            if ($this->wallets->tryDebit($lockedWallet, $amount) !== 1) {
                throw new NotifyErrorException(__('Insufficient wallet balance for the initial card load of :amount :currency.', [
                    'amount'   => $amount,
                    'currency' => $currencyCode,
                ]));
            }

            $request->forceFill([
                'initial_load_amount' => (float) $amount,
                'card_currency'       => $fx['card_currency'],
                'card_load_amount'    => $cardAmount,
                'load_exchange_rate'  => $cardAmount !== null ? $fx['rate'] : null,
            ])->save();

            $rate = \Wallet::fxRate($wallet);
            $data = new TransactionData(
                user_id: (int) $request->user_id,
                trx_type: TrxType::CARD_TOPUP,
                amount: 0,
                amount_flow: AmountFlow::MINUS,
                fee: 0,
                currency: $currencyCode,
                provider: $providerModel?->name,
                processing_type: MethodType::AUTOMATIC,
                net_amount: 0,
                payable_amount: 0,
                payable_currency: $currencyCode,
                wallet_reference: $wallet->uuid,
                trx_data: array_filter([
                    'kind'                    => 'initial_load',
                    'virtual_card_request_id' => $request->id,
                    'card_currency'           => $fx['card_currency'],
                    'card_amount'             => $cardAmount,
                    'card_exchange_rate'      => $cardAmount !== null ? $fx['rate'] : null,
                ], static fn (mixed $value): bool => $value !== null),
                description: __('Virtual Card Initial Load via :method', [
                    'method' => $providerModel?->name ?? __('card provider'),
                ]),
                status: TrxStatus::COMPLETED,
                exchange_rate: $rate,
            );

            return TransactionModel::create(array_merge($data->toArray(), [
                'amount'         => $amount,
                'fee'            => '0',
                'net_amount'     => $amount,
                'payable_amount' => $amount,
            ], Money::fxSnapshot($amount, '0', $rate)));
        }, 3);
    }

    /**
     * Give the initial load back when the provider did not put it on a card.
     *
     * Called when the driver throws (nothing was issued) and when it returns a
     * card with status `failed` (an id was allocated, no card exists). Inside
     * the approval's database transaction the rollback would undo the debit
     * anyway; outside one — and on the failed-status path, which the caller
     * commits on purpose so the failed row survives — this is what makes the
     * wallet whole. Idempotent: an already-failed row is left alone.
     */
    private function refundInitialLoad(VirtualCardRequest $request, ?TransactionModel $loadTransaction, string $reason): void
    {
        if (! $loadTransaction) {
            return;
        }

        DB::transaction(function () use ($request, $loadTransaction, $reason): void {
            $locked = TransactionModel::query()->lockForUpdate()->find($loadTransaction->id);

            if (! $locked || $locked->status === TrxStatus::FAILED) {
                return;
            }

            $this->wallets->creditSettlementByWalletUuid($request->wallet->uuid, (string) $locked->amount);

            $locked->update([
                'status'  => TrxStatus::FAILED,
                'remarks' => __('Initial load returned: :reason', ['reason' => $reason]),
            ]);
        }, 3);

        $loadTransaction->refresh();
    }

    /**
     * Give a request's initial load back, finding the ledger row by itself.
     *
     * For the paths that learn about a failed issuance after the approval has
     * long committed: a provider webhook, an admin refresh, the scheduled sync.
     * Idempotent through the row's own status, so any number of callers can
     * ask and the wallet is credited once. Returns whether money moved.
     */
    public function refundInitialLoadForRequest(VirtualCardRequest $request, string $reason): bool
    {
        $request->loadMissing('wallet');

        $load = $this->findInitialLoadTransaction($request);

        if (! $load || ! $request->wallet || $load->status === TrxStatus::FAILED) {
            return false;
        }

        $this->refundInitialLoad($request, $load, $reason);

        return $load->status === TrxStatus::FAILED;
    }

    /**
     * The request's load that is still out of the wallet: the newest
     * COMPLETED initial-load row.
     *
     * A request can collect several rows — one refunded after a refusal,
     * a later one kept after an unanswered call — and only a COMPLETED row
     * is money still owed somewhere. Picking the oldest row whatever its
     * status, as this used to, found the refunded one, so the next approval
     * debited a third time and the kept debit was never reused or returned.
     *
     * @param bool $unattachedOnly only a row no card has claimed yet, for reuse by a new approval
     */
    private function findInitialLoadTransaction(VirtualCardRequest $request, bool $unattachedOnly = false): ?TransactionModel
    {
        return TransactionModel::query()
            ->where('trx_type', TrxType::CARD_TOPUP->value)
            ->where('trx_data->kind', 'initial_load')
            ->where('trx_data->virtual_card_request_id', $request->id)
            ->where('status', TrxStatus::COMPLETED->value)
            ->when($unattachedOnly, fn ($query) => $query->whereNull('trx_data->virtual_card_id'))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Bring a card row in line with what the provider reports about it.
     *
     * Issuance is asynchronous at StroWallet and Bitnob: `create` hands back an
     * id and the PAN, expiry and final status arrive later. The row is saved
     * `pending` and stays that way until something asks the provider — which
     * is this. It is the one place the provider's answer is written locally,
     * and it is reached from the card webhooks (as a trigger only: nothing in
     * an unsigned payload is written), the admin "Refresh from gateway"
     * action and the scheduled sync for cards still pending.
     *
     * @return array<int, string> the attributes that changed
     *
     * @throws NotifyErrorException when the provider cannot report on this card
     */
    public function syncCardFromProvider(VirtualCard $card): array
    {
        $card->loadMissing(['provider', 'wallet.currency', 'request']);

        $providerModel = $card->provider;

        if (! $providerModel) {
            throw new NotifyErrorException(__('No virtual card provider is linked to this card.'));
        }

        if (! $providerModel->supports('card_details')) {
            throw new NotifyErrorException(__('This provider cannot report card details.'));
        }

        $details = $this->providerFactory->getProvider($providerModel->code)->getCardDetails($card);

        if (! is_array($details)) {
            throw new NotifyErrorException(__('The provider did not return card details.'));
        }

        return $this->applyProviderCardDetails($card, $details);
    }

    /**
     * Write a provider-verified detail set onto the card row.
     *
     * Under a row lock, because the webhook, the scheduler and an admin click
     * can all arrive for one card at once. Status wording is normalised through
     * VirtualCardStatus::fromProvider() and unknown wording changes nothing.
     * A soft freeze — one the provider has no API for — is never undone by
     * the provider reporting the card active, since the provider was never
     * told. A verified `failed` returns the initial load to the wallet and
     * fails the request; both are idempotent, so a card already marked failed
     * by an earlier unverified event is made whole on the first verified pass.
     *
     * @param  array<string, mixed> $details
     * @return array<int, string>   the attributes that changed
     */
    public function applyProviderCardDetails(VirtualCard $card, array $details): array
    {
        $changed = DB::transaction(function () use ($card, $details): array {
            $locked = VirtualCard::query()->lockForUpdate()->find($card->id);

            if (! $locked) {
                return [];
            }

            $locked->loadMissing(['provider', 'wallet.currency', 'request']);

            $update      = [];
            $meta        = is_array($locked->meta) ? $locked->meta : [];
            $metaChanged = false;

            $last4 = $this->providerLast4($details);
            if ($last4 !== null && $last4 !== (string) $locked->last4) {
                $update['last4'] = $last4;
            }

            [$expiryMonth, $expiryYear] = $this->providerExpiry($details);
            if ($expiryMonth !== null && $expiryMonth !== (string) $locked->expiry_month) {
                $update['expiry_month'] = $expiryMonth;
            }
            if ($expiryYear !== null && $expiryYear !== (string) $locked->expiry_year) {
                $update['expiry_year'] = $expiryYear;
            }

            $brand = strtolower(trim((string) ($details['card_brand'] ?? '')));
            if ($brand !== '' && empty($locked->brand)) {
                $update['brand'] = $brand;
            }

            $balance = $details['balance'] ?? null;
            if (is_numeric($balance)) {
                $currency  = $locked->provider?->cardCurrency() ?? $locked->wallet?->currency?->code;
                $quantized = Money::quantize((string) $balance, $currency);

                if (! array_key_exists('balance', $meta) || DecimalMoney::compare((string) $meta['balance'], $quantized) !== 0) {
                    $meta['balance'] = $quantized;
                    $metaChanged     = true;
                }
            }

            $status     = VirtualCardStatus::fromProvider($details['card_status'] ?? $details['status'] ?? null);
            $softFrozen = (bool) ($meta['freeze']['soft'] ?? false);

            if ($status === VirtualCardStatus::Active && $softFrozen) {
                $status = null;
            }

            // "Failed" is an issuance outcome. A card that was already live and
            // is now reported cancelled or deleted has stopped working, but its
            // initial load was spent on it long ago and is not owed back.
            $wasUnsettled = in_array($locked->status, [VirtualCardStatus::Pending, VirtualCardStatus::Failed], true);

            if ($status === VirtualCardStatus::Failed && ! $wasUnsettled) {
                $status = VirtualCardStatus::Blocked;
            }

            if ($status === VirtualCardStatus::Failed && empty($meta['failure_reason'])) {
                $reason                 = trim((string) ($details['failure_reason'] ?? ''));
                $meta['failure_reason'] = $reason !== '' ? $reason : __('The provider reported the card as failed.');
                $metaChanged            = true;
            }

            if ($status !== null && $status !== $locked->status) {
                $update['status'] = $status->value;
            }

            if ($metaChanged) {
                $update['meta'] = $meta;
            }

            if ($update !== []) {
                $locked->update($update);
            }

            if ($status === VirtualCardStatus::Failed) {
                $this->failIssuedCardRequest($locked, (string) ($meta['failure_reason'] ?? ''));
            }

            return array_keys($update);
        }, 3);

        $card->refresh();

        return $changed;
    }

    /**
     * A verified failed issuance: the request is failed and the load returned.
     */
    private function failIssuedCardRequest(VirtualCard $card, string $reason): void
    {
        $request = $card->request;

        if (! $request) {
            return;
        }

        // A driver that funds after issuing recorded whether the load was
        // sent. If it was, or may have been, the money is on the provider's
        // side of the card: returning it automatically could pay the
        // customer twice. An operator settles it with the provider instead.
        $loadSent = in_array(
            data_get($card->meta, 'initial_load.status'),
            [self::INITIAL_LOAD_FUNDED, self::INITIAL_LOAD_UNCONFIRMED],
            true,
        );

        if ($loadSent) {
            $this->noteFailedCardWithLoadSent($request, $reason);
        } else {
            $this->refundInitialLoadForRequest($request, $reason !== '' ? $reason : __('Provider declined the card creation.'));
        }

        if ($request->status !== VirtualCardRequestStatus::Failed) {
            $request->update(['status' => VirtualCardRequestStatus::Failed]);
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function providerLast4(array $details): ?string
    {
        foreach ([$details['last4'] ?? null, $details['card_number'] ?? null] as $candidate) {
            $digits = preg_replace('/\D+/', '', (string) $candidate) ?? '';

            if (strlen($digits) >= 4) {
                return substr($digits, -4);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>          $details
     * @return array{0: ?string, 1: ?string} month and four-digit year
     */
    private function providerExpiry(array $details): array
    {
        $expiry = $details['expiry'] ?? null;

        if (is_string($expiry) && preg_match('#^(\d{1,2})\s*/\s*(\d{2}|\d{4})$#', trim($expiry), $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);

            if ((int) $month >= 1 && (int) $month <= 12) {
                return [$month, strlen($matches[2]) === 2 ? '20'.$matches[2] : $matches[2]];
            }
        }

        $month = preg_replace('/\D+/', '', (string) ($details['expiry_month'] ?? ''));
        $year  = preg_replace('/\D+/', '', (string) ($details['expiry_year'] ?? ''));

        if ($month === '' || $year === '' || (int) $month < 1 || (int) $month > 12) {
            return [null, null];
        }

        return [str_pad($month, 2, '0', STR_PAD_LEFT), strlen($year) === 2 ? '20'.$year : $year];
    }

    /**
     * How a wallet's money maps onto the card's currency.
     *
     * Rates come from the platform's own currency table — the same numbers the
     * ledger's FX snapshot is written from — never from a live lookup at
     * funding time, so a row can always reproduce the figures it was priced
     * at. Both currencies are stored relative to the site's base currency, so
     * the wallet-per-card rate is their ratio: an NGN wallet at 1500 and a USD
     * card at 1 give 1500 NGN per USD.
     *
     * `card_currency` is null when no conversion applies (the provider funds
     * cards in the wallet's currency, or the two currencies match), and every
     * caller then behaves exactly as it did before conversion existed.
     *
     * @return array{card_currency: ?string, rate: string} rate = wallet units per one card unit
     *
     * @throws NotifyErrorException when either side has no usable rate
     */
    public function cardCurrencyInfo(?VirtualCardProvider $provider, ?Wallet $wallet): array
    {
        $walletCurrency = strtoupper((string) ($wallet?->currency?->code ?? ''));
        $cardCurrency   = $provider?->cardCurrency();

        if ($cardCurrency === null || $walletCurrency === '' || $cardCurrency === $walletCurrency) {
            return ['card_currency' => null, 'rate' => '1'];
        }

        $cardCurrencyRow = Currency::query()->where('code', $cardCurrency)->first();
        $walletRate      = $this->wallets->fxRate($wallet);
        $cardRate        = $cardCurrencyRow ? $this->wallets->fxRate($cardCurrencyRow) : '0';

        if (DecimalMoney::compare($walletRate, 0) <= 0 || DecimalMoney::compare($cardRate, 0) <= 0) {
            throw new NotifyErrorException(__('The exchange rate between :from and :to is not configured.', [
                'from' => $walletCurrency,
                'to'   => $cardCurrency,
            ]));
        }

        return [
            'card_currency' => $cardCurrency,
            'rate'          => DecimalMoney::rate(DecimalMoney::divide($walletRate, $cardRate, DecimalMoney::CALCULATION_SCALE)),
        ];
    }

    /**
     * A wallet-currency amount expressed in the card's currency, at the card's
     * own precision.
     */
    private function toCardAmount(string $walletAmount, string $rate, string $cardCurrency): string
    {
        return Money::quantize(
            DecimalMoney::divide($walletAmount, $rate, DecimalMoney::CALCULATION_SCALE),
            $cardCurrency
        );
    }

    /**
     * A card-currency amount expressed in the wallet's currency, at the
     * wallet currency's precision.
     */
    private function toWalletAmount(string $cardAmount, string $rate, ?string $walletCurrency): string
    {
        return Money::quantize(
            DecimalMoney::multiply($cardAmount, $rate, DecimalMoney::CALCULATION_SCALE),
            $walletCurrency
        );
    }

    /**
     * Top up a virtual card.
     *
     * Three phases, and the boundaries between them are the point.
     *
     *  1. Reserve — inside one transaction, under a row lock: debit the wallet
     *     and write the PENDING ledger entry. Committed before anything leaves
     *     the machine, so a funded card always has local accounting behind it.
     *  2. Dispatch — outside every transaction, with no lock held: stamp
     *     `submitted_at` (committed), then call the provider. The stamp is
     *     what later tells "never contacted" from "outcome unknown".
     *  3. Resolve — settle on success, compensate on a conclusive refusal,
     *     park for review when the outcome cannot be established.
     *
     * The amount is a decimal string throughout. It is never cast to float:
     * `amount + fee` in floats is what leaves a wallet a satoshi short of what
     * was actually debited.
     *
     * @param string      $amount         decimal string in the card's own currency
     * @param string|null $idempotencyKey replaying one key funds the card once
     *
     * @throws NotifyErrorException         the request cannot be accepted
     * @throws IdempotencyConflictException the key was used for a different request
     */
    public function topup(int $cardId, string $amount, ?string $idempotencyKey = null): VirtualCardTopup
    {
        $user        = Auth::user();
        $virtualCard = VirtualCard::where('id', $cardId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $virtualCard->loadMissing(['wallet.currency', 'provider']);

        $wallet = $virtualCard->wallet;
        $this->wallets->assertOperational($wallet);
        $this->assertCardFundable($virtualCard);

        $providerModel = $virtualCard->provider;
        $provider      = $this->providerFactory->getProvider($providerModel->code);

        $currencyCode = $wallet->currency?->code;

        // Quantize to the card currency before anything is computed from it,
        // so the figure debited is a figure the currency can actually hold.
        $amount = Money::quantize($amount, $currencyCode);

        if (DecimalMoney::compare($amount, 0) <= 0) {
            throw new NotifyErrorException(__('Amount must be greater than zero.'));
        }

        $feeSetting = $this->getFeeSetting($virtualCard, VirtualCardFeeOperation::Topup->value);
        $this->assertAmountWithinRange($feeSetting, $amount);

        $fee   = $this->decimalFee($feeSetting, $amount, $currencyCode);
        $total = DecimalMoney::add($amount, $fee, DecimalMoney::CALCULATION_SCALE);

        // The card may be kept in another currency than the wallet paying for
        // it. The wallet is debited in its own currency; the provider is asked
        // for the card-currency figure; the row keeps both and the rate.
        $fx         = $this->cardCurrencyInfo($providerModel, $wallet);
        $cardAmount = $fx['card_currency'] !== null
            ? $this->toCardAmount($amount, $fx['rate'], $fx['card_currency'])
            : null;

        if ($cardAmount !== null && DecimalMoney::compare($cardAmount, 0) <= 0) {
            throw new NotifyErrorException(__('Amount is too small to fund a :card card.', ['card' => $fx['card_currency']]));
        }

        $this->assertProviderMinimum($provider, $virtualCard, $cardAmount ?? $amount, $fx['card_currency'] ?? $currencyCode);

        $requestHash = $this->topupRequestHash($virtualCard, $amount, $fee);
        $isReplay    = false;

        $attempt = DB::transaction(function () use (
            $user, $virtualCard, $wallet, $providerModel, $currencyCode,
            $amount, $fee, $total, $idempotencyKey, $requestHash, $fx, $cardAmount, &$isReplay
        ): VirtualCardTopup {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = VirtualCardTopup::query()
                    ->where('user_id', $user->id)
                    ->where('client_idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if (! $existing->client_request_hash
                        || ! hash_equals((string) $existing->client_request_hash, $requestHash)) {
                        throw new IdempotencyConflictException(
                            __('This request was already submitted with different details.')
                        );
                    }

                    $isReplay = true;

                    return $existing;
                }
            }

            // Locked, so the balance cannot go stale between the check and the
            // debit — and tryDebit carries its own `balance >= amount` in the
            // WHERE clause, so both happen in one statement.
            $lockedWallet = Wallet::query()->lockForUpdate()->find($wallet->id);
            $this->wallets->assertOperational($lockedWallet);

            if ($this->wallets->tryDebit($lockedWallet, $total) !== 1) {
                throw new NotifyErrorException(__('Insufficient wallet balance.'));
            }

            $topup = VirtualCardTopup::create([
                'user_id'                => $user->id,
                'wallet_id'              => $wallet->id,
                'virtual_card_id'        => $virtualCard->id,
                'amount'                 => $amount,
                'fee'                    => $fee,
                'total_amount'           => $total,
                'currency'               => $currencyCode,
                'card_amount'            => $cardAmount,
                'card_currency'          => $fx['card_currency'],
                'exchange_rate'          => $cardAmount !== null ? $fx['rate'] : null,
                'provider'               => $providerModel->code,
                'provider_reference'     => 'vct_'.Str::uuid()->toString(),
                'status'                 => VirtualCardTopupStatus::Pending,
                'dispatch_state'         => VirtualCardTopupDispatchState::Reserved->value,
                'client_idempotency_key' => $idempotencyKey ?: null,
                'client_request_hash'    => $requestHash,
            ]);

            $transaction = TransactionModel::create(
                $this->topupLedgerRow($virtualCard, $wallet, $topup, $amount, $fee, $total)
            );

            $topup->forceFill(['transaction_id' => $transaction->id])->save();

            return $topup;
        }, 3);

        if ($isReplay) {
            return $attempt;
        }

        return $this->dispatchTopup($attempt, $virtualCard, $provider, $cardAmount ?? $amount);
    }

    /**
     * Phase 2 and 3: hand the funding to the provider and act on the answer.
     *
     * No transaction is open here and no lock is held — a gateway with a
     * 30-second timeout must not be holding a database connection, and the
     * reservation is already durable whatever happens next.
     */
    private function dispatchTopup(
        VirtualCardTopup $attempt,
        VirtualCard $virtualCard,
        VirtualCardProviderInterface $provider,
        string $amount,
    ): VirtualCardTopup {
        // Committed before the request goes out. Everything downstream depends
        // on being able to tell a crash here from a crash one line earlier.
        $attempt->forceFill([
            'dispatch_state' => VirtualCardTopupDispatchState::Submitting->value,
            'submitted_at'   => now(),
        ])->save();

        try {
            $response = $provider instanceof SupportsIdempotentTopUp
                ? $provider->topUpCardWithReference((string) $attempt->provider_reference, $amount, $virtualCard->provider_card_id)
                : $provider->topUpCard($amount, $virtualCard->provider_card_id);
        } catch (NotifyErrorException $e) {
            // The gateway said no. Nothing moved there, so the wallet can be
            // made whole — and only here.
            return $this->compensateTopup($attempt, $e->getMessage());
        } catch (Throwable $e) {
            // A timeout, a dropped connection, a 500, a bug in the driver: the
            // card may or may not have been funded. Refunding could pay the
            // customer twice; retrying could fund twice. Neither is ours to
            // decide.
            report($e);

            return $this->parkTopupForReview($attempt, $e->getMessage());
        }

        try {
            return $this->settleTopup($attempt, $response);
        } catch (Throwable $e) {
            // The provider funded the card and the local write failed. The
            // debit and the PENDING ledger row are already committed, so the
            // money is accounted for; only the confirmation is missing.
            //
            // This is a *known* outcome, not an ambiguous one — the gateway
            // answered. Recording it as such is what lets the sweep finish the
            // job instead of handing it to a human.
            report($e);

            return $this->markTopupSucceeded($attempt, $response, $e->getMessage());
        }
    }

    /**
     * Record a confirmed funding. Safe to run twice — a settled attempt
     * returns untouched, so a recovery sweep cannot credit the card again.
     *
     * @param array<string, mixed> $response
     */
    public function settleTopup(VirtualCardTopup $attempt, array $response = []): VirtualCardTopup
    {
        return DB::transaction(function () use ($attempt, $response): VirtualCardTopup {
            $locked = VirtualCardTopup::query()->lockForUpdate()->find($attempt->id);

            if (! $locked || $locked->status === VirtualCardTopupStatus::Completed) {
                return $locked ?? $attempt;
            }

            $card = VirtualCard::query()->find($locked->virtual_card_id);

            if ($card) {
                $card->update(['meta' => $this->creditCardBalance($card, (string) ($locked->card_amount ?? $locked->amount))]);
            }

            $transaction = TransactionModel::query()->lockForUpdate()->find($locked->transaction_id);

            if ($transaction && $transaction->status === TrxStatus::PENDING) {
                $transaction->update([
                    'status'        => TrxStatus::COMPLETED,
                    'trx_reference' => $response['reference'] ?? $locked->provider_reference,
                ]);
            }

            $locked->forceFill([
                'status'                  => VirtualCardTopupStatus::Completed,
                'dispatch_state'          => VirtualCardTopupDispatchState::Settled->value,
                'provider_transaction_id' => isset($response['id']) ? (string) $response['id'] : null,
                'metadata'                => $response ?: null,
                'failure_reason'          => null,
                'processed_at'            => now(),
            ])->save();

            if ($card) {
                // The activity list is a display. It is written once the
                // settlement has committed, and a failure there is reported
                // and nothing more - it must never roll back a top-up the
                // provider has already funded.
                DB::afterCommit(fn () => rescue(fn () => app(VirtualCardActivityService::class)->recordTopup($card, $locked)));
            }

            return $locked;
        }, 3);
    }

    /**
     * Give the money back for a funding that conclusively did not happen.
     *
     * Idempotent twice over: a failed attempt returns untouched, and the
     * refund entry is only written when no refund already points at the
     * original — so a second sweep cannot pay the customer twice.
     */
    public function compensateTopup(VirtualCardTopup $attempt, string $reason): VirtualCardTopup
    {
        return DB::transaction(function () use ($attempt, $reason): VirtualCardTopup {
            $locked = VirtualCardTopup::query()->lockForUpdate()->find($attempt->id);

            if (! $locked || $locked->status === VirtualCardTopupStatus::Failed) {
                return $locked ?? $attempt;
            }

            $transaction = TransactionModel::query()->lockForUpdate()->find($locked->transaction_id);

            $alreadyRefunded = $transaction && TransactionModel::query()
                ->where('trx_reference', $transaction->trx_id)
                ->where('trx_type', TrxType::REFUND->value)
                ->exists();

            if ($transaction && ! $alreadyRefunded) {
                // The decimal string, not a float. `total_amount` is cast
                // `decimal:8`, and it stays exact all the way to bcmath in
                // WalletService::shiftBalance().
                $this->wallets->creditSettlementByWalletUuid(
                    $transaction->wallet_reference,
                    (string) $locked->total_amount
                );

                TransactionModel::create(
                    $this->topupRefundLedgerRow($locked, $transaction, $reason)
                );
            }

            if ($transaction && $transaction->status === TrxStatus::PENDING) {
                $transaction->update([
                    'status'  => TrxStatus::FAILED,
                    'remarks' => $reason,
                ]);
            }

            $locked->forceFill([
                'status'         => VirtualCardTopupStatus::Failed,
                'dispatch_state' => VirtualCardTopupDispatchState::Refunded->value,
                'failure_reason' => $reason,
                'processed_at'   => now(),
            ])->save();

            return $locked;
        }, 3);
    }

    /**
     * Record a funding the provider confirmed but that could not be written
     * down locally.
     *
     * Kept apart from parkTopupForReview() on purpose: an operator has nothing
     * to decide here. The answer is known, the money is already accounted for,
     * and the recovery sweep can settle it on its next pass. If even this
     * write fails the attempt stays `submitting`, which the sweep treats as
     * ambiguous — the safe direction.
     *
     * @param array<string, mixed> $response
     */
    public function markTopupSucceeded(VirtualCardTopup $attempt, array $response, string $reason): VirtualCardTopup
    {
        $attempt->forceFill([
            'dispatch_state' => VirtualCardTopupDispatchState::Succeeded->value,
            'metadata'       => $response ?: null,
            'failure_reason' => $reason,
        ])->save();

        Log::warning('Virtual card top-up funded but not settled locally', [
            'virtual_card_topup_id' => $attempt->id,
            'provider'              => $attempt->provider,
            'provider_reference'    => $attempt->provider_reference,
        ]);

        return $attempt;
    }

    /**
     * Park an attempt whose outcome cannot be established.
     *
     * The wallet stays debited and the ledger entry stays PENDING, which is
     * the honest record: we do not know. An operator and the provider settle
     * it between them.
     */
    public function parkTopupForReview(
        VirtualCardTopup $attempt,
        string $reason,
        ?VirtualCardTopupDispatchState $observed = null,
    ): VirtualCardTopup {
        $attempt->forceFill([
            'dispatch_state' => VirtualCardTopupDispatchState::NeedsReview->value,
            'failure_reason' => $reason,
            'metadata'       => array_filter([
                'observed_state' => $observed?->value,
            ]) ?: null,
        ])->save();

        Log::warning('Virtual card top-up needs reconciliation', [
            'virtual_card_topup_id' => $attempt->id,
            'provider'              => $attempt->provider,
            'provider_reference'    => $attempt->provider_reference,
            'observed_state'        => $observed?->value,
        ]);

        return $attempt;
    }

    /**
     * @throws NotifyErrorException
     */
    private function assertCardFundable(VirtualCard $card): void
    {
        if (! $card->status?->isFundable()) {
            throw new NotifyErrorException(__('This card cannot be topped up while it is :status.', [
                'status' => $card->status?->label() ?? __('unavailable'),
            ]));
        }

        $provider = $card->provider;

        if (! $provider) {
            throw new NotifyErrorException(__('No virtual card provider is linked to this card.'));
        }

        // The dashboard hides the button; that is presentation, not
        // authorization. The server has to refuse it too.
        if (! $provider->supports('topup')) {
            throw new NotifyErrorException(__('Top up is not supported by this provider.'));
        }
    }

    /**
     * Refuse a top-up below what the provider will load at a time, before the
     * wallet is touched.
     *
     * @param string $cardAmount the figure the provider would be sent, in the card's currency
     *
     * @throws NotifyErrorException
     */
    private function assertProviderMinimum(VirtualCardProviderInterface $provider, VirtualCard $card, string $cardAmount, ?string $cardCurrency): void
    {
        if (! $provider instanceof SupportsFundingMinimum) {
            return;
        }

        $minimum = $provider->minimumFunding($card);

        if ($minimum !== null && DecimalMoney::compare($cardAmount, $minimum) < 0) {
            throw new NotifyErrorException(__('This card can be topped up with at least :amount :currency at a time.', [
                'amount'   => $minimum,
                'currency' => $cardCurrency,
            ]));
        }
    }

    /**
     * The fee as a decimal string.
     *
     * Deliberately not VirtualCardFeeSetting::calculateFee(), which returns
     * FeeCalculator's float convenience. Both the top-up and the withdrawal
     * charge through here, so neither fee is ever a float.
     */
    private function decimalFee(?VirtualCardFeeSetting $feeSetting, string $amount, ?string $currencyCode): string
    {
        if (! $feeSetting) {
            return Money::quantize('0', $currencyCode);
        }

        $fee = app(FeeCalculator::class)->calculate(
            $amount,
            $feeSetting->fee_amount,
            $feeSetting->fee_percent,
            scale: DecimalMoney::CALCULATION_SCALE,
        )->total;

        return Money::quantize($fee, $currencyCode);
    }

    /**
     * Range check in decimals rather than floats, for the top-up and the
     * withdrawal alike.
     *
     * @throws NotifyErrorException
     */
    private function assertAmountWithinRange(?VirtualCardFeeSetting $feeSetting, string $amount): void
    {
        if (! $feeSetting) {
            return;
        }

        $min = $feeSetting->min_amount !== null ? (string) $feeSetting->min_amount : null;
        $max = $feeSetting->max_amount !== null ? (string) $feeSetting->max_amount : null;

        $belowFloor = $min !== null && DecimalMoney::compare($amount, $min) < 0;
        $aboveCap   = $max !== null && DecimalMoney::compare($amount, $max) > 0;

        if ($belowFloor || $aboveCap) {
            throw new NotifyErrorException(__('Amount must be between :min and :max', [
                'min' => $min ?? 0,
                'max' => $max ?? __('no limit'),
            ]));
        }
    }

    /**
     * What makes two requests "the same request" for idempotency purposes.
     */
    private function topupRequestHash(VirtualCard $card, string $amount, string $fee): string
    {
        return hash('sha256', implode('|', [
            $card->id,
            DecimalMoney::normalize($amount, DecimalMoney::CALCULATION_SCALE),
            DecimalMoney::normalize($fee, DecimalMoney::CALCULATION_SCALE),
        ]));
    }

    /**
     * The CARD_TOPUP ledger entry, with every money column a decimal string.
     *
     * TransactionData types its money properties `float`, and Eloquent applies
     * a `decimal:` cast on read but never on write — so a float attribute
     * reaches PDO as "1.0E-8", a double literal against a decimal(24,8)
     * column. The DTO is built for its shape and the money keys are then
     * overridden with strings, which is the same array-create path
     * RemittanceService uses.
     *
     * The FX snapshot columns are filled in here for the same reason:
     * Transaction::completeFxSnapshot() assigns them with `??=` and casts to
     * float, so supplying them keeps this row off that path. Retyping the
     * shared DTO is a platform-wide change and is tracked separately.
     *
     * `amount_flow` is MINUS: a top-up is money leaving the wallet. The audit
     * in AuditMoneyPrecision::driftedWallets() reconciles a debit against
     * `payable_amount`, which is why the fee belongs in it.
     *
     * @return array<string, mixed>
     */
    private function topupLedgerRow(
        VirtualCard $card,
        Wallet $wallet,
        VirtualCardTopup $attempt,
        string $amount,
        string $fee,
        string $total,
    ): array {
        $currencyCode = $wallet->currency?->code;
        $rate         = \Wallet::fxRate($wallet);

        $data = new TransactionData(
            user_id: (int) $wallet->user_id,
            trx_type: TrxType::CARD_TOPUP,
            amount: 0,
            amount_flow: AmountFlow::MINUS,
            fee: 0,
            currency: $currencyCode,
            provider: $card->provider->name,
            processing_type: MethodType::AUTOMATIC,
            net_amount: 0,
            payable_amount: 0,
            payable_currency: $currencyCode,
            wallet_reference: $wallet->uuid,
            trx_data: array_filter([
                'virtual_card_id'       => $card->id,
                'virtual_card_topup_id' => $attempt->id,
                'provider_reference'    => $attempt->provider_reference,
                'card_currency'         => $attempt->card_currency,
                'card_amount'           => $attempt->card_amount,
                'card_exchange_rate'    => $attempt->exchange_rate,
            ], static fn (mixed $value): bool => $value !== null),
            description: __(':type via :method', [
                'type'   => TrxType::CARD_TOPUP->value,
                'method' => $card->provider->name,
            ]),
            status: TrxStatus::PENDING,
            exchange_rate: $rate,
        );

        return array_merge($data->toArray(), [
            'amount'         => $amount,
            'fee'            => $fee,
            'net_amount'     => $amount,
            'payable_amount' => $total,
        ], Money::fxSnapshot($amount, $fee, $rate));
    }

    /**
     * The compensating entry for a funding that was conclusively refused.
     *
     * Mirrors MobileRechargeService::failAndRefund(): a COMPLETED REFUND row
     * pointing back at the original through `trx_reference`, which is also
     * what makes a second compensation detectable.
     *
     * @return array<string, mixed>
     */
    private function topupRefundLedgerRow(VirtualCardTopup $attempt, TransactionModel $original, string $reason): array
    {
        $currency = $original->payable_currency ?? $original->currency;
        $rate     = \Wallet::fxRate($currency);
        $total    = (string) $attempt->total_amount;

        $data = new TransactionData(
            user_id: (int) $original->user_id,
            trx_type: TrxType::REFUND,
            amount: 0,
            amount_flow: AmountFlow::PLUS,
            fee: 0,
            currency: $currency,
            provider: $attempt->provider,
            processing_type: MethodType::AUTOMATIC,
            net_amount: 0,
            payable_amount: 0,
            payable_currency: $currency,
            wallet_reference: $original->wallet_reference,
            trx_reference: $original->trx_id,
            trx_data: [
                'refunded_trx_id'       => $original->trx_id,
                'source'                => 'virtual_card_topup',
                'virtual_card_topup_id' => $attempt->id,
            ],
            remarks: $reason,
            description: __('Refund for failed card topup'),
            status: TrxStatus::COMPLETED,
            exchange_rate: $rate,
        );

        return array_merge($data->toArray(), [
            'amount'         => $total,
            'fee'            => Money::quantize('0', $currency),
            'net_amount'     => $total,
            'payable_amount' => $total,
        ], Money::fxSnapshot($total, Money::quantize('0', $currency), $rate));
    }

    /**
     * Withdraw from a virtual card.
     *
     * @throws Exception
     */
    /**
     * Move money from the card back to the wallet.
     *
     * `$amount` is in the card's currency — the figure the user sees as the
     * card balance and the figure the provider unloads. It is converted at
     * the platform's stored rate into the wallet's currency, the withdrawal
     * fee (configured per wallet currency) comes off that, and the remainder
     * is credited to the wallet with a completed ledger row.
     *
     * The credit is the point: the previous version unloaded the card and
     * wrote a pending ledger row, and nothing ever paid the wallet.
     *
     * @throws NotifyErrorException when the amount, balance, or fee rule refuses it
     */
    public function withdraw(int $cardId, string $amount): string
    {
        $user        = Auth::user();
        $virtualCard = VirtualCard::query()
            ->with(['wallet.currency', 'provider'])
            ->where('id', $cardId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $wallet = $virtualCard->wallet;
        $this->wallets->assertOperational($wallet);
        $providerModel  = $virtualCard->provider;
        $walletCurrency = $wallet->currency?->code;

        $fx         = $this->cardCurrencyInfo($providerModel, $wallet);
        $cardAmount = Money::quantize($amount, $fx['card_currency'] ?? $walletCurrency);

        if (DecimalMoney::compare($cardAmount, 0) <= 0) {
            throw new NotifyErrorException(__('Amount must be greater than zero.'));
        }

        $walletAmount = $fx['card_currency'] !== null
            ? $this->toWalletAmount($cardAmount, $fx['rate'], $walletCurrency)
            : $cardAmount;

        $feeSetting = $this->getFeeSetting($virtualCard, VirtualCardFeeOperation::Withdrawal->value);
        $this->assertAmountWithinRange($feeSetting, $walletAmount);
        $fee = $this->decimalFee($feeSetting, $walletAmount, $walletCurrency);
        $net = DecimalMoney::subtract($walletAmount, $fee, DecimalMoney::CALCULATION_SCALE);

        if (DecimalMoney::compare($net, 0) <= 0) {
            throw new NotifyErrorException(__('The withdrawal fee leaves nothing to credit to your wallet.'));
        }

        // Guard against over-withdrawal at the manager level so the provider
        // call is never made with an amount larger than what the card holds.
        $meta           = is_array($virtualCard->meta) ? $virtualCard->meta : [];
        $currentBalance = (string) ($meta['balance'] ?? 0);

        if (DecimalMoney::compare($cardAmount, $currentBalance) > 0) {
            throw new NotifyErrorException(__('Withdraw amount exceeds the card balance.'));
        }

        $provider  = $this->providerFactory->getProvider($providerModel->code);
        $reference = 'vcw_'.Str::lower((string) Str::ulid());
        $rate      = \Wallet::fxRate($wallet);

        // Written before the provider is asked, so money leaving the card is
        // never unaccounted for: a refusal fails the row, an answer that never
        // arrives leaves it pending for reconciliation, and only a confirmed
        // unload credits the wallet.
        $pending = DB::transaction(function () use ($virtualCard, $wallet, $walletCurrency, $fx, $cardAmount, $walletAmount, $fee, $net, $providerModel, $reference, $rate): TransactionModel {
            $lockedCard = VirtualCard::query()->lockForUpdate()->findOrFail($virtualCard->id);
            $lockedMeta = is_array($lockedCard->meta) ? $lockedCard->meta : [];

            if (DecimalMoney::compare($cardAmount, (string) ($lockedMeta['balance'] ?? 0)) > 0) {
                throw new NotifyErrorException(__('Withdraw amount exceeds the card balance.'));
            }

            $lockedCard->update([
                'meta' => $this->creditCardBalance($lockedCard, '-'.$cardAmount),
            ]);

            $data = new TransactionData(
                user_id: (int) $wallet->user_id,
                trx_type: TrxType::CARD_WITHDRAW,
                amount: 0,
                amount_flow: AmountFlow::PLUS,
                fee: 0,
                currency: $walletCurrency,
                provider: $providerModel->name,
                processing_type: MethodType::AUTOMATIC,
                net_amount: 0,
                payable_amount: 0,
                payable_currency: $walletCurrency,
                wallet_reference: $wallet->uuid,
                trx_reference: $reference,
                trx_data: array_filter([
                    'virtual_card_id'    => $virtualCard->id,
                    'card_currency'      => $fx['card_currency'],
                    'card_amount'        => $fx['card_currency'] !== null ? $cardAmount : null,
                    'card_exchange_rate' => $fx['card_currency'] !== null ? $fx['rate'] : null,
                ], static fn (mixed $value): bool => $value !== null),
                description: __(':type via :method', [
                    'type'   => TrxType::CARD_WITHDRAW->value,
                    'method' => $providerModel->name,
                ]),
                status: TrxStatus::PENDING,
                exchange_rate: $rate,
            );

            return TransactionModel::create(array_merge($data->toArray(), [
                'amount'         => $walletAmount,
                'fee'            => $fee,
                'net_amount'     => $net,
                'payable_amount' => $net,
            ], Money::fxSnapshot($walletAmount, $fee, $rate)));
        }, 3);

        try {
            $withdrawResponse = $provider->withdrawFromCard($cardAmount, $virtualCard->provider_card_id);
        } catch (NotifyErrorException $e) {
            // The provider refused: the money is still on the card.
            DB::transaction(function () use ($virtualCard, $cardAmount, $pending, $e): void {
                $lockedCard = VirtualCard::query()->lockForUpdate()->findOrFail($virtualCard->id);
                $lockedCard->update(['meta' => $this->creditCardBalance($lockedCard, $cardAmount)]);

                TransactionModel::query()->whereKey($pending->id)->update([
                    'status'  => TrxStatus::FAILED->value,
                    'remarks' => __('Provider refused the withdrawal: :reason', ['reason' => Str::limit($e->getMessage(), 200)]),
                ]);
            }, 3);

            throw $e;
        } catch (Throwable $e) {
            // No answer. The card may or may not have been unloaded, so the row
            // stays pending — visible in the ledger — until it is reconciled.
            report($e);

            TransactionModel::query()->whereKey($pending->id)->update([
                'remarks' => __('Provider did not confirm the withdrawal; awaiting reconciliation. :reason', ['reason' => Str::limit($e->getMessage(), 200)]),
            ]);

            throw new NotifyErrorException(__('The card provider did not confirm the withdrawal. It has been recorded and your wallet will be credited once it is confirmed.'));
        }

        DB::transaction(function () use ($wallet, $net, $pending, $withdrawResponse, $reference, $virtualCard, $cardAmount, $fx, $walletCurrency): void {
            $lockedRow = TransactionModel::query()->lockForUpdate()->findOrFail($pending->id);

            if ($lockedRow->status === TrxStatus::COMPLETED) {
                return;
            }

            // Only a confirmed unload is card activity; written after commit
            // and never allowed to undo the credit.
            DB::afterCommit(fn () => rescue(fn () => app(VirtualCardActivityService::class)->recordWithdraw($virtualCard, $cardAmount, $fx['card_currency'] ?? $walletCurrency, $reference)));

            $lockedWallet = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);
            $this->wallets->creditSettlement($lockedWallet, $net);

            $lockedRow->update([
                'status'        => TrxStatus::COMPLETED,
                'trx_reference' => isset($withdrawResponse['reference']) && $withdrawResponse['reference'] !== null && $withdrawResponse['reference'] !== ''
                    ? (string) $withdrawResponse['reference']
                    : $reference,
                'trx_data' => array_merge($lockedRow->trx_data ?? [], ['provider_response' => $withdrawResponse]),
            ]);
        }, 3);

        return 'success';
    }

    /**
     * Move the on-card balance by a signed delta, at the card's own precision.
     *
     * This used to `round(..., 2)`, which is right for a USD card and wrong for
     * every other kind: a card funded from a BTC wallet had each movement
     * rounded to cents before it was stored, so the displayed balance drifted
     * from the ledger by up to half a cent per operation and a sub-cent top-up
     * registered as nothing at all. The scale now comes from the wallet's
     * currency, the same source the rest of the platform formats from.
     *
     * Clamped at zero because a card balance is not a credit line.
     *
     * @return array<string, mixed> the card's meta with the new balance
     */
    private function creditCardBalance(VirtualCard $card, string|int|float $delta): array
    {
        $meta     = is_array($card->meta) ? $card->meta : [];
        $currency = $card->provider?->cardCurrency() ?? $card->wallet?->currency?->code;

        $balance = DecimalMoney::add(
            (string) ($meta['balance'] ?? 0),
            (string) $delta,
            DecimalMoney::CALCULATION_SCALE,
        );

        if (DecimalMoney::compare($balance, 0) < 0) {
            $balance = '0';
        }

        // Kept as the decimal string, never a float: every reader compares it
        // with DecimalMoney, and a float JSON number drops digits past ~14.
        $meta['balance'] = Money::quantize($balance, $currency);

        return $meta;
    }

    /**
     * What a top-up or withdrawal of this amount would cost, before anything
     * moves: the fee in the wallet's currency, what the card gains or loses
     * in its own, and the wallet figure after. A refusal the manager would
     * make (below the minimum, above the maximum) is raised here too.
     *
     * @return array{operation: string, amount: string, fee: string, total: string, net: string, card_amount: string, card_currency: ?string, wallet_currency: ?string, rate: ?string}
     *
     * @throws NotifyErrorException
     */
    public function quote(VirtualCard $virtualCard, string $operation, string $amount): array
    {
        $virtualCard->loadMissing(['wallet.currency', 'provider']);
        $wallet         = $virtualCard->wallet;
        $walletCurrency = $wallet?->currency?->code;
        $fx             = $this->cardCurrencyInfo($virtualCard->provider, $wallet);
        $amount         = Money::quantize($amount, $walletCurrency);

        if (DecimalMoney::compare($amount, 0) <= 0) {
            throw new NotifyErrorException(__('Amount must be greater than zero.'));
        }

        $feeOperation = $operation === 'withdraw' ? VirtualCardFeeOperation::Withdrawal->value : VirtualCardFeeOperation::Topup->value;
        $feeSetting   = $this->getFeeSetting($virtualCard, $feeOperation);
        $this->assertAmountWithinRange($feeSetting, $amount);
        $fee = $this->decimalFee($feeSetting, $amount, $walletCurrency);

        if ($operation === 'withdraw') {
            $cardAmount = $fx['card_currency'] !== null ? $this->toCardAmount($amount, $fx['rate'], $fx['card_currency']) : $amount;
            $net        = DecimalMoney::subtract($amount, $fee, DecimalMoney::CALCULATION_SCALE);

            return [
                'operation'       => 'withdraw',
                'amount'          => $amount,
                'fee'             => $fee,
                'total'           => $amount,
                'net'             => Money::quantize($net, $walletCurrency),
                'card_amount'     => $cardAmount,
                'card_currency'   => $fx['card_currency'] ?? $walletCurrency,
                'wallet_currency' => $walletCurrency,
                'rate'            => $fx['card_currency'] !== null ? (string) $fx['rate'] : null,
            ];
        }

        $total      = DecimalMoney::add($amount, $fee, DecimalMoney::CALCULATION_SCALE);
        $cardAmount = $fx['card_currency'] !== null ? $this->toCardAmount($amount, $fx['rate'], $fx['card_currency']) : $amount;

        return [
            'operation'       => 'topup',
            'amount'          => $amount,
            'fee'             => $fee,
            'total'           => Money::quantize($total, $walletCurrency),
            'net'             => $amount,
            'card_amount'     => $cardAmount,
            'card_currency'   => $fx['card_currency'] ?? $walletCurrency,
            'wallet_currency' => $walletCurrency,
            'rate'            => $fx['card_currency'] !== null ? (string) $fx['rate'] : null,
        ];
    }

    /**
     * Get the fee setting for the operation (topup/withdraw).
     */
    private function getFeeSetting(VirtualCard $virtualCard, string $operation): ?VirtualCardFeeSetting
    {
        return VirtualCardFeeSetting::where('provider_id', $virtualCard->provider_id)
            ->where('currency_id', $virtualCard->wallet->currency_id)
            ->where('operation', $operation)
            ->first();
    }
}
