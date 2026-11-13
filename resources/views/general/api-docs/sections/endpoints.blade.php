<!-- Initiate Payment Section -->
<section id="initiate-payment" class="content-section">
    <div class="endpoint-heading">
        <span class="method-badge method-post endpoint-heading__method">POST</span>
        <div>
            <h2>{{ __('Initiate Payment') }}</h2>
            <p>{{ __('Create a hosted checkout session for a merchant-supported currency. The returned payment_url should be opened by the customer.') }}</p>
        </div>
    </div>

    <div class="endpoint-card">
        <div class="endpoint-card__url">
            <code>{{ request()->getSchemeAndHttpHost() }}/api/v1/initiate-payment</code>
            <button type="button" class="endpoint-copy btn btn-outline-primary btn-sm" onclick="copyToClipboard(this)">
                <i class="fas fa-copy"></i> {{ __('Copy') }}
            </button>
        </div>
        <div class="endpoint-card__meta">
            <span><i class="fas fa-lock"></i> {{ __('API key required') }}</span>
            <span><i class="fas fa-signature"></i> {{ __('HMAC signature required') }}</span>
            <span><i class="fas fa-layer-group"></i> {{ __('Sandbox + production') }}</span>
            <span><i class="fas fa-wallet"></i> {{ __('Currency wallet checked') }}</span>
        </div>
    </div>

    <div class="api-alert api-alert-info api-alert-modern">
        <strong><i class="fas fa-sliders-h me-2"></i>{{ __('Gateway Selection') }}</strong>
        {{ __('Hosted checkout first applies merchant currency and wallet rules, then the gateways selected on Merchant API Config. allow_payment_methods is only an optional extra filter.') }}
    </div>

    <h3>{{ __('Request Headers') }}</h3>
    <table class="api-table">
        <thead>
            <tr>
                <th>{{ __('Header') }}</th>
                <th>{{ __('Value') }}</th>
                <th>{{ __('Required') }}</th>
                <th>{{ __('Description') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>Content-Type</code></td>
                <td><code>application/json</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Request content type') }}</td>
            </tr>
            <tr>
                <td><code>Accept</code></td>
                <td><code>application/json</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Expected response type') }}</td>
            </tr>
            <tr>
                <td><code>X-Environment</code></td>
                <td><code>sandbox</code> | <code>production</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Use sandbox for testing and production for live payments') }}</td>
            </tr>
            <tr>
                <td><code>X-Merchant-Key</code></td>
                <td><code>{merchant_key}</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Merchant identifier from the API Config page') }}</td>
            </tr>
            <tr>
                <td><code>X-API-Key</code></td>
                <td><code>{api_key}</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('API key for the selected environment') }}</td>
            </tr>
            <tr>
                <td><code>X-Timestamp</code></td>
                <td><code>{unix_timestamp}</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Current Unix timestamp in seconds. Requests outside the allowed tolerance are rejected.') }}</td>
            </tr>
            <tr>
                <td><code>X-Signature</code></td>
                <td><code>sha256={hmac}</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('HMAC-SHA256 of timestamp.METHOD.path_with_query.raw_body using the API Secret for the selected environment.') }}</td>
            </tr>
        </tbody>
    </table>

    <h3>{{ __('Request Parameters') }}</h3>
    <table class="api-table">
        <thead>
            <tr>
                <th>{{ __('Parameter') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Required') }}</th>
                <th>{{ __('Description') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>payment_amount</code></td>
                <td>number</td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Payment amount. Minimum value is 1.00.') }}</td>
            </tr>
            <tr>
                <td><code>currency_code</code></td>
                <td>string</td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('3-letter currency code. Must be enabled for the merchant and backed by an active merchant wallet.') }}</td>
            </tr>
            <tr>
                <td><code>ref_trx</code></td>
                <td>string</td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Your unique order or transaction reference. Max 60 characters.') }}</td>
            </tr>
            <tr>
                <td><code>success_redirect</code></td>
                <td>url</td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Customer is redirected here after a successful hosted checkout.') }}</td>
            </tr>
            <tr>
                <td><code>cancel_redirect</code></td>
                <td>url</td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Customer is redirected here when checkout is canceled or cannot continue.') }}</td>
            </tr>
            <tr>
                <td><code>ipn_url</code></td>
                <td>url</td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Server-to-server webhook URL for payment status updates.') }}</td>
            </tr>
            <tr>
                <td><code>description</code></td>
                <td>string</td>
                <td><span class="required-badge required-badge--no">{{ __('No') }}</span></td>
                <td>{{ __('Payment description visible in merchant transaction context.') }}</td>
            </tr>
            <tr>
                <td><code>customer_name</code></td>
                <td>string</td>
                <td><span class="required-badge required-badge--no">{{ __('No') }}</span></td>
                <td>{{ __('Customer name. Max 100 characters.') }}</td>
            </tr>
            <tr>
                <td><code>customer_email</code></td>
                <td>email</td>
                <td><span class="required-badge required-badge--no">{{ __('No') }}</span></td>
                <td>{{ __('Customer email address. Max 100 characters.') }}</td>
            </tr>
            <tr>
                <td><code>customer_phone</code></td>
                <td>string</td>
                <td><span class="required-badge required-badge--no">{{ __('No') }}</span></td>
                <td>{{ __('Customer phone number, preferably with the country code (e.g. +256770123456). A mobile money gateway (MTN MoMo, Airtel Money) requests the payment from this number. If you leave it out, the checkout page asks the customer for it.') }}</td>
            </tr>
            <tr>
                <td><code>allow_payment_methods</code></td>
                <td>string | array</td>
                <td><span class="required-badge required-badge--no">{{ __('No') }}</span></td>
                <td>{{ __('Optional keyword filter applied after merchant gateway rules. Example: "stripe,paypal" or ["stripe","paypal"].') }}</td>
            </tr>
        </tbody>
    </table>

    <h3>{{ __('Gateway Availability Response Fields') }}</h3>
    <table class="api-table">
        <thead>
            <tr>
                <th>{{ __('Field') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Meaning') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>info.merchant_payment_methods_restricted</code></td>
                <td>boolean</td>
                <td>{{ __('true when the merchant has selected specific gateways in API Config.') }}</td>
            </tr>
            <tr>
                <td><code>info.merchant_payment_method_ids</code></td>
                <td>array</td>
                <td>{{ __('Eligible deposit method IDs for the requested currency.') }}</td>
            </tr>
            <tr>
                <td><code>info.merchant_payment_method_codes</code></td>
                <td>array</td>
                <td>{{ __('Eligible method codes that can appear on hosted checkout.') }}</td>
            </tr>
        </tbody>
    </table>

    <h3>{{ __('Code Examples') }}</h3>
    <ul class="nav nav-tabs api-code-tabs" id="initiatePaymentTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="curl-initiate-tab" data-bs-toggle="tab" data-bs-target="#curl-initiate" type="button" role="tab">
                <i class="fa-solid fa-terminal me-1"></i>{{ __('cURL') }}
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="php-initiate-tab" data-bs-toggle="tab" data-bs-target="#php-initiate" type="button" role="tab">
                <i class="fa-brands fa-php me-1"></i>{{ __('PHP') }}
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="node-initiate-tab" data-bs-toggle="tab" data-bs-target="#node-initiate" type="button" role="tab">
                <i class="fa-brands fa-node-js me-1"></i>{{ __('Node.js') }}
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="curl-initiate" role="tabpanel">
            <div class="code-block">
                <pre><code class="bash">curl -X POST "{{ request()->getSchemeAndHttpHost() }}/api/v1/initiate-payment" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-Environment: sandbox" \
  -H "X-Merchant-Key: test_merchant_key" \
  -H "X-API-Key: test_api_key" \
  -H "X-Timestamp: 1778650000" \
  -H "X-Signature: sha256=generated_hmac_signature" \
  -d '{
    "payment_amount": 250.00,
    "currency_code": "USD",
    "ref_trx": "ORDER_12345",
    "description": "Premium Subscription",
    "success_redirect": "https://merchant.example/payments/success",
    "cancel_redirect": "https://merchant.example/payments/cancel",
    "ipn_url": "https://merchant.example/webhooks/{{ strtolower(setting('site_title')) }}",
    "customer_name": "John Doe",
    "customer_email": "john@example.com",
    "customer_phone": "+256770123456",
    "allow_payment_methods": ["stripe", "paypal"]
  }'</code></pre>
            </div>
        </div>

        <div class="tab-pane fade" id="php-initiate" role="tabpanel">
            <div class="code-block">
                <pre><code class="php">&lt;?php

use Illuminate\Support\Facades\Http;

$payload = [
    'payment_amount' => 250.00,
    'currency_code' => 'USD',
    'ref_trx' => 'ORDER_12345',
    'description' => 'Premium Subscription',
    'success_redirect' => route('payments.success'),
    'cancel_redirect' => route('payments.cancel'),
    'ipn_url' => route('webhooks.{{ strtolower(setting('site_title')) }}'),
    'customer_name' => 'John Doe',
    'customer_email' => 'john@example.com',
    'customer_phone' => '+256770123456',
    'allow_payment_methods' => ['stripe', 'paypal'],
];

$body = json_encode($payload, JSON_THROW_ON_ERROR);
$timestamp = (string) time();
$path = '/api/v1/initiate-payment';
$signature = hash_hmac(
    'sha256',
    $timestamp.'.POST.'.$path.'.'.$body,
    config('services.{{ strtolower(setting('site_title')) }}.api_secret')
);

$response = Http::withHeaders([
    'Content-Type' => 'application/json',
    'Accept' => 'application/json',
    'X-Environment' => 'sandbox',
    'X-Merchant-Key' => config('services.{{ strtolower(setting('site_title')) }}.merchant_key'),
    'X-API-Key' => config('services.{{ strtolower(setting('site_title')) }}.api_key'),
    'X-Timestamp' => $timestamp,
    'X-Signature' => 'sha256='.$signature,
])->withBody($body, 'application/json')
    ->post('{{ request()->getSchemeAndHttpHost() }}/api/v1/initiate-payment')
    ->throw()
    ->json();

return redirect()->away($response['payment_url']);</code></pre>
            </div>
        </div>

        <div class="tab-pane fade" id="node-initiate" role="tabpanel">
            <div class="code-block">
                <pre><code class="javascript">const crypto = require('crypto');

const payload = {
  payment_amount: 250.00,
  currency_code: 'USD',
  ref_trx: 'ORDER_12345',
  description: 'Premium Subscription',
  success_redirect: 'https://merchant.example/payments/success',
  cancel_redirect: 'https://merchant.example/payments/cancel',
  ipn_url: 'https://merchant.example/webhooks/{{ strtolower(setting('site_title')) }}',
  customer_name: 'John Doe',
  customer_email: 'john@example.com',
  customer_phone: '+256770123456',
  allow_payment_methods: ['stripe', 'paypal']
};

const body = JSON.stringify(payload);
const timestamp = Math.floor(Date.now() / 1000).toString();
const path = '/api/v1/initiate-payment';
const signature = crypto
  .createHmac('sha256', process.env.DIGIKASH_API_SECRET)
  .update(`${timestamp}.POST.${path}.${body}`)
  .digest('hex');

const response = await fetch('{{ request()->getSchemeAndHttpHost() }}/api/v1/initiate-payment', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-Environment': 'sandbox',
    'X-Merchant-Key': process.env.DIGIKASH_MERCHANT_KEY,
    'X-API-Key': process.env.DIGIKASH_API_KEY,
    'X-Timestamp': timestamp,
    'X-Signature': `sha256=${signature}`
  },
  body
});

const data = await response.json();

if (!response.ok) {
  throw new Error(data.error || 'Payment initiation failed');
}

window.location.href = data.payment_url;</code></pre>
            </div>
        </div>
    </div>

    <h3>{{ __('Success Response') }}</h3>
    <div class="response-example">
        <div class="response-header">
            <span class="status-code status-200">200 OK</span>
            {{ __('Payment session created') }}
        </div>
        <div class="response-body">
            <div class="code-block">
                <pre><code class="json">{
  "payment_url": "https://{{ setting('site_title') }}.test/payment/checkout?token=encrypted&signature=signed",
  "info": {
    "ref_trx": "ORDER_12345",
    "description": "Premium Subscription",
    "ipn_url": "https://merchant.example/webhooks/{{ strtolower(setting('site_title')) }}",
    "cancel_redirect": "https://merchant.example/payments/cancel",
    "success_redirect": "https://merchant.example/payments/success",
    "customer_name": "John Doe",
    "customer_email": "john@example.com",
    "customer_phone": "+256770123456",
    "merchant_id": 1,
    "merchant_name": "Demo Store",
    "amount": 250,
    "currency_code": "USD",
    "environment": "sandbox",
    "is_sandbox": true,
    "allow_payment_methods": ["stripe", "paypal"],
    "merchant_payment_methods_restricted": true,
    "merchant_payment_method_ids": [7],
    "merchant_payment_method_codes": ["stripe-usd"]
  }
}</code></pre>
            </div>
        </div>
    </div>

    <h3>{{ __('Common Error Responses') }}</h3>
    <div class="api-error-grid">
        <div class="api-error-card">
            <span class="status-code status-400">422</span>
            <strong>{{ __('Currency is not enabled for this merchant.') }}</strong>
            <p>{{ __('The requested currency_code is not in the merchant-supported currency list.') }}</p>
        </div>
        <div class="api-error-card">
            <span class="status-code status-400">422</span>
            <strong>{{ __('Receiver wallet for this currency is not available.') }}</strong>
            <p>{{ __('The merchant owner does not have an active wallet for the requested currency.') }}</p>
        </div>
        <div class="api-error-card">
            <span class="status-code status-401">401</span>
            <strong>{{ __('Invalid API credentials.') }}</strong>
            <p>{{ __('Check X-Environment, X-Merchant-Key, and X-API-Key for the same environment.') }}</p>
        </div>
    </div>
</section>

<!-- Verify Payment Section -->
<section id="verify-payment" class="content-section">
    <div class="endpoint-heading">
        <span class="method-badge method-get endpoint-heading__method">GET</span>
        <div>
            <h2>{{ __('Verify Payment') }}</h2>
            <p>{{ __('Fetch the latest status for a hosted checkout transaction. Always verify server-side before fulfilling an order.') }}</p>
        </div>
    </div>

    <div class="endpoint-card">
        <div class="endpoint-card__url">
            <code>{{ request()->getSchemeAndHttpHost() }}/api/v1/verify-payment/{trxId}</code>
            <button type="button" class="endpoint-copy btn btn-outline-primary btn-sm" onclick="copyToClipboard(this)">
                <i class="fas fa-copy"></i> {{ __('Copy') }}
            </button>
        </div>
        <div class="endpoint-card__meta">
            <span><i class="fas fa-clock"></i> {{ __('Use after redirect or IPN') }}</span>
            <span><i class="fas fa-user-lock"></i> {{ __('Merchant scoped') }}</span>
        </div>
    </div>

    <h3>{{ __('Request Headers') }}</h3>
    <table class="api-table">
        <thead>
            <tr>
                <th>{{ __('Header') }}</th>
                <th>{{ __('Value') }}</th>
                <th>{{ __('Required') }}</th>
                <th>{{ __('Description') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>Accept</code></td>
                <td><code>application/json</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Expected response type') }}</td>
            </tr>
            <tr>
                <td><code>X-Environment</code></td>
                <td><code>sandbox</code> | <code>production</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Must match the environment used to initiate the payment') }}</td>
            </tr>
            <tr>
                <td><code>X-Merchant-Key</code></td>
                <td><code>{merchant_key}</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Merchant identifier from API Config') }}</td>
            </tr>
            <tr>
                <td><code>X-API-Key</code></td>
                <td><code>{api_key}</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('API key for the selected environment') }}</td>
            </tr>
            <tr>
                <td><code>X-Timestamp</code></td>
                <td><code>{unix_timestamp}</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Use the same timestamp format as payment initiation.') }}</td>
            </tr>
            <tr>
                <td><code>X-Signature</code></td>
                <td><code>sha256={hmac}</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Sign timestamp.GET./api/v1/verify-payment/{trxId}. using the matching API Secret. The raw body is empty for GET requests.') }}</td>
            </tr>
        </tbody>
    </table>

    <h3>{{ __('Path Parameter') }}</h3>
    <table class="api-table">
        <thead>
            <tr>
                <th>{{ __('Parameter') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Required') }}</th>
                <th>{{ __('Description') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>trxId</code></td>
                <td>string</td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __(setting('site_title').' transaction ID returned by hosted checkout or IPN.') }}</td>
            </tr>
        </tbody>
    </table>

    <h3>{{ __('cURL Example') }}</h3>
    <div class="code-block">
        <pre><code class="bash">curl -X GET "{{ request()->getSchemeAndHttpHost() }}/api/v1/verify-payment/TXNQ5V8K2L9N3XM1" \
  -H "Accept: application/json" \
  -H "X-Environment: sandbox" \
  -H "X-Merchant-Key: test_merchant_key" \
  -H "X-API-Key: test_api_key" \
  -H "X-Timestamp: 1778650000" \
  -H "X-Signature: sha256=generated_hmac_signature"</code></pre>
    </div>

    <h3>{{ __('Success Response') }}</h3>
    <div class="response-example">
        <div class="response-header">
            <span class="status-code status-200">200 OK</span>
            {{ __('Completed payment') }}
        </div>
        <div class="response-body">
            <div class="code-block">
                <pre><code class="json">{
  "status": "success",
  "trx_id": "TXNQ5V8K2L9N3XM1",
  "amount": 237.5,
  "fee": 12.5,
  "currency": "USD",
  "net_amount": 237.5,
  "customer": {
    "name": "John Doe",
    "email": "john@example.com"
  },
  "description": "Premium Subscription",
  "created_at": "2026-05-13T10:30:00.000000Z",
  "updated_at": "2026-05-13T10:35:45.000000Z"
}</code></pre>
            </div>
        </div>
    </div>

    <h3>{{ __('Payment Status Values') }}</h3>
    <table class="api-table">
        <thead>
            <tr>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Description') }}</th>
                <th>{{ __('Recommended Action') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>pending</code></td>
                <td>{{ __('Payment is still processing.') }}</td>
                <td>{{ __('Wait for IPN or verify again later.') }}</td>
            </tr>
            <tr>
                <td><code>success</code></td>
                <td>{{ __('Payment completed successfully.') }}</td>
                <td>{{ __('Fulfill the order after idempotency checks.') }}</td>
            </tr>
            <tr>
                <td><code>failed</code></td>
                <td>{{ __('Payment failed or was canceled.') }}</td>
                <td>{{ __('Do not fulfill; show retry or cancel state.') }}</td>
            </tr>
        </tbody>
    </table>

    <h3>{{ __('Code Examples') }}</h3>
    <ul class="nav nav-tabs api-code-tabs" id="verifyPaymentTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="curl-verify-tab" data-bs-toggle="tab" data-bs-target="#curl-verify" type="button" role="tab">
                <i class="fa-solid fa-terminal me-1"></i>{{ __('cURL') }}
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="php-verify-tab" data-bs-toggle="tab" data-bs-target="#php-verify" type="button" role="tab">
                <i class="fa-brands fa-php me-1"></i>{{ __('PHP') }}
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="node-verify-tab" data-bs-toggle="tab" data-bs-target="#node-verify" type="button" role="tab">
                <i class="fa-brands fa-node-js me-1"></i>{{ __('Node.js') }}
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="curl-verify" role="tabpanel">
            <div class="code-block">
                <pre><code class="bash">curl -X GET "{{ request()->getSchemeAndHttpHost() }}/api/v1/verify-payment/TRXAB12CD34EF" \
  -H "Accept: application/json" \
  -H "X-Environment: production" \
  -H "X-Merchant-Key: live_merchant_key" \
  -H "X-API-Key: live_api_key" \
  -H "X-Timestamp: 1778650000" \
  -H "X-Signature: sha256=generated_hmac_signature"</code></pre>
            </div>
        </div>

        <div class="tab-pane fade" id="php-verify" role="tabpanel">
            <div class="code-block">
                <pre><code class="php">&lt;?php

use Illuminate\Support\Facades\Http;

$trxId = 'TRXAB12CD34EF';
$timestamp = (string) time();
$path = '/api/v1/verify-payment/'.$trxId;
$signature = hash_hmac(
    'sha256',
    $timestamp.'.GET.'.$path.'.', // GET has an empty body
    config('services.{{ strtolower(setting('site_title')) }}.api_secret')
);

$response = Http::withHeaders([
    'Accept' => 'application/json',
    'X-Environment' => 'production',
    'X-Merchant-Key' => config('services.{{ strtolower(setting('site_title')) }}.merchant_key'),
    'X-API-Key' => config('services.{{ strtolower(setting('site_title')) }}.api_key'),
    'X-Timestamp' => $timestamp,
    'X-Signature' => 'sha256='.$signature,
])->get('{{ request()->getSchemeAndHttpHost() }}/api/v1/verify-payment/'.$trxId)
    ->throw()
    ->json();

// $response['status'], $response['refunded_amount'], $response['fully_refunded']</code></pre>
            </div>
        </div>

        <div class="tab-pane fade" id="node-verify" role="tabpanel">
            <div class="code-block">
                <pre><code class="javascript">const crypto = require('crypto');

const trxId = 'TRXAB12CD34EF';
const timestamp = Math.floor(Date.now() / 1000).toString();
const path = `/api/v1/verify-payment/${trxId}`;
const signature = crypto
  .createHmac('sha256', process.env.DIGIKASH_API_SECRET)
  .update(`${timestamp}.GET.${path}.`) // GET has an empty body
  .digest('hex');

const response = await fetch(`{{ request()->getSchemeAndHttpHost() }}/api/v1/verify-payment/${trxId}`, {
  method: 'GET',
  headers: {
    'Accept': 'application/json',
    'X-Environment': 'production',
    'X-Merchant-Key': process.env.DIGIKASH_MERCHANT_KEY,
    'X-API-Key': process.env.DIGIKASH_API_KEY,
    'X-Timestamp': timestamp,
    'X-Signature': `sha256=${signature}`
  }
});

const data = await response.json();</code></pre>
            </div>
        </div>
    </div>

    <div class="api-alert api-alert-warning api-alert-modern">
        <strong><i class="fas fa-shield-alt me-2"></i>{{ __('Idempotency') }}</strong>
        {{ __('Use your ref_trx and the returned trx_id to make fulfillment idempotent. A webhook and a verify request may arrive close together.') }}
    </div>
</section>

<!-- Refund Payment Section -->
<section id="refund-payment" class="content-section">
    <div class="endpoint-heading">
        <span class="method-badge method-post endpoint-heading__method">POST</span>
        <div>
            <h2>{{ __('Refund Payment') }}</h2>
            <p>{{ __('Refund all or part of a completed payment. The merchant wallet is debited, a refund transaction is recorded, and your IPN endpoint is notified.') }}</p>
        </div>
    </div>

    <div class="endpoint-card">
        <div class="endpoint-card__url">
            <code>{{ request()->getSchemeAndHttpHost() }}/api/v1/refund-payment</code>
            <button type="button" class="endpoint-copy btn btn-outline-primary btn-sm" onclick="copyToClipboard(this)">
                <i class="fas fa-copy"></i> {{ __('Copy') }}
            </button>
        </div>
        <div class="endpoint-card__meta">
            <span><i class="fas fa-lock"></i> {{ __('API key required') }}</span>
            <span><i class="fas fa-signature"></i> {{ __('HMAC signature required') }}</span>
            <span><i class="fas fa-undo"></i> {{ __('Full or partial') }}</span>
            <span><i class="fas fa-user-lock"></i> {{ __('Merchant scoped') }}</span>
        </div>
    </div>

    <div class="api-alert api-alert-info api-alert-modern">
        <strong><i class="fas fa-info-circle me-2"></i>{{ __('What this does') }}</strong>
        {{ __('This reverses the platform-side ledger — it debits the amount back out of the merchant wallet and records a refund. Returning the money to the buyer\'s card is completed by the platform through the original payment gateway, since the card is never held by this system.') }}
    </div>

    <h3>{{ __('Request Headers') }}</h3>
    <table class="api-table">
        <thead>
            <tr>
                <th>{{ __('Header') }}</th>
                <th>{{ __('Value') }}</th>
                <th>{{ __('Required') }}</th>
                <th>{{ __('Description') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>Accept</code></td>
                <td><code>application/json</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Expected response type') }}</td>
            </tr>
            <tr>
                <td><code>X-Environment</code></td>
                <td><code>sandbox</code> | <code>production</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Must match the environment used to initiate the payment') }}</td>
            </tr>
            <tr>
                <td><code>X-Merchant-Key</code></td>
                <td><code>{merchant_key}</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Merchant identifier from API Config') }}</td>
            </tr>
            <tr>
                <td><code>X-API-Key</code></td>
                <td><code>{api_key}</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('API key for the selected environment') }}</td>
            </tr>
            <tr>
                <td><code>X-Timestamp</code></td>
                <td><code>{unix_timestamp}</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('Unix seconds, used for the HMAC signature') }}</td>
            </tr>
            <tr>
                <td><code>X-Signature</code></td>
                <td><code>{hmac_sha256}</code></td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('HMAC-SHA256 of the request, signed with your API secret') }}</td>
            </tr>
        </tbody>
    </table>

    <h3>{{ __('Request Parameters') }}</h3>
    <table class="api-table">
        <thead>
            <tr>
                <th>{{ __('Parameter') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Required') }}</th>
                <th>{{ __('Description') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>trx_id</code></td>
                <td>{{ __('string') }}</td>
                <td><span class="required-badge required-badge--yes">{{ __('Yes') }}</span></td>
                <td>{{ __('The trx_id of the completed payment to refund') }}</td>
            </tr>
            <tr>
                <td><code>amount</code></td>
                <td>{{ __('number') }}</td>
                <td><span class="required-badge required-badge--no">{{ __('No') }}</span></td>
                <td>{{ __('Amount to refund. Omit for a full refund; pass a smaller value for a partial refund. Partial refunds accumulate and cannot exceed the original amount.') }}</td>
            </tr>
            <tr>
                <td><code>reason</code></td>
                <td>{{ __('string') }}</td>
                <td><span class="required-badge required-badge--no">{{ __('No') }}</span></td>
                <td>{{ __('Optional human-readable reason stored with the refund') }}</td>
            </tr>
            <tr>
                <td><code>ref_refund</code></td>
                <td>{{ __('string') }}</td>
                <td><span class="required-badge required-badge--no">{{ __('No') }}</span></td>
                <td>{{ __('Your own refund reference. When supplied, the same reference never refunds twice (idempotent).') }}</td>
            </tr>
        </tbody>
    </table>

    <h3>{{ __('Code Examples') }}</h3>
    <ul class="nav nav-tabs api-code-tabs" id="refundPaymentTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="curl-refund-tab" data-bs-toggle="tab" data-bs-target="#curl-refund" type="button" role="tab">
                <i class="fa-solid fa-terminal me-1"></i>{{ __('cURL') }}
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="php-refund-tab" data-bs-toggle="tab" data-bs-target="#php-refund" type="button" role="tab">
                <i class="fa-brands fa-php me-1"></i>{{ __('PHP') }}
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="node-refund-tab" data-bs-toggle="tab" data-bs-target="#node-refund" type="button" role="tab">
                <i class="fa-brands fa-node-js me-1"></i>{{ __('Node.js') }}
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="curl-refund" role="tabpanel">
            <div class="code-block">
                <pre><code class="bash">curl -X POST "{{ request()->getSchemeAndHttpHost() }}/api/v1/refund-payment" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-Environment: production" \
  -H "X-Merchant-Key: live_merchant_key" \
  -H "X-API-Key: live_api_key" \
  -H "X-Timestamp: 1778650000" \
  -H "X-Signature: sha256=generated_hmac_signature" \
  -d '{
    "trx_id": "TRXAB12CD34EF",
    "amount": 10.00,
    "reason": "Customer returned item",
    "ref_refund": "RFD-10231"
  }'</code></pre>
            </div>
        </div>

        <div class="tab-pane fade" id="php-refund" role="tabpanel">
            <div class="code-block">
                <pre><code class="php">&lt;?php

use Illuminate\Support\Facades\Http;

// Omit "amount" for a full refund. Pass a unique "ref_refund" so a retry
// never refunds twice.
$payload = [
    'trx_id' => 'TRXAB12CD34EF',
    'amount' => 10.00,
    'reason' => 'Customer returned item',
    'ref_refund' => 'RFD-10231',
];

$body = json_encode($payload, JSON_THROW_ON_ERROR);
$timestamp = (string) time();
$path = '/api/v1/refund-payment';
$signature = hash_hmac(
    'sha256',
    $timestamp.'.POST.'.$path.'.'.$body,
    config('services.{{ strtolower(setting('site_title')) }}.api_secret')
);

$response = Http::withHeaders([
    'Content-Type' => 'application/json',
    'Accept' => 'application/json',
    'X-Environment' => 'production',
    'X-Merchant-Key' => config('services.{{ strtolower(setting('site_title')) }}.merchant_key'),
    'X-API-Key' => config('services.{{ strtolower(setting('site_title')) }}.api_key'),
    'X-Timestamp' => $timestamp,
    'X-Signature' => 'sha256='.$signature,
])->withBody($body, 'application/json')
    ->post('{{ request()->getSchemeAndHttpHost() }}/api/v1/refund-payment')
    ->throw()
    ->json();

// $response['refund_trx_id'], $response['remaining_refund'], $response['fully_refunded']</code></pre>
            </div>
        </div>

        <div class="tab-pane fade" id="node-refund" role="tabpanel">
            <div class="code-block">
                <pre><code class="javascript">const crypto = require('crypto');

// Omit `amount` for a full refund. A unique `ref_refund` makes the call
// idempotent — the same reference never refunds twice.
const payload = {
  trx_id: 'TRXAB12CD34EF',
  amount: 10.00,
  reason: 'Customer returned item',
  ref_refund: 'RFD-10231'
};

const body = JSON.stringify(payload);
const timestamp = Math.floor(Date.now() / 1000).toString();
const path = '/api/v1/refund-payment';
const signature = crypto
  .createHmac('sha256', process.env.DIGIKASH_API_SECRET)
  .update(`${timestamp}.POST.${path}.${body}`)
  .digest('hex');

const response = await fetch('{{ request()->getSchemeAndHttpHost() }}/api/v1/refund-payment', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-Environment': 'production',
    'X-Merchant-Key': process.env.DIGIKASH_MERCHANT_KEY,
    'X-API-Key': process.env.DIGIKASH_API_KEY,
    'X-Timestamp': timestamp,
    'X-Signature': `sha256=${signature}`
  },
  body
});

const data = await response.json();</code></pre>
            </div>
        </div>
    </div>

    <h3>{{ __('Success Response') }}</h3>
    <div class="code-block">
        <button type="button" class="endpoint-copy btn btn-outline-primary btn-sm" onclick="copyToClipboard(this)">
            <i class="fas fa-copy"></i> {{ __('Copy') }}
        </button>
<pre><code>{
    "status": "success",
    "message": "Refund processed successfully.",
    "refund_trx_id": "TRXZ9YX87WV65",
    "original_trx_id": "TRXAB12CD34EF",
    "refunded_amount": 10.00,
    "total_refunded": 10.00,
    "remaining_refund": 39.99,
    "fully_refunded": false,
    "currency": "USD"
}</code></pre>
    </div>

    <h3>{{ __('Refund Webhook (IPN)') }}</h3>
    <p>{{ __('After a successful refund, your ipn_url receives a signed POST. Verify the X-Signature header (HMAC-SHA256 of the raw body, using your API secret) before trusting it.') }}</p>
    <div class="code-block">
        <button type="button" class="endpoint-copy btn btn-outline-primary btn-sm" onclick="copyToClipboard(this)">
            <i class="fas fa-copy"></i> {{ __('Copy') }}
        </button>
<pre><code>{
    "event": "payment.refunded",
    "status": "refunded",
    "data": {
        "original_trx_id": "TRXAB12CD34EF",
        "refund_trx_id": "TRXZ9YX87WV65",
        "ref_trx": "ORDER-10231",
        "ref_refund": "RFD-10231",
        "refunded_amount": 10.00,
        "total_refunded": 10.00,
        "remaining_refund": 39.99,
        "fully_refunded": false,
        "currency": "USD"
    },
    "message": "Payment refunded.",
    "timestamp": 1719750000
}</code></pre>
    </div>

    <div class="api-alert api-alert-warning api-alert-modern">
        <strong><i class="fas fa-exclamation-triangle me-2"></i>{{ __('Common errors') }}</strong>
        {{ __('422 — "Only completed payments can be refunded", "Refund amount exceeds the refundable balance", "This payment has already been fully refunded", or "Merchant wallet balance is insufficient to process this refund". The processing fee charged on the original payment is not returned.') }}
    </div>
</section>

<!-- Site Info Section -->
<section id="site-info" class="content-section">
    <div class="endpoint-heading">
        <span class="method-badge method-get endpoint-heading__method">GET</span>
        <div>
            <h2>{{ __('Site Info') }}</h2>
            <p>{{ __('Fetch gateway branding, environment labels, and customer-facing checkout metadata for a merchant-authenticated integration.') }}</p>
        </div>
    </div>

    <div class="endpoint-card">
        <div class="endpoint-card__url">
            <code>{{ request()->getSchemeAndHttpHost() }}/api/v1/site-info</code>
            <button type="button" class="endpoint-copy btn btn-outline-primary btn-sm" onclick="copyToClipboard(this)">
                <i class="fas fa-copy"></i> {{ __('Copy') }}
            </button>
        </div>
        <div class="endpoint-card__meta">
            <span><i class="fas fa-lock"></i> {{ __('Merchant auth required') }}</span>
            <span><i class="fas fa-palette"></i> {{ __('Checkout branding') }}</span>
            <span><i class="fas fa-layer-group"></i> {{ __('Environment labels') }}</span>
        </div>
    </div>

    <h3>{{ __('cURL Example') }}</h3>
    <div class="code-block">
        <pre><code class="bash">curl -X GET "{{ request()->getSchemeAndHttpHost() }}/api/v1/site-info" \
  -H "Accept: application/json" \
  -H "X-Environment: sandbox" \
  -H "X-Merchant-Key: test_merchant_key" \
  -H "X-API-Key: test_api_key" \
  -H "X-Timestamp: 1778650000" \
  -H "X-Signature: sha256=generated_hmac_signature"</code></pre>
    </div>

    <h3>{{ __('Success Response') }}</h3>
    <div class="response-example">
        <div class="response-header">
            <span class="status-code status-200">200 OK</span>
            {{ __('Gateway metadata') }}
        </div>
        <div class="response-body">
            <div class="code-block">
                <pre><code class="json">{
  "site_name": "{{ setting('site_title') }}",
  "site_logo": "{{ asset(setting('logo')) }}",
  "site_url": "{{ url('/') }}",
  "gateway_name": "{{ setting('site_title') }} Payment Gateway",
  "gateway_description": "Secure payment powered by {{ setting('site_title') }}",
  "features": {
    "ssl_secured": "SSL Secured",
    "instant_processing": "Instant",
    "global_support": "Global",
    "mobile_ready": "Mobile Ready"
  },
  "environments": {
    "production": "Production Mode - Live payment processing is active",
    "sandbox": "Test Mode - This is a test transaction. No real money will be charged"
  },
  "api_version": "1.0",
  "status": "active"
}</code></pre>
            </div>
        </div>
    </div>
</section>
