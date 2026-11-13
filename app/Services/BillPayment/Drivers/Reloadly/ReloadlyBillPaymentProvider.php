<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Drivers\Reloadly;

use App\Contracts\BillPayment\BillPaymentProviderInterface;
use App\Contracts\BillPayment\ChecksBillPaymentStatus;
use App\Contracts\BillPayment\ProvidesBillPaymentDiagnostics;
use App\Contracts\BillPayment\SyncsBillers;
use App\Enums\BillPayment\ProviderFault;
use App\Exceptions\BillerSyncException;
use App\Exceptions\ProviderOutcomeUnknownException;
use App\Models\BillPaymentProvider;
use App\Services\BillPayment\BillPaymentOrder;
use App\Services\BillPayment\BillPaymentResult;
use App\Services\BillPayment\Drivers\Support\ProviderDecimal;
use App\Services\BillPayment\Drivers\Support\UnrepresentableAmountException;
use App\Services\BillPayment\Drivers\Support\WireAmount;
use App\Services\BillPayment\Sync\BillerSyncReport;
use App\Services\BillPayment\Sync\SyncedBiller;
use App\Services\BillPayment\Sync\SyncedBillerItem;
use App\Services\MobileRecharge\Drivers\ReloadlyMobileRechargeProvider;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reloadly Utility Payments: electricity, water, TV and internet bills paid
 * through Reloadly's utilities API.
 *
 * pay() only ever answers Pending, Failed or Unknown. Reloadly's /pay reply
 * carries no delivered amount, so a payment is settled only by a later
 * status() that names the amount and currency the biller received. Failed is
 * answered only when nothing can have been charged: a refusal before any
 * HTTP call, a token that could not be had before /pay was contacted, a 4xx
 * refusal with an error code and no transaction id, or an explicit FAILED
 * for our own reference. A timeout, a 5xx, an unreadable reply, a duplicate
 * reference, a 409 or a 429 is Unknown, and the payment waits for a status
 * check.
 *
 * The biller catalogue can be synced (GET /billers, paged per country and
 * type); the synchroniser decides what is written.
 *
 * Reloadly has no customer lookup endpoint and no utilities webhook event, so
 * this driver implements neither capability. The mobile recharge Reloadly
 * driver is a separate class with its own token cache.
 *
 * @link https://docs.reloadly.com/assets/api/utility-payments.json
 * @link https://developers.reloadly.com/utility-payments/quickstart
 */
class ReloadlyBillPaymentProvider implements BillPaymentProviderInterface, ChecksBillPaymentStatus, ProvidesBillPaymentDiagnostics, SyncsBillers
{
    /**
     * Built against the published schema and examples, without live
     * credentials. Each item still needs a sandbox or live check.
     *
     * @todo live-check every item below before a provider is enabled
     */
    private const LIVE_CHECKS = [
        'The real field spellings on /billers (countryCode or countryIsoCode, localTransactionCurencyCode, internatonalAmountSupported) and the shape of fx.',
        'Whether /transactions/{id} returns an object or a top-level list, and whether pinDetails.token is a string or a number.',
        'Whether deliveryAmount and deliveryAmountCurrencyCode hold the local bill amount when useLocalAmount is true. A wrong guess parks the payment as a mismatch; it never loses money.',
        'What a reused referenceId returns, and the longest referenceId accepted (bp_<uuid> is 39 characters).',
        'The HTTP status and errorCode for insufficient balance and for an invalid account on /pay.',
        'Whether /pay ever answers SUCCESSFUL or FAILED straight away.',
        'Whether ?referenceId= really filters /transactions, and how soon a timed-out /pay appears there.',
        'Whether a utilities webhook event now exists in the dashboard.',
        'Whether REFUNDED after SUCCESSFUL happens, and whether it reverses the provider charge.',
        'Whether size=100 is accepted on /billers.',
        'The real pay() latency inside the customer request, which tunes the timeout.',
        'Timeout behaviour, which tunes the reconcile deadline, grace period and backoff.',
    ];

    private ?BillPaymentProvider $provider = null;

    /** @var array<string, mixed> */
    private array $credentials = [];

    private ?ReloadlyUtilitiesClient $client = null;

