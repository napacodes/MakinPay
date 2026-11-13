<section id="recurring-payments" class="content-section">
    <div class="endpoint-heading">
        <span class="api-feature-card__icon api-feature-card__icon--blue" aria-hidden="true">
            <i class="fas fa-sync-alt"></i>
        </span>
        <div>
            <h2>{{ __('Recurring Payments & Subscriptions') }}</h2>
            <p>{{ __('Create, retrieve, cancel, and renew external subscriptions through dedicated Merchant API endpoints. DigiKash persists the schedule, generates hosted invoices, and advances the subscription after verified payment completion.') }}</p>
        </div>
    </div>

    <div class="api-alert api-alert-warning api-alert-modern">
        <strong><i class="fas fa-circle-exclamation me-2"></i>{{ __('Customer-authorized recurring billing') }}</strong>
        {{ __('DigiKash automatically creates each due renewal invoice, but it does not store a buyer card or payment mandate. The customer authorizes every hosted checkout. Unattended card-on-file charging still requires a gateway-specific recurring-billing customization.') }}
    </div>

    <div class="api-feature-grid">
        <article class="api-feature-card">
            <span class="api-feature-card__icon api-feature-card__icon--green" aria-hidden="true">
                <i class="fas fa-wallet"></i>
            </span>
            <h3>{{ __('Built-in plans') }}</h3>
            <p>{{ __('DigiKash platform plans can renew automatically from a user wallet when the server scheduler is running. Trials, grace periods, cancellations, plan changes, transactions, and invoices are handled inside DigiKash.') }}</p>
        </article>
        <article class="api-feature-card">
            <span class="api-feature-card__icon api-feature-card__icon--blue" aria-hidden="true">
                <i class="fas fa-code"></i>
            </span>
            <h3>{{ __('Dedicated API lifecycle') }}</h3>
            <p>{{ __('DigiKash stores the external reference, amount, cycle, next billing date, status, and invoices. Merchant-scoped endpoints expose the current state without leaking another merchant or environment.') }}</p>
        </article>
        <article class="api-feature-card">
            <span class="api-feature-card__icon api-feature-card__icon--amber" aria-hidden="true">
                <i class="fas fa-user-check"></i>
            </span>
            <h3>{{ __('Customer authorization') }}</h3>
            <p>{{ __('Send or display the invoice payment_url. DigiKash activates or extends the subscription only after payment completion; your application should still verify the signed completed IPN before granting its own access.') }}</p>
        </article>
    </div>

    <h3>{{ __('Subscription Endpoints') }}</h3>
    <table class="api-table api-endpoint-table">
        <thead>
            <tr>
                <th>{{ __('Method') }}</th>
                <th>{{ __('Endpoint') }}</th>
                <th>{{ __('Purpose') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><span class="method-badge method-post">POST</span></td>
                <td><code>/api/v1/subscriptions</code></td>
                <td>{{ __('Create a subscription and its first invoice, or schedule it for a future start.') }}</td>
            </tr>
            <tr>
                <td><span class="method-badge method-get">GET</span></td>
                <td><code>/api/v1/subscriptions</code></td>
                <td>{{ __('List subscriptions for the authenticated merchant and selected environment.') }}</td>
            </tr>
            <tr>
                <td><span class="method-badge method-get">GET</span></td>
                <td><code>/api/v1/subscriptions/{subscriptionId}</code></td>
                <td>{{ __('Retrieve current status and the latest 20 invoices.') }}</td>
            </tr>
            <tr>
                <td><span class="method-badge method-post">POST</span></td>
                <td><code>/api/v1/subscriptions/{subscriptionId}/cancel</code></td>
                <td>{{ __('Cancel at period end by default, or immediately with at_period_end=false.') }}</td>
            </tr>
            <tr>
                <td><span class="method-badge method-post">POST</span></td>
                <td><code>/api/v1/subscriptions/{subscriptionId}/renew</code></td>
                <td>{{ __('Create or recover a due hosted renewal checkout. Active subscriptions cannot renew early.') }}</td>
            </tr>
        </tbody>
    </table>

    <h3>{{ __('Required Headers') }}</h3>
    <table class="api-table">
        <thead>
            <tr>
                <th>{{ __('Header') }}</th>
                <th>{{ __('Required') }}</th>
                <th>{{ __('Description') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>X-Merchant-Key</code> / <code>X-API-Key</code></td>
                <td>{{ __('Yes') }}</td>
                <td>{{ __('Use the credentials that belong to the selected sandbox or production environment.') }}</td>
            </tr>
            <tr>
                <td><code>X-Environment</code></td>
                <td>{{ __('Recommended') }}</td>
                <td><code>production</code> {{ __('or') }} <code>sandbox</code>. {{ __('Defaults to production.') }}</td>
            </tr>
            <tr>
                <td><code>X-Timestamp</code> / <code>X-Signature</code></td>
                <td>{{ __('Yes') }}</td>
                <td>{{ __('HMAC-SHA256 request authentication described in the Authentication section.') }}</td>
            </tr>
            <tr>
                <td><code>Idempotency-Key</code></td>
                <td>{{ __('Strongly recommended') }}</td>
                <td>{{ __('A UUID or another unique value, up to 255 characters. Retrying the identical request within 24 hours returns the original response with Idempotent-Replayed: true. Reusing the key with different parameters returns 409.') }}</td>
            </tr>
        </tbody>
    </table>

    <h3>{{ __('Create Subscription Fields') }}</h3>
    <table class="api-table">
        <thead>
            <tr>
                <th>{{ __('Field') }}</th>
                <th>{{ __('Required') }}</th>
                <th>{{ __('Rules') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr><td><code>external_reference</code></td><td>{{ __('Yes') }}</td><td>{{ __('Unique per merchant and environment; maximum 100 characters.') }}</td></tr>
            <tr><td><code>billing_cycle</code></td><td>{{ __('Yes') }}</td><td><code>daily</code>, <code>weekly</code>, <code>monthly</code>, <code>half_yearly</code>, {{ __('or') }} <code>yearly</code>.</td></tr>
            <tr><td><code>payment_amount</code></td><td>{{ __('Yes') }}</td><td>{{ __('Numeric and at least 1 in the selected currency.') }}</td></tr>
            <tr><td><code>currency_code</code></td><td>{{ __('Yes') }}</td><td>{{ __('An enabled currency for this merchant.') }}</td></tr>
            <tr><td><code>customer_email</code></td><td>{{ __('Yes') }}</td><td>{{ __('Valid email; maximum 150 characters.') }}</td></tr>
            <tr><td><code>success_redirect</code>, <code>cancel_redirect</code>, <code>ipn_url</code></td><td>{{ __('Yes') }}</td><td>{{ __('Absolute URLs with a maximum of 255 characters. ipn_url must use HTTPS and resolve to a public address; redirects and private/internal webhook targets are blocked.') }}</td></tr>
            <tr><td><code>customer_name</code>, <code>description</code></td><td>{{ __('No') }}</td><td>{{ __('Customer-facing context for the hosted invoice.') }}</td></tr>
            <tr><td><code>starts_at</code></td><td>{{ __('No') }}</td><td>{{ __('ISO 8601 date-time. Omit for immediate checkout; past start dates are rejected.') }}</td></tr>
            <tr><td><code>allow_payment_methods</code></td><td>{{ __('No') }}</td><td>{{ __('A method code, comma-separated codes, or an array of enabled method codes.') }}</td></tr>
            <tr><td><code>metadata</code></td><td>{{ __('No') }}</td><td>{{ __('JSON object with at most 50 top-level entries. Do not store secrets or card data.') }}</td></tr>
        </tbody>
    </table>

    <h3>{{ __('Subscription Status Lifecycle') }}</h3>
    <table class="api-table">
        <thead>
            <tr>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Meaning') }}</th>
                <th>{{ __('Merchant action') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr><td><code>pending</code></td><td>{{ __('The first invoice is unpaid or the future start date has not arrived.') }}</td><td>{{ __('Open latest_invoice.payment_url when available.') }}</td></tr>
            <tr><td><code>active</code></td><td>{{ __('The current billing period has a verified completed payment.') }}</td><td>{{ __('Grant access only through current_period_end.') }}</td></tr>
            <tr><td><code>past_due</code></td><td>{{ __('The paid period ended or the due renewal failed and payment is still required.') }}</td><td>{{ __('Deliver the pending checkout or call the renew endpoint after failure or expiry.') }}</td></tr>
            <tr><td><code>cancelled</code></td><td>{{ __('No more invoices will be generated.') }}</td><td>{{ __('Create a new subscription to restart billing.') }}</td></tr>
        </tbody>
    </table>

    <h3>{{ __('Recommended Renewal Flow') }}</h3>
    <div class="api-step-grid">
        <article class="api-step-card">
            <span class="api-step-card__number">{{ __('STEP 1') }}</span>
            <h3>{{ __('Create subscription') }}</h3>
            <p>{{ __('POST the customer, amount, billing cycle, callbacks, and your unique external_reference.') }}</p>
        </article>
        <article class="api-step-card">
            <span class="api-step-card__number">{{ __('STEP 2') }}</span>
            <h3>{{ __('Collect first invoice') }}</h3>
            <p>{{ __('Open latest_invoice.payment_url so the customer can authorize the initial hosted checkout.') }}</p>
        </article>
        <article class="api-step-card">
            <span class="api-step-card__number">{{ __('STEP 3') }}</span>
            <h3>{{ __('Confirm payment') }}</h3>
            <p>{{ __('Verify the completed IPN. DigiKash marks the invoice paid, activates access, and sets next_billing_at.') }}</p>
        </article>
        <article class="api-step-card">
            <span class="api-step-card__number">{{ __('STEP 4') }}</span>
            <h3>{{ __('Collect renewals') }}</h3>
            <p>{{ __('The scheduler creates due invoices. Poll GET subscription, then deliver the new pending invoice payment_url to the customer.') }}</p>
        </article>
    </div>

    <div class="api-flow-strip" aria-label="{{ __('External recurring payment flow') }}">
        <span>{{ __('Create subscription') }}</span>
        <i class="fas fa-arrow-right" aria-hidden="true"></i>
        <span>{{ __('Hosted invoice') }}</span>
        <i class="fas fa-arrow-right" aria-hidden="true"></i>
        <span>{{ __('Completed IPN') }}</span>
        <i class="fas fa-arrow-right" aria-hidden="true"></i>
        <span>{{ __('Active period') }}</span>
        <i class="fas fa-arrow-right" aria-hidden="true"></i>
        <span>{{ __('Next due invoice') }}</span>
    </div>

    <h3>{{ __('Complete PHP Example: Monthly Renewal') }}</h3>
    <p>{{ __('This server-side example creates a monthly subscription, signs the exact raw JSON body, calls DigiKash, and redirects the customer to the first hosted invoice.') }}</p>

    <div class="code-block">
        <pre><code class="php">&lt;?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$path = '/api/v1/subscriptions';
$payload = [
    'external_reference' => 'CUSTOMER-PRO-1042',
    'billing_cycle' => 'monthly',
    'currency_code' => 'USD',
    'payment_amount' => 29.00,
    'description' => 'Pro plan monthly subscription',
    'customer_name' => 'Jane Doe',
    'customer_email' => 'jane@example.com',
    'success_redirect' => 'https://merchant.example/billing/success',
    'cancel_redirect' => 'https://merchant.example/billing/cancel',
    'ipn_url' => 'https://merchant.example/webhooks/{{ strtolower(setting('site_title')) }}',
];

$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$timestamp = (string) time();
$idempotencyKey = (string) Str::uuid(); // Persist this key until the request succeeds.
$signaturePayload = implode('.', [$timestamp, 'POST', $path, $body]);
$signature = hash_hmac(
    'sha256',
    $signaturePayload,
    config('services.{{ strtolower(setting('site_title')) }}.api_secret'),
);

$checkout = Http::withHeaders([
    'Accept' => 'application/json',
    'Content-Type' => 'application/json',
    'X-Environment' => 'production',
    'X-Merchant-Key' => config('services.{{ strtolower(setting('site_title')) }}.merchant_key'),
    'X-API-Key' => config('services.{{ strtolower(setting('site_title')) }}.api_key'),
    'X-Timestamp' => $timestamp,
    'X-Signature' => 'sha256='.$signature,
    'Idempotency-Key' => $idempotencyKey,
])->withBody($body, 'application/json')
    ->post('{{ request()->getSchemeAndHttpHost() }}'.$path)
    ->throw()
    ->json();

// Save the subscription ID and open the first hosted invoice.
$subscription = $checkout['data'];

return redirect()->away($subscription['latest_invoice']['payment_url']);</code></pre>
    </div>

    <h3>{{ __('Expected Checkout Response') }}</h3>
    <div class="response-example">
        <div class="response-header">
            <span class="status-code status-200">201 Created</span>
            {{ __('Subscription and first hosted invoice created') }}
        </div>
        <div class="response-body">
            <div class="code-block">
                <pre><code class="json">{
  "data": {
    "id": "2bbcf684-3156-43ac-a06d-a5c641641eef",
    "external_reference": "CUSTOMER-PRO-1042",
    "environment": "production",
    "status": "pending",
    "billing_cycle": "monthly",
    "amount": "29.00000000",
    "currency_code": "USD",
    "next_billing_at": "2026-08-13T06:00:00+00:00",
    "latest_invoice": {
      "id": "cbf09fac-c188-4219-ac66-e114a7b48dc5",
      "reference": "MSI-2BBCF684315643AC-1",
      "sequence": 1,
      "status": "pending",
      "payment_url": "https://{{ setting('site_title') }}.example/payment/checkout?token=encrypted&amp;signature=signed",
      "checkout_expires_at": "2026-08-13T21:00:00+00:00"
    }
  }
}</code></pre>
            </div>
        </div>
    </div>

    <h3>{{ __('List, Cancel, and Renew Examples') }}</h3>
    <div class="code-block">
        <pre><code class="bash"># List, 1-100 records per page. Sign the complete path including the query string.
GET /api/v1/subscriptions?per_page=20&amp;page=1

# Retrieve one subscription and its latest 20 invoices.
GET /api/v1/subscriptions/{subscriptionId}

# Cancel at the end of the paid period (default).
POST /api/v1/subscriptions/{subscriptionId}/cancel
Idempotency-Key: 63632250-3170-421f-8021-9d0a730e90aa
{"at_period_end":true}

# Cancel immediately and invalidate every pending hosted invoice.
POST /api/v1/subscriptions/{subscriptionId}/cancel
Idempotency-Key: a2ce7c46-4bb8-4675-8c45-58f2eed6ff84
{"at_period_end":false}

# Recover a due, failed, or expired renewal checkout.
POST /api/v1/subscriptions/{subscriptionId}/renew
Idempotency-Key: 890508df-f54c-4ec0-a603-e4f5da2a8206
{"force_new":false}</code></pre>
    </div>

    <div class="api-alert api-alert-info api-alert-modern">
        <strong><i class="fas fa-clock me-2"></i>{{ __('Scheduler requirement') }}</strong>
        {{ __('Run php artisan schedule:run every minute. DigiKash executes merchant-subscriptions:process --limit=100 without overlapping, creates one due invoice under a database lock, and changes an expired active period to past_due until payment completes.') }}
    </div>

    <h3>{{ __('HTTP Responses and Safe Retries') }}</h3>
    <table class="api-table">
        <thead>
            <tr>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Meaning') }}</th>
                <th>{{ __('What to do') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr><td><code>200</code></td><td>{{ __('Read, cancellation, or renewal completed.') }}</td><td>{{ __('Use the returned resource as the source of truth.') }}</td></tr>
            <tr><td><code>201</code></td><td>{{ __('Subscription created, including a replay of the original create response.') }}</td><td>{{ __('Store data.id and the Idempotency-Key.') }}</td></tr>
            <tr><td><code>401 / 403</code></td><td>{{ __('Credentials, signature, environment, or merchant state is invalid.') }}</td><td>{{ __('Correct authentication; do not blindly retry.') }}</td></tr>
            <tr><td><code>404</code></td><td>{{ __('The subscription does not exist in this merchant/environment scope.') }}</td><td>{{ __('Check the ID and X-Environment.') }}</td></tr>
            <tr><td><code>409</code></td><td>{{ __('Reference conflict, invalid lifecycle transition, reused idempotency key, or an identical request is still running.') }}</td><td>{{ __('Read message; honor Retry-After when present.') }}</td></tr>
            <tr><td><code>422</code></td><td>{{ __('Payload or payment configuration validation failed.') }}</td><td>{{ __('Correct the fields before retrying.') }}</td></tr>
            <tr><td><code>429</code></td><td>{{ __('Merchant API rate limit exceeded.') }}</td><td>{{ __('Wait for Retry-After, then retry with exponential backoff.') }}</td></tr>
            <tr><td><code>503</code></td><td>{{ __('Temporary checkout creation failure.') }}</td><td>{{ __('Retry the identical POST with the same Idempotency-Key.') }}</td></tr>
        </tbody>
    </table>

    <h3>{{ __('Payment Confirmation Rules') }}</h3>
    <ul class="api-list">
        <li><strong>{{ __('Keep your access pending') }}:</strong> {{ __('subscription status=pending and an invoice payment URL do not mean payment has completed.') }}</li>
        <li><strong>{{ __('Verify the IPN signature') }}:</strong> {{ __('calculate HMAC-SHA256 over the raw webhook body with the API secret for the webhook environment and compare it with X-Signature.') }}</li>
        <li><strong>{{ __('Match your reference') }}:</strong> {{ __('data.ref_trx must match the open renewal invoice and data.environment must match the expected environment.') }}</li>
        <li><strong>{{ __('Confirm completed status') }}:</strong> {{ __('only status=completed should activate or extend access. Use data.trx_id with GET /api/v1/verify-payment/{trxId} when an additional server-to-server check is needed.') }}</li>
        <li><strong>{{ __('Poll for scheduled invoices') }}:</strong> {{ __('GET the subscription at or after next_billing_at. A new pending latest_invoice contains the customer-authorized renewal checkout URL.') }}</li>
        <li><strong>{{ __('Make fulfillment idempotent') }}:</strong> {{ __('store the DigiKash transaction ID and never grant your own service twice if an IPN is retried.') }}</li>
        <li><strong>{{ __('Handle failed renewals') }}:</strong> {{ __('status becomes past_due after failure. POST the renew endpoint to create a fresh checkout for the unpaid period.') }}</li>
    </ul>

    <div class="api-alert api-alert-success api-alert-modern">
        <strong><i class="fas fa-check-circle me-2"></i>{{ __('Practical result') }}</strong>
        {{ __('The API supports daily, weekly, monthly, half-yearly, and yearly billing cycles with separate sandbox and production records, while preserving hosted-checkout card security.') }}
    </div>
</section>
