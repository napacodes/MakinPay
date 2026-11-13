<?php

namespace App\Services\MobileRecharge\Drivers;

use App\Contracts\MobileRecharge\DetectsRechargeOperators;
use App\Contracts\MobileRecharge\ListsRechargePlans;
use App\Contracts\MobileRecharge\MobileRechargeProviderInterface;
use App\Contracts\MobileRecharge\ProvidesRechargeDiagnostics;
use App\Enums\MobileRechargeStatus;
use App\Exceptions\NotifyErrorException;
use App\Exceptions\ProviderOutcomeUnknownException;
use App\Models\MobileRecharge;
use App\Models\MobileRechargeProvider;
use App\Services\CurrencyService;
use App\Services\MobileRecharge\MobileRechargeProviderResult;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reloadly Airtime API driver.
 *
 * Reloadly is a global airtime/recharge aggregator covering 180+ countries
 * with sandbox + production environments. Auth uses OAuth2 client
 * credentials → bearer token (cached). Topup endpoint: POST /topups.
 * Credentials are read from the matching plugin row; non-sensitive
 * provider config (default country, use_local_amount, etc.) is read from
 * the mobile_recharge_provider config column.
 *
 * @link https://developers.reloadly.com/
 */
class ReloadlyMobileRechargeProvider implements DetectsRechargeOperators, ListsRechargePlans, MobileRechargeProviderInterface, ProvidesRechargeDiagnostics
{
    private const AUTH_URL = 'https://auth.reloadly.com/oauth/token';

    private const SANDBOX_AUDIENCE = 'https://topups-sandbox.reloadly.com';

    private const SANDBOX_BASE = 'https://topups-sandbox.reloadly.com';

    private const PRODUCTION_AUDIENCE = 'https://topups.reloadly.com';

    private const PRODUCTION_BASE = 'https://topups.reloadly.com';

    private const TOKEN_CACHE_PREFIX = 'mobile_recharge_reloadly_token:';

    private ?MobileRechargeProvider $provider = null;

    /** @var array<string, mixed> */
    private array $credentials = [];

    /** @var array<string, mixed> */
    private array $config = [];

    /**
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $config
     */
    public function configure(MobileRechargeProvider $provider, array $credentials, array $config): void
    {
        $this->provider    = $provider;
        $this->credentials = $credentials;
        $this->config      = $config;
    }

