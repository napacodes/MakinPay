<?php

declare(strict_types=1);

namespace App\Services\VirtualCard\Drivers\BsiCards;

use App\Enums\VirtualCard\CardholderType;
use App\Exceptions\NotifyErrorException;
use App\Exceptions\ProviderOutcomeUnknownException;
use App\Models\Cardholders;
use App\Models\VirtualCard;
use App\Models\VirtualCardRequest;
use App\Services\VirtualCard\Drivers\AbstractVirtualCardProvider;
use App\Services\VirtualCard\Drivers\SupportsCardActivity;
use App\Services\VirtualCard\Drivers\SupportsFundingMinimum;
use App\Services\VirtualCard\VirtualCardActivityService;
use App\Services\VirtualCard\VirtualCardManager;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * BSICARDS virtual cards: Visa through the Digital Visa Wallet program,
 * Mastercard through the Digital USD Mastercard program, both in USD.
 *
 * Three things about BSICARDS shape this driver:
 *
 *  - The cardholder is an email address. Every call names the card by
 *    `useremail` + `cardid`, so the email used at issuance is kept on the card
 *    and reused for its whole life, whatever the cardholder's email becomes.
 *  - Creating a card cannot load the customer's money. BSICARDS puts its own
 *    starter load on every new card (charged to the operator, see
 *    BsiCardsProgram::starterLoad()), and the customer's initial load is a
 *    separate funding call, reported to the manager as `initial_load`.
 *  - Nothing is idempotent. A create or fund that went unanswered may have
 *    happened, so it is reported as unknown and never retried.
 *
 * PAN, CVV and identity documents never reach a log line or the card's meta.
 */
class BsiCardsProvider extends AbstractVirtualCardProvider implements SupportsCardActivity, SupportsFundingMinimum
{
    public function __construct(private ?BsiCardsClient $client = null) {}

    public function issueCard(VirtualCardRequest $request): array
    {
        $program = BsiCardsProgram::fromNetwork($request->network);

        if (! $program) {
            throw new NotifyErrorException(__('BSICARDS issues Visa and Mastercard cards only.'));
        }

        $cardholder = $this->cardholderFor($request);
        $email      = $this->cardholderEmail($cardholder, $request);
        $load       = self::cents($request->providerLoadDecimal());

        // Checked before anything is created: a card BSICARDS has issued
        // cannot be taken back, and a load it will refuse is better refused
        // while nothing exists.
        if (DecimalMoney::compare($load, 0) > 0 && DecimalMoney::compare($load, $program->minimumFunding()) < 0) {
            throw new NotifyErrorException(__(':program cards take an initial load of at least :min USD, or none.', [
                'program' => $program->label(),
                'min'     => $program->minimumFunding(),
            ]));
        }

        $fields = [
            'useremail' => $email,
            'firstname' => trim((string) $cardholder->first_name),
            'lastname'  => trim((string) $cardholder->last_name),
        ];

        $created = $program->requiresKycFiles()
            ? $this->client()->postMultipart((string) $program->path('create'), $fields, $this->kycFiles($cardholder))
            : $this->client()->postJson((string) $program->path('create'), $fields);

        $created->orFail(__('BSICARDS did not issue the card.'));

        $data   = $created->data();
        $cardId = $this->firstString($data, ['cardid', 'card_id', 'id', 'cardId']);

        if ($cardId === null) {
            // BSICARDS said yes without naming the card. It may well exist —
            // and be charged for — so this is not a refusal to refund on.
            throw new ProviderOutcomeUnknownException(__('BSICARDS accepted the card request but returned no card id.'));
        }

        $createdStatus = self::status($this->firstString($data, ['status', 'cardstatus', 'card_status']));

        // A card BSICARDS created as failed is not funded: the manager returns
        // the load for it, and that is only safe while nothing went onto it.
        [$initialLoad, $initialLoadReason] = $createdStatus === 'failed'
            ? [null, null]
            : $this->fundInitialLoad($program, $email, $cardId, $load);

        $details = $this->fetchCardQuietly($program, $email, $cardId);
        $status  = $details['card_status'] ?? $createdStatus ?? 'active';

        // Once money may be on the card, "failed" is no longer an outcome the
        // manager may refund on; the scheduled sync reads the real state.
        if ($status === 'failed' && $initialLoad !== null) {
            $status = 'pending';
        }

        // Created as failed, so the load was held back — but the card reads
        // as alive now. It keeps the card; the load, never sent, goes back.
        if ($createdStatus === 'failed' && $status !== 'failed' && DecimalMoney::compare($load, 0) > 0) {
            $initialLoad       = VirtualCardManager::INITIAL_LOAD_REFUSED;
            $initialLoadReason = __('BSICARDS reported the card as failed when creating it, so the initial load was not sent.');
        }

        [$expiryMonth, $expiryYear] = $this->expiry($details + $data);

        $meta = [
            'card_id'       => $cardId,
            'bsi_program'   => $program->value,
            'bsi_useremail' => $email,
        ];

        if (isset($details['balance'])) {
            $meta['balance'] = $details['balance'];
        }

        return [
            'id'                  => $cardId,
            'last4'               => $details['last4'] ?? $this->last4($data),
            'brand'               => $program->value,
            'expiry_month'        => $expiryMonth,
            'expiry_year'         => $expiryYear,
            'status'              => $status,
            'meta'                => $meta,
            'initial_load'        => $initialLoad,
            'initial_load_reason' => $initialLoadReason,
            'raw'                 => ['create' => $this->withoutSensitive($created->json ?? [])],
        ];
    }

