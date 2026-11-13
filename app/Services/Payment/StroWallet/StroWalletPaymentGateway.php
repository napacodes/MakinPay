<?php

namespace App\Services\Payment\StroWallet;

use App\Enums\TrxStatus;
use App\Enums\VirtualCard\VirtualCardStatus;
use App\Models\PaymentGateway;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualCard;
use App\Notifications\User\CustomMessageNotify;
use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use App\Services\VirtualCard\VirtualCardManager;
use App\Support\DecimalMoney;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class StroWalletPaymentGateway implements PaymentGatewayInterface
{
    /**
     * StroWallet's published card-webhook event → handler-method map.
     * Adding a new event is one row here + one method below.
     *
     * Reference: https://strowallet.readme.io/reference/webhook-for-card
     *
     * @var array<string, string>
     */
    private const CARD_WEBHOOK_EVENTS = [
        'virtualcard.created.complete'                => 'cardCreated',
        'virtualcard.created.failed'                  => 'cardCreationFailed',
        'virtualcard.topup.complete'                  => 'cardTopupSuccess',
        'virtualcard.topup.failed'                    => 'cardTopupFailed',
        'virtualcard.withdrawal.success'              => 'cardWithdrawalSuccess',
        'virtualcard.transaction.authorization'       => 'cardAuthorization',
        'virtualcard.transaction.declined'            => 'cardDeclined',
        'virtualcard.transaction.declined.terminated' => 'cardTerminated',
        'otp.code'                                    => 'cardOtpCode',
    ];

    /**
     * Card notifications that carry no `event` field. StroWallet names them by
     * the key that holds their id instead, so they are recognised by key.
     *
     * Reference: https://strowallet.readme.io/reference/card-authorization
     *
     * @var array<string, string>
     */
    private const AUTHORIZATION_NOTIFICATIONS = [
        'transaction.created' => 'transaction_created',
        'transaction.refund'  => 'transaction_refund',
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $credentials;

    private string $mode;

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('strowallet');
        $this->mode        = filter_var($this->credentials['sandbox'] ?? false, FILTER_VALIDATE_BOOL)
            ? 'sandbox'
            : 'live';

        if (! PaymentGateway::credentialIsFilled('public_key', $this->credentials['public_key'] ?? null)) {
            throw new Exception('StroWallet API credentials are not configured.');
        }
    }

    /**
     * The key that signs the legacy express IPN, or null when none is stored.
     *
     * StroWallet now issues a public key only, so the secret is optional. A
     * blank or placeholder secret must never be used as an HMAC key: anyone
     * can compute a signature over an empty or published key, which would
     * let a forged IPN settle a deposit.
     */
    private function expressSigningSecret(): ?string
    {
        $secret = $this->credentials['secret_key'] ?? null;

        return PaymentGateway::credentialIsFilled('secret_key', $secret) ? (string) $secret : null;
    }

    /**
     * Send the payer to StroWallet's hosted checkout.
     *
     * The documented flow is `POST /pay`, which answers with a StroWallet
     * `reference` and a `checkout_url`; the reference is written on the
     * transaction so the callback can be bound to it and verified through the
     * Checkout Status API. The older `/express/initiate` flow is no longer in
     * StroWallet's reference but is still served, so an installation whose
     * account has not been moved to the new checkout is not stranded: when
     * the host answers that `/pay` does not exist, the express flow is used.
     *
     * Reference: https://strowallet.readme.io/reference/checkout-payment
     */
    public function deposit($amount, $currency, $trxId)
    {
        $transaction = \Transaction::findTransaction($trxId);
        $user        = $transaction ? User::find($transaction->user_id) : null;

        $parameters = [
            'public_key'     => $this->credentials['public_key'],
            'amount'         => $amount,
            'currency'       => $currency,
            'description'    => 'Deposit for transaction '.$trxId,
            'customer_name'  => (string) ($user?->name ?? ''),
            'customer_email' => (string) ($user?->email ?? ''),
            'callback_url'   => route('ipn.handle', ['gateway' => 'strowallet']),
            'success_url'    => route('status.success'),
            'cancel_url'     => route('status.cancel'),
        ];

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(30)
                ->connectTimeout(10)
                ->post(StroWalletEndpoints::checkout($this->credentials), $parameters);
        } catch (ConnectionException $e) {
            Log::error('StroWallet checkout init transport error', ['error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'StroWallet payment failed to initialize.']);
        }

        if (self::routeIsMissing($response)) {
            Log::warning('StroWallet documented checkout is not served on this host; using the express flow.', [
                'status' => $response->status(),
            ]);

            return $this->depositViaExpress($amount, $currency, $trxId, $user);
        }

        $body        = $response->json();
        $reference   = trim((string) data_get($body, 'data.reference', ''));
        $checkoutUrl = trim((string) data_get($body, 'data.checkout_url', ''));

        if (! filter_var(data_get($body, 'status'), FILTER_VALIDATE_BOOL) || $reference === '' || $checkoutUrl === '') {
            Log::error('StroWallet checkout init error', ['status' => $response->status(), 'response' => $body]);

            return back()->withErrors(['error' => 'StroWallet payment failed to initialize.']);
        }

        $this->mergeTransactionData($trxId, [
            'strowallet' => [
                'flow'         => 'checkout',
                'reference'    => $reference,
                'checkout_url' => $checkoutUrl,
            ],
        ], $reference);

        return redirect($checkoutUrl);
    }

    /**
     * The legacy express flow: a GET that answers with a hosted URL, settled
     * later by a signed IPN (`handleDepositIpn`).
     */
    private function depositViaExpress($amount, $currency, string $trxId, ?User $user): RedirectResponse
    {
        if ($this->expressSigningSecret() === null) {
            Log::error('StroWallet express checkout refused: no secret key is stored to verify its IPN.', [
                'transaction_id' => $trxId,
            ]);

            return back()->withErrors(['error' => 'StroWallet payment failed to initialize.']);
        }

        $parameters = [
            'amount'      => $amount,
            'currency'    => $currency,
            'details'     => 'Deposit for transaction '.$trxId,
            'custom'      => $trxId,
            'ipn_url'     => route('ipn.handle', ['gateway' => 'strowallet']),
            'success_url' => route('status.success'),
            'cancel_url'  => route('status.cancel'),
            'public_key'  => $this->credentials['public_key'],
            'name'        => (string) ($user?->name ?? ''),
            'email'       => (string) ($user?->email ?? ''),
        ];

        try {
            $response = Http::acceptJson()
                ->timeout(30)
                ->connectTimeout(10)
                ->withOptions(['allow_redirects' => true])
                ->get(StroWalletEndpoints::express($this->credentials), $parameters);
        } catch (ConnectionException $e) {
            // Without a timeout this call could hang the checkout request until
            // PHP's own limit killed it, and a transport failure used to fall
            // through to json_decode(false) and report a missing redirect URL.
            Log::error('StroWallet payment init transport error', ['error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'StroWallet payment failed to initialize.']);
        }

        $responseData = $response->json();

        if (! is_array($responseData) || (isset($responseData['error']) && $responseData['error'] !== 'ok')) {
            Log::error('StroWallet payment init error', ['response' => $responseData]);

            return back()->withErrors(['error' => 'StroWallet payment failed to initialize.']);
        }

        if (empty($responseData['url'])) {
            Log::error('StroWallet payment missing redirect URL', ['response' => $responseData]);

            return back()->withErrors(['error' => 'StroWallet redirect URL missing.']);
        }

        $this->mergeTransactionData($trxId, ['strowallet' => ['flow' => 'express']]);

        return redirect($responseData['url']);
    }

    /**
     * Whether the host answered that this route does not exist at all.
     *
     * StroWallet's Laravel host answers an unknown route with a JSON 404 or a
     * 405, and its marketing host with an HTML page. Any of those means
     * nothing was executed, which is the one condition under which trying
     * the older endpoint is safe. A 4xx that names a field or a key is an
     * answer from the endpoint and is never treated as "missing".
     */
    public static function routeIsMissing(Response $response): bool
    {
        if (in_array($response->status(), [404, 405], true)) {
            return true;
        }

        return ! is_array($response->json());
    }

    /**
     * Unified IPN + virtual-card webhook receiver.
     *
     * StroWallet uses ONE callback URL: the `callback_url` / `ipn_url` given
     * at deposit time is the same address the dashboard's card webhook and
     * card-authorisation settings point at. Several payload shapes therefore
     * arrive on this route and are told apart by shape, never by trust:
     *
     *   1. Card webhook   → has an `event` field (CARD_WEBHOOK_EVENTS).
     *      Unsigned; used as a trigger to ask StroWallet, never as a source
     *      of money movement.
     *   2. Authorisation  → `authorization.request`; answered YES/NO.
     *   3. Balance check  → `card.balance`; answered with the card balance.
     *   4. Post-purchase  → `transaction.created` / `transaction.refund`;
     *      recorded on the card.
     *   5. Express IPN    → `custom` + `signature`, HMAC-SHA256 over
     *      amount+currency+custom+trx_num with the secret key.
     *   6. Checkout       → `reference` (+ `status`); nothing in it is
     *      trusted — the payment is verified through the Checkout Status
     *      API before the local transaction is touched.
     */
    public function handleIPN(Request $request): JsonResponse
    {
        $payload = $request->all();
        $event   = (string) ($payload['event'] ?? '');

        if ($event !== '') {
            return $this->handleCardWebhook($request, $event);
        }

        if (array_key_exists('authorization.request', $payload)) {
            return $this->answerCardAuthorization($payload);
        }

        if (array_key_exists('card.balance', $payload)) {
            return $this->answerCardBalance($payload);
        }

        foreach (self::AUTHORIZATION_NOTIFICATIONS as $field => $kind) {
            if (array_key_exists($field, $payload)) {
                return $this->recordAuthorizationNotification($payload, $field, $kind);
            }
        }

        if ($request->filled('signature') || $request->filled('custom')) {
            return $this->handleDepositIpn($request);
        }

        if ($request->filled('reference')) {
            return $this->handleCheckoutCallback($request);
        }

        Log::warning('StroWallet IPN rejected: incomplete payload', ['ip' => $request->ip()]);

        return response()->json(['error' => 'Invalid payload'], 400);
    }

    /**
     * StroWallet's real-time authorisation hook.
     *
     * When the dashboard points the card-authorisation webhook at this URL,
     * StroWallet asks before every purchase and treats anything other than
     * `{"APPROVE":"YES"}` under HTTP 200 as a decline. The payload carries no
     * `event` field and its keys contain dots (`authorization.request`,
     * `card_Id`), so it is read as a plain array — Laravel's dotted `input()`
     * would look for a nested `authorization[request]` and find nothing.
     *
     * Approve only a card we issued that is active, and only within the
     * per-transaction limit its holder set. Nothing here moves money: the
     * card's own balance at StroWallet settles the purchase, so an approval
     * from a forged request buys the forger nothing, while a decline is the
     * safe answer for any card this installation does not know.
     *
     * Reference: https://strowallet.readme.io/reference/card-authorization
     *
     * @param array<string, mixed> $payload
     */
    private function answerCardAuthorization(array $payload): JsonResponse
    {
        $card = $this->findCardByAuthorizationPayload($payload);

        if (! $card) {
            Log::info('StroWallet authorization declined: unknown card', ['card_id' => self::authorizationCardId($payload)]);

            return response()->json(['APPROVE' => 'NO', 'reason' => 'Unknown card']);
        }

        $meta   = is_array($card->meta) ? $card->meta : [];
        $amount = $payload['merchantAmount']         ?? $payload['amount'] ?? null;
        $limit  = $meta['limits']['per_transaction'] ?? null;

        $reason = match (true) {
            $card->status !== VirtualCardStatus::Active => 'Card is not active',
            is_numeric($amount) && is_numeric($limit) && (float) $limit                     > 0
                                && DecimalMoney::compare((string) $amount, (string) $limit) > 0 => 'Exceeds the per-transaction limit',
            default                                                                             => null,
        };

        $meta['last_authorization_request'] = [
            'reference' => $payload['authorization.request'] ?? null,
            'amount'    => $amount,
            'channel'   => $payload['channel'] ?? null,
            'type'      => $payload['type']    ?? null,
            'decision'  => $reason === null ? 'approved' : 'declined',
            'reason'    => $reason,
            'at'        => now()->toIso8601String(),
        ];
        $card->update(['meta' => $meta]);

        if ($reason !== null) {
            Log::info('StroWallet authorization declined', ['virtual_card_id' => $card->id, 'reason' => $reason]);

            return response()->json(['APPROVE' => 'NO', 'reason' => $reason]);
        }

        return response()->json(['APPROVE' => 'YES']);
    }

    /**
     * StroWallet's balance-check hook: `{"card.balance": id, "card_Id": ..}`
     * must be answered with `{"balance": n}`. The figure is the balance this
     * installation last verified for the card; a card it never issued has
     * nothing to spend.
     *
     * Reference: https://strowallet.readme.io/reference/card-authorization
     *
     * @param array<string, mixed> $payload
     */
    private function answerCardBalance(array $payload): JsonResponse
    {
        $card = $this->findCardByAuthorizationPayload($payload);

        if (! $card) {
            Log::info('StroWallet balance check for an unknown card', ['card_id' => self::authorizationCardId($payload)]);

            return response()->json(['balance' => 0]);
        }

        $meta    = is_array($card->meta) ? $card->meta : [];
        $balance = is_numeric($meta['balance'] ?? null) ? (float) $meta['balance'] : 0.0;

        return response()->json(['balance' => $balance]);
    }

    /**
     * `transaction.created` (a purchase settled after an approval) and
     * `transaction.refund` (a reversal of one). Both share the shape of the
     * authorisation request and used to be mistaken for one, so every settled
     * purchase was answered with a fresh APPROVE and logged as a decision.
     * They are notifications: recorded on the card, answered 200.
     *
     * @param array<string, mixed> $payload
     */
    private function recordAuthorizationNotification(array $payload, string $field, string $kind): JsonResponse
    {
        $card = $this->findCardByAuthorizationPayload($payload);

        if (! $card) {
            return response()->json(['ok' => true, 'ignored' => 'unknown card']);
        }

        $meta = is_array($card->meta) ? $card->meta : [];

        $eventId = trim((string) ($payload[$field] ?? ''));
        $seen    = is_array($meta['processed_events'] ?? null) ? $meta['processed_events'] : [];

        if ($eventId !== '' && in_array($eventId, $seen, true)) {
            return response()->json(['ok' => true]);
        }

        if ($eventId !== '') {
            $seen[]                   = $eventId;
            $meta['processed_events'] = array_slice($seen, -20);
        }

        $meta['last_'.$kind]       = $payload;
        $meta['last_'.$kind.'_at'] = now()->toIso8601String();
        $card->update(['meta' => $meta]);

        return response()->json(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function authorizationCardId(array $payload): string
    {
        return (string) ($payload['card_Id'] ?? $payload['cardId'] ?? $payload['card_id'] ?? '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function findCardByAuthorizationPayload(array $payload): ?VirtualCard
    {
        $providerCardId = self::authorizationCardId($payload);

        return $providerCardId !== '' ? $this->findCard(['cardId' => $providerCardId]) : null;
    }

    /**
     * Express-deposit IPN.
     *
     * The signature is the only thing that makes this payload trustworthy, so
     * nothing is decided before it verifies — and an unverified caller can no
     * longer decide anything at all. The previous version failed the named
     * transaction *before* rejecting the bad signature, which handed any
     * anonymous caller who knew a pending trx id the power to kill a real
     * deposit.
     *
     * Once the signature holds, the signed amount and currency are still
     * checked against the transaction they claim to settle, matching how every
     * other gateway in this application binds a callback to its payment.
     */
    private function handleDepositIpn(Request $request): JsonResponse
    {
        $amount   = (string) $request->input('amount', '');
        $currency = (string) $request->input('currency', '');
        $custom   = (string) $request->input('custom', '');
        $trxNum   = (string) $request->input('trx_num', '');
        $sentSign = (string) $request->input('signature', '');

        if ($custom === '' || $sentSign === '') {
            Log::warning('StroWallet IPN rejected: incomplete payload', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $secret = $this->expressSigningSecret();

        if ($secret === null) {
            Log::warning('StroWallet express IPN rejected: no secret key is stored to verify it', [
                'ip'             => $request->ip(),
                'transaction_id' => $custom,
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $expectedSign = strtoupper(hash_hmac('sha256', $amount.$currency.$custom.$trxNum, $secret));

        if (! hash_equals($expectedSign, strtoupper($sentSign))) {
            Log::warning('StroWallet IPN signature verification failed', [
                'ip'             => $request->ip(),
                'transaction_id' => $custom,
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $transaction = Transaction::query()->where('trx_id', $custom)->first();

        if (! $transaction) {
            Log::info('StroWallet IPN: unknown transaction reference, ignoring', ['transaction_id' => $custom]);

            return response()->json(['status' => 'ignored']);
        }

        if ($transaction->status !== TrxStatus::PENDING) {
            return response()->json(['status' => 'already_processed']);
        }

        return $this->settleVerifiedPayment($transaction, $amount, $currency, 'StroWallet amount or currency mismatch.');
    }

    /**
     * Checkout callback: `?reference=..&status=..` on the callback URL.
     *
     * The doc is explicit that the callback is a prompt, not proof — "merchants
     * should always verify the payment status using the Checkout Status API
     * instead of relying solely on callback data". So the reference is first
     * bound to the transaction this installation created it for, then the
     * payment is read back from StroWallet with the public key, and only the
     * verified status, amount and currency decide anything. A caller who
     * knows a reference can therefore make this endpoint ask StroWallet a
     * question, and nothing else.
     *
     * Reference: https://strowallet.readme.io/reference/checkout-callback
     */
    private function handleCheckoutCallback(Request $request): JsonResponse
    {
        $reference   = trim((string) $request->input('reference', ''));
        $transaction = Transaction::query()->where('trx_reference', $reference)->first();

        if (! $transaction || (string) data_get($transaction->trx_data, 'strowallet.reference') !== $reference) {
            Log::info('StroWallet checkout callback: unknown reference, ignoring', ['reference' => $reference]);

            return response()->json(['status' => 'ignored']);
        }

        if ($transaction->status !== TrxStatus::PENDING) {
            return response()->json(['status' => 'already_processed']);
        }

        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->connectTimeout(10)
                ->get(StroWalletEndpoints::checkoutStatus($this->credentials), [
                    'public_key' => $this->credentials['public_key'],
                    'reference'  => $reference,
                ]);
        } catch (ConnectionException $e) {
            Log::error('StroWallet checkout verification transport error', [
                'transaction_id' => $transaction->trx_id,
                'error'          => $e->getMessage(),
            ]);

            return response()->json(['status' => 'verification_unavailable'], 502);
        }

        $body = $response->json();

        if (! is_array($body) || ! filter_var($body['status'] ?? false, FILTER_VALIDATE_BOOL) || ! is_array($body['data'] ?? null)) {
            Log::warning('StroWallet checkout verification did not confirm the payment', [
                'transaction_id' => $transaction->trx_id,
                'http_status'    => $response->status(),
                'message'        => is_array($body) ? ($body['message'] ?? null) : null,
            ]);

            return response()->json(['status' => 'verification_failed'], 502);
        }

        $verified = $body['data'];

        if ((string) ($verified['reference'] ?? '') !== $reference) {
            Log::warning('StroWallet checkout verification answered for a different reference', [
                'transaction_id' => $transaction->trx_id,
            ]);

            return response()->json(['status' => 'reference_mismatch'], 400);
        }

        $stored = data_get($transaction->trx_data, 'strowallet');

        $this->mergeTransactionData($transaction->trx_id, [
            'strowallet' => array_merge(is_array($stored) ? $stored : [], [
                'checkout_verify' => collect($verified)->only(['reference', 'amount', 'currency', 'payment_status', 'paid_at'])->all(),
            ]),
        ]);

        $paymentStatus = strtolower(trim((string) ($verified['payment_status'] ?? '')));

        if ($paymentStatus === 'paid') {
            return $this->settleVerifiedPayment(
                $transaction,
                (string) ($verified['amount'] ?? ''),
                (string) ($verified['currency'] ?? ''),
                'StroWallet checkout amount or currency mismatch.'
            );
        }

        if (in_array($paymentStatus, ['failed', 'cancelled', 'canceled', 'expired'], true)) {
            \Transaction::failTransaction($transaction->trx_id, 'StroWallet reported the checkout as '.$paymentStatus.'.');

            return response()->json(['status' => 'failed']);
        }

        return response()->json(['status' => 'pending']);
    }

    /**
     * Settle a payment whose amount and currency have been verified with
     * StroWallet — by signature (express) or by the status API (checkout).
     * A short payment or a foreign currency fails the row rather than
     * crediting whatever arrived.
     */
    private function settleVerifiedPayment(Transaction $transaction, string $amount, string $currency, string $mismatchRemark): JsonResponse
    {
        $expectedAmount   = (string) $transaction->payable_amount;
        $expectedCurrency = strtoupper((string) $transaction->payable_currency);

        if (! is_numeric($amount)
            || DecimalMoney::compare($amount, $expectedAmount) < 0
            || strtoupper($currency) !== $expectedCurrency) {
            Log::warning('StroWallet IPN: amount or currency mismatch', [
                'transaction_id'    => $transaction->trx_id,
                'expected_amount'   => $expectedAmount,
                'reported_amount'   => $amount,
                'expected_currency' => $expectedCurrency,
                'reported_currency' => $currency,
            ]);

            \Transaction::failTransaction($transaction->trx_id, $mismatchRemark);

            return response()->json(['status' => 'amount_mismatch'], 400);
        }

        \Transaction::completeTransaction($transaction->trx_id);

        return response()->json(['status' => 'success']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mergeTransactionData(string $trxId, array $data, ?string $reference = null): void
    {
        $transaction = Transaction::query()->where('trx_id', $trxId)->first();

        if (! $transaction) {
            return;
        }

        $updates = [
            'trx_data' => array_merge(is_array($transaction->trx_data) ? $transaction->trx_data : [], $data),
        ];

        if ($reference !== null) {
            $updates['trx_reference'] = $reference;
        }

        $transaction->update($updates);
    }

    /**
     * Dispatch a virtual-card webhook event to its handler. Always
     * returns 200 so StroWallet does not enter a 3-retry storm —
     * failures are logged instead.
     */
    private function handleCardWebhook(Request $request, string $event): JsonResponse
    {
        $payload = $request->all();
        $method  = self::CARD_WEBHOOK_EVENTS[$event] ?? null;

        if (! $method) {
            Log::info('StroWallet card webhook received unknown event', ['event' => $event]);

            return response()->json(['ok' => true, 'ignored' => $event]);
        }

        try {
            $this->{$method}($payload);
        } catch (Throwable $e) {
            Log::error('StroWallet card webhook handler error', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Handle `virtualcard.created.complete`.
     *
     * Payload fields (per the StroWallet doc):
     *   id, event, amount, cardId, cardBrand, lastFour, status,
     *   createdStatus, reference.
     *
     * Strowallet's async issuance pipeline can fire this event with
     * `createdStatus="failed"` when KYC review rejects the card after
     * we've already saved a row. Marking it Active in that case
     * would lie to the user. Only flip to Active when both fields
     * confirm success.
     *
     * Also backfills `last4` and `brand` from the event because the
     * initial `issueCard()` response can return them as null on the
     * async path — the webhook is the source of truth.
     *
     * @param array<string, mixed> $event
     */
    private function cardCreated(array $event): void
    {
        $card = $this->findCard($event);
        if (! $card) {
            return;
        }

        // StroWallet does not sign card webhooks, so the payload is only a
        // prompt to ask StroWallet itself. The verified answer carries the
        // status, last four and expiry; the payload's own copy is never
        // written. If the detail fetch is not ready yet the row stays pending
        // and the scheduled sync asks again.
        if ($this->syncCardFromProvider($card, 'virtualcard.created.complete')) {
            return;
        }

        $meta                          = is_array($card->meta) ? $card->meta : [];
        $meta['last_created_event']    = $event;
        $meta['last_created_event_at'] = now()->toIso8601String();
        $card->update(['meta' => $meta]);
    }

    /**
     * Handle `virtualcard.created.failed`.
     *
     * Documented payload: id, event, cardId, status, createdStatus,
     * failureReason, reference, balanceCurrency, name, refundAmount. This is
     * the event StroWallet sends when KYC review rejects a card after
     * `create-card` already handed us an id, so the row we saved as pending
     * must become failed — and the reason must reach the admin, who otherwise
     * sees a card stuck in "pending" forever.
     *
     * @param array<string, mixed> $event
     */
    private function cardCreationFailed(array $event): void
    {
        $card = $this->findCard($event);
        if (! $card) {
            return;
        }

        $meta                   = is_array($card->meta) ? $card->meta : [];
        $meta['failure_reason'] = trim((string) ($event['failureReason'] ?? $event['reason'] ?? ''))
            ?: __('Provider declined the card creation.');
        $meta['creation_failed_at'] = now()->toIso8601String();

        // The reason is recorded first so the sync's verified `failed` keeps
        // StroWallet's wording. The sync is also what returns the initial
        // load to the wallet — a refund must rest on StroWallet's answer, not
        // on an unsigned payload that anyone who knows a card id could post.
        $card->update(['meta' => $meta]);

        if ($this->syncCardFromProvider($card, 'virtualcard.created.failed')) {
            return;
        }

        // Unverifiable for now: the status is flipped so the customer is not
        // shown a card that will never arrive, and the load stays where it is
        // until an admin refresh or the scheduled sync can confirm it.
        $card->update(['status' => VirtualCardStatus::Failed->value]);

        Log::warning('StroWallet reported a failed card but the card could not be verified; the initial load is pending review.', [
            'virtual_card_id' => $card->id,
        ]);
    }

    /**
     * Ask StroWallet about a card and write down what it says.
     *
     * Returns false when the provider could not be consulted, so the caller
     * can fall back to a status-only note that never touches money.
     */
    private function syncCardFromProvider(VirtualCard $card, string $event): bool
    {
        try {
            app(VirtualCardManager::class)->syncCardFromProvider($card);

            return true;
        } catch (Throwable $e) {
            Log::warning('StroWallet card webhook: provider verification not available yet.', [
                'event'           => $event,
                'virtual_card_id' => $card->id,
                'error'           => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Handle `virtualcard.topup.failed`.
     *
     * Documented payload: id, event, status, reference, refundReference,
     * amount, charge, netAmount, currency, message, transactionReference,
     * timestamp — and no cardId. The top-up itself was already resolved
     * synchronously by `VirtualCardManager` (refunded on a refusal, parked on
     * an unknown outcome), so this is logged for reconciliation rather than
     * acted on: an unsigned event must not be able to refund a wallet.
     *
     * @param array<string, mixed> $event
     */
    private function cardTopupFailed(array $event): void
    {
        Log::warning('StroWallet reported a failed card top-up; reconcile against the local attempt.', [
            'reference'        => $event['reference']       ?? null,
            'refund_reference' => $event['refundReference'] ?? null,
            'amount'           => $event['amount']          ?? null,
            'currency'         => $event['currency']        ?? null,
            'message'          => $event['message']         ?? null,
        ]);

        $card = $this->findCard($event);
        if ($card) {
            $this->recordCardEvent($event, 'topup');
        }
    }

    /**
     * Handle `virtualcard.topup.complete`.
     *
     * Payload: id, event, date, amount, cardId, status, reference,
     * reason, narrative, isTerminated.
     *
     * The event fires for both successful AND failed topups (it's the
     * "complete" — meaning settled either way — event), and it arrives after
     * `VirtualCardManager` has already booked the same movement. It is
     * therefore recorded, not re-applied — see `recordCardEvent()`.
     *
     * @param array<string, mixed> $event
     */
    private function cardTopupSuccess(array $event): void
    {
        $this->recordCardEvent($event, 'topup');
    }

    /**
     * Handle `virtualcard.withdrawal.success`.
     *
     * Payload: id, event, date, amount, fee, credited, cardId, reason,
     * status, narrative, reference.
     *
     * Same handling as topup — Strowallet ships this event for both success
     * and failure paths, and the withdrawal has already been booked locally,
     * so the event is recorded rather than applied a second time.
     *
     * @param array<string, mixed> $event
     */
    private function cardWithdrawalSuccess(array $event): void
    {
        $this->recordCardEvent($event, 'withdrawal');
    }

    /**
     * Record a settled money movement without re-booking it.
     *
     * Top-ups and withdrawals are initiated by us, and `VirtualCardManager`
     * already debits the wallet, writes the ledger entry, and moves the
     * on-card balance inside the same synchronous call — under a lock, and
     * idempotently. This webhook is StroWallet telling us about that same
     * movement afterwards.
     *
     * It therefore records, and only records. Applying the amount again added
     * the top-up to `meta.balance` a second time (and subtracted a withdrawal
     * twice), and writing the local transaction's status straight from the
     * payload bypassed the gateway binding and idempotency that
     * `TransactionService` exists to enforce — while a `status=failed` event
     * marked a ledger row failed without ever refunding the wallet it had
     * already debited. StroWallet does not sign card webhooks, so no
     * balance-changing decision may rest on this payload.
     *
     * A provider outcome that contradicts our own is logged for an operator to
     * reconcile against the provider's authoritative balance (admin →
     * "Refresh card"), which is the one place that reads it from the source.
     *
     * @param array<string, mixed> $event
     */
    private function recordCardEvent(array $event, string $kind): void
    {
        $card = $this->findCard($event);

        if (! $card) {
            return;
        }

        $meta = is_array($card->meta) ? $card->meta : [];

        $eventId = trim((string) ($event['id'] ?? ''));
        $seen    = is_array($meta['processed_events'] ?? null) ? $meta['processed_events'] : [];

        if ($eventId !== '' && in_array($eventId, $seen, true)) {
            return;
        }

        if ($eventId !== '') {
            $seen[]                   = $eventId;
            $meta['processed_events'] = array_slice($seen, -20);
        }

        $meta['last_'.$kind.'_event']    = $event;
        $meta['last_'.$kind.'_event_at'] = now()->toIso8601String();

        $card->update(['meta' => $meta]);

        if (! $this->eventIsSuccess($event)) {
            Log::warning('StroWallet reported a failed card movement; reconcile the card balance.', [
                'kind'            => $kind,
                'virtual_card_id' => $card->id,
                'reference'       => $event['reference'] ?? null,
                'status'          => $event['status']    ?? null,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function cardAuthorization(array $event): void
    {
        // Merchant authorization — record on meta for audit but don't
        // touch the card status or our wallet ledger. The float lives at
        // StroWallet; we don't double-book the spend.
        $card = $this->findCard($event);
        if (! $card) {
            return;
        }
        $meta                          = is_array($card->meta) ? $card->meta : [];
        $meta['last_authorization']    = $event;
        $meta['last_authorization_at'] = now()->toIso8601String();
        $card->update(['meta' => $meta]);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function cardDeclined(array $event): void
    {
        $card = $this->findCard($event);
        if (! $card) {
            return;
        }
        $meta                  = is_array($card->meta) ? $card->meta : [];
        $meta['last_declined'] = $event;
        $card->update(['meta' => $meta]);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function cardTerminated(array $event): void
    {
        $card = $this->findCard($event);
        if (! $card) {
            return;
        }
        $card->update(['status' => VirtualCardStatus::Blocked->value]);
    }

    /**
     * Handle `otp.code`: the one-time code a merchant's 3-D Secure step asks
     * the cardholder for. StroWallet delivers it only here, so until this
     * event was handled every online purchase that challenged the holder
     * failed for want of a code nobody ever saw. The code goes straight to
     * the cardholder through their in-app feed and email; it is never
     * written to the log or to the card row.
     *
     * Documented payload: id, event, authorizationCode, cardId, cardBrand,
     * currency, last4, createdStatus, reference.
     *
     * @param array<string, mixed> $event
     */
    private function cardOtpCode(array $event): void
    {
        $card = $this->findCard($event);
        if (! $card) {
            Log::info('StroWallet OTP received for an unknown card', ['card_id' => $event['cardId'] ?? null]);

            return;
        }

        $code = trim((string) ($event['authorizationCode'] ?? ''));
        if ($code === '') {
            return;
        }

        $meta                = is_array($card->meta) ? $card->meta : [];
        $meta['last_otp_at'] = now()->toIso8601String();
        $card->update(['meta' => $meta]);

        $last4 = trim((string) ($event['last4'] ?? $card->last4 ?? ''));
        $user  = $card->user;

        if (! $user) {
            return;
        }

        $user->notify(new CustomMessageNotify(
            __('Your one-time authorization code for card ****:last4 is :code. Enter it to complete your purchase. If you did not start a purchase, freeze the card now.', [
                'last4' => $last4 !== '' ? $last4 : '••••',
                'code'  => $code,
            ]),
            ['database', 'mail'],
            __('Card authorization code'),
        ));
    }

    /**
     * Find the card this event belongs to.
     *
     * Strowallet's documented payload uses `cardId` (camelCase) across
     * every webhook event. The previous fallback to `$event['id']` was
     * a bug — `id` is the EVENT id, not the card id, so dropping a real
     * `cardId` (e.g. due to a typo in a hand-fired test webhook) would
     * silently match a card by unrelated UUID.
     *
     * `card_id` is kept as a defensive fallback only for case-variant
     * payloads we've occasionally seen in legacy logs.
     *
     * @param array<string, mixed> $event
     */
    private function findCard(array $event): ?VirtualCard
    {
        $providerCardId = $event['cardId']
            ?? $event['card_id']
            ?? null;

        if (! $providerCardId) {
            return null;
        }

        return VirtualCard::query()
            ->whereJsonContains('meta->card_id', $providerCardId)
            ->first()
            ?? VirtualCard::query()
                ->where('provider_card_id', $providerCardId)
                ->first();
    }

    /**
     * Whether a webhook event's `status` field signals success.
     *
     * Strowallet uses the same event name (e.g. `virtualcard.topup.complete`)
     * for both success and failure outcomes — the `status` field is the
     * actual discriminator. Treat empty/missing status as success so
     * minimal payloads (and sandbox events that omit it) keep working.
     *
     * @param array<string, mixed> $event
     */
    private function eventIsSuccess(array $event): bool
    {
        $status = strtolower(trim((string) ($event['status'] ?? '')));

        if ($status === '') {
            return true;
        }

        return in_array($status, ['success', 'successful', 'completed', 'complete', 'ok'], true);
    }
}
