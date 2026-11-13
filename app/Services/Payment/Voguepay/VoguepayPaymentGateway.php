<?php

namespace App\Services\Payment\Voguepay;

use App\Models\PaymentGateway;
use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use App\Support\CallbackSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Transaction;

class VoguepayPaymentGateway implements PaymentGatewayInterface
{
    protected $credentials;

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('voguepay');
    }

    public function deposit($amount, $currency, $trxId)
    {
        // Voguepay checkout URL with parameters
        $checkoutUrl = 'https://voguepay.com/pay/?'.http_build_query([
            'p'              => $this->credentials['merchant_id'],
            'pay_id'         => $trxId,
            'price'          => $amount,
            'cur'            => $currency,
            'merchant_ref'   => $trxId,
            'memo'           => 'Payment for '.setting('site_title'),
            'notify_url'     => route('ipn.handle', ['gateway' => 'voguepay']),
            'success_url'    => route('status.success', ['trx_id' => $trxId]),
            'fail_url'       => route('status.cancel', ['trx_id' => $trxId]),
            'developer_code' => '5a61aca296c05',
        ]);

        return $checkoutUrl;
    }

    /**
     * Voguepay's notification is unsigned, so the body only says which
     * Voguepay transaction to ask about. Voguepay's own record then has to
     * carry this transaction as its merchant reference, be approved, and
     * total what the customer owes. Before this, an approved payment of any
     * size could be pointed at any pending deposit through `merchant_ref`,
     * and the checkout URL is unsigned, so the price in it is the payer's.
     */
    public function handleIPN(Request $request): JsonResponse
    {
        $transactionId = trim((string) $request->input('merchant_ref', ''));
        $voguepayTxnId = trim((string) $request->input('transaction_id', ''));

        if ($transactionId === '' || $voguepayTxnId === '') {
            return response()->json(['error' => 'Missing parameters'], 400);
        }

        $transaction = CallbackSettlement::find($transactionId);

        if ($transaction === null) {
            return $this->refuse('unknown_transaction', $transactionId, $voguepayTxnId);
        }

        try {
            $verificationResponse = Http::get('https://voguepay.com/api/', [
                'v_transaction_id' => $voguepayTxnId,
                'type'             => 'json',
            ]);
        } catch (Throwable $e) {
            Log::error('Voguepay verification request failed: '.$e->getMessage(), ['trx_id' => $transactionId]);

            return response()->json(['status' => 'unverified'], 502);
        }

        $verification = $verificationResponse->json();

        if (! $verificationResponse->successful() || ! is_array($verification)) {
            Log::warning('Voguepay verification unavailable', ['trx_id' => $transactionId, 'http' => $verificationResponse->status()]);

            return response()->json(['status' => 'unverified'], 502);
        }

        if ((string) ($verification['merchant_ref'] ?? '') !== $transactionId) {
            return $this->refuse('reference_mismatch', $transactionId, $voguepayTxnId);
        }

        if (($verification['status'] ?? null) !== 'Approved') {
            Log::info('Voguepay payment not approved for transaction: '.$transactionId, ['status' => $verification['status'] ?? null]);

            return response()->json(['status' => 'pending']);
        }

        if (! CallbackSettlement::amountMatches($transaction, $verification['total'] ?? null, $verification['cur'] ?? null)) {
            return $this->refuse('amount_mismatch', $transactionId, $voguepayTxnId);
        }

        Transaction::completeTransaction($transactionId);
        Log::info('Voguepay payment completed for transaction: '.$transactionId);

        return response()->json(['status' => 'success']);
    }

    private function refuse(string $reason, string $transactionId, string $voguepayTxnId): JsonResponse
    {
        Log::warning('Voguepay IPN refused', [
            'reason'         => $reason,
            'trx_id'         => $transactionId,
            'transaction_id' => $voguepayTxnId,
        ]);

        return response()->json(['status' => 'rejected'], 422);
    }
}