    /**
     * Fund a card from the customer's wallet.
     *
     * Deliberately not SupportsIdempotentTopUp: BSICARDS takes no reference
     * and cannot tell a repeat from a new funding, so the manager must never
     * retry this on its own. A refusal comes back as NotifyErrorException (the
     * wallet is made whole); no answer as anything else (parked for review).
     */
    public function topUpCard($amount, $cardID): array
    {
        $card    = $this->cardByProviderId((string) $cardID);
        $program = $this->programFor($card);

        $data = $this->fund($program, $this->emailFor($card), (string) $cardID, (string) $amount);

        return [
            'id'        => $this->firstString($data, ['transaction_id', 'transactionId', 'tx_ref', 'id']),
            'status'    => 'completed',
            'cardId'    => (string) $cardID,
            'reference' => $this->firstString($data, ['reference', 'tx_ref', 'transaction_reference']),
        ];
    }

    public function minimumFunding(VirtualCard $card): ?string
    {
        return BsiCardsProgram::forCard($card)?->minimumFunding();
    }

    /**
     * The card's live details, PAN and CVV included, for the owner's
     * details view and the provider sync. Nothing here is written down.
     */
    public function getCardDetails(VirtualCard $card)
    {
        $program = $this->programFor($card);
        $details = $this->fetchCard($program, $this->emailFor($card), $this->providerCardId($card));

        return $details + [
            'card_name'  => $program->label(),
            'card_brand' => $program->value,
            'currency'   => $card->provider?->cardCurrency() ?? 'USD',
        ];
    }

    public function freezeCard(VirtualCard $card): array
    {
        $this->setBlocked($card, true);

        return ['status' => 'inactive', 'soft' => false];
    }

    public function unfreezeCard(VirtualCard $card): array
    {
        $this->setBlocked($card, false);

        return ['status' => 'active', 'soft' => false];
    }