    /**
     * The plugin credentials an API provider of this driver starts with.
     * `client_secret` is sealed by PluginSecretFields. `sandbox` is read like
     * the recharge driver reads it: missing means sandbox, blank means live.
     *
     * @return array<string, string>
     */
    public static function credentialTemplate(): array
    {
        return [
            'client_id'     => '',
            'client_secret' => '',
            'sandbox'       => '1',
            'timeout'       => (string) ReloadlyUtilitiesClient::PAY_TIMEOUT_DEFAULT,
        ];
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
     * No I/O: the client is built, and a token fetched, only when a call
     * needs one.
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
        $billerCode = trim((string) $order->billerExternalCode);

        if (! self::isNumericId($billerCode)) {
            return BillPaymentResult::failed(__('This biller is not set up for the provider.'));
        }

        try {
            $amount = WireAmount::number($order->amount);
        } catch (UnrepresentableAmountException) {
            return BillPaymentResult::failed(__('This amount cannot be sent to the provider.'));
        }

        $body = [
            'billerId'                => (int) $billerCode,
            'subscriberAccountNumber' => $order->customerNumber,
            'amount'                  => $amount,
        ];

        $itemCode = trim((string) $order->itemCode);

        if (self::isNumericId($itemCode)) {
            $body['amountId'] = (int) $itemCode;
        }

        $body['useLocalAmount'] = true;
        $body['referenceId']    = $order->providerRequestId;

        try {
            [$status, $json] = $this->client()->pay($body);
        } catch (ReloadlyTokenException) {
            $this->log('Reloadly bill payment token refused', '/pay', null, $order);

            return BillPaymentResult::failed(__('The provider refused the connection credentials.'), fault: ProviderFault::Credentials);
        } catch (ProviderOutcomeUnknownException) {
            $this->log('Reloadly bill payment pay did not answer', '/pay', null, $order);

            return BillPaymentResult::unknown(__('The provider did not answer in time.'));
        }

        return $this->payResult($status, $json, $order);
    }

    public function status(BillPaymentOrder $order): BillPaymentResult
    {
        $byId = $order->providerTransactionId !== null && trim($order->providerTransactionId) !== '';
        $path = $byId ? '/transactions/{id}' : '/transactions';

        try {
            [$status, $json] = $byId
                ? $this->client()->transaction(trim((string) $order->providerTransactionId))
                : $this->client()->transactionsByReference($order->providerRequestId);
        } catch (ReloadlyTokenException) {
            $this->log('Reloadly bill payment token refused', $path, null, $order);

            return BillPaymentResult::unknown(__('The provider refused the connection credentials.'));
        } catch (ProviderOutcomeUnknownException) {
            return BillPaymentResult::unknown(__('The provider did not answer in time.'));
        }

        if ($status < 200 || $status >= 300) {
            return BillPaymentResult::unknown(__('The provider has no record of this payment yet.'));
        }

        $entry = ReloadlyPayload::matching($json, $order->providerRequestId);

        if ($entry === null) {
            return BillPaymentResult::unknown(__('The provider has no record of this payment yet.'));
        }

        $transaction = $entry['transaction'];
        $mismatch    = $this->bindingMismatch($transaction, $order);

        if ($mismatch !== null) {
            Log::warning('Reloadly bill payment status does not match the payment', [
                'endpoint'                 => $path,
                'http_status'              => $status,
                'check'                    => $mismatch,
                'bill_payment_id'          => $order->paymentId,
                'provider_request_id'      => $order->providerRequestId,
                'bill_payment_provider_id' => $this->provider?->getKey(),
            ]);

            return BillPaymentResult::unknown(__('The provider\'s answer does not match this payment.'));
        }

        $id             = self::scalarString($transaction['id'] ?? null);
        $providerStatus = strtoupper(trim((string) self::scalarString($transaction['status'] ?? null)));
        $statusPayload  = array_filter(['status' => $providerStatus, 'code' => $entry['code']], static fn (mixed $value): bool => $value !== null && $value !== '');

        return match ($providerStatus) {
            'SUCCESSFUL' => $this->completed($id, $entry, $transaction),
            'FAILED', 'REFUNDED' => BillPaymentResult::failed(
                ReloadlyPayload::message(['message' => $entry['message']]) ?? __('The provider reported the payment as failed.'),
                $statusPayload,
            ),
            'PROCESSING' => BillPaymentResult::pending(__('The provider is still processing the payment.'), $id, $statusPayload),
            default      => BillPaymentResult::unknown(__('The provider did not give a clear answer.'), $statusPayload, $id),
        };
    }

