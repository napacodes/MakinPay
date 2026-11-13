<?php

namespace App\Services\Payment\Stripe;

use App\Enums\Marketplace\OrderStatus;
use App\Enums\TrxStatus;
use App\Models\MarketplaceOrder;
use App\Models\PaymentGateway;
use App\Models\Transaction as TransactionModel;
use App\Models\Vendor;
use App\Services\Marketplace\MarketplaceOrderHandler;
use App\Services\Marketplace\Split\StripeConnectSplitProvider;
use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use App\Support\WithdrawFieldNormalizer;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Payout;
use Stripe\Stripe;
use Stripe\Webhook;
use Transaction;
use UnexpectedValueException;

class StripePaymentGateway implements PaymentGatewayInterface
{
    /**
     * Reference prefix the marketplace split driver writes to
     * `marketplace_orders.trx_reference`.
     */
    private const MARKETPLACE_REFERENCE_PREFIX = 'marketplace_order_';

    protected array $credentials;

    private string $secretKey;

    private StripeCredentials $keys;

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('stripe');
        $this->keys        = new StripeCredentials($this->credentials);
        $this->secretKey   = $this->keys->secretKey();

        if ($this->secretKey === '') {
            throw new Exception('Stripe secret key is not configured.');
        }

        Stripe::setApiKey($this->secretKey);
    }

    public function deposit($amount, $currency, $trxId)
    {
        // Create a new Checkout session for payment
        $session = CheckoutSession::create([
            'line_items' => [[
                'price_data' => [
                    'currency'     => $currency,
                    'product_data' => [
                        'name' => setting('site_title'),
                    ],
                    // Through StripeMoney, not a bare ×100: a zero-decimal
                    // currency such as JPY or KRW is quoted in whole units, so
                    // multiplying by 100 charged the depositor a hundred times
                    // the amount they entered.
                    'unit_amount' => $this->amountToSubunit((float) $amount, (string) $currency),
                ],
                'quantity' => 1,
            ]],
            'mode'                => 'payment',
            'success_url'         => route('status.success', ['trx_id' => $trxId]),
            'cancel_url'          => route('status.cancel', ['trx_id' => $trxId]),
            'client_reference_id' => $trxId, // Pass the transaction ID here
        ]);

        // Redirect to the Stripe checkout page
        return $session->url;
    }

    /**
     * @param  array<string, mixed>|string|null $withdrawCredential
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function withdraw($amount, $currency, $trxId, $withdrawCredential): array
    {
        $currency    = strtolower((string) $currency);
        $credentials = is_array($withdrawCredential)
            ? $withdrawCredential
            : WithdrawFieldNormalizer::values($withdrawCredential);

        $payload = [
            'amount'      => $this->amountToSubunit((float) $amount, $currency),
            'currency'    => $currency,
            'description' => setting('site_title').' withdrawal '.$trxId,
            'metadata'    => [
                'trx_id' => $trxId,
                'source' => 'wallet-withdraw',
            ],
        ];

        $this->putIfPresent($payload, 'destination', $this->firstCredentialValue($credentials, ['destination', 'destination_id', 'external_account_id']));
        $this->putIfPresent($payload, 'method', $this->firstCredentialValue($credentials, ['method']));
        $this->putIfPresent($payload, 'source_type', $this->firstCredentialValue($credentials, ['source_type']));
        $this->putIfPresent($payload, 'statement_descriptor', $this->statementDescriptor($credentials));

        $options          = [];
        $connectedAccount = $this->firstCredentialValue($credentials, ['connected_account_id', 'stripe_account', 'account_id']);

        if ($connectedAccount !== null) {
            $options['stripe_account'] = $connectedAccount;
        }

        try {
            $payout = Payout::create($payload, $options);
        } catch (ApiErrorException $e) {
            Log::error('Stripe payout failed', [
                'transaction_id' => $trxId,
                'status_code'    => $e->getHttpStatus(),
                'error'          => $e->getMessage(),
            ]);

            throw new Exception('Stripe payout failed: '.$e->getMessage(), previous: $e);
        }

        $response = $payout->toArray();

        return [
            'reference' => (string) ($response['id'] ?? $trxId),
            'status'    => strtolower((string) ($response['status'] ?? 'pending')),
            'data'      => $response,
        ];
    }

    public function handleIPN(Request $request): JsonResponse
    {
        // Retrieve the request's body and parse it
        $payload    = $request->getContent();
        $sig_header = $request->headers->get('Stripe-Signature');

        try {
            // Verify the webhook signature
            $event = $this->constructEvent($payload, (string) $sig_header);
        } catch (UnexpectedValueException $e) {
            // Invalid payload
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            // Invalid signature
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $eventData = $this->stripeObjectToArray($event);
        $eventType = (string) data_get($eventData, 'type');

        // Handle the event based on its type
        switch ($eventType) {
            case 'checkout.session.completed':
                $this->handleCheckoutSessionCompleted(data_get($eventData, 'data.object'));
                break;

            case 'payout.paid':
                $this->handlePayoutCompleted(data_get($eventData, 'data.object'));
                break;

            case 'payout.failed':
            case 'payout.canceled':
                $this->handlePayoutFailed(data_get($eventData, 'data.object'));
                break;

            case 'payout.updated':
                $this->handlePayoutUpdated(data_get($eventData, 'data.object'));
                break;

            case 'account.updated':
                $this->handleConnectAccountUpdated(data_get($eventData, 'data.object'));
                break;

            default:
                // Log or handle unexpected event types
                Log::info('Unhandled Stripe event type: '.$eventType);
                break;
        }

        return response()->json(['status' => 'success']);
    }

    protected function handleCheckoutSessionCompleted($session): void
    {
        $txn = data_get($session, 'client_reference_id');

        if (! is_string($txn) || $txn === '') {
            return;
        }

        // Marketplace checkouts reuse `client_reference_id` for the order
        // reference the split driver wrote, so they are released through the
        // marketplace instead of the wallet transaction ledger.
        if (str_starts_with($txn, self::MARKETPLACE_REFERENCE_PREFIX)) {
            $this->releaseMarketplaceOrder($txn, $this->stripeObjectToArray($session));

            return;
        }

        Transaction::completeTransaction($txn);
    }

    /**
     * Confirm a Stripe Connect marketplace order once Stripe reports the
     * destination charge as paid.
     *
     * @param array<string, mixed> $session
     */
    private function releaseMarketplaceOrder(string $reference, array $session): void
    {
        $order = MarketplaceOrder::query()->where('trx_reference', $reference)->first();

        if (! $order || $order->status !== OrderStatus::PENDING) {
            return;
        }

        $paymentIntent = data_get($session, 'payment_intent');
        $paymentIntent = is_string($paymentIntent) && $paymentIntent !== '' ? $paymentIntent : null;

        $released = app(StripeConnectSplitProvider::class)->release($order, $paymentIntent);

        if ($released->status === OrderStatus::RELEASED) {
            app(MarketplaceOrderHandler::class)->notifyPaid($released);
        }
    }

    /**
     * Keep the vendor's onboarding badge in step with Stripe. Connect sends
     * `account.updated` for every requirement the vendor clears.
     *
     * @param mixed $account
     */
    private function handleConnectAccountUpdated($account): void
    {
        $accountData = $this->stripeObjectToArray($account);
        $accountId   = (string) ($accountData['id'] ?? '');

        if ($accountId === '') {
            return;
        }

        $vendor = Vendor::query()->where('stripe_connect_account_id', $accountId)->first();

        if (! $vendor) {
            return;
        }

        app(StripeConnectService::class)->applyAccountStatus($vendor, $accountData);
    }

    /**
     * Connect events are signed with the Connect endpoint's own secret, which
     * is separate from the account endpoint's. Both endpoints post to the same
     * `/ipn/stripe` URL, so each candidate secret is tried before the request
     * is rejected.
     *
     * @throws SignatureVerificationException
     * @throws UnexpectedValueException
     */
    private function constructEvent(string $payload, string $signature): Event
    {
        $secrets = array_values(array_filter([
            $this->keys->webhookSecret(),
            $this->keys->connectWebhookSecret(),
        ]));

        if ($secrets === []) {
            throw new SignatureVerificationException('Stripe webhook secret is not configured.');
        }

        $lastFailure = null;

        foreach ($secrets as $secret) {
            try {
                return Webhook::constructEvent($payload, $signature, $secret);
            } catch (SignatureVerificationException $e) {
                $lastFailure = $e;
            }
        }

        throw $lastFailure;
    }

    private function handlePayoutCompleted(mixed $payout): void
    {
        $payoutData  = $this->stripeObjectToArray($payout);
        $transaction = $this->findPayoutTransaction($payoutData);

        if (! $transaction) {
            return;
        }

        $this->recordPayoutWebhook($transaction, $payoutData);

        if ($transaction->status === TrxStatus::PENDING) {
            Transaction::completeTransaction($transaction->trx_id, __('Stripe payout paid.'));
        }
    }

    private function handlePayoutFailed(mixed $payout): void
    {
        $payoutData  = $this->stripeObjectToArray($payout);
        $transaction = $this->findPayoutTransaction($payoutData);

        if (! $transaction) {
            return;
        }

        $this->recordPayoutWebhook($transaction, $payoutData);

        if ($transaction->status === TrxStatus::PENDING) {
            Transaction::cancelTransaction(
                $transaction->trx_id,
                (string) ($payoutData['failure_message'] ?? __('Stripe payout failed or was canceled.')),
                true
            );
        }
    }

    private function handlePayoutUpdated(mixed $payout): void
    {
        $payoutData = $this->stripeObjectToArray($payout);
        $status     = strtolower((string) ($payoutData['status'] ?? ''));

        if ($status === 'paid') {
            $this->handlePayoutCompleted($payout);
        } elseif (in_array($status, ['failed', 'canceled', 'cancelled'], true)) {
            $this->handlePayoutFailed($payout);
        }
    }

    /**
     * @param array<string, mixed> $payout
     */
    private function findPayoutTransaction(array $payout): ?TransactionModel
    {
        $payoutId = (string) ($payout['id'] ?? '');
        $trxId    = (string) data_get($payout, 'metadata.trx_id', '');

        if ($payoutId === '' && $trxId === '') {
            return null;
        }

        return TransactionModel::query()
            ->where(function ($query) use ($payoutId, $trxId) {
                if ($trxId !== '') {
                    $query->where('trx_id', $trxId);
                }

                if ($payoutId !== '') {
                    $query->orWhere('trx_reference', $payoutId)
                        ->orWhere('trx_data->stripe_withdraw->reference', $payoutId)
                        ->orWhere('trx_data->stripe_withdraw->data->id', $payoutId);
                }
            })
            ->latest('id')
            ->first();
    }

    /**
     * @param array<string, mixed> $payout
     */
    private function recordPayoutWebhook(TransactionModel $transaction, array $payout): void
    {
        $trxData                          = $transaction->trx_data ?? [];
        $trxData['stripe_payout_webhook'] = $payout;

        $transaction->update(['trx_data' => $trxData]);
    }

    /**
     * @param array<string, mixed> $credentials
     * @param array<int, string>   $keys
     */
    private function firstCredentialValue(array $credentials, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($credentials[$key]) && is_scalar($credentials[$key])) {
                $value = trim((string) $credentials[$key]);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function putIfPresent(array &$payload, string $key, ?string $value): void
    {
        if ($value !== null) {
            $payload[$key] = $value;
        }
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function statementDescriptor(array $credentials): ?string
    {
        $descriptor = $this->firstCredentialValue($credentials, ['statement_descriptor']);

        return $descriptor !== null ? substr($descriptor, 0, 22) : null;
    }

    private function amountToSubunit(float $amount, string $currency): int
    {
        return StripeMoney::toSubunit($amount, $currency);
    }

    /**
     * @return array<string, mixed>
     */
    private function stripeObjectToArray(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode(json_encode($value), true);

        return is_array($decoded) ? $decoded : [];
    }
}