    /**
     * What the card was used for, read into the platform's activity rows.
     *
     * Visa lists a card's transactions inside get-card; Mastercard has its
     * own endpoint and passes its upstream processor's rows through. Neither
     * program has published a transaction row, so fields are read from every
     * name in use across BSICARDS's programs, and a row without a readable
     * amount is skipped rather than guessed at.
     */
    public function listCardActivity(VirtualCard $card): array
    {
        $program = $this->programFor($card);
        $body    = [
            'useremail' => $this->emailFor($card),
            'cardid'    => $this->providerCardId($card),
        ];

        $transactionsPath = $program->path('transactions');

        $data = $transactionsPath !== null
            ? $this->client()->postJson($transactionsPath, $body)->orFail(__('BSICARDS did not return the card transactions.'))->data()
            : ($this->client()->postJson((string) $program->path('get'), $body)->orFail(__('BSICARDS did not return the card.'))->data()['transactions'] ?? []);

        $currency = $card->provider?->cardCurrency() ?? 'USD';
        $rows     = [];

        foreach ($this->transactionRows(is_array($data) ? $data : []) as $row) {
            $mapped = $this->activityRow($row, $currency);

            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        return $rows;
    }

    /**
     * Credential probe: the read-only account balance, which moves nothing
     * and answers 401 for keys BSICARDS does not know.
     *
     * @return array{ok: bool, mode: ?string, message: string, latency_ms: ?int, details: array<string, mixed>}
     */
    public function testConnection(): array
    {
        $started = microtime(true);

        try {
            $client  = $this->client();
            $answer  = $client->get('admin/balance');
            $latency = (int) ((microtime(true) - $started) * 1000);

            if ($answer->isSuccessful()) {
                return [
                    'ok'         => true,
                    'mode'       => $client->mode(),
                    'message'    => __('BSICARDS accepted the keys (:mode). Reachable in :ms ms.', ['mode' => $client->mode(), 'ms' => $latency]),
                    'latency_ms' => $latency,
                    'details'    => ['status_code' => $answer->httpStatus],
                ];
            }

            return [
                'ok'      => false,
                'mode'    => $client->mode(),
                'message' => $answer->isUsable()
                    ? $answer->message(__('BSICARDS refused the keys.'))
                    : __('BSICARDS did not give a usable answer (HTTP :status).', ['status' => $answer->httpStatus]),
                'latency_ms' => $latency,
                'details'    => ['status_code' => $answer->httpStatus],
            ];
        } catch (Throwable $e) {
            return [
                'ok'         => false,
                'mode'       => null,
                'message'    => __('BSICARDS connection failed: :error', ['error' => $e->getMessage()]),
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
                'details'    => ['exception' => class_basename($e)],
            ];
        }
    }

    private function setBlocked(VirtualCard $card, bool $blocked): void
    {
        $program = $this->programFor($card);

        $this->client()->postJson((string) $program->path($blocked ? 'block' : 'unblock'), [
            'useremail' => $this->emailFor($card),
            'cardid'    => $this->providerCardId($card),
        ])->orFail($blocked ? __('BSICARDS did not freeze the card.') : __('BSICARDS did not unfreeze the card.'));
    }

    /**
     * The list of transaction rows wherever this program nests it.
     *
     * @param  array<mixed>               $data
     * @return list<array<string, mixed>>
     */
    private function transactionRows(array $data): array
    {
        $candidates = [
            $data,
            $data['data']                             ?? null,
            $data['transactions']                     ?? null,
            $data['items']                            ?? null,
            $data['response']['data']['transactions'] ?? null,
            $data['response']['items']                ?? null,
            $data['data']['transactions']             ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== [] && array_is_list($candidate) && is_array($candidate[0])) {
                return array_values(array_filter($candidate, 'is_array'));
            }
        }

        return [];
    }

