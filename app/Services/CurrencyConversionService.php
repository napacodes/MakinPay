<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CurrencyConversionService
{
    /**
     * The keyless fiat feed, named at the host that actually serves it.
     *
     * `api.frankfurter.app/latest` still resolves, but only to answer 301 to
     * this URL, so every quote spent a second DNS lookup and TLS handshake
     * out of one five-second budget — and the hop that timed out was the one
     * no file in this application mentioned. Kept as the fallback for an
     * installation whose cached config predates `remittance.fx.endpoint`.
     */
    private const KEYLESS_FX_ENDPOINT = 'https://api.frankfurter.dev/v1/latest';

    protected Client $coinMarketCapClient;

    protected Client $exchangeRateClient;

    protected array $fiatConversionData = [];

    protected array $cryptoConversionData = [];

    public function __construct()
    {

        $this->coinMarketCapClient = new Client([
            'base_uri' => 'https://pro-api.coinmarketcap.com/',
        ]);

        $this->exchangeRateClient = new Client([
            'base_uri' => 'https://api.currencylayer.com/',
        ]);

        $this->fiatConversionData   = $this->prepareAutoUpdateData('currencylayer');
        $this->cryptoConversionData = $this->prepareAutoUpdateData('coinmarketcap');

    }

    /**
     * Convert currency based on the type of conversion.
     *
     * @param  float      $amount The amount to be converted
     * @param  string     $from   The currency to convert from
     * @param  string     $to     The currency to convert to
     * @return float|null The converted amount
     *
     * @throws Exception
     */
    public function convertCurrency(float $amount, string $from, string $to): ?float
    {
        $from = strtoupper(trim($from));
        $to   = strtoupper(trim($to));

        if ($from !== '' && $from === $to) {
            return $amount;
        }

        $cryptoCurrencies   = collect(getJsonData('currencies')['crypto'])->pluck('code');
        $isCryptoConversion = $cryptoCurrencies->contains($from) || $cryptoCurrencies->contains($to);

        return $isCryptoConversion ? $this->convertCryptoWithCache($amount, $from, $to) : $this->convertFiatWithCache($amount, $from, $to);
    }

    /**
     * Prepare auto-update data based on plugin credentials.
     */
    protected function prepareAutoUpdateData(string $plugin): array
    {
        $credentials = pluginCredentials($plugin);

        if (! is_array($credentials)) {
            $credentials = [];
        }

        return array_merge(
            is_array($credentials['fields'] ?? null) ? $credentials['fields'] : [],
            [
                'api_key'            => $credentials['api_key'] ?? null,
                'status'             => (int) ($credentials['status'] ?? 0),
                'auto_update_status' => (int) ($credentials['auto_update_status'] ?? 0),
                'auto_update_time'   => (int) ($credentials['auto_update_time'] ?? 60),
            ]
        );
    }

    /**
     * Convert cryptocurrency using CoinMarketCap API with caching.
     *
     * @param  float      $amount The amount to be converted
     * @param  string     $from   The cryptocurrency symbol to convert from
     * @param  string     $to     The currency symbol to convert to
     * @return float|null The converted amount or null on failure
     */
    protected function convertCryptoWithCache(float $amount, string $from, string $to): ?float
    {
        $type     = 'crypto';
        $cacheKey = $this->getCacheKey($amount, $from, $to, $type);

        // A disabled provider means the rate is unknown, not that the two
        // assets trade at parity. This used to return $amount unchanged, which
        // made every crypto conversion an identity: asked what 1 USD buys in
        // BTC it answered 1. RateGuard could not catch it because the
        // placeholder was manufactured here, downstream of the stored rate the
        // guard inspects — so the guard passed on a correct 0.000016 while the
        // conversion ran at 1. Returning null lets callers fall back to the
        // rate that was actually configured.
        if ((int) ($this->cryptoConversionData['status'] ?? 0) !== 1) {
            return null;
        }

        return $this->cacheConversionResult($cacheKey, $amount, $from, $to, $type);
    }

    protected function getCacheKey(float $amount, string $from, string $to, string $type): string
    {
        return "{$type}_conversion_{$amount}_{$from}_{$to}";
    }

    /**
     * Cache the conversion result.
     *
     * @param string $type 'crypto' or 'fiat'
     */
    protected function cacheConversionResult(string $cacheKey, float $amount, string $from, string $to, string $type): ?float
    {

        $data     = $type == 'crypto' ? $this->cryptoConversionData : $this->fiatConversionData;
        $callback = $type == 'crypto' ? fn () => $this->fetchCryptoConversion($amount, $from, $to) : fn () => $this->fetchFiatConversion($amount, $from, $to);

        $configuredMinutes = (int) ($data['auto_update_time'] ?? config('remittance.fx.max_cache_minutes', 60));
        $cacheMinutes      = max(1, min($configuredMinutes, (int) config('remittance.fx.max_cache_minutes', 60)));

        return Cache::remember($cacheKey, now()->addMinutes($cacheMinutes), $callback);
    }

    /**
     * Fetch the cryptocurrency conversion from the CoinMarketCap API.
     *
     * @throws GuzzleException
     */
    protected function fetchCryptoConversion(float $amount, string $from, string $to): ?float
    {

        try {
            $response = $this->coinMarketCapClient->get('v2/tools/price-conversion', [
                'query' => [
                    'amount'  => $amount,
                    'symbol'  => $from,
                    'convert' => $to,
                ],
                'headers' => [
                    'Accept'            => 'application/json',
                    'X-CMC_PRO_API_KEY' => $this->cryptoConversionData['api_key'],
                ],
            ]);

            $data = json_decode($response->getBody(), true);

            return $data['data'][0]['quote'][$to]['price'] ?? null;
        } catch (Exception $e) {
            report($e);

            return null;
        }
    }

    /**
     * Fetch the fiat currency conversion from the ExchangeRate API.
     *
     * @throws GuzzleException
     */
    protected function fetchFiatConversion(float $amount, string $from, string $to): ?float
    {

        try {
            $response = $this->exchangeRateClient->get('convert', [
                'query' => [
                    'access_key' => $this->fiatConversionData['api_key'],
                    'from'       => $from,
                    'to'         => $to,
                    'amount'     => $amount,
                ],
            ]);
            $data = json_decode((string) $response->getBody(), true);

        } catch (Exception $e) {
            // Report the exception and try to extract a meaningful error message safely
            report($e);

            $errorMessage = 'ExchangeRate conversion failed.';

            if (method_exists($e, 'getResponse')) {
                $response = $e->getResponse();
                if ($response) {
                    try {
                        $decoded      = json_decode((string) $response->getBody(), true);
                        $errorMessage = $decoded['error']['info'] ?? $errorMessage;
                    } catch (\Throwable $t) {
                        // Ignore JSON/body parsing issues and keep default message
                    }
                }
            } else {
                // Fallback to the exception message when available
                $errorMessage = $e->getMessage() ?: $errorMessage;
            }

            session()->flash('exchange_rate_error', $errorMessage);

            return null;
        }

        if (! is_array($data)) {
            session()->flash('exchange_rate_error', 'ExchangeRate conversion failed.');

            return null;
        }

        return (! empty($data['success']) && array_key_exists('result', $data)) ? $data['result'] : null;
    }

    /**
     * Convert fiat currency using ExchangeRate API with caching.
     *
     * @param  float      $amount The amount to be converted
     * @param  string     $from   The fiat currency code to convert from
     * @param  string     $to     The currency code to convert to
     * @return float|null The converted amount or null on failure
     *
     * @throws Exception
     */
    protected function convertFiatWithCache(float $amount, string $from, string $to): ?float
    {
        $type     = 'fiat';
        $cacheKey = $this->getCacheKey($amount, $from, $to, $type);

        return $this->fiatConversionData['status'] == 1 ? $this->cacheConversionResult($cacheKey, $amount, $from, $to, $type) : $this->convertFiatTemporarily($from, $to, $amount);
    }

    /**
     * @throws Exception
     */
    private function convertFiatTemporarily(string $from, string $to, float $amount): ?float
    {
        try {
            $response = Http::timeout((int) config('remittance.fx.timeout_seconds', 5))
                ->retry(
                    (int) config('remittance.fx.retry_attempts', 2),
                    (int) config('remittance.fx.retry_delay_ms', 250),
                    throw: false,
                )
                ->get(config('remittance.fx.endpoint') ?: self::KEYLESS_FX_ENDPOINT, [
                    'base'    => strtoupper($from),
                    'symbols' => strtoupper($to),
                ]);
        } catch (ConnectionException $e) {
            // `throw: false` only suppresses a non-2xx *response*. A connection
            // that never completes throws straight past the guard below, so an
            // unreachable provider reached callers as a raw cURL string while
            // an unreachable-but-answering one got the message here: one
            // outage, two different failures. The original is chained rather
            // than swallowed, and neither path invents a rate.
            throw new Exception('Exchange-rate provider is currently unavailable.', 0, $e);
        }

        if (! $response->successful()) {
            throw new Exception('Exchange-rate provider is currently unavailable.');
        }

        $data = $response->json();

        if (! isset($data['rates'][strtoupper($to)]) || ! is_numeric($data['rates'][strtoupper($to)])) {
            throw new Exception('Invalid currency code.');
        }

        return round($amount * (float) $data['rates'][strtoupper($to)], 8);
    }

    public function fiatRateProvider(): string
    {
        return (int) ($this->fiatConversionData['status'] ?? 0) === 1 ? 'currencylayer' : 'frankfurter';
    }

    /**
     * Whether a crypto rate can be priced at all right now.
     *
     * Fiat always has a route — currencylayer when configured, the keyless
     * frankfurter endpoint otherwise — but crypto has only CoinMarketCap, and
     * with the plugin switched off `convertCryptoWithCache()` returns null for
     * every symbol. That is the correct answer, but from the outside it is
     * indistinguishable from a feed that happened to fail, so a scheduled
     * refresh reported "0 could not be priced" and looked like a clean run
     * while no crypto rate had moved in weeks. Callers ask this to tell the
     * operator which of the two they are looking at.
     */
    public function cryptoRatesAvailable(): bool
    {
        return (int) ($this->cryptoConversionData['status'] ?? 0) === 1;
    }
}
