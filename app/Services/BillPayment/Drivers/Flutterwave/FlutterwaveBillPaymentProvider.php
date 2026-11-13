<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Drivers\Flutterwave;

use App\Contracts\BillPayment\BillPaymentProviderInterface;
use App\Contracts\BillPayment\ChecksBillPaymentStatus;
use App\Contracts\BillPayment\ProvidesBillPaymentDiagnostics;
use App\Contracts\BillPayment\ReceivesBillPaymentWebhooks;
use App\Contracts\BillPayment\SyncsBillers;
use App\Contracts\BillPayment\ValidatesBillCustomer;
use App\Enums\BillPayment\ProviderFault;
use App\Exceptions\BillerSyncException;
use App\Exceptions\InvalidBillPaymentWebhookException;
use App\Exceptions\ProviderOutcomeUnknownException;
use App\Models\Biller;
use App\Models\BillerItem;
use App\Models\BillPaymentProvider;
use App\Services\BillPayment\BillCustomerLookup;
use App\Services\BillPayment\BillCustomerQuery;
use App\Services\BillPayment\BillerRules;
use App\Services\BillPayment\BillPaymentOrder;
use App\Services\BillPayment\BillPaymentResult;
use App\Services\BillPayment\BillPaymentWebhookNotice;
use App\Services\BillPayment\Drivers\Support\ProviderDecimal;
use App\Services\BillPayment\Drivers\Support\UnrepresentableAmountException;
use App\Services\BillPayment\Drivers\Support\WireAmount;
use App\Services\BillPayment\Sync\BillerSyncReport;
use App\Services\BillPayment\Sync\SyncedBiller;
use App\Services\BillPayment\Sync\SyncedBillerItem;
use App\Services\Payment\Flutterwave\FlutterwaveBillNoticeForwarder;
use App\Support\DecimalMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Flutterwave Bills (v3): cable TV, electricity and internet bills paid from
 * the merchant's Flutterwave balance. Nigeria is the documented market.
 *
 * pay() only ever answers Pending, Failed or Unknown: Flutterwave's guide
 * says the payment reply is "pending" first, so a payment is settled only by
 * a later status() that names the amount and currency the biller received.
 * Failed is answered only when nothing can have been charged: a refusal
 * before any call (no key, a biller code that cannot be read, an amount that
 * cannot be sent exactly) or a 4xx error envelope with no transaction
 * reference. A timeout, a 5xx, an unreadable reply, a 429 or "Duplicate
 * transaction reference" is Unknown, and the payment waits for a check.
 *
 * The catalogue can be synced: categories, their billers and each biller's
 * items, mapped through FlutterwaveBillerCode; the synchroniser decides what
 * is written.
 *
 * A webhook only names the payment to check. It is authenticated by the
 * `verif-hash` header, which Flutterwave sets to the dashboard secret hash
 * verbatim (no HMAC), the same check the deposit gateway makes.
 *
 * @link https://developer.flutterwave.com/docs/bill-payment
 * @link https://developer.flutterwave.com/docs/webhooks
 */
class FlutterwaveBillPaymentProvider implements BillPaymentProviderInterface, ChecksBillPaymentStatus, ProvidesBillPaymentDiagnostics, ReceivesBillPaymentWebhooks, SyncsBillers, ValidatesBillCustomer
{
    /** The webhook event a bill payment status change arrives as. */
    public const WEBHOOK_EVENT = 'singlebillpayment.status';

    public const DEFAULT_COUNTRY = 'NG';

    /**
     * A synced biller or item code: it ends up in a URL path, so letters,
     * digits, dashes and underscores only, and at most 60 characters so
     * "CC:BILLER:ITEM" always fits the 128-character external_code.
     */
    private const CODE_PATTERN = '/^[A-Za-z0-9_-]{1,60}$/';

    /** The Flutterwave bill category electricity billers are listed under. */
    private const UTILITY_CATEGORY = 'UTILITYBILLS';