    /**
     * One provider transaction as a SupportsCardActivity row, or null when it
     * carries no amount.
     *
     * @param  array<string, mixed>      $row
     * @return array<string, mixed>|null
     */
    private function activityRow(array $row, string $currency): ?array
    {
        $amount = $row['amount'] ?? $row['transaction_amount'] ?? $row['billing_amount'] ?? null;
        if (is_array($amount)) {
            $amount = $amount['value'] ?? $amount['amount'] ?? null;
        }

        if (! is_numeric($amount)) {
            return null;
        }

        $type     = strtolower((string) ($this->firstString($row, ['type', 'transaction_type', 'transactionType', 'method', 'category']) ?? ''));
        $status   = strtolower((string) ($this->firstString($row, ['status', 'transaction_status']) ?? ''));
        $incoming = strtolower((string) ($row['direction'] ?? '')) === 'incoming';
        $declined = in_array($status, ['decline', 'declined', 'failed', 'rejected', 'error'], true) || str_contains($type, 'declin');

        $kind = match (true) {
            $declined                                                                                                                                                         => 'decline',
            str_contains($type, 'refund')   || str_contains($type, 'reversal')                                                                                                => 'refund',
            $incoming                       || str_contains($type, 'fund') || str_contains($type, 'topup') || str_contains($type, 'deposit') || str_contains($type, 'credit') => 'topup',
            str_contains($type, 'withdraw') || str_contains($type, 'unload')                                                                                                  => 'withdraw',
            str_contains($type, 'fee')      || str_contains($type, 'charge')                                                                                                  => 'fee',
            default                                                                                                                                                           => 'purchase',
        };

        $merchant = $row['merchant'] ?? null;
        $merchant = is_array($merchant) ? ($merchant['name'] ?? null) : $merchant;

        return [
            'reference'         => $this->firstString($row, ['id', 'transaction_id', 'transactionId', 'reference', 'tx_ref']) ?? md5((string) json_encode($row)),
            'kind'              => $kind,
            'status'            => $declined ? 'declined' : (in_array($status, ['pending', 'processing', 'authorized', 'authorised', 'authorization'], true) ? 'pending' : (str_contains($type, 'reversal') ? 'reversed' : 'settled')),
            'amount'            => DecimalMoney::round(ltrim((string) $amount, '-'), 2),
            'currency'          => strtoupper((string) ($this->firstString($row, ['currency', 'currencyCode']) ?? $currency)),
            'merchant'          => is_scalar($merchant) && trim((string) $merchant) !== '' ? trim((string) $merchant) : $this->firstString($row, ['merchant_name', 'merchantName', 'narrative', 'description']),
            'category'          => $this->firstString($row, ['merchant_mcc', 'mcc', 'transaction_category']),
            'channel'           => null,
            'original_amount'   => isset($row['merchantAmount']) && is_numeric($row['merchantAmount']) ? DecimalMoney::round((string) $row['merchantAmount'], 2) : null,
            'original_currency' => $this->firstString($row, ['merchantCurrency', 'merchant_currency']),
            'decline_reason'    => $declined ? VirtualCardActivityService::reasonFrom($this->firstString($row, ['declineReason', 'decline_reason', 'reason', 'failure_reason', 'narrative'])) : null,
            'occurred_at'       => $this->firstString($row, ['date', 'createdAt', 'created_at', 'paymentDateTime', 'transaction_date', 'transactionDate', 'timestamp']) ?? now()->toIso8601String(),
            'meta'              => ['provider' => BsiCardsClient::GATEWAY_CODE],
        ];
    }

