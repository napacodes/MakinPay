<?php

namespace App\Http\Controllers\Frontend;

use App\Exceptions\GatewayBindingViolationException;
use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentGatewayFactory;
use App\Support\GatewayCallbackContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class IPNController extends Controller
{
    protected PaymentGatewayFactory $paymentFactory;

    public function __construct(PaymentGatewayFactory $paymentFactory)
    {
        $this->paymentFactory = $paymentFactory;
    }

    /**
     * Entry point for every provider callback.
     *
     * The gateway named in the URL is established here, once, before any
     * driver runs. Settlement later refuses to act on a transaction this
     * gateway did not create — which is the whole of C6, and is what keeps a
     * callback arriving at one gateway's endpoint from completing another
     * gateway's payment.
     *
     * The gateway is resolved without requiring it to be enabled: money may
     * already sit at a gateway an administrator has since switched off, and
     * stranding it would be worse than settling it. Whether that callback may
     * settle anything is the binding's decision, not this method's.
     */
    public function handleIPN(Request $request, $gateway)
    {
        $gatewayCode = strtolower(trim((string) $gateway));

        try {
            $paymentGateway = $this->paymentFactory->getGateway($gatewayCode, requireActive: false);
        } catch (Throwable $e) {
            // An unknown gateway is not a server fault, and the caller learns
            // nothing beyond the fact that this endpoint does not exist.
            Log::warning('IPN received for an unknown payment gateway', [
                'gateway' => $gatewayCode,
            ]);

            abort(404);
        }

        app(GatewayCallbackContext::class)->initialize($gatewayCode);

        try {
            return $paymentGateway->handleIPN($request);
        } catch (GatewayBindingViolationException $e) {
            // The message names the transaction and both gateways, so it goes
            // to the log and never to an unauthenticated caller.
            Log::warning('IPN rejected: gateway is not bound to this transaction', [
                'gateway' => $gatewayCode,
                'reason'  => $e->getMessage(),
            ]);

            return response()->json(['status' => 'rejected'], 422);
        }
    }
}
