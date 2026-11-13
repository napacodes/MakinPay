<?php

namespace App\Http\Controllers\Api;

use App\Enums\EnvironmentMode;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\PaymentInitiateRequest;
use App\Http\Requests\Merchant\RefundPaymentRequest;
use App\Services\MerchantCheckoutService;
use App\Services\MerchantRefundService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Transaction;

class PaymentController extends Controller
{
    public function initiatePayment(PaymentInitiateRequest $request, MerchantCheckoutService $checkouts): JsonResponse
    {
        try {
            $checkout = $checkouts->create(
                merchant: $request->merchant,
                environment: EnvironmentMode::from($request->get('environment')),
                data: $request->validated(),
            );

            return response()->json([
                'payment_url' => $checkout['payment_url'],
                'info'        => $checkout['info'],
                'fee'         => $checkout['fee'],
                'net_amount'  => $checkout['net_amount'],
            ]);
        } catch (NotifyErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function verifyPayment(Request $request, string $trxId): JsonResponse
    {
        // = request('trx_id');
        if (! $trxId) {
            return response()->json(['error' => 'Transaction ID is required.'], 422);
        }

        $merchant    = $request->merchant;
        $transaction = Transaction::findTransaction($trxId);

        if (! $transaction) {
            return response()->json(['error' => 'Transaction not found.'], 404);
        }

        $trxData = $transaction->trx_data ?? [];
        if ($transaction->trx_type !== TrxType::RECEIVE_PAYMENT || (int) ($trxData['merchant_id'] ?? 0) !== (int) $merchant->id) {
            return response()->json(['error' => 'Transaction not found.'], 404);
        }

        if (($trxData['environment'] ?? null) !== null && $trxData['environment'] !== $request->get('environment')) {
            return response()->json(['error' => 'Transaction not found.'], 404);
        }

        // Use strict enum comparison for status
        return match ($transaction->status) {
            TrxStatus::COMPLETED => response()->json([
                'status'     => 'success',
                'trx_id'     => $transaction->trx_id,
                'amount'     => $transaction->amount,
                'fee'        => $transaction->fee,
                'currency'   => $transaction->currency,
                'net_amount' => $transaction->net_amount,
                'customer'   => [
                    'name'  => $transaction->trx_data['customer_name']  ?? null,
                    'email' => $transaction->trx_data['customer_email'] ?? null,
                ],
                'description'       => $transaction->description,
                'refunded_amount'   => $transaction->refundedAmount(),
                'refundable_amount' => $transaction->refundableAmount(),
                'fully_refunded'    => $transaction->isFullyRefunded(),
                'created_at'        => $transaction->created_at,
                'updated_at'        => $transaction->updated_at,
            ]),
            TrxStatus::FAILED, TrxStatus::CANCELED => response()->json([
                'status'  => 'failed',
                'trx_id'  => $transaction->trx_id,
                'message' => 'Payment failed or canceled.',
            ]),
            default => response()->json([
                'status'  => 'pending',
                'trx_id'  => $transaction->trx_id,
                'message' => 'Payment is still pending.',
            ]),
        };
    }

    /**
     * Refund all or part of a completed merchant payment.
     *
     * Reverses the merchant wallet credit, records a REFUND transaction, and
     * notifies the merchant IPN. Omit `amount` for a full refund or pass a
     * smaller value for a partial one; partial refunds accumulate and an
     * over-refund is rejected.
     */
    public function refundPayment(RefundPaymentRequest $request, MerchantRefundService $refunds): JsonResponse
    {
        $merchant    = $request->merchant;
        $environment = EnvironmentMode::from($request->get('environment', EnvironmentMode::PRODUCTION->value));
        $validated   = $request->validated();

        try {
            $refund = $refunds->refund(
                merchant: $merchant,
                trxId: $validated['trx_id'],
                amount: isset($validated['amount']) ? (float) $validated['amount'] : null,
                reason: $validated['reason']        ?? null,
                refRefund: $validated['ref_refund'] ?? null,
                environment: $environment->value,
            );

            $original = Transaction::findTransaction($validated['trx_id']);

            return response()->json([
                'status'           => 'success',
                'message'          => 'Refund processed successfully.',
                'refund_trx_id'    => $refund->trx_id,
                'original_trx_id'  => $validated['trx_id'],
                'refunded_amount'  => (float) $refund->amount,
                'total_refunded'   => $original ? $original->refundedAmount() : (float) $refund->amount,
                'remaining_refund' => $original ? $original->refundableAmount() : 0,
                'fully_refunded'   => $original ? $original->isFullyRefunded() : false,
                'currency'         => $refund->currency,
            ]);
        } catch (NotifyErrorException $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 422);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 422);
        }
    }

    public function siteInfo()
    {
        return response()->json([
            'site_name'           => setting('site_title'),
            'site_logo'           => asset(setting('logo')),
            'site_url'            => url('/'),
            'gateway_name'        => setting('site_title').' Payment Gateway',
            'gateway_description' => 'Secure payment powered by '.setting('site_title'),
            'security_message'    => 'Your payment information is processed securely. We do not store credit card details.',
            'features'            => [
                'ssl_secured'        => '🔒 SSL Secured',
                'instant_processing' => '⚡ Instant',
                'global_support'     => '🌍 Global',
                'mobile_ready'       => '📱 Mobile Ready',
            ],
            'branding' => [
                'primary_color'   => '#28a745',
                'secondary_color' => '#20c997',
                'powered_by_text' => 'Powered by '.setting('site_title'),
            ],
            'environments' => [
                'production' => 'Production Mode - Live payment processing is active',
                'sandbox'    => 'Test Mode - This is a test transaction. No real money will be charged',
            ],
            'api_version' => '1.0',
            'status'      => 'active',
        ]);
    }
}