    /**
     * Fetches a fresh token, then reads the account balance. Never throws,
     * and never puts a credential or a token in the answer.
     *
     * @return array{ok: bool, mode: string, message: string, latency_ms: int|null, details: array<string, mixed>}
     */
    public function testConnection(): array
    {
        $startedAt = microtime(true);
        $mode      = $this->isSandbox() ? 'sandbox' : 'live';
        $details   = ['host' => (string) parse_url($this->isSandbox() ? ReloadlyUtilitiesClient::SANDBOX : ReloadlyUtilitiesClient::LIVE, PHP_URL_HOST)];

        try {
            $client = $this->client();
            $client->forgetToken();
            $client->token(true);

            [$status, $json] = $client->balance();
        } catch (ReloadlyTokenException) {
            return $this->diagnosis(false, $mode, __('Reloadly refused these credentials. Check that the Sandbox / Live setting matches the keys.'), $startedAt, $details);
        } catch (Throwable) {
            return $this->diagnosis(false, $mode, __('The Reloadly utilities API could not be reached.'), $startedAt, $details);
        }

        if ($status < 200 || $status >= 300) {
            $details['http_status'] = $status;

            return $this->diagnosis(false, $mode, __('The credentials were accepted, but the Reloadly utilities API refused the request.'), $startedAt, $details);
        }

        $details['balance']  = self::scalarString($json['balance'] ?? null);
        $details['currency'] = self::scalarString($json['currencyCode'] ?? null);

        return $this->diagnosis(true, $mode, __('Reloadly credentials work and the :mode utilities API is reachable.', ['mode' => $mode]), $startedAt, $details);
    }

    /**
     * Reloadly lists billers for any country.
     *
     * @return list<string>
     */
    public function syncableCountries(): array
    {
        return [];
    }

    /**
     * The Reloadly biller types the category map knows.
     *
     * @return list<string>
     */
    public function providerCategories(): array
    {
        return array_values(array_map('strval', array_keys((array) config('bill_payments.category_map.reloadly', []))));
    }

    /**
     * GET /billers?countryISOCode=&type=&page=&size=, one type at a time,
     * until a page comes back short. Every page is read before anything is
     * returned, so a failure on any page leaves the caller with nothing.
     *
     * A biller that needs an invoice, or that takes only international
     * amounts, is still returned, with its skip reason, so it is counted.
     *
     * @todo live-check the field spellings, the fx shape and whether size=100 is accepted
     *
     * @param  list<string>       $providerCategories
     * @return list<SyncedBiller>
     *
     * @throws BillerSyncException
     */
    public function fetchBillers(string $country, array $providerCategories): iterable
    {
        $country  = strtoupper(trim($country));
        $pageSize = max(1, (int) config('bill_payments.sync.page_size', 100));
        $maxPages = max(1, (int) config('bill_payments.sync.max_pages', 50));
        $billers  = [];

        foreach (array_values(array_unique(array_map('strval', $providerCategories))) as $type) {
            for ($page = 1; ; $page++) {
                if ($page > $maxPages) {
                    throw new BillerSyncException(__('Reloadly lists more than :pages pages of :type billers for :country, so nothing was synced.', [
                        'pages'   => $maxPages,
                        'type'    => $type,
                        'country' => $country,
                    ]));
                }

                [$status, $json] = $this->catalogueReply($type, $country, $page, $pageSize);

                $rows = is_array($json['content'] ?? null) ? $json['content'] : ($json !== null && array_is_list($json) ? $json : null);

                if ($rows === null) {
                    throw new BillerSyncException(__('Reloadly sent a :type biller list for :country that could not be read (HTTP :status).', [
                        'type'    => $type,
                        'country' => $country,
                        'status'  => $status,
                    ]));
                }

                foreach (ReloadlyPayload::billers($json) as $row) {
                    $biller = $this->syncedBiller($row, $country, $type);

                    if ($biller !== null) {
                        $billers[] = $biller;
                    }
                }

                if (count($rows) < $pageSize) {
                    break;
                }
            }
        }

        return $billers;
    }

    /**
     * One page of GET /billers, or a BillerSyncException that names what
     * went wrong without a body, a token or a secret.
     *
     * @return array{0: int, 1: array<array-key, mixed>|null}
     *
     * @throws BillerSyncException
     */
    private function catalogueReply(string $type, string $country, int $page, int $pageSize): array
    {
        try {
            [$status, $json] = $this->client()->billers([
                'countryISOCode' => $country,
                'type'           => $type,
                'page'           => $page,
                'size'           => $pageSize,
            ]);
        } catch (ReloadlyTokenException) {
            throw new BillerSyncException(__('Reloadly refused the connection credentials.'));
        } catch (ProviderOutcomeUnknownException) {
            throw new BillerSyncException(__('The Reloadly utilities API could not be reached.'));
        }

        if ($status < 200 || $status >= 300 || $json === null) {
            throw new BillerSyncException(__('Reloadly did not list :type billers for :country (HTTP :status).', [
                'type'    => $type,
                'country' => $country,
                'status'  => $status,
            ]));
        }

        return [$status, $json];
    }