    /**
     * Built against the published reference samples, without live
     * credentials. Each item still needs a sandbox or live check.
     *
     * @todo live-check every item below before a provider is enabled
     */
    private const LIVE_CHECKS = [
        'Whether reference accepts bp_<uuid> (format and length). If it does not, every payment is refused with a 400 and refunded, which shows at once in testing.',
        'Every bill status spelling (success, successful, Successful, failed, pending, reversed), and whether a webhook is sent when a bill fails.',
        'Whether the verbose status for electricity bills carries a status field at all. The guide\'s prepaid sample has none, which leaves every such payment Unknown until a person reviews it; success is never inferred from a missing status.',
        'Whether GET /bills/{reference} accepts our own reference, and what bills/history?reference= filters on. The driver matches customer_reference on our side either way.',
        'Whether from and to are required on the bill history, and their format.',
        'Whether verif-hash is also sent to the per-payment callback_url.',
        'Where the prepaid token arrives (token, recharge_token or extra).',
        'Whether an item amount of 0 means a variable amount, and what minimum and maximum 0 mean on validate.',
        'Whether a failed bill returns the debit from the Flutterwave balance.',
        'The error message for an insufficient Flutterwave balance.',
        'Bill coverage outside Nigeria (the samples show GH, KE and ZM).',
        'Whether each operator must whitelist their server IP.',
        'Whether the verbose amount includes the fee.',
        'The real pay() latency inside the customer request, which tunes the timeout.',
        'Timeout behaviour, which tunes the reconcile deadline, grace period and backoff.',
    ];

    private ?BillPaymentProvider $provider = null;

    /** @var array<string, mixed> */
    private array $credentials = [];

    private ?FlutterwaveBillsClient $client = null;

    /**
     * The plugin credentials an API provider of this driver starts with.
     * `api_secret` (the FLWSECK… key) and `webhook_secret` (the dashboard
     * secret hash) are sealed by PluginSecretFields.
     *
     * @return array<string, string>
     */
    public static function credentialTemplate(): array
    {
        return [
            'api_secret'     => '',
            'webhook_secret' => '',
            'country'        => self::DEFAULT_COUNTRY,
            'timeout'        => (string) FlutterwaveBillsClient::PAY_TIMEOUT_DEFAULT,
        ];
    }

    /**
     * Credentials the driver works without. The bill webhook secret may stay
     * blank: on a site taking Flutterwave deposits the deposit webhook
     * authenticates bill notices with its own secret hash and hands them
     * over, and polling settles payments either way.
     *
     * @return list<string>
     */
    public static function optionalCredentialKeys(): array
    {
        return ['webhook_secret'];
    }

    /**
     * The deposit webhook address the Flutterwave dashboard must keep when
     * this site also takes Flutterwave deposits, or null when it does not.
     * A Flutterwave account has one dashboard webhook URL, and bill events
     * reaching the deposit webhook are handed over to this module.
     */
    public static function depositWebhookUrl(): ?string
    {
        return FlutterwaveBillNoticeForwarder::depositWebhookUrl();
    }

    /**
     * What still needs a live or sandbox check, for the provider screen.
     *
     * @return list<string>
     */
    public static function liveCheckPending(): array
    {
        return array_map(static fn (string $item): string => __($item), self::LIVE_CHECKS);
    }

    /**
     * No I/O: the client is built only when a call needs one.
     *
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $config
     */
    public function configure(BillPaymentProvider $provider, array $credentials, array $config): void
    {
        $this->provider    = $provider;
        $this->credentials = $credentials;
        $this->client      = null;
    }

    public function provider(): ?BillPaymentProvider
    {
        return $this->provider;
    }

    public function pay(BillPaymentOrder $order): BillPaymentResult
    {
        if (! $this->client()->isConfigured()) {
            return BillPaymentResult::failed(__('The provider refused the connection credentials.'), fault: ProviderFault::Credentials);
        }

        $code    = FlutterwaveBillerCode::parse((string) $order->billerExternalCode, $order->itemCode);
        $country = strtoupper(trim($order->country));

        if ($code === null || $code['country'] !== $country) {
            return BillPaymentResult::failed(__('This biller is not set up for the provider.'));
        }

        try {
            $amount = WireAmount::number($order->amount);
        } catch (UnrepresentableAmountException) {
            return BillPaymentResult::failed(__('This amount cannot be sent to the provider.'));
        }

        $body = [
            'country'     => $country,
            'customer_id' => $order->customerNumber,
            'amount'      => $amount,
            'reference'   => $order->providerRequestId,
        ];

        $callbackUrl = $this->callbackUrl();

        if ($callbackUrl !== null) {
            $body['callback_url'] = $callbackUrl;
        }

        try {
            [$status, $json] = $this->client()->pay($code['biller'], $code['item'], $body);
        } catch (ProviderOutcomeUnknownException) {
            $this->log('Flutterwave bill payment pay did not answer', '/billers/{biller}/items/{item}/payment', null, $order);

            return BillPaymentResult::unknown(__('The provider did not answer in time.'));
        }

        return $this->payResult($status, $json, $order);
    }