    /**
     * Put the customer's initial load on a card that has just been created.
     *
     * @return array{0: ?string, 1: ?string} the manager's initial_load outcome and its reason
     */
    private function fundInitialLoad(BsiCardsProgram $program, string $email, string $cardId, string $load): array
    {
        if (DecimalMoney::compare($load, 0) <= 0) {
            return [null, null];
        }

        try {
            $this->fund($program, $email, $cardId, $load);

            return [VirtualCardManager::INITIAL_LOAD_FUNDED, null];
        } catch (NotifyErrorException $e) {
            return [VirtualCardManager::INITIAL_LOAD_REFUSED, $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return [VirtualCardManager::INITIAL_LOAD_UNCONFIRMED, $e->getMessage()];
        }
    }

    /**
     * An amount as the two-decimal USD figure BSICARDS takes, refusing one
     * that cannot be sent exactly. Rounding would fund the card with a
     * different figure from the one the wallet paid.
     *
     * @throws NotifyErrorException
     */
    private static function cents(string $amount): string
    {
        $cents = DecimalMoney::round($amount, 2);

        if (DecimalMoney::compare($cents, $amount) !== 0) {
            throw new NotifyErrorException(__('BSICARDS takes amounts in whole cents; :amount cannot be sent exactly.', ['amount' => $amount]));
        }

        return $cents;
    }

    /**
     * One funding call. Never retried: BSICARDS cannot recognise a repeat.
     *
     * @return array<string, mixed>
     *
     * @throws NotifyErrorException            BSICARDS refused the funding
     * @throws ProviderOutcomeUnknownException BSICARDS never answered
     */
    private function fund(BsiCardsProgram $program, string $email, string $cardId, string $amount): array
    {
        $amount = self::cents($amount);

        if (DecimalMoney::compare($amount, $program->minimumFunding()) < 0) {
            throw new NotifyErrorException(__(':program cards can be funded with at least :min USD at a time.', [
                'program' => $program->label(),
                'min'     => $program->minimumFunding(),
            ]));
        }

        return $this->client()->postJson((string) $program->path('fund'), [
            'useremail' => $email,
            'cardid'    => $cardId,
            'amount'    => $amount,
        ])->orFail(__('BSICARDS did not fund the card.'))->data();
    }

    /**
     * The card as BSICARDS reports it, read into the platform's words. PAN and
     * CVV are returned to the caller and go no further than that.
     *
     * @return array<string, mixed>
     */
    private function fetchCard(BsiCardsProgram $program, string $email, string $cardId): array
    {
        $data = $this->client()->postJson((string) $program->path('get'), [
            'useremail' => $email,
            'cardid'    => $cardId,
        ])->orFail(__('BSICARDS did not return the card.'))->data();

        $number = $this->firstString($data, ['card_number', 'cardNumber', 'pan', 'number']);
        $masked = $this->firstString($data, ['maskedpan', 'masked_pan', 'maskedPan', 'maskedCardNumber']);

        [$month, $year] = $this->expiry($data);

        return array_filter([
            'card_number'      => $number,
            'masked_pan'       => $masked,
            'last4'            => $this->last4($data, $number ?? $masked),
            'cvv'              => $this->firstString($data, ['cvv', 'cvc', 'cvv2']),
            'expiry_month'     => $month,
            'expiry_year'      => $year,
            'expiry'           => $month !== null && $year !== null ? $month.'/'.substr($year, -2) : null,
            'card_holder_name' => $this->firstString($data, ['nameoncard', 'name_on_card', 'cardName', 'card_name', 'name']),
            'card_status'      => self::status($this->firstString($data, ['status', 'cardstatus', 'card_status', 'cardStatus'])),
            'balance'          => $this->balance($data),
            'card_type'        => $this->firstString($data, ['type', 'issuance_type', 'issuanceType']) ?? 'virtual',
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Read a just-created card without letting a slow provisioning step fail
     * an issuance that has already happened.
     *
     * @return array<string, mixed>
     */
    private function fetchCardQuietly(BsiCardsProgram $program, string $email, string $cardId): array
    {
        try {
            return $this->fetchCard($program, $email, $cardId);
        } catch (Throwable $e) {
            Log::info('BSICARDS card details not readable right after issuance', [
                'card_id' => $cardId,
                'program' => $program->value,
            ]);

            return [];
        }
    }

    /**
     * Translate BSICARDS's card states into the platform's.
     *
     * Block/unblock is how this platform freezes a BSICARDS card, so a blocked
     * or frozen card reads as `inactive` — the status the platform's own
     * freeze writes — and never as a provider-imposed block. Only a card that
     * is gone for good reads as `blocked`. Unknown words return null, which
     * leaves the local status alone.
     */
    public static function status(?string $status): ?string
    {
        return match (strtolower(trim((string) $status))) {
            'active', 'activated', 'issued', 'approved' => 'active',
            'blocked', 'frozen', 'freeze', 'inactive', 'disabled', 'suspended' => 'inactive',
            'pending', 'processing', 'requested', 'created' => 'pending',
            'terminated', 'deleted', 'deactivated', 'cancelled', 'canceled', 'closed', 'expired' => 'blocked',
            'failed', 'rejected', 'declined' => 'failed',
            default => null,
        };
    }

    /**
     * @return array{0: ?string, 1: ?string} two-digit month and four-digit year
     */
    private function expiry(array $data): array
    {
        $combined = $this->firstString($data, ['expiresAt', 'expiry', 'expiry_date', 'expiryDate']);

        if ($combined !== null && preg_match('#^(\d{1,2})\s*/\s*(\d{2}|\d{4})$#', $combined, $matches)) {
            [$month, $year] = [$matches[1], $matches[2]];
        } else {
            $month = $this->firstString($data, ['expiry_month', 'expmonth', 'expiryMonth', 'exp_month', 'month']);
            $year  = $this->firstString($data, ['expiry_year', 'expyear', 'expiryYear', 'exp_year', 'year']);
        }

        $month = preg_replace('/\D+/', '', (string) $month) ?? '';
        $year  = preg_replace('/\D+/', '', (string) $year)  ?? '';

        if ($month === '' || $year === '' || (int) $month < 1 || (int) $month > 12 || ! in_array(strlen($year), [2, 4], true)) {
            return [null, null];
        }

        return [str_pad($month, 2, '0', STR_PAD_LEFT), strlen($year) === 2 ? '20'.$year : $year];
    }

    private function last4(array $data, ?string $number = null): ?string
    {
        foreach ([$this->firstString($data, ['lastfour', 'last4', 'last4Digits', 'last_four']), $number] as $candidate) {
            $digits = preg_replace('/\D+/', '', (string) $candidate) ?? '';

            if (strlen($digits) >= 4) {
                return substr($digits, -4);
            }
        }

        return null;
    }

    /**
     * The spendable balance as a decimal string.
     *
     * Visa reports one `balance`; Mastercard reports available, ledger and
     * suspense balances under names BSICARDS has not published, so the
     * available one is looked for first and the plain one last.
     */
    private function balance(array $data): ?string
    {
        foreach (['available_balance', 'availableBalance', 'available', 'balance', 'card_balance', 'cardBalance'] as $key) {
            $value = $data[$key] ?? null;

            if (is_array($value)) {
                $value = $value['available'] ?? $value['value'] ?? $value['amount'] ?? null;
            }

            if (is_numeric($value)) {
                return DecimalMoney::round((string) $value, 2);
            }
        }

        return null;
    }

    /**
     * @param list<string> $keys
     */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * A response with every field that could identify or spend the card
     * removed, fit to be stored on the request for debugging.
     *
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function withoutSensitive(array $body): array
    {
        $sensitive = ['card_number', 'cardnumber', 'pan', 'number', 'cvv', 'cvc', 'cvv2', 'pin', 'otp'];

        foreach ($body as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                unset($body[$key]);
            } elseif (is_array($value)) {
                $body[$key] = $this->withoutSensitive($value);
            }
        }

        return $body;
    }

    private function cardholderFor(VirtualCardRequest $request): Cardholders
    {
        $cardholder = Cardholders::query()->with('user')->find($request->cardholder_id);

        if (! $cardholder) {
            throw new NotifyErrorException(__('This card request has no cardholder.'));
        }

        if ($cardholder->card_type === CardholderType::BUSINESS) {
            throw new NotifyErrorException(__('BSICARDS issues cards to personal cardholders only.'));
        }

        if (trim((string) $cardholder->first_name) === '' || trim((string) $cardholder->last_name) === '') {
            throw new NotifyErrorException(__('The cardholder needs a first and last name for BSICARDS.'));
        }

        return $cardholder;
    }

    private function cardholderEmail(Cardholders $cardholder, VirtualCardRequest $request): string
    {
        $email = strtolower(trim((string) ($cardholder->email ?: $cardholder->user?->email ?: $request->user?->email)));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new NotifyErrorException(__('The cardholder needs a valid email address for BSICARDS.'));
        }

        return $email;
    }

    /**
     * The three identity photos the Mastercard program validates, read from
     * where the cardholder form stored them.
     *
     * @return array<string, array{contents: string, filename: string}>
     */
    private function kycFiles(Cardholders $cardholder): array
    {
        $documents = is_array($cardholder->kyc_documents) ? $cardholder->kyc_documents : [];
        $labels    = [
            'documentFront' => ['id_document', __('ID document (front)')],
            'documentBack'  => ['id_document_back', __('ID document (back)')],
            'selfie'        => ['selfie', __('selfie')],
        ];

        $files = [];

        foreach ($labels as $field => [$key, $label]) {
            $path      = trim((string) ($documents[$key] ?? ''));
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if ($path === '' || ! Storage::disk('public')->exists($path)) {
                throw new NotifyErrorException(__('BSICARDS Mastercard needs the cardholder\'s :label. Ask the customer to add it to a cardholder profile first.', ['label' => $label]));
            }

            if (! in_array($extension, Cardholders::KYC_PHOTO_EXTENSIONS, true)) {
                throw new NotifyErrorException(__('BSICARDS Mastercard accepts the :label as a JPG, PNG or WEBP photo only.', ['label' => $label]));
            }

            $files[$field] = [
                'contents' => (string) Storage::disk('public')->get($path),
                'filename' => $field.'.'.$extension,
            ];
        }

        return $files;
    }

    /**
     * The BSICARDS card a provider card id belongs to.
     *
     * The interface hands top-up and withdrawal only the provider's id, but
     * BSICARDS needs the email the card was issued under, which lives on the
     * card row.
     */
    private function cardByProviderId(string $providerCardId): VirtualCard
    {
        $card = VirtualCard::query()
            ->where('provider_card_id', $providerCardId)
            ->whereHas('provider', fn ($query) => $query->where('code', BsiCardsClient::GATEWAY_CODE))
            ->first();

        if (! $card) {
            throw new NotifyErrorException(__('This card is not a BSICARDS card.'));
        }

        return $card;
    }

    private function providerCardId(VirtualCard $card): string
    {
        $meta = is_array($card->meta) ? $card->meta : [];
        $id   = trim((string) ($meta['card_id'] ?? $card->provider_card_id));

        if ($id === '') {
            throw new NotifyErrorException(__('Provider card id is missing for this card.'));
        }

        return $id;
    }

    private function programFor(VirtualCard $card): BsiCardsProgram
    {
        return BsiCardsProgram::forCard($card)
            ?? throw new NotifyErrorException(__('This card does not belong to a BSICARDS program.'));
    }

    /**
     * The email the card was issued under — never the cardholder's current
     * one, which BSICARDS does not know about.
     */
    private function emailFor(VirtualCard $card): string
    {
        $meta  = is_array($card->meta) ? $card->meta : [];
        $email = trim((string) ($meta['bsi_useremail'] ?? ''));

        if ($email === '') {
            throw new NotifyErrorException(__('The email this card was issued under is missing; it cannot be reached at BSICARDS.'));
        }

        return $email;
    }

    private function client(): BsiCardsClient
    {
        return $this->client ??= BsiCardsClient::fromGateway();
    }
}
