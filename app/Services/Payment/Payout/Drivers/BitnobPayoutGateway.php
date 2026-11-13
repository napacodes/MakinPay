<?php

namespace App\Services\Payment\Payout\Drivers;

use App\Enums\PayoutMethod;
use App\Enums\RemittanceStatus;
use App\Models\Beneficiary;
use App\Models\RemittanceTransfer;
use App\Services\Bitnob\BitnobException;
use App\Services\Bitnob\BitnobPayoutService;
use App\Services\Bitnob\BitnobProviderSettings;
use App\Services\Payment\Payout\PayoutGateway;
use App\Services\Payment\Payout\PayoutNotFoundException;
use App\Services\Payment\Payout\PayoutNotSentException;
use App\Services\Payment\Payout\PayoutOrder;
use App\Services\Payment\Payout\PayoutOrderGateway;
use App\Services\Payment\Payout\PayoutProviderUnavailableException;
use App\Services\Payment\Payout\PayoutRecipient;
use App\Services\Payment\Payout\PayoutResult;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class BitnobPayoutGateway implements PayoutGateway, PayoutOrderGateway
{
    /** The Bitnob payment reason sent with every mass payout. */
    public const MASS_PAYOUT_PAYMENT_REASON = 'vendor_payment';

    /** Bitnob refused our credentials or permissions. */
    private const CREDENTIAL_REFUSED_STATUSES = [401, 403];

    /**
     * 4xx statuses that can mean the request is still being, or was already,
     * processed: timeout, conflict, too early, too many requests.
     */
    private const AMBIGUOUS_CLIENT_STATUSES = [408, 409, 425, 429];

    public function __construct(
        protected BitnobPayoutService $payouts,
        protected ?BitnobProviderSettings $settings = null,
    ) {}

    public function code(): string
    {
        return 'bitnob';
    }

    public function healthCheck(): array
    {
        try {
            $this->payouts->limits();

            return ['ok' => true, 'message' => __('Bitnob payout API credentials are valid.')];
        } catch (Throwable $exception) {
            return [
                'ok'      => false,
                'message' => __('Bitnob payout API health check failed: :message', [
                    'message' => $exception->getMessage(),
                ]),
            ];
        }
    }

    public function validateBeneficiary(Beneficiary $beneficiary): void
    {
        $details = (array) $beneficiary->account_details;

        if ($beneficiary->payout_method === PayoutMethod::BankAccount) {
            $this->requireDetail($details, 'account_number');
            $this->requireDetail($details, 'bank_code');

            return;
        }

        if ($beneficiary->payout_method === PayoutMethod::MobileWallet) {
            $this->requireAnyDetail($details, ['phone_number', 'account_number']);
            $this->requireAnyDetail($details, ['provider_code', 'bank_code']);

            return;
        }

        throw new RuntimeException(__('Bitnob does not support the selected beneficiary payout method.'));
    }

    public function payout(RemittanceTransfer $transfer): PayoutResult
    {
        $snapshot = (array) $transfer->recipient_snapshot;
        $details  = (array) ($snapshot['account_details'] ?? []);
        $method   = PayoutMethod::from((string) ($snapshot['payout_method'] ?? $transfer->payout_method->value));

        $beneficiary = $this->beneficiaryPayload($method, $snapshot, $details);
        $response    = $this->payouts->payout(
            quoteInput: [
                'settlement_amount' => (string) $transfer->receive_amount,
                'country'           => strtoupper((string) $snapshot['country_code']),
                'to_currency'       => strtoupper($transfer->destination_currency),
                'reference'         => $transfer->provider_idempotency_key,
            ],
            beneficiary: $beneficiary,
            callbackUrl: route('ipn.handle', ['gateway' => 'bitnob']),
            paymentReason: $this->providerSettings()->remittancePaymentReason(),
            reference: $transfer->provider_idempotency_key,
        );

        return $this->resultFromResponse($response, RemittanceStatus::Submitted);
    }

    public function checkStatus(RemittanceTransfer $transfer): PayoutResult
    {
        if (blank($transfer->gateway_reference)) {
            throw new RuntimeException(__('Bitnob payout reference is missing; automatic reconciliation is unsafe.'));
        }

        return $this->resultFromResponse(
            $this->payouts->showPayoutByReference($transfer->provider_idempotency_key),
            RemittanceStatus::Processing,
        );
    }

    /**
     * @param  array<string, mixed> $snapshot
     * @param  array<string, mixed> $details
     * @return array<string, mixed>
     */
    protected function beneficiaryPayload(PayoutMethod $method, array $snapshot, array $details): array
    {
        $beneficiary = [
            'destination_type' => $method === PayoutMethod::MobileWallet ? 'mobile_money' : 'bank',
            'country'          => strtoupper((string) $snapshot['country_code']),
            'account_name'     => (string) $snapshot['full_name'],
            'account_number'   => $details['account_number'] ?? $details['phone_number'] ?? null,
            'bank_code'        => $details['bank_code']      ?? $details['provider_code'] ?? null,
            'swift_code'       => $details['swift_code']     ?? null,
        ];

        foreach (['account_number', 'bank_code'] as $key) {
            if (blank($beneficiary[$key])) {
                throw new RuntimeException(__('Bitnob beneficiary data is missing :field.', ['field' => $key]));
            }
        }

        return array_filter($beneficiary, fn (mixed $value): bool => filled($value));
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function resultFromResponse(array $response, RemittanceStatus $default): PayoutResult
    {
        $providerStatus = strtolower((string) (
            data_get($response, 'data.payout.status')
            ?? data_get($response, 'data.data.status')
            ?? data_get($response, 'data.status')
            ?? data_get($response, 'status')
            ?? ''
        ));
        $status = match ($providerStatus) {
            'completed', 'successful', 'success' => RemittanceStatus::Completed,
            'expired', 'failed', 'rejected', 'cancelled', 'canceled', 'underpayment' => RemittanceStatus::Failed,
            'processing', 'in_progress', 'payment_received' => RemittanceStatus::Processing,
            'initiated', 'pending', 'pending_address_deposit', 'awaiting_payment', 'submitted', 'queued' => RemittanceStatus::Submitted,
            default => $default,
        };
        $reference = data_get($response, 'data.payout.id')
            ?? data_get($response, 'data.data.id')
            ?? data_get($response, 'data.id')
            ?? data_get($response, 'data.payout_id')
            ?? data_get($response, 'data.reference')
            ?? data_get($response, 'reference');

        return new PayoutResult(
            status: $status,
            gatewayReference: is_scalar($reference) ? (string) $reference : null,
            message: is_scalar(data_get($response, 'message')) ? (string) data_get($response, 'message') : null,
            payload: [
                'driver'          => $this->code(),
                'provider_status' => $providerStatus ?: null,
            ],
        );
    }

    /** @param array<string, mixed> $details */
    protected function requireDetail(array $details, string $key): void
    {
        if (blank($details[$key] ?? null)) {
            throw new RuntimeException(__('Bitnob beneficiary data requires :field.', ['field' => $key]));
        }
    }

    /**
     * @param array<string, mixed> $details
     * @param array<int, string>   $keys
     */
    protected function requireAnyDetail(array $details, array $keys): void
    {
        foreach ($keys as $key) {
            if (filled($details[$key] ?? null)) {
                return;
            }
        }

        throw new RuntimeException(__('Bitnob beneficiary data requires one of: :fields.', [
            'fields' => implode(', ', $keys),
        ]));
    }

    protected function providerSettings(): BitnobProviderSettings
    {
        return $this->settings ??= app(BitnobProviderSettings::class);
    }

    /* ------------------------- mass payout contract ------------------------- */

    public function validateRecipient(PayoutRecipient $recipient): void
    {
        $this->massPayoutBeneficiary($recipient);
    }

    /**
     * Runs quote, initialize and finalize itself, so a failure is classified
     * by the step it happened in. Finalize is the step that releases funds:
     * only what fails before it can be certain nothing was sent.
     */
    public function send(PayoutOrder $order): PayoutResult
    {
        try {
            $beneficiary = $this->massPayoutBeneficiary($order->recipient);
        } catch (InvalidArgumentException $exception) {
            throw new PayoutNotSentException('preflight', $exception->getMessage(), $exception);
        }

        $quoteId = $this->quoteMassPayout($order, (string) $beneficiary['country']);

        $this->initializeMassPayout($order, $quoteId, $beneficiary);

        return $this->massPayoutResult($this->payouts->finalize($quoteId), RemittanceStatus::Submitted);
    }

    /**
     * Looks the payout up by our own idempotency key, so it works before
     * any provider reference has been recorded.
     */
    public function lookup(PayoutOrder $order): PayoutResult
    {
        try {
            $response = $this->payouts->showPayoutByReference($order->idempotencyKey);
        } catch (BitnobException $exception) {
            if ($this->bitnobHttpStatus($exception) === 404) {
                throw new PayoutNotFoundException(__('Bitnob holds no payout for this reference.'), 0, $exception);
            }

            throw $exception;
        }

        if (blank(data_get($response, 'data'))) {
            throw new PayoutNotFoundException(__('Bitnob holds no payout for this reference.'));
        }

        return $this->massPayoutResult($response, RemittanceStatus::Processing);
    }

    public function supportsLookup(): bool
    {
        return true;
    }

    /** Three sequential HTTP calls, each bounded by the configured timeout. */
    public function maxCallSeconds(): int
    {
        return 3 * $this->providerSettings()->requestTimeout();
    }

    /**
     * @throws PayoutNotSentException
     */
    protected function quoteMassPayout(PayoutOrder $order, string $country): string
    {
        try {
            $quote = $this->payouts->quote([
                'settlement_amount' => $order->amount,
                'country'           => $country,
                'to_currency'       => $order->currency,
                'reference'         => $order->idempotencyKey,
            ]);
        } catch (Throwable $exception) {
            throw $this->massPayoutRefusal('quote', $exception);
        }

        $quoteId = data_get($quote, 'data.quote_id')
            ?? data_get($quote, 'data.payout.quote_id')
            ?? data_get($quote, 'data.id')
            ?? data_get($quote, 'quote_id');

        if (! is_scalar($quoteId) || trim((string) $quoteId) === '') {
            throw new PayoutNotSentException('quote', __('Bitnob returned no quote id.'));
        }

        return trim((string) $quoteId);
    }

    /**
     * A definitive 4xx at initialize means Bitnob refused the order before
     * any funds could be released. A timeout, a 5xx or one of the statuses
     * that can mean "already being processed" is rethrown as it is: the
     * outcome is unknown.
     *
     * @param array<string, mixed> $beneficiary
     *
     * @throws PayoutNotSentException
     */
    protected function initializeMassPayout(PayoutOrder $order, string $quoteId, array $beneficiary): void
    {
        try {
            $this->payouts->initialize(
                $quoteId,
                $beneficiary,
                self::MASS_PAYOUT_PAYMENT_REASON,
                route('ipn.handle', ['gateway' => 'bitnob']),
                $order->idempotencyKey,
            );
        } catch (BitnobException $exception) {
            $status = $this->bitnobHttpStatus($exception);

            if (in_array($status, self::CREDENTIAL_REFUSED_STATUSES, true)) {
                throw new PayoutProviderUnavailableException('initialize', $exception->getMessage(), $exception);
            }

            if ($status !== null && $status >= 400 && $status <= 499 && ! in_array($status, self::AMBIGUOUS_CLIENT_STATUSES, true)) {
                throw new PayoutNotSentException('initialize', $exception->getMessage(), $exception);
            }

            throw $exception;
        }
    }

    protected function massPayoutRefusal(string $stage, Throwable $exception): PayoutNotSentException
    {
        if (in_array($this->bitnobHttpStatus($exception), self::CREDENTIAL_REFUSED_STATUSES, true)) {
            return new PayoutProviderUnavailableException($stage, $exception->getMessage(), $exception);
        }

        return new PayoutNotSentException($stage, $exception->getMessage(), $exception);
    }

    protected function bitnobHttpStatus(Throwable $exception): ?int
    {
        if (! $exception instanceof BitnobException) {
            return null;
        }

        $status = $exception->context()['status'] ?? null;

        return is_numeric($status) ? (int) $status : null;
    }

    /**
     * The Bitnob beneficiary for a mass payout line, built with the same
     * field aliases the single withdraw flow accepts.
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException
     */
    protected function massPayoutBeneficiary(PayoutRecipient $recipient): array
    {
        if ($recipient->detail(['wallet_address', 'address']) !== null) {
            throw new InvalidArgumentException(__('Bitnob mass payouts cannot pay a crypto wallet address.'));
        }

        $destinationType = strtolower($recipient->detail(['destination_type']) ?? 'bank');

        if (! in_array($destinationType, ['bank', 'mobile_money'], true)) {
            throw new InvalidArgumentException(__('Bitnob mass payouts support bank and mobile money accounts only.'));
        }

        $country = strtoupper(trim((string) ($recipient->country ?? $recipient->detail(['country']) ?? '')));

        if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            throw new InvalidArgumentException(__('The recipient country must be a two-letter ISO code.'));
        }

        $accountName = trim($recipient->accountName) !== ''
            ? trim($recipient->accountName)
            : $recipient->detail(['account_name', 'name', 'recipient_name']);

        $beneficiary = [
            'destination_type' => $destinationType,
            'country'          => $country,
            'account_name'     => $accountName,
            'account_number'   => $destinationType === 'mobile_money'
                ? $recipient->detail(['account_number', 'phone_number', 'mobile_number'])
                : $recipient->detail(['account_number']),
            'bank_code'  => $recipient->detail(['bank_code', 'bank', 'provider_code']),
            'swift_code' => $recipient->detail(['swift_code']),
        ];

        foreach (['bank_code', 'account_number', 'account_name'] as $field) {
            if (blank($beneficiary[$field])) {
                throw new InvalidArgumentException(__('The :field field is required for a Bitnob payout.', ['field' => $field]));
            }
        }

        return array_filter($beneficiary, fn (?string $value): bool => filled($value));
    }

    /**
     * The remittance mapping, plus the settlement amount and currency when
     * Bitnob reports them.
     *
     * @param array<string, mixed> $response
     */
    protected function massPayoutResult(array $response, RemittanceStatus $default): PayoutResult
    {
        $result = $this->resultFromResponse($response, $default);
        $node   = data_get($response, 'data.payout') ?? data_get($response, 'data.data') ?? data_get($response, 'data');
        $node   = is_array($node) ? $node : [];

        $amount   = $node['settlement_amount']   ?? null;
        $currency = $node['settlement_currency'] ?? $node['to_currency'] ?? $node['destination_currency'] ?? null;

        $result->amount   = is_numeric($amount) ? (string) $amount : null;
        $result->currency = is_string($currency) && trim($currency) !== '' ? strtoupper(trim($currency)) : null;

        return $result;
    }
}
