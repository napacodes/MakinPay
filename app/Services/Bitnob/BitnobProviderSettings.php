<?php

namespace App\Services\Bitnob;

use App\Models\PaymentGateway;

class BitnobProviderSettings
{
    public const DEFAULT_API_BASE_URL = 'https://api.bitnob.com';

    public const DEFAULT_CARD_SANDBOX_BASE_URL = 'https://sandboxapi.bitnob.co/api/v1';

    public const DEFAULT_CARD_LIVE_BASE_URL = 'https://api.bitnob.co/api/v1';

    private const DEFAULT_TIMEOUT_SECONDS = 20;

    /** @var array<string, string> */
    private const ENDPOINTS = [
        'whoami'            => '/api/whoami',
        'customers'         => '/api/customers',
        'customer'          => '/api/customers/%s',
        'customer_cards'    => '/api/customers/%s/cards',
        'cards'             => '/api/cards',
        'card'              => '/api/cards/%s',
        'card_secure'       => '/api/cards/%s/secure',
        'card_balance'      => '/api/cards/%s/balance',
        'card_withdraw'     => '/api/cards/%s/balance',
        'card_status'       => '/api/cards/%s/status',
        'card_freeze'       => '/api/cards/%s/status',
        'card_unfreeze'     => '/api/cards/%s/status',
        'card_terminate'    => '/api/cards/%s',
        'card_limits'       => '/api/cards/%s/spend-limits',
        'card_transactions' => '/api/cards/%s/transactions',
        'payout_quote'      => '/api/payouts/quotes',
        'payout_initialize' => '/api/payouts/%s/initialize',
        'payout_finalize'   => '/api/payouts/%s/finalize',
        'payout_lookup'     => '/api/payouts/account-lookup',
        'payout_list'       => '/api/payouts',
        'payout_show'       => '/api/payouts/%s',
        'payout_reference'  => '/api/payouts/reference/%s',
        'payout_countries'  => '/api/payouts/supported-countries',
        'payout_country'    => '/api/payouts/supported-countries/%s',
        'payout_banks'      => '/api/payouts/banks/%s',
        'payout_limits'     => '/api/payouts/limits',
        'deposit_address'   => '/api/addresses',
        'stablecoin_send'   => '/api/v1/stablecoins/send',
    ];

    /** @var array<string, string> */
    private const WEBHOOK_HANDLERS = [
        'virtualcard.creation.success'     => 'handleCardCreated',
        'virtualcard.creation.failed'      => 'handleCardCreationFailed',
        'virtualcard.topup.success'        => 'handleCardTopupSuccess',
        'virtualcard.topup.failed'         => 'handleCardTopupFailed',
        'virtualcard.withdrawal.success'   => 'handleCardWithdrawalSuccess',
        'virtualcard.withdrawal.failed'    => 'handleCardWithdrawalFailed',
        'virtualcard.transaction.debit'    => 'handleCardDebit',
        'virtualcard.transaction.reversal' => 'handleCardReversal',
        'virtualcard.transaction.declined' => 'handleCardDeclined',
        'virtualcard.terminated'           => 'handleCardTerminated',
        'stablecoin.deposit.success'       => 'handleStablecoinDepositSuccess',
        // Observed in production: an incoming stablecoin deposit arrives as
        // `deposit.success` with the address under `data.address` and no
        // status field — the event name itself is the outcome.
        'deposit.success'   => 'handleStablecoinDepositSuccess',
        'deposit.confirmed' => 'handleStablecoinDepositSuccess',
        'deposit.failed'    => 'handleStablecoinTransferFailed',
        // One transfer.* event covers both incoming deposits and outgoing
        // sends; the handler routes by reference/address.
        'transfer.success'              => 'handleStablecoinTransfer',
        'transfer.failed'               => 'handleStablecoinTransferFailed',
        'payouts.initialized'           => 'handlePayoutProcessing',
        'payouts.asset.received'        => 'handlePayoutProcessing',
        'payouts.withdrawal.processing' => 'handlePayoutProcessing',
        'payouts.withdrawal.success'    => 'handlePayoutSuccess',
        'payouts.withdrawal.failed'     => 'handlePayoutFailed',
        'payouts.withdrawal.rejected'   => 'handlePayoutFailed',
        'payouts.withdrawal.expired'    => 'handlePayoutFailed',
        'payouts.asset.underpayment'    => 'handlePayoutFailed',
        'payout.processing'             => 'handlePayoutProcessing',
        'payout.completed'              => 'handlePayoutSuccess',
        'payout.failed'                 => 'handlePayoutFailed',
        'payout.success'                => 'handlePayoutSuccess',
    ];