    public function recharge(MobileRecharge $recharge): MobileRechargeProviderResult
    {
        $credentials = $this->credentials;
        $config      = $this->config;

        if ($credentials === []) {
            $provider = $this->provider ?? MobileRechargeProvider::query()
                ->where('code', $recharge->provider)
                ->with('plugin')
                ->first();

            if (! $provider) {
                throw new NotifyErrorException(__('Reloadly provider configuration is missing.'));
            }

            $this->configure($provider, $provider->credentials(), is_array($provider->config) ? $provider->config : []);

            $credentials = $this->credentials;
            $config      = $this->config;
        }

        $clientId     = (string) ($credentials['client_id'] ?? '');
        $clientSecret = (string) ($credentials['client_secret'] ?? '');
        $isSandbox    = self::isSandbox($credentials);
        $operatorId   = $recharge->metadata['operator_id']     ?? null;
        $planAmount   = $recharge->metadata['provider_amount'] ?? null;
        $country      = strtoupper((string) ($recharge->country ?? ($config['default_country'] ?? 'BD')));

        if ($clientId === '' || $clientSecret === '') {
            throw new NotifyErrorException(__('Reloadly credentials are not configured.'));
        }

        // The web form sends an operator name, never an id, and the admin form
        // has no field for a default id — so every web recharge used to stop
        // here. Reloadly names the operator behind a number itself; the
        // configured default is only the last resort.
        if ($operatorId === null || $operatorId === '') {
            $operatorId = $this->detectOperator((string) $recharge->phone_number, $country)['id'] ?? null;
        }

        if ($operatorId === null || $operatorId === '') {
            $operatorId = $config['default_operator_id'] ?? null;
        }

        // Without an operator the payload below silently drops the field via
        // array_filter and Reloadly answers with a generic rejection.
        if ($operatorId === null || $operatorId === '') {
            throw new NotifyErrorException(__('Reloadly could not find the operator for this number. Check the number and country.'));
        }

        // A catalogue plan is bought for its own sender-currency price; the
        // customer paid the wallet equivalent of it. Typed airtime is in the
        // wallet's currency and has to be priced for Reloadly first.
        $token = $this->fetchAccessToken($recharge->provider, $clientId, $clientSecret, $isSandbox);

        $sent = $planAmount !== null
            ? ['amount' => (string) $planAmount, 'currency' => (string) ($recharge->metadata['provider_currency'] ?? ''), 'use_local_amount' => false, 'rate' => null]
            : $this->typedAirtimeAmount($recharge, (string) $operatorId, $config);

        try {
            $response = Http::baseUrl($isSandbox ? self::SANDBOX_BASE : self::PRODUCTION_BASE)
                ->withToken($token)
                ->acceptJson()
                ->withHeaders(['Accept' => 'application/com.reloadly.topups-v1+json'])
                ->timeout((int) ($credentials['timeout'] ?? $config['timeout'] ?? 20))
                ->post('/topups', array_filter([
                    'operatorId'       => $operatorId,
                    'amount'           => (float) $sent['amount'],
                    'useLocalAmount'   => $sent['use_local_amount'],
                    'customIdentifier' => 'recharge_'.$recharge->id,
                    'recipientPhone'   => [
                        'countryCode' => $country,
                        'number'      => $recharge->phone_number,
                    ],
                    'senderPhone' => ! empty($config['sender_phone'])
                        ? [
                            'countryCode' => strtoupper((string) ($config['sender_country'] ?? 'US')),
                            'number'      => (string) $config['sender_phone'],
                        ]
                        : null,
                ], fn ($value) => $value !== null && $value !== ''));
        } catch (ConnectionException) {
            // MR1: the top-up may have left before the connection dropped or
            // timed out, so nobody can say it was not delivered - not a
            // failure to refund on.
            throw new ProviderOutcomeUnknownException('Reloadly did not answer the top-up for recharge '.$recharge->id.'.');
        } catch (Throwable $e) {
            throw new NotifyErrorException(__('Reloadly is unreachable: :msg', ['msg' => $e->getMessage()]));
        }

        return $this->mapResponse($response, $recharge, $sent);
    }