    /**
     * One /billers row in local terms, or null when it has no usable id.
     *
     *   FIXED  a package biller; the packages are localFixedAmounts, each
     *          keyed by its amount id (sent back as amountId)
     *   RANGE  an open biller between the local minimum and maximum
     *
     * @param array<string, mixed> $row
     */
    private function syncedBiller(array $row, string $country, string $requestedType): ?SyncedBiller
    {
        $id = self::scalarString($row['id'] ?? null);

        if ($id === null || ! self::isNumericId($id)) {
            return null;
        }

        $type        = self::upperOrNull($row['type'] ?? null) ?? $requestedType;
        $serviceType = self::upperOrNull($row['serviceType'] ?? null);
        $fixed       = self::upperOrNull($row['denominationType'] ?? null) === 'FIXED';
        $items       = $fixed ? $this->syncedItems($row['localFixedAmounts'] ?? null) : [];
        $name        = is_string($row['name'] ?? null) && trim($row['name']) !== '' ? trim($row['name']) : __('Reloadly biller :id', ['id' => $id]);
        $fx          = ReloadlyPayload::fx($row);
        $invoice     = ($row['requiresInvoice'] ?? false) === true;

        $itemMeta = [];

        foreach ($items as $item) {
            $itemMeta[$item->externalCode] = ['is_resolvable' => false, 'fee' => null, 'label' => $item->name];
        }

        return new SyncedBiller(
            externalCode: $id,
            name: mb_substr($name, 0, 120),
            country: ReloadlyPayload::billerCountry($row) ?? $country,
            currency: ReloadlyPayload::billerLocalCurrency($row),
            providerCategory: $type,
            amountType: $fixed ? 'fixed' : 'open',
            minAmount: $fixed ? null : self::limit($row['minLocalTransactionAmount'] ?? null),
            maxAmount: $fixed ? null : self::limit($row['maxLocalTransactionAmount'] ?? null),
            items: $items,
            customerNumberLabel: match ($type) {
                'ELECTRICITY_BILL_PAYMENT' => __('Meter number'),
                'TV_BILL_PAYMENT'          => __('Smartcard / account number'),
                default                    => __('Account number'),
            },
            tokenLabel: $type === 'ELECTRICITY_BILL_PAYMENT' && $serviceType === 'PREPAID' ? __('Token') : null,
            supportsLookup: false,
            skipReason: match (true) {
                $invoice                                         => BillerSyncReport::SKIP_REQUIRES_INVOICE,
                ($row['localAmountSupported'] ?? null) === false => BillerSyncReport::SKIP_INTERNATIONAL_ONLY,
                default                                          => null,
            },
            meta: [
                'provider_type' => $type,
                'service_type'  => $serviceType,
                'fees'          => [
                    'fixed'            => ProviderDecimal::from($row['localTransactionFee'] ?? null),
                    'percent'          => ProviderDecimal::from($row['localTransactionFeePercentage'] ?? null),
                    'currency'         => self::upperOrNull($row['localTransactionFeeCurrencyCode'] ?? null),
                    'discount_percent' => ProviderDecimal::from($row['localDiscountPercentage'] ?? null),
                ],
                'fx' => [
                    'rate'     => ProviderDecimal::from($fx['rate']),
                    'currency' => $fx['currencyCode'],
                ],
                'requires_invoice' => $invoice,
                'items'            => $itemMeta,
            ],
        );
    }

    /**
     * The packages of a FIXED biller. A package without a numeric id or a
     * positive amount cannot be paid, so it is left out.
     *
     * @return list<SyncedBillerItem>
     */
    private function syncedItems(mixed $amounts): array
    {
        if (! is_array($amounts)) {
            return [];
        }

        $items = [];
        $seen  = [];

        foreach ($amounts as $amount) {
            if (! is_array($amount)) {
                continue;
            }

            $id    = self::scalarString($amount['id'] ?? null);
            $value = ProviderDecimal::from($amount['amount'] ?? null);

            if ($id === null || ! self::isNumericId($id) || isset($seen[$id]) || $value === null || DecimalMoney::compare($value, '0') <= 0) {
                continue;
            }

            $seen[$id] = true;
            $name      = is_string($amount['description'] ?? null) && trim($amount['description']) !== ''
                ? trim($amount['description'])
                : __('Package :id', ['id' => $id]);

            $items[] = new SyncedBillerItem($id, mb_substr($name, 0, 120), $value);
        }

        return $items;
    }

