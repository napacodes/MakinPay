<?php

namespace App\Services\Payment\Paypal;

use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use App\Support\CallbackSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Session;
use Transaction;

class PaypalPaymentGateway implements PaymentGatewayInterface
{
    /**
     * Payout item states PayPal reports as money gone for good: the item was
     * paid out and will not come back.
     */
    private const SETTLED_STATES = ['SUCCESS'];

    /**
     * States in which the money never left, or came back: the withdrawal is
     * reversed and the customer's wallet refunded. Everything else — PENDING,
     * UNCLAIMED, ONHOLD, NEW — is still moving and is left alone.
     */
    private const RETURNED_STATES = ['FAILED', 'BLOCKED', 'RETURNED', 'REVERSED', 'REFUNDED', 'CANCELED', 'DENIED'];

    private PayPalClient $client;

    public function __construct()
    {
        $this->client = app(PayPalClient::class);
    }

    public function deposit($amount, $currency, $trxId)
    {
        $paymentPayload = [
            'intent' => 'sale',
            'payer'  => [
                'payment_method' => 'paypal',
            ],
            'transactions' => [
                [
                    'amount' => [
                        'total'    => number_format($amount, 2),
                        'currency' => $currency,
                    ],
                    'description' => $trxId,
                ],
            ],
            'redirect_urls' => [
                'return_url' => route('ipn.handle', ['gateway' => 'paypal']),
                'cancel_url' => route('status.cancel', ['trx_id' => $trxId]),
            ],
        ];

        $payment = $this->client->createPayment($paymentPayload);
        Session::put('cancel_tnx', $trxId);

        foreach ($payment->links as $link) {
            if ($link->rel === 'approval_url') {
                return $link->href;
            }
        }

        return true;
    }

    public function withdraw($amount, $currency, $trxId, $withdrawCredential): array
    {
        $receiver = is_array($withdrawCredential)
            ? (string) ($withdrawCredential['paypal_email'] ?? $withdrawCredential['email'] ?? reset($withdrawCredential))
            : (string) $withdrawCredential;

        // Build PayPal Payouts request payload
        $payoutData = [
            'sender_batch_header' => [
                'sender_batch_id' => $trxId,
                'email_subject'   => 'You have a payout!',
                'email_message'   => 'You have received a payout!',
            ],
            'items' => [
                [
                    'recipient_type' => 'EMAIL',
                    'receiver'       => $receiver,
                    'note'           => 'Withdrawal Payout',
                    'sender_item_id' => $trxId,
                    'amount'         => [
                        'value'    => number_format($amount, 2, '.', ''),
                        'currency' => $currency,
                    ],
                ],
            ],
        ];

        $response = $this->client->payoutPayment($payoutData);

        return [
            'reference' => data_get($response, 'batch_header.payout_batch_id', $trxId),
            'status'    => strtolower((string) data_get($response, 'batch_header.batch_status', 'pending')),
            'data'      => json_decode(json_encode($response), true) ?: [],
        ];
    }

    public function handleIPN(Request $request)
    {
        // If 'event_type' is present, this is a PayPal webhook event.
        if ($request->has('event_type')) {
            return $this->handleWebhookEvent($request);
        }

        // Otherwise, it's the user returning from PayPal (execute the payment).
        return $this->handleExecutePayment($request);

    }

    /**
     * A webhook body is not verified, so nothing in it decides anything. It
     * names a payout item; PayPal is then asked about that item, and PayPal's
     * answer says which transaction it was for (`sender_item_id`, which we
     * set) and what became of it. Before this, the body alone could complete
     * any withdrawal, and any event type we did not list cancelled one and
     * refunded the wallet — including UNCLAIMED, where PayPal still holds the
     * money for the recipient to collect.
     */
    protected function handleWebhookEvent(Request $request): JsonResponse
    {
        $eventType    = (string) $request->input('event_type', '');
        $payoutItemId = trim((string) $request->input('resource.payout_item_id', ''));

        Log::info('Received PayPal Webhook', ['event_type' => $eventType, 'payout_item_id' => $payoutItemId]);

        if (! str_starts_with($eventType, 'PAYMENT.PAYOUTS-ITEM.')) {
            return response()->json(['status' => 'ignored']);
        }

        if ($payoutItemId === '') {
            return response()->json(['error' => 'Missing payout item'], 400);
        }

        $item = $this->client->getPayoutItem($payoutItemId);

        if ($item === null) {
            Log::warning('PayPal payout item could not be verified', ['payout_item_id' => $payoutItemId]);

            return response()->json(['status' => 'unverified'], 502);
        }

        $trxId  = trim((string) data_get($item, 'payout_item.sender_item_id', ''));
        $status = strtoupper((string) data_get($item, 'transaction_status', ''));

        if ($trxId === '' || CallbackSettlement::find($trxId) === null) {
            Log::warning('PayPal payout item names no transaction of ours', ['payout_item_id' => $payoutItemId]);

            return response()->json(['status' => 'rejected'], 422);
        }

        if (in_array($status, self::SETTLED_STATES, true)) {
            Transaction::completeTransaction($trxId);
        } elseif (in_array($status, self::RETURNED_STATES, true)) {
            Transaction::cancelTransaction(
                $trxId,
                __('Your withdrawal request was unsuccessful. The amount has been refunded. Please contact the administrator for assistance.'),
                true
            );
        } else {
            Log::info('PayPal payout item still in flight; no action taken', ['trx_id' => $trxId, 'status' => $status]);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle the payment execution flow when the user returns from PayPal.
     */
    protected function handleExecutePayment(Request $request): RedirectResponse
    {
        // Validate that required parameters exist; you can add further validation as needed.
        $request->validate([
            'paymentId' => 'required|string',
            'PayerID'   => 'required|string',
        ]);

        // Execute the payment via your PayPal client
        $response = $this->client->executePayment(
            $request->input('paymentId'),
            $request->input('PayerID')
        );

        // Check the response state
        if (isset($response->state) && $response->state === 'approved') {
            // Fetch your transaction ID (assuming you stored it in the 'description')
            $trxId = $response->transactions[0]->description ?? null;

            // Mark the transaction as complete in your system
            Transaction::completeTransaction($trxId);

            // Notify and redirect
            notifyEvs('success', __('Payment Successful'));

            return redirect()->route('status.success', ['trx_id' => $trxId]);
        }

        // If not approved, handle failure accordingly
        notifyEvs('warning', __('Something went wrong. Please try again.'));

        return redirect()->back();
    }
}