    /**
     * What to ask Reloadly for when the customer typed an amount.
     *
     * The amount is in the wallet's currency — the app and the web form both
     * charge it that way. It used to go to Reloadly as a *local* amount
     * whatever the wallet was, so a USD wallet paying 10 bought 10 BDT of
     * airtime. Now:
     *
     *  - wallet currency is the operator's own and the operator takes local
     *    amounts: sent as a local amount, exactly as typed;
     *  - otherwise: converted into the account's sender currency at the
     *    site's rates, truncated to that currency's precision so the platform
     *    is never billed more than the customer paid.
     *
     * Nothing is sent when the operator cannot be priced or no rate exists —
     * the recharge fails and the service refunds the wallet.
     *
     * @param  array<string, mixed>                                                           $config
     * @return array{amount: string, currency: string, use_local_amount: bool, rate: ?string}
     *
     * @throws NotifyErrorException
     */
    private function typedAirtimeAmount(MobileRecharge $recharge, string $operatorId, array $config): array
    {
        $walletCurrency = strtoupper((string) $recharge->currency);
        $amount         = DecimalMoney::normalize((string) $recharge->amount);

        try {
            $response = $this->client()->get('/operators/'.$operatorId);
        } catch (ConnectionException) {
            $response = null;
        }

        $operator = $response?->successful() ? (array) $response->json() : [];
        $local    = strtoupper((string) ($operator['destinationCurrencyCode'] ?? ''));
        $sender   = strtoupper((string) ($operator['senderCurrencyCode'] ?? ''));

        if ($local === '' || $sender === '') {
            throw new NotifyErrorException(__('Reloadly could not price this operator right now. Please try again.'));
        }

        $preferLocal = (bool) ($config['use_local_amount'] ?? true);

        if ($preferLocal && $walletCurrency === $local && (bool) ($operator['supportsLocalAmounts'] ?? false)) {
            return ['amount' => $amount, 'currency' => $local, 'use_local_amount' => true, 'rate' => null];
        }

        $currencies = app(CurrencyService::class);
        $rate       = $currencies->crossRate($walletCurrency, $sender);
        $rates      = $currencies->ratesByCode([$walletCurrency, $sender]);

        if ($rate === null || ! $rates->has($walletCurrency) || ! $rates->has($sender)) {
            throw new NotifyErrorException(__('A :wallet wallet cannot pay for this recharge: there is no exchange rate to :sender.', [
                'wallet' => $walletCurrency,
                'sender' => $sender,
            ]));
        }

        // Multiplied before it is divided: the cross rate itself is often a
        // repeating decimal (1/120), and truncating a product taken through it
        // turned 600 BDT into 4.99 USD rather than 5.00. normalize() then
        // truncates (bcmath), so the platform's cost is never rounded up.
        $converted = $walletCurrency === $sender
            ? DecimalMoney::normalize($amount, Money::decimals($sender))
            : DecimalMoney::normalize(
                DecimalMoney::divide(DecimalMoney::multiply($amount, $rates->get($sender)), $rates->get($walletCurrency)),
                Money::decimals($sender),
            );

        if (DecimalMoney::compare($converted, '0') <= 0) {
            throw new NotifyErrorException(__('This amount is too small to recharge.'));
        }

        return ['amount' => $converted, 'currency' => $sender, 'use_local_amount' => false, 'rate' => $rate];
    }