    /** A provider limit as a decimal string; zero or less means "no limit". */
    private static function limit(mixed $value): ?string
    {
        $decimal = ProviderDecimal::from($value);

        return $decimal === null || DecimalMoney::compare($decimal, '0') <= 0 ? null : $decimal;
    }

    private static function upperOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : null;
    }

    /**
     * @param array<array-key, mixed>|null $json
     */
    private function payResult(int $status, ?array $json, BillPaymentOrder $order): BillPaymentResult
    {
        $errorCode = ReloadlyPayload::errorCode($json);
        $message   = ReloadlyPayload::message($json);
        $id        = self::scalarString($json['id'] ?? null);

        if ($status >= 200 && $status < 300) {
            if ($json === null) {
                $this->log('Reloadly bill payment pay reply unreadable', '/pay', $status, $order);

                return BillPaymentResult::unknown(__('The provider did not give a clear answer.'));
            }

            $payStatus = strtoupper(trim((string) self::scalarString($json['status'] ?? null)));

            if ($id !== null && in_array($payStatus, ['PROCESSING', 'SUCCESSFUL'], true)) {
                return BillPaymentResult::pending(
                    __('The provider is processing the payment.'),
                    $id,
                    array_filter([
                        'status'          => $payStatus,
                        'code'            => self::scalarString($json['code'] ?? null),
                        'submitted_at'    => self::providerTime($json['submittedAt'] ?? null),
                        'final_status_at' => self::providerTime($json['finalStatusAvailabilityAt'] ?? null),
                    ], static fn (mixed $value): bool => $value !== null),
                );
            }

            if ($payStatus === 'FAILED' && ($json['referenceId'] ?? null) === $order->providerRequestId) {
                return BillPaymentResult::failed($message ?? __('The provider refused the payment.'), ['status' => $payStatus]);
            }

            $this->log('Reloadly bill payment pay reply unclear', '/pay', $status, $order);

            return BillPaymentResult::unknown(__('The provider did not give a clear answer.'));
        }

        if ($status === 401) {
            $this->log('Reloadly bill payment token refused', '/pay', $status, $order);

            return BillPaymentResult::failed(__('The provider refused the connection credentials.'), fault: ProviderFault::Credentials);
        }

        if (in_array($status, [408, 409, 429], true) || self::isDuplicateReference($errorCode, $message)) {
            $this->log('Reloadly bill payment pay reply unclear', '/pay', $status, $order);

            return BillPaymentResult::unknown(__('The provider did not give a clear answer.'));
        }

        if ($status === 404 && self::isRoutingNotFound($json, $errorCode)) {
            return BillPaymentResult::failed(__('The provider could not find this biller.'));
        }

        if ($status >= 400 && $status < 500 && $errorCode !== null && $id === null) {
            return BillPaymentResult::failed(
                $message ?? __('The provider refused the payment.'),
                ['error_code' => $errorCode],
                ProviderFault::fromRefusal($status, $errorCode, $message),
            );
        }

        $this->log('Reloadly bill payment pay reply unclear', '/pay', $status, $order);

        return BillPaymentResult::unknown(__('The provider did not give a clear answer.'));
    }

    /**
     * Whether a 404 is the routing "not found" envelope ({status, error,
     * message, path}, no errorCode), which means nothing was processed.
     * Reloadly also answers 404 with errorCode null and "Could not
     * retrieve/update resources at the moment" for a backend fault that may
     * have happened mid-write, and an empty 404 says nothing: neither is a
     * refusal.
     *
     * @param array<array-key, mixed>|null $json
     */
    private static function isRoutingNotFound(?array $json, ?string $errorCode): bool
    {
        return is_array($json)
            && $errorCode === null
            && is_string($json['error'] ?? null)
            && trim($json['error']) !== '';
    }

    /**
     * @param array{code: ?string, message: ?string, transaction: array<string, mixed>} $entry
     * @param array<string, mixed>                                                      $transaction
     */
    private function completed(?string $id, array $entry, array $transaction): BillPaymentResult
    {
        $amount   = ProviderDecimal::from($transaction['deliveryAmount'] ?? null);
        $currency = self::scalarString($transaction['deliveryAmountCurrencyCode'] ?? null);
        $currency = $currency === null ? null : strtoupper(trim($currency));

        if ($amount === null || $currency === null || $currency === '') {
            return BillPaymentResult::unknown(
                __('The provider confirmed the payment without the amount it delivered.'),
                ['status' => 'SUCCESSFUL'],
                $id,
            );
        }

        $billDetails = is_array($transaction['billDetails'] ?? null) ? $transaction['billDetails'] : [];
        $balanceInfo = is_array($transaction['balanceInfo'] ?? null) ? $transaction['balanceInfo'] : [];
        $pin         = ReloadlyPayload::pinDetails($transaction);
        $receipt     = self::scalarString($billDetails['billerReferenceId'] ?? null);

        return BillPaymentResult::completed(
            providerTransactionId: $id,
            receiptReference: $receipt !== null && trim($receipt) !== '' ? trim($receipt) : $id,
            token: ReloadlyPayload::token($transaction),
            amount: $amount,
            currency: $currency,
            message: __('The provider confirmed the payment.'),
            payload: array_filter([
                'status'             => 'SUCCESSFUL',
                'code'               => $entry['code'],
                'amount'             => self::scalarString($transaction['amount'] ?? null),
                'amountCurrencyCode' => self::scalarString($transaction['amountCurrencyCode'] ?? null),
                'fee'                => self::scalarString($transaction['fee'] ?? null),
                'feeCurrencyCode'    => self::scalarString($transaction['feeCurrencyCode'] ?? null),
                'discount'           => self::scalarString($transaction['discount'] ?? null),
                'cost'               => self::scalarString($balanceInfo['cost'] ?? null),
                'cost_currency'      => self::scalarString($balanceInfo['currencyCode'] ?? null),
                'info1'              => self::scalarString($pin['info1'] ?? null),
                'info2'              => self::scalarString($pin['info2'] ?? null),
                'info3'              => self::scalarString($pin['info3'] ?? null),
                'completed_at'       => self::scalarString($billDetails['completedAt'] ?? null),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }

    /**
     * Which binding check a matched transaction fails, or null when it is
     * ours: the same id we hold, our biller, our customer's account.
     *
     * @param array<string, mixed> $transaction
     */
    private function bindingMismatch(array $transaction, BillPaymentOrder $order): ?string
    {
        $id = self::scalarString($transaction['id'] ?? null);

        if ($order->providerTransactionId !== null && trim($order->providerTransactionId) !== '' && $id !== null && $id !== trim($order->providerTransactionId)) {
            return 'transaction_id';
        }

        $billDetails = is_array($transaction['billDetails'] ?? null) ? $transaction['billDetails'] : [];
        $billerId    = self::scalarString($billDetails['billerId'] ?? null);

        if ($billerId !== null && $billerId !== trim((string) $order->billerExternalCode)) {
            return 'biller';
        }

        $account = $billDetails['subscriberDetails']['accountNumber'] ?? null;

        if ($account !== null && self::alphanumeric((string) self::scalarString($account)) !== self::alphanumeric($order->customerNumber)) {
            return 'account';
        }

        return null;
    }

    private function client(): ReloadlyUtilitiesClient
    {
        return $this->client ??= ReloadlyUtilitiesClient::fromCredentials(
            (int) ($this->provider?->getKey() ?? 0),
            $this->credentials,
            $this->isSandbox(),
        );
    }

    private function isSandbox(): bool
    {
        return ReloadlyMobileRechargeProvider::isSandbox($this->credentials);
    }

    /**
     * Ids, endpoint and status only: never a body, a token, a secret or the
     * customer number.
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

    private static function isDuplicateReference(?string $errorCode, ?string $message): bool
    {
        foreach ([$errorCode, $message] as $text) {
            if ($text !== null && preg_match('/DUPLICATE|REFERENCE.*(EXIST|USED|ALREADY)/i', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** A positive integer id that fits in an int, as Reloadly's billerId and amountId are. */
    private static function isNumericId(string $value): bool
    {
        return $value !== '' && ctype_digit($value) && strlen(ltrim($value, '0')) <= 18 && (int) $value > 0;
    }

    /**
     * A Reloadly timestamp ("2022-05-19 09:13:52", no zone, UTC) as ISO 8601,
     * or null when it cannot be read.
     *
     * @todo live-check that Reloadly timestamps are UTC
     */
    private static function providerTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value), 'UTC')->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private static function scalarString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return null;
    }

    private static function alphanumeric(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }
}