    public function status(BillPaymentOrder $order): BillPaymentResult
    {
        if (! $this->client()->isConfigured()) {
            return BillPaymentResult::unknown(__('The provider refused the connection credentials.'));
        }

        $transactionReference = $order->providerTransactionId === null ? null : FlutterwavePayload::string($order->providerTransactionId);

        if ($transactionReference === null) {
            $transactionReference = $this->referenceFromHistory($order);

            if ($transactionReference === null) {
                return BillPaymentResult::unknown(__('The provider has no record of this payment yet.'));
            }
        }

        try {
            [$status, $json] = $this->client()->bill($transactionReference);
        } catch (ProviderOutcomeUnknownException) {
            return BillPaymentResult::unknown(__('The provider did not answer in time.'), [], $transactionReference);
        }

        $data = FlutterwavePayload::data($json);

        if ($status < 200 || $status >= 300 || $data === []) {
            return BillPaymentResult::unknown(__('The provider has no record of this payment yet.'), [], $transactionReference);
        }

        $mismatch = $this->bindingMismatch($data, $order, $transactionReference);

        if ($mismatch !== null) {
            Log::warning('Flutterwave bill payment status does not match the payment', [
                'endpoint'                 => '/bills/{tx_ref}',
                'http_status'              => $status,
                'check'                    => $mismatch,
                'bill_payment_id'          => $order->paymentId,
                'provider_request_id'      => $order->providerRequestId,
                'bill_payment_provider_id' => $this->provider?->getKey(),
            ]);

            return BillPaymentResult::unknown(__('The provider\'s answer does not match this payment.'));
        }

        $billStatus    = strtolower((string) FlutterwavePayload::string($data['status'] ?? null));
        $statusPayload = array_filter([
            'status' => $billStatus,
            'code'   => FlutterwavePayload::string($data['code'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return match ($billStatus) {
            'success', 'successful' => $this->completed($transactionReference, $data, $order),
            'failed' => BillPaymentResult::failed(
                FlutterwavePayload::message($data) ?? __('The provider reported the payment as failed.'),
                $statusPayload,
            ),
            'pending', 'processing' => BillPaymentResult::pending(__('The provider is still processing the payment.'), $transactionReference, $statusPayload),
            default => BillPaymentResult::unknown(__('The provider did not give a clear answer.'), $statusPayload, $transactionReference),
        };
    }

    /**
     * Pure: false when the code cannot be read or the synced catalogue says
     * the item cannot be resolved.
     */
    public function supportsCustomerLookup(Biller $biller, ?BillerItem $item): bool
    {
        $code = FlutterwaveBillerCode::parse((string) $biller->external_code, $item?->external_code);

        if ($code === null) {
            return false;
        }

        $meta = is_array($biller->external_meta) ? $biller->external_meta : [];

        return ($meta['items'][$code['item']]['is_resolvable'] ?? null) !== false;
    }

    /**
     * GET /bill-items/{item}/validate. Never throws, and never logs the
     * customer number or the name.
     */
    public function lookupCustomer(BillCustomerQuery $query): BillCustomerLookup
    {
        $code     = FlutterwaveBillerCode::parse($query->billerExternalCode, $query->itemCode);
        $customer = trim($query->customerNumber);

        if (! $this->client()->isConfigured() || $code === null || $code['country'] !== strtoupper(trim($query->country)) || $customer === '') {
            return BillCustomerLookup::unavailable();
        }

        try {
            [$status, $json] = $this->client()->validate($code['item'], $customer);
        } catch (Throwable) {
            return BillCustomerLookup::unavailable();
        }

        if ($status >= 200 && $status < 300) {
            $data       = FlutterwavePayload::data($json);
            $billerCode = FlutterwavePayload::string($data['biller_code'] ?? null);

            if (($data['response_code'] ?? null) === '00' && $billerCode !== null && strcasecmp($billerCode, $code['biller']) === 0) {
                $name = FlutterwavePayload::string($data['name'] ?? null);

                return BillCustomerLookup::verified($name === null ? null : mb_substr($name, 0, 120));
            }

            return BillCustomerLookup::unavailable();
        }

        if ($status === 400 && FlutterwavePayload::envelopeStatus($json) === 'error') {
            return BillCustomerLookup::notFound(__('The provider could not find this account.'));
        }

        return BillCustomerLookup::unavailable();
    }

    /**
     * Authenticate a callback by its `verif-hash` header and say which
     * payment it is about. The body's status is used only to tell one event
     * from another, never applied.
     */
    public function webhookNotice(Request $request): ?BillPaymentWebhookNotice
    {
        $secret = trim((string) ($this->credentials['webhook_secret'] ?? ''));
        $header = (string) $request->header('verif-hash', '');

        if ($secret === '' || $header === '' || ! hash_equals($secret, $header)) {
            throw new InvalidBillPaymentWebhookException('The Flutterwave bill payment webhook could not be authenticated.');
        }

        return self::noticeFrom($request);
    }

    /**
     * Whether this provider holds its own webhook secret and the request's
     * `verif-hash` is not it. A Flutterwave account signs every notice with
     * its one dashboard secret hash, so a provider configured with another
     * secret belongs to another account. A blank secret disagrees with
     * nothing: the caller has authenticated the request some other way.
     */
    public function disagreesWithWebhookSignature(Request $request): bool
    {
        $secret = trim((string) ($this->credentials['webhook_secret'] ?? ''));

        if ($secret === '') {
            return false;
        }

        return ! hash_equals($secret, (string) $request->header('verif-hash', ''));
    }

    /**
     * Which payment a bill notice is about, read from a request the caller
     * has already authenticated. Null for any other event, or a notice that
     * names neither reference. The body's status only tells one event from
     * another; it is never applied.
     */
    public static function noticeFrom(Request $request): ?BillPaymentWebhookNotice
    {
        if ($request->input('event') !== self::WEBHOOK_EVENT) {
            return null;
        }

        $requestId     = FlutterwavePayload::string($request->input('data.customer_reference')) ?? '';
        $transactionId = FlutterwavePayload::string($request->input('data.tx_ref'))             ?? '';

        if ($requestId === '' && $transactionId === '') {
            return null;
        }

        return new BillPaymentWebhookNotice(
            FlutterwavePayload::eventId($transactionId, $requestId, (string) FlutterwavePayload::string($request->input('data.status'))),
            $requestId     === '' ? null : $requestId,
            $transactionId === '' ? null : $transactionId,
        );
    }

    /**
     * Reads the bill categories for the configured country, which proves the
     * key, bills access and IP whitelisting at once. Never throws, and never
     * puts a credential in the answer.
     *
     * @return array{ok: bool, mode: string, message: string, latency_ms: int|null, details: array<string, mixed>}
     */
    public function testConnection(): array
    {
        $startedAt = microtime(true);
        $client    = $this->client();
        $mode      = $client->mode();
        $country   = $this->country();
        $details   = ['mode' => $mode, 'country' => $country];

        if (! $client->isConfigured()) {
            return $this->diagnosis(false, $mode, __('Add the Flutterwave secret key first.'), $startedAt, $details);
        }

        try {
            [$status, $json] = $client->categories($country);
        } catch (Throwable) {
            return $this->diagnosis(false, $mode, __('The Flutterwave API could not be reached.'), $startedAt, $details);
        }

        $categories = $json['data'] ?? null;

        if ($status < 200 || $status >= 300 || FlutterwavePayload::envelopeStatus($json) !== 'success' || ! is_array($categories) || ! array_is_list($categories)) {
            $details['http_status'] = $status;

            $message = in_array($status, [401, 403], true)
                ? __('Flutterwave refused this secret key, or this server\'s IP address is not whitelisted.')
                : __('Flutterwave did not list bill categories for :country.', ['country' => $country]);

            return $this->diagnosis(false, $mode, $message, $startedAt, $details);
        }

        $details['category_count'] = count($categories);

        return $this->diagnosis(true, $mode, __('The Flutterwave secret key works and bill payments are available in :country.', ['country' => $country]), $startedAt, $details);
    }

    /**
     * The countries a billing currency is configured for.
     *
     * @return list<string>
     */
    public function syncableCountries(): array
    {
        return array_values(array_map('strval', array_keys((array) config('bill_payments.flutterwave_country_currencies', []))));
    }

    /**
     * The Flutterwave bill categories the category map knows. Airtime and
     * mobile data are not among them, so they are never fetched.
     *
     * @return list<string>
     */
    public function providerCategories(): array
    {
        return array_values(array_map('strval', array_keys((array) config('bill_payments.category_map.flutterwave', []))));
    }

    /**
     * Categories, then each category's billers, then each biller's items,
     * all read before anything is returned.
     *
     * The packages of one biller code become one package biller (NG:BIL119),
     * and every item with an amount of 0 becomes its own open biller bound to
     * that item (NG:BIL112:UB157). A country with no configured currency is
     * still listed, each biller marked country_not_mapped, so it is counted.
     *
     * @todo live-check whether an item amount of 0 means a variable amount
     *
     * @param  list<string>       $providerCategories
     * @return list<SyncedBiller>
     *
     * @throws BillerSyncException
     */
    public function fetchBillers(string $country, array $providerCategories): iterable
    {
        $client = $this->client();

        if (! $client->isConfigured()) {
            throw new BillerSyncException(__('Add the Flutterwave secret key first.'));
        }

        $country  = strtoupper(trim($country));
        $wanted   = array_values(array_unique(array_map(static fn (mixed $code): string => strtoupper(trim((string) $code)), $providerCategories)));
        $currency = ((array) config('bill_payments.flutterwave_country_currencies', []))[$country] ?? null;
        $currency = is_string($currency) && trim($currency) !== '' ? strtoupper(trim($currency)) : null;

        $categories = [];

        foreach ($this->catalogueRows(fn (): array => $client->categories($country), __('bill categories'), $country) as $row) {
            $code       = FlutterwavePayload::string($row['code'] ?? null);
            $rowCountry = FlutterwavePayload::string($row['country_code'] ?? null);
            $code       = $code === null ? null : strtoupper($code);

            if ($code !== null && in_array($code, $wanted, true) && ($rowCountry === null || strtoupper($rowCountry) === $country)) {
                $categories[$code] = true;
            }
        }

        $billers = [];
        $items   = [];

        foreach (array_keys($categories) as $category) {
            $rows = $this->catalogueRows(fn (): array => $client->billers($category, $country), __(':category billers', ['category' => $category]), $country);

            foreach ($rows as $row) {
                $billerCode = FlutterwavePayload::string($row['biller_code'] ?? null);

                if ($billerCode === null || preg_match(self::CODE_PATTERN, $billerCode) !== 1) {
                    continue;
                }

                $items[$billerCode] ??= $this->catalogueRows(fn (): array => $client->items($billerCode), __('the items of :biller', ['biller' => $billerCode]), $country);

                array_push($billers, ...$this->syncedBillers($row, $billerCode, $items[$billerCode], $country, $currency, $category));
            }
        }

        return $billers;
    }

    /**
     * The `data` list of one catalogue call, or a BillerSyncException naming
     * what was refused, with the provider's own short message and never the
     * body or the key.
     *
     * @param  callable(): array{0: int, 1: array<array-key, mixed>|null} $call
     * @return list<array<array-key, mixed>>
     *
     * @throws BillerSyncException
     */
    private function catalogueRows(callable $call, string $what, string $country): array
    {
        try {
            [$status, $json] = $call();
        } catch (ProviderOutcomeUnknownException) {
            throw new BillerSyncException(__('The Flutterwave API could not be reached.'));
        }

        $rows = $json['data'] ?? null;

        if ($status < 200 || $status >= 300 || FlutterwavePayload::envelopeStatus($json) !== 'success' || ! is_array($rows) || ! array_is_list($rows)) {
            throw new BillerSyncException(__('Flutterwave did not list :what for :country: :reason', [
                'what'    => $what,
                'country' => $country,
                'reason'  => FlutterwavePayload::message($json) ?? __('HTTP :status.', ['status' => $status]),
            ]));
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * One Flutterwave biller in local terms: a package biller for its priced
     * items, and an open biller for each item priced 0.
     *
     * @param  array<array-key, mixed>       $row
     * @param  list<array<array-key, mixed>> $itemRows
     * @return list<SyncedBiller>
     */
    private function syncedBillers(array $row, string $billerCode, array $itemRows, string $country, ?string $currency, string $category): array
    {
        $rowCountry = FlutterwavePayload::string($row['country_code'] ?? null);
        $country    = $rowCountry === null ? $country : strtoupper($rowCountry);
        $name       = FlutterwavePayload::string($row['name'] ?? null) ?? $billerCode;
        $skip       = $currency === null ? BillerSyncReport::SKIP_COUNTRY_NOT_MAPPED : null;

        $fixed = [];
        $open  = [];
        $seen  = [];

        foreach ($itemRows as $item) {
            $code   = FlutterwavePayload::string($item['item_code'] ?? null);
            $amount = ProviderDecimal::from($item['amount'] ?? null);

            if ($code === null || preg_match(self::CODE_PATTERN, $code) !== 1 || isset($seen[$code]) || $amount === null || DecimalMoney::compare($amount, '0') < 0) {
                continue;
            }

            $seen[$code] = true;
            $resolvable  = $item['is_resolvable'] ?? null;

            $entry = [
                'code'    => $code,
                'name'    => FlutterwavePayload::string($item['short_name'] ?? null) ?? FlutterwavePayload::string($item['biller_name'] ?? null) ?? FlutterwavePayload::string($item['name'] ?? null) ?? $code,
                'amount'  => $amount,
                'label'   => FlutterwavePayload::string($item['label_name'] ?? null),
                'pattern' => self::pattern($item['reg_expression'] ?? null),
                'prepaid' => self::namesPrepaid($item['name'] ?? null, $item['short_name'] ?? null, $item['biller_name'] ?? null),
                'meta'    => [
                    'is_resolvable' => is_bool($resolvable) ? $resolvable : null,
                    'fee'           => ProviderDecimal::from($item['fee'] ?? null),
                    'label'         => FlutterwavePayload::string($item['label_name'] ?? null),
                ],
            ];

            if (DecimalMoney::compare($amount, '0') > 0) {
                $fixed[] = $entry;
            } else {
                $open[] = $entry;
            }
        }

        $billers = [];

        if ($fixed !== []) {
            $billers[] = new SyncedBiller(
                externalCode: FlutterwaveBillerCode::format($country, $billerCode),
                name: mb_substr($name, 0, 120),
                country: $country,
                currency: $currency,
                providerCategory: $category,
                amountType: 'fixed',
                items: array_map(static fn (array $entry): SyncedBillerItem => new SyncedBillerItem($entry['code'], mb_substr($entry['name'], 0, 120), $entry['amount']), $fixed),
                customerNumberLabel: self::firstLabel($fixed),
                customerNumberPattern: self::commonPattern($fixed),
                supportsLookup: self::anyResolvable($fixed),
                tokenLabel: self::tokenLabel($category, $row, $fixed),
                skipReason: $skip,
                meta: self::billerMeta($category, $fixed),
            );
        }

        foreach ($open as $entry) {
            $billers[] = new SyncedBiller(
                externalCode: FlutterwaveBillerCode::format($country, $billerCode, $entry['code']),
                name: mb_substr($name.' – '.$entry['name'], 0, 120),
                country: $country,
                currency: $currency,
                providerCategory: $category,
                amountType: 'open',
                customerNumberLabel: self::firstLabel([$entry]),
                customerNumberPattern: self::commonPattern([$entry]),
                supportsLookup: self::anyResolvable([$entry]),
                tokenLabel: self::tokenLabel($category, $row, [$entry]),
                skipReason: $skip,
                meta: self::billerMeta($category, [$entry]),
            );
        }

        return $billers;
    }

    /**
     * The token label a synced utility biller starts with: "Token" for a
     * prepaid one, whose meter token arrives in the status reply's `extra`,
     * which the driver keeps only for a biller with a token label. Null for
     * anything else, and the admin owns the label once the biller exists.
     *
     * @param array<array-key, mixed>    $row
     * @param list<array{prepaid: bool}> $entries
     */
    private static function tokenLabel(string $category, array $row, array $entries): ?string
    {
        if (strtoupper($category) !== self::UTILITY_CATEGORY) {
            return null;
        }

        $prepaid = self::namesPrepaid($row['name'] ?? null, $row['short_name'] ?? null, $row['description'] ?? null)
            || in_array(true, array_column($entries, 'prepaid'), true);

        return $prepaid ? __('Token') : null;
    }

    /** Whether any of these provider names says the service is prepaid. */
    private static function namesPrepaid(mixed ...$names): bool
    {
        foreach ($names as $name) {
            if (is_string($name) && preg_match('/PRE[\s_-]?PAID/i', $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{code: string, meta: array<string, mixed>}> $entries
     * @return array<string, mixed>
     */
    private static function billerMeta(string $category, array $entries): array
    {
        $items = [];

        foreach ($entries as $entry) {
            $items[$entry['code']] = $entry['meta'];
        }

        return [
            'provider_type'    => $category,
            'service_type'     => null,
            'fees'             => null,
            'fx'               => null,
            'requires_invoice' => false,
            'items'            => $items,
        ];
    }

    /**
     * @param list<array{label: ?string}> $entries
     */
    private static function firstLabel(array $entries): ?string
    {
        foreach ($entries as $entry) {
            if ($entry['label'] !== null) {
                return mb_substr($entry['label'], 0, 80);
            }
        }

        return null;
    }

    /**
     * The customer-number pattern every item agrees on, or null when they
     * differ, one has none, or it does not compile.
     *
     * @param list<array{pattern: ?string}> $entries
     */
    private static function commonPattern(array $entries): ?string
    {
        $patterns = array_unique(array_map(static fn (array $entry): string => (string) $entry['pattern'], $entries));

        if (count($patterns) !== 1) {
            return null;
        }

        $pattern = reset($patterns);

        return $pattern !== '' && mb_strlen($pattern) <= 255 && BillerRules::compiles($pattern) ? $pattern : null;
    }

    /**
     * @param list<array{meta: array<string, mixed>}> $entries
     */
    private static function anyResolvable(array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($entry['meta']['is_resolvable'] !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * A reg_expression with its anchors removed: the module anchors a
     * pattern itself.
     */
    private static function pattern(mixed $value): ?string
    {
        $pattern = FlutterwavePayload::string($value);

        if ($pattern === null) {
            return null;
        }

        if (str_starts_with($pattern, '^')) {
            $pattern = substr($pattern, 1);
        }

        if (str_ends_with($pattern, '$') && ! str_ends_with($pattern, '\\$')) {
            $pattern = substr($pattern, 0, -1);
        }

        return $pattern === '' ? null : $pattern;
    }

    /**
     * @param array<array-key, mixed>|null $json
     */
    private function payResult(int $status, ?array $json, BillPaymentOrder $order): BillPaymentResult
    {
        $data                 = FlutterwavePayload::data($json);
        $transactionReference = FlutterwavePayload::string($data['tx_ref'] ?? null);
        $message              = FlutterwavePayload::message($json);
        $envelope             = FlutterwavePayload::envelopeStatus($json);

        if ($status >= 200 && $status < 300) {
            if ($envelope === 'success' && $transactionReference !== null) {
                return BillPaymentResult::pending(
                    __('The provider is processing the payment.'),
                    $transactionReference,
                    array_filter([
                        'code'      => FlutterwavePayload::string($data['code'] ?? null),
                        'reference' => FlutterwavePayload::string($data['reference'] ?? null),
                        'fee'       => FlutterwavePayload::string($data['fee'] ?? null),
                        'network'   => FlutterwavePayload::string($data['network'] ?? null),
                    ], static fn (mixed $value): bool => $value !== null),
                );
            }

            $this->log('Flutterwave bill payment pay reply unclear', '/billers/{biller}/items/{item}/payment', $status, $order);

            return BillPaymentResult::unknown(__('The provider did not give a clear answer.'));
        }

        if ($status === 429 || ($message !== null && preg_match('/duplicate/i', $message) === 1)) {
            $this->log('Flutterwave bill payment pay reply unclear', '/billers/{biller}/items/{item}/payment', $status, $order);

            return BillPaymentResult::unknown(__('The provider did not give a clear answer.'));
        }

        if (in_array($status, [400, 401, 403, 404, 422], true) && $envelope === 'error' && $transactionReference === null) {
            return BillPaymentResult::failed(
                $message ?? __('The provider refused the payment.'),
                ['http_status' => $status],
                ProviderFault::fromRefusal($status, null, $message),
            );
        }

        $this->log('Flutterwave bill payment pay reply unclear', '/billers/{biller}/items/{item}/payment', $status, $order);

        return BillPaymentResult::unknown(__('The provider did not give a clear answer.'));
    }

    /**
     * The provider's reference for a payment whose pay() gave none, found in
     * the bill history by our own reference, matched exactly on our side.
     */
    private function referenceFromHistory(BillPaymentOrder $order): ?string
    {
        $from = ($order->submittedAt ?? now()->toImmutable())->subDay()->format('Y-m-d');

        try {
            [$status, $json] = $this->client()->history([
                'reference' => $order->providerRequestId,
                'from'      => $from,
                'to'        => now()->addDay()->format('Y-m-d'),
            ]);
        } catch (ProviderOutcomeUnknownException) {
            return null;
        }

        if ($status < 200 || $status >= 300) {
            return null;
        }

        $row = FlutterwavePayload::historyRow($json, $order->providerRequestId);

        return $row === null ? null : FlutterwavePayload::string($row['tx_ref'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function completed(string $transactionReference, array $data, BillPaymentOrder $order): BillPaymentResult
    {
        $amount   = ProviderDecimal::from($data['amount'] ?? null);
        $currency = FlutterwavePayload::string($data['currency'] ?? null);

        if ($amount === null || $currency === null) {
            return BillPaymentResult::unknown(
                __('The provider confirmed the payment without the amount it delivered.'),
                ['status' => 'success'],
                $transactionReference,
            );
        }

        $flwRef = FlutterwavePayload::string($data['flw_ref'] ?? null);

        return BillPaymentResult::completed(
            providerTransactionId: $transactionReference,
            receiptReference: $flwRef ?? $transactionReference,
            token: FlutterwavePayload::token($data, $this->billerIssuesToken($order)),
            amount: $amount,
            currency: strtoupper($currency),
            message: __('The provider confirmed the payment.'),
            payload: array_filter([
                'status'           => strtolower((string) FlutterwavePayload::string($data['status'] ?? null)),
                'code'             => FlutterwavePayload::string($data['code'] ?? null),
                'product'          => FlutterwavePayload::string($data['product'] ?? null),
                'product_name'     => FlutterwavePayload::string($data['product_name'] ?? null),
                'fee'              => FlutterwavePayload::string($data['fee'] ?? null),
                'commission'       => FlutterwavePayload::string($data['commission'] ?? null),
                'transaction_date' => FlutterwavePayload::string($data['transaction_date'] ?? null),
                'flw_ref'          => $flwRef,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }

    /**
     * Which binding check a bill status fails, or null when it is ours: the
     * reference we asked about, our own reference when it is echoed, our
     * country and our customer's account.
     *
     * @param array<array-key, mixed> $data
     */
    private function bindingMismatch(array $data, BillPaymentOrder $order, string $transactionReference): ?string
    {
        if (FlutterwavePayload::string($data['tx_ref'] ?? null) !== $transactionReference) {
            return 'transaction_reference';
        }

        $customerReference = FlutterwavePayload::string($data['customer_reference'] ?? null);

        if ($customerReference !== null && $customerReference !== $order->providerRequestId) {
            return 'reference';
        }

        $country = FlutterwavePayload::string($data['country'] ?? null);

        if ($country === null || strtoupper($country) !== strtoupper(trim($order->country))) {
            return 'country';
        }

        $customer = FlutterwavePayload::string($data['customer_id'] ?? null);

        if ($customer === null || FlutterwavePayload::alphanumeric($customer) !== FlutterwavePayload::alphanumeric($order->customerNumber)) {
            return 'customer';
        }

        return null;
    }

    /** Whether the payment's biller issues a token (it has a token label), so `extra` may be read as one. */
    private function billerIssuesToken(BillPaymentOrder $order): bool
    {
        $label = Biller::query()->whereKey($order->billerId)->value('token_label');

        return is_string($label) && trim($label) !== '';
    }

    /**
     * The bill webhook URL Flutterwave is told to notify, on the configured
     * application URL. Never from the request: pay() runs inside the
     * customer's own request, and a forged Host header would otherwise send
     * the notice (and the verif-hash it may carry) to a host of their
     * choosing. Null, so no callback_url is sent, when the application URL
     * has no host; the dashboard webhook and polling still apply.
     */
    private function callbackUrl(): ?string
    {
        $code = $this->provider?->code;
        $root = rtrim(trim((string) config('app.url')), '/');

        if (! is_string($code) || $code === '' || ! is_string(parse_url($root, PHP_URL_HOST))) {
            return null;
        }

        return $root.route('webhooks.bill-payments', ['providerCode' => $code], false);
    }

    private function country(): string
    {
        $country = strtoupper(trim((string) ($this->credentials['country'] ?? '')));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : self::DEFAULT_COUNTRY;
    }

    private function client(): FlutterwaveBillsClient
    {
        return $this->client ??= FlutterwaveBillsClient::fromCredentials($this->credentials);
    }

    /**
     * Ids, endpoint and status only: never a body, a key, the secret hash or
     * the customer number.
     */
    private function log(string $message, string $endpoint, ?int $status, BillPaymentOrder $order): void
    {
        Log::warning($message, [
            'endpoint'                 => $endpoint,
            'http_status'              => $status,
            'bill_payment_id'          => $order->paymentId,
            'provider_request_id'      => $order->providerRequestId,
            'bill_payment_provider_id' => $this->provider?->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>                                                                                $details
     * @return array{ok: bool, mode: string, message: string, latency_ms: int|null, details: array<string, mixed>}
     */
    private function diagnosis(bool $ok, string $mode, string $message, float $startedAt, array $details): array
    {
        return [
            'ok'         => $ok,
            'mode'       => $mode,
            'message'    => $message,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'details'    => $details,
        ];
    }
}
