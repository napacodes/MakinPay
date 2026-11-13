<?php

namespace App\Services\Payment\Paypal;

use App\Models\PaymentGateway;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class PayPalClient
{
    protected array $credentials;

    private Client $client;

    private $accessToken;

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('paypal');

        $this->client = new Client([
            'base_uri' => filter_var($this->credentials['sandbox'] ?? false, FILTER_VALIDATE_BOOL)
                ? 'https://api.sandbox.paypal.com/'
                : 'https://api.paypal.com/',
        ]);
    }

    // Fetch Access Token from PayPal

    public function createPayment(array $payload)
    {
        return $this->makeRequest('POST', 'v1/payments/payment', $payload);
    }

    // Function to execute an approved payment

    public function executePayment(string $paymentId, string $payerId)
    {
        return $this->makeRequest('POST', "v1/payments/payment/{$paymentId}/execute", [
            'payer_id' => $payerId,
        ]);
    }

    public function payoutPayment($payoutData): mixed
    {
        $response = $this->makeRequest(
            'POST',
            'v1/payments/payouts',
            $payoutData
        );
        Log::info('PayPal Payout Response:', (array) $response);

        return $response;
    }

    /**
     * What PayPal says became of one payout item. Null for anything that is
     * not a conclusive answer — an unknown id, a 4xx, a timeout — because not
     * verified is not the same as not paid, and neither settles.
     *
     * @return array<string, mixed>|null
     */
    public function getPayoutItem(string $payoutItemId): ?array
    {
        try {
            $response = $this->client->request('GET', 'v1/payments/payouts-item/'.rawurlencode($payoutItemId), [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->getAccessToken(),
                    'Content-Type'  => 'application/json',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('PayPal payout item lookup failed: '.$e->getMessage(), ['payout_item_id' => $payoutItemId]);

            return null;
        }

        $decoded = json_decode($response->getBody()->getContents(), true);

        return is_array($decoded) ? $decoded : null;
    }

    // Centralize API requests

    protected function makeRequest(string $method, string $uri, array $data = [])
    {
        $response = $this->client->request($method, $uri, [
            'headers' => [
                'Authorization' => 'Bearer '.$this->getAccessToken(),
                'Content-Type'  => 'application/json',
            ],
            'json' => $data,
        ]);

        return json_decode($response->getBody()->getContents());
    }

    // Function to create a payment request

    protected function getAccessToken(): string
    {
        if (! $this->accessToken) {
            $response = $this->client->post('v1/oauth2/token', [
                'auth'        => [$this->credentials['client_id'], $this->credentials['client_secret']],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $this->accessToken = json_decode($response->getBody()->getContents())->access_token;
        }

        return $this->accessToken;
    }
}
