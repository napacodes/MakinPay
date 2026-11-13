<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Payment\Cashmaal\CashmaalPaymentGateway;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Hands the customer to CashMaal.
 *
 * CashMaal's checkout is documented as a POST, while every gateway in this
 * application returns a redirect URL from `deposit()`. Rather than bend that
 * shared contract, the driver returns a signed link to this page, which
 * renders the documented fields and submits them.
 *
 * The route is signed because the page carries the payer's email and the
 * amount. It carries no credential: `web_id` is the public merchant
 * identifier CashMaal expects in the form, and the IPN key never leaves the
 * server.
 */
class CashmaalRedirectController extends Controller
{
    public function __invoke(Request $request, CashmaalPaymentGateway $gateway): View
    {
        $transaction = Transaction::query()
            ->where('trx_id', (string) $request->query('trx_id'))
            ->where('gateway_code', 'cashmaal')
            ->firstOrFail();

        return view('frontend.payment.cashmaal.redirect', [
            'endpoint' => CashmaalPaymentGateway::CHECKOUT_URL,
            'fields'   => $gateway->checkoutFields($transaction),
        ]);
    }
}