    public function gateway(): ?PaymentGateway
    {
        return PaymentGateway::getByCode('bitnob');
    }

    /** @return array<string, mixed> */
    public function credentials(): array
    {
        return (array) ($this->gateway()?->credentials ?? []);
    }

    public function isGatewayEnabled(): bool
    {
        return $this->gateway()?->status === true;
    }

    public function isSandbox(): bool
    {
        return filter_var($this->credentials()['sandbox'] ?? true, FILTER_VALIDATE_BOOL);
    }

    public function mode(): string
    {
        return $this->isSandbox() ? 'sandbox' : 'live';
    }

    public function apiBaseUrl(?bool $sandbox = null): string
    {
        $credentials = $this->credentials();
        $mode        = ($sandbox ?? $this->isSandbox()) ? 'sandbox' : 'live';
        $value       = $credentials[$mode.'_base_url']
            ?? $credentials['base_url']
            ?? self::DEFAULT_API_BASE_URL;

        return $this->secureBaseUrl($value, $mode.' API base URL');
    }

    public function cardBaseUrl(?bool $sandbox = null): string
    {
        $credentials = $this->credentials();
        $isSandbox   = $sandbox ?? $this->isSandbox();
        $key         = $isSandbox ? 'card_sandbox_base_url' : 'card_live_base_url';
        $fallback    = $isSandbox ? self::DEFAULT_CARD_SANDBOX_BASE_URL : self::DEFAULT_CARD_LIVE_BASE_URL;

        return $this->secureBaseUrl($credentials[$key] ?? $fallback, 'card API base URL');
    }

    public function requestTimeout(): int
    {
        $timeout = filter_var(
            $this->credentials()['request_timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 120]],
        );

        return $timeout === false ? self::DEFAULT_TIMEOUT_SECONDS : $timeout;
    }

    public function signatureAlgorithm(): string
    {
        $algorithm = strtolower(trim((string) ($this->credentials()['signature_algo'] ?? 'sha256')));

        if (! in_array($algorithm, ['sha256', 'sha512'], true)) {
            throw BitnobException::fromResponse('Bitnob signature algorithm must be sha256 or sha512.');
        }

        return $algorithm;
    }

    public function remittancePaymentReason(): string
    {
        $reason = strtolower(trim((string) ($this->credentials()['remittance_payment_reason'] ?? 'personal_transfer')));

        return preg_match('/\A[a-z0-9_-]{2,64}\z/', $reason) === 1 ? $reason : 'personal_transfer';
    }

    public function endpoint(string $key, mixed ...$arguments): string
    {
        $template = self::ENDPOINTS[$key] ?? null;

        if ($template === null) {
            throw BitnobException::fromResponse("Unknown Bitnob endpoint: {$key}");
        }

        if ($arguments === []) {
            return $template;
        }

        try {
            return vsprintf($template, $arguments);
        } catch (\ValueError $exception) {
            throw BitnobException::fromResponse("Invalid arguments for Bitnob endpoint: {$key}", [
                'endpoint' => $key,
                'error'    => $exception->getMessage(),
            ]);
        }
    }

    public function webhookHandler(string $event): ?string
    {
        return self::WEBHOOK_HANDLERS[$event] ?? null;
    }

    private function secureBaseUrl(mixed $value, string $field): string
    {
        $url    = rtrim(trim(is_scalar($value) ? (string) $value : ''), '/');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = parse_url($url, PHP_URL_HOST);

        if ($scheme !== 'https' || ! is_string($host) || $host === '') {
            throw BitnobException::fromResponse("Bitnob {$field} must be an absolute HTTPS URL.");
        }

        return $url;
    }
}