    /**
     * Probe the credentials without sending money.
     *
     * Deliberately does not reuse fetchAccessToken(): that caches the token
     * for 50 minutes and collapses every failure into one generic message, so
     * a diagnostic built on it could report success from a token minted before
     * the admin changed the keys, or fail without saying why.
     *
     * Two mistakes account for most "Reloadly is not working" reports and both
     * are called out by name here — production keys sent to the sandbox
     * audience (Reloadly refuses the token, never the recharge), and a
     * provider saved with no operator id (accepted at save time, fails only
     * when a customer tries to top up).
     *
     * @return array{ok: bool, mode: ?string, message: string, latency_ms: ?int, details: array<string, mixed>}
     */
    public function testConnection(): array
    {
        $credentials  = $this->credentials;
        $config       = $this->config;
        $clientId     = trim((string) ($credentials['client_id'] ?? ''));
        $clientSecret = trim((string) ($credentials['client_secret'] ?? ''));
        $isSandbox    = self::isSandbox($credentials);
        $mode         = $isSandbox ? 'sandbox' : 'live';
        $operatorId   = $config['default_operator_id'] ?? null;

        $details = [
            'mode'                => $mode,
            'audience'            => $isSandbox ? self::SANDBOX_AUDIENCE : self::PRODUCTION_AUDIENCE,
            'default_operator_id' => $operatorId === null || $operatorId === '' ? null : (string) $operatorId,
        ];

        if ($clientId === '' || $clientSecret === '') {
            return [
                'ok'         => false,
                'mode'       => $mode,
                'message'    => __('Client ID and Client Secret are not both set on the Reloadly plugin.'),
                'latency_ms' => null,
                'details'    => $details,
            ];
        }

        $startedAt = microtime(true);

        try {
            $auth = Http::acceptJson()
                ->timeout((int) ($credentials['timeout'] ?? $config['timeout'] ?? 20))
                ->post(self::AUTH_URL, [
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type'    => 'client_credentials',
                    'audience'      => $isSandbox ? self::SANDBOX_AUDIENCE : self::PRODUCTION_AUDIENCE,
                ]);
        } catch (Throwable $e) {
            return [
                'ok'         => false,
                'mode'       => $mode,
                'message'    => __('Could not reach Reloadly: :error', ['error' => $e->getMessage()]),
                'latency_ms' => $this->elapsedMs($startedAt),
                'details'    => $details,
            ];
        }

        if (! $auth->successful()) {
            $details['http_status']    = $auth->status();
            $details['provider_error'] = $this->providerMessage($auth);

            return [
                'ok'         => false,
                'mode'       => $mode,
                'message'    => __('Reloadly rejected these credentials for the :mode environment. If the keys are correct, check that the Sandbox switch matches the keys you pasted.', ['mode' => $mode]),
                'latency_ms' => $this->elapsedMs($startedAt),
                'details'    => $details,
            ];
        }

        $token = (string) data_get($auth->json(), 'access_token', '');

        if ($token === '') {
            return [
                'ok'         => false,
                'mode'       => $mode,
                'message'    => __('Reloadly accepted the credentials but returned no access token.'),
                'latency_ms' => $this->elapsedMs($startedAt),
                'details'    => $details,
            ];
        }

        // Auth alone only proves the keys exist. Spending the token on the
        // topups host is what proves the environment the recharge will
        // actually use is reachable with it.
        try {
            $balance = Http::baseUrl($isSandbox ? self::SANDBOX_BASE : self::PRODUCTION_BASE)
                ->withToken($token)
                ->acceptJson()
                ->withHeaders(['Accept' => 'application/com.reloadly.topups-v1+json'])
                ->timeout((int) ($credentials['timeout'] ?? $config['timeout'] ?? 20))
                ->get('/accounts/balance');
        } catch (Throwable $e) {
            return [
                'ok'         => false,
                'mode'       => $mode,
                'message'    => __('Credentials were accepted, but the Reloadly topup API could not be reached: :error', ['error' => $e->getMessage()]),
                'latency_ms' => $this->elapsedMs($startedAt),
                'details'    => $details,
            ];
        }

        $latency = $this->elapsedMs($startedAt);

        if (! $balance->successful()) {
            $details['http_status']    = $balance->status();
            $details['provider_error'] = $this->providerMessage($balance);

            return [
                'ok'         => false,
                'mode'       => $mode,
                'message'    => __('Credentials were accepted, but the Reloadly topup API refused the request.'),
                'latency_ms' => $latency,
                'details'    => $details,
            ];
        }

        $details['balance']  = data_get($balance->json(), 'balance');
        $details['currency'] = data_get($balance->json(), 'currencyCode');

        // Not a failure any more: a recharge without an operator id asks
        // Reloadly to detect it from the number, and only falls back to this.
        $details['operator'] = $details['default_operator_id'] === null ? 'auto-detect' : 'default';

        return [
            'ok'         => true,
            'mode'       => $mode,
            'message'    => __('Reloadly credentials work and the :mode topup API is reachable.', ['mode' => $mode]),
            'latency_ms' => $latency,
            'details'    => $details,
        ];
    }

    /**
     * Whether these credentials target the sandbox.
     *
     * The plugin dialog stored this as free text, and a PHP cast reads
     * "false", "off", "no" and "live" as true — so an admin who typed any of
     * them sent live keys to the sandbox audience and Reloadly refused every
     * token. Only an off value selects live; anything unreadable stays on the
     * sandbox, the safe side. A blank value keeps meaning live, as the old
     * cast read it, so an installation already running live is not moved.
     *
     * @param array<string, mixed> $credentials
     */
    public static function isSandbox(array $credentials): bool
    {
        $value = $credentials['sandbox'] ?? true;

        if (is_bool($value)) {
            return $value;
        }

        return ! in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'off', 'no', 'live', 'production'], true);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * The provider's own words, so an administrator sees why it refused
     * rather than a message this application invented.
     */
    private function providerMessage(Response $response): string
    {
        $payload = $response->json();

        if (is_array($payload)) {
            foreach (['message', 'error_description', 'error', 'errorCode'] as $key) {
                $value = data_get($payload, $key);

                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }

        return trim((string) $response->body()) !== ''
            ? mb_substr(trim((string) $response->body()), 0, 300)
            : __('No message returned.');
    }

    private function fetchAccessToken(string $providerCode, string $clientId, string $clientSecret, bool $isSandbox): string
    {
        // Keyed on the keys themselves too, so pasting a corrected pair takes
        // effect on the next recharge rather than after the old token expires.
        $cacheKey = self::TOKEN_CACHE_PREFIX.$providerCode.':'.($isSandbox ? 'sandbox' : 'production').':'.substr(hash('sha256', $clientId.'|'.$clientSecret), 0, 16);

        return (string) Cache::remember($cacheKey, now()->addMinutes(50), function () use ($clientId, $clientSecret, $isSandbox): string {
            $response = Http::acceptJson()
                ->timeout(15)
                ->post(self::AUTH_URL, [
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type'    => 'client_credentials',
                    'audience'      => $isSandbox ? self::SANDBOX_AUDIENCE : self::PRODUCTION_AUDIENCE,
                ]);

            if (! $response->successful()) {
                throw new NotifyErrorException(__('Reloadly refused the :mode credentials (:reason). Check that the Sandbox / Live setting matches the keys.', [
                    'mode'   => $isSandbox ? 'sandbox' : 'live',
                    'reason' => $this->providerMessage($response),
                ]));
            }

            $token = (string) data_get($response->json(), 'access_token', '');

            if ($token === '') {
                throw new NotifyErrorException(__('Reloadly returned an empty access token.'));
            }

            return $token;
        });
    }

    /**
     * @param array{amount: string, currency: string, use_local_amount: bool, rate: ?string} $sent what was asked of Reloadly, kept on the recharge so the conversion can be audited
     */
    private function mapResponse(Response $response, MobileRecharge $recharge, array $sent): MobileRechargeProviderResult
    {
        $payload                     = (array) ($response->json() ?? []);
        $payload['provider_request'] = $sent;

        // MR1: a server error says nothing about whether the top-up went
        // through; only a 4xx is Reloadly refusing it.
        if ($response->serverError()) {
            throw new ProviderOutcomeUnknownException('Reloadly answered '.$response->status().' to the top-up for recharge '.$recharge->id.'.');
        }

        if (! $response->successful()) {
            return new MobileRechargeProviderResult(
                status: MobileRechargeStatus::FAILED,
                reference: 'reloadly_'.$recharge->id,
                message: (string) data_get($payload, 'message', __('Reloadly rejected the request.')),
                payload: $payload,
            );
        }

        $apiStatus = strtoupper((string) data_get($payload, 'status', 'PROCESSING'));
        $status    = match ($apiStatus) {
            'SUCCESSFUL', 'COMPLETED' => MobileRechargeStatus::COMPLETED,
            'FAILED', 'REFUNDED', 'CANCELLED' => MobileRechargeStatus::FAILED,
            default => MobileRechargeStatus::PROCESSING,
        };

        return new MobileRechargeProviderResult(
            status: $status,
            reference: (string) data_get($payload, 'transactionId', 'reloadly_'.$recharge->id),
            message: (string) data_get($payload, 'message', __('Reloadly accepted the recharge.')),
            payload: $payload,
        );
    }

    /**
     * Reloadly's own guess at the operator behind a number, or null when it
     * has none - the app then falls back to typing the operator's name.
     */
    public function detectOperator(string $phoneNumber, string $country): ?array
    {
        $digits = preg_replace('/[^0-9]/', '', $phoneNumber) ?? '';
        if ($digits === '') {
            return null;
        }

        try {
            $response = $this->client()->get('/operators/auto-detect/phone/'.$digits.'/countries/'.strtoupper($country), ['suggestedAmountsMap' => 'true']);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || ! is_numeric($response->json('operatorId'))) {
            return null;
        }

        return [
            'id'      => (string) $response->json('operatorId'),
            'name'    => (string) $response->json('name'),
            'prepaid' => ! (bool) $response->json('postpaid', false),
            'logo'    => data_get($response->json(), 'logoUrls.0'),
        ];
    }

    /**
     * The operator's fixed-amount products, each sold by id. Amounts are in
     * the sender currency Reloadly prices for this account, with the local
     * price beside them.
     */
    public function plans(string $operatorId, string $country, string $currency): array
    {
        try {
            $response = $this->client()->get('/operators/'.$operatorId, ['includeBundles' => 'true']);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $operator     = $response->json() ?? [];
        $amounts      = (array) ($operator['fixedAmounts'] ?? []);
        $local        = (array) ($operator['localFixedAmounts'] ?? []);
        $descriptions = (array) ($operator['fixedAmountsDescriptions'] ?? []);
        $localCode    = (string) ($operator['destinationCurrencyCode'] ?? '');
        $isBundle     = (bool) ($operator['bundle'] ?? false) || (bool) ($operator['data'] ?? false);
        $plans        = [];

        foreach (array_values($amounts) as $i => $amount) {
            $description = (string) ($descriptions[(string) $amount] ?? $descriptions[$i] ?? '');
            $plans[]     = [
                'id'             => $operatorId.':'.$amount,
                'kind'           => self::kindOf($description, $isBundle),
                'name'           => $description !== '' ? Str::limit(Str::before($description, ','), 40, '') : $amount.' '.$localCode,
                'description'    => $description !== '' ? $description : null,
                'amount'         => (string) $amount,
                'currency'       => (string) ($operator['senderCurrencyCode'] ?? $currency),
                'local_amount'   => isset($local[$i]) ? (string) $local[$i] : null,
                'local_currency' => $localCode !== '' ? $localCode : null,
                'validity'       => self::validityOf($description),
                'tags'           => [],
                'operator_id'    => $operatorId,
            ];
        }

        return $plans;
    }

    public function plan(string $planId, string $country, string $currency): ?array
    {
        [$operatorId, $amount] = array_pad(explode(':', $planId, 2), 2, '');
        if ($operatorId === '' || $amount === '') {
            return null;
        }

        foreach ($this->plans($operatorId, $country, $currency) as $plan) {
            if ($plan['id'] === $planId) {
                return $plan;
            }
        }

        return null;
    }

    private static function kindOf(string $description, bool $bundle): string
    {
        $d = strtolower($description);

        return match (true) {
            str_contains($d, 'roam')                                                      => 'roaming',
            str_contains($d, 'min') && (str_contains($d, 'gb') || str_contains($d, 'mb')) => 'combo',
            str_contains($d, 'min')                                                       => 'minutes',
            str_contains($d, 'gb') || str_contains($d, 'mb') || $bundle                   => 'data',
            default                                                                       => 'airtime',
        };
    }

    private static function validityOf(string $description): ?string
    {
        return preg_match('/(\d+)\s*(day|days|week|weeks|month|months)/i', $description, $m) === 1 ? $m[1].' '.strtolower($m[2]) : null;
    }

    /**
     * A client on the right audience with a fresh-enough token, for the
     * read-only catalogue calls.
     */
    private function client(): PendingRequest
    {
        $credentials  = $this->credentials;
        $clientId     = (string) ($credentials['client_id'] ?? '');
        $clientSecret = (string) ($credentials['client_secret'] ?? '');
        $isSandbox    = self::isSandbox($credentials);
        $token        = $this->fetchAccessToken((string) ($this->provider?->code ?? 'reloadly'), $clientId, $clientSecret, $isSandbox);

        return Http::baseUrl($isSandbox ? self::SANDBOX_BASE : self::PRODUCTION_BASE)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders(['Accept' => 'application/com.reloadly.topups-v1+json'])
            ->timeout((int) ($credentials['timeout'] ?? $this->config['timeout'] ?? 20));
    }
}
