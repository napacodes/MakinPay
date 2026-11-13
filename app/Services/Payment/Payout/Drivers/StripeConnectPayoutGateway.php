<?php

declare(strict_types=1);

namespace App\Services\Payment\Payout\Drivers;

use App\Enums\PayoutMethod;
use App\Enums\RemittanceStatus;
use App\Models\Beneficiary;
use App\Models\RemittanceTransfer;
use App\Services\Payment\Payout\PayoutContradictionException;
use App\Services\Payment\Payout\PayoutGateway;
use App\Services\Payment\Payout\PayoutNotFoundException;
use App\Services\Payment\Payout\PayoutNotSentException;
use App\Services\Payment\Payout\PayoutOrder;
use App\Services\Payment\Payout\PayoutOrderGateway;
use App\Services\Payment\Payout\PayoutProviderUnavailableException;
use App\Services\Payment\Payout\PayoutRecipient;
use App\Services\Payment\Payout\PayoutResult;
use App\Services\Payment\Stripe\StripeConnectService;
use App\Services\Payment\Stripe\StripeMoney;
use InvalidArgumentException;
use RuntimeException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Stripe\StripeObject;
use Throwable;

/**
 * Remittance payouts over Stripe Connect.
 *
 * The recipient holds a Stripe connected account (`acct_…`) with their bank
 * attached; a Transfer moves the receive amount from the platform balance into
 * that account and Stripe's own payout schedule settles it to their bank. This
 * covers the corridors Stripe serves directly — US, UK, EU, CA, AU — which are
 * exactly the ones a mobile-money partner such as Bitnob does not.
 *
 * `Completed` therefore means the funds are irrevocably in the recipient's
 * Stripe balance; the bank leg follows on Stripe's payout schedule.
 */
class StripeConnectPayoutGateway implements PayoutGateway, PayoutOrderGateway
{
    /**
     * Statuses an InvalidRequestException can carry while the request may
     * still be, or already have been, processed.
     */
    private const AMBIGUOUS_CLIENT_STATUSES = [409, 429];

    /**
     * Beneficiary `account_details` key holding the recipient's connected
     * account id.
     */
    public const ACCOUNT_DETAIL_KEY = 'stripe_account_id';

    public function __construct(protected StripeConnectService $connect) {}

    public function code(): string
    {
        return 'stripe_connect';
    }

    public function healthCheck(): array
    {
        try {
            $account = $this->connect->client()->accounts->retrieve();

            return [
                'ok'      => true,
                'message' => __('Stripe Connect is reachable (platform account :id).', [
                    'id' => $account->id ?? 'unknown',
                ]),
            ];
        } catch (Throwable $exception) {
            return [
                'ok'      => false,
                'message' => __('Stripe Connect health check failed: :message', [
                    'message' => $exception->getMessage(),
                ]),
            ];
        }
    }

    /**
     * Stripe can only pay a recipient who already has a connected account, so
     * this is checked before any wallet is debited.
     */
    public function validateBeneficiary(Beneficiary $beneficiary): void
    {
        if (! in_array($beneficiary->payout_method, [PayoutMethod::BankAccount, PayoutMethod::WalletCredit], true)) {
            throw new RuntimeException(__('Stripe Connect only pays out to a bank account or Stripe balance.'));
        }

        $this->accountIdFrom((array) $beneficiary->account_details);
    }

    public function payout(RemittanceTransfer $transfer): PayoutResult
    {
        $snapshot  = (array) $transfer->recipient_snapshot;
        $details   = (array) ($snapshot['account_details'] ?? []);
        $accountId = $this->accountIdFrom($details);
        $currency  = strtolower((string) $transfer->destination_currency);

        $transferObject = $this->connect->client()->transfers->create([
            'amount'         => StripeMoney::toSubunit((float) $transfer->receive_amount, $currency),
            'currency'       => $currency,
            'destination'    => $accountId,
            'transfer_group' => (string) $transfer->reference,
            'description'    => __('Remittance :reference', ['reference' => $transfer->reference]),
            'metadata'       => [
                'transfer_reference' => (string) $transfer->reference,
                'beneficiary'        => (string) ($snapshot['full_name'] ?? ''),
            ],
        ], [
            // Retrying a timed-out request must never create a second transfer.
            'idempotency_key' => (string) $transfer->provider_idempotency_key,
        ]);

        return $this->resultFrom($transferObject->toArray(), RemittanceStatus::Submitted);
    }

    public function checkStatus(RemittanceTransfer $transfer): PayoutResult
    {
        if (blank($transfer->gateway_reference)) {
            throw new RuntimeException(__('Stripe transfer reference is missing; automatic reconciliation is unsafe.'));
        }

        $transferObject = $this->connect->client()->transfers->retrieve((string) $transfer->gateway_reference);

        return $this->resultFrom($transferObject->toArray(), RemittanceStatus::Processing);
    }

    /**
     * A Stripe transfer has no status field: it either stands or it has been
     * reversed, so the reversal amount is the signal.
     *
     * @param array<string, mixed> $payload
     */
    protected function resultFrom(array $payload, RemittanceStatus $default): PayoutResult
    {
        $reversed = (bool) ($payload['reversed'] ?? false)
            || (int) ($payload['amount_reversed'] ?? 0) > 0;

        $status = match (true) {
            $reversed                                       => RemittanceStatus::Failed,
            filled($payload['destination_payment'] ?? null) => RemittanceStatus::Completed,
            default                                         => $default,
        };

        $reference = $payload['id'] ?? null;

        return new PayoutResult(
            status: $status,
            gatewayReference: is_scalar($reference) ? (string) $reference : null,
            message: $reversed ? __('The Stripe transfer was reversed.') : null,
            payload: [
                'driver'          => $this->code(),
                'destination'     => $payload['destination']     ?? null,
                'amount_reversed' => $payload['amount_reversed'] ?? 0,
            ],
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    protected function accountIdFrom(array $details): string
    {
        $accountId = trim((string) ($details[self::ACCOUNT_DETAIL_KEY] ?? ''));

        if (! str_starts_with($accountId, 'acct_')) {
            throw new RuntimeException(__('This recipient needs a Stripe connected account id (acct_…) before Stripe can pay them.'));
        }

        return $accountId;
    }

    /* ------------------------- mass payout contract ------------------------- */

    public function validateRecipient(PayoutRecipient $recipient): void
    {
        try {
            $this->accountIdFrom($recipient->details);
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * One transfer, deduplicated by Stripe under the order's key and filed
     * under a transfer group of the same key, so a lookup can find it even
     * when the create answer never arrived.
     */
    public function send(PayoutOrder $order): PayoutResult
    {
        try {
            $accountId = $this->accountIdFrom($order->recipient->details);
        } catch (RuntimeException $exception) {
            throw new PayoutNotSentException('preflight', $exception->getMessage(), $exception);
        }

        $subunit = StripeMoney::toSubunitExact($order->amount, $order->currency);

        if ($subunit === null || $subunit < 1) {
            throw new PayoutNotSentException('preflight', __('The amount cannot be paid exactly in :currency.', [
                'currency' => $order->currency,
            ]));
        }

        try {
            $client = $this->connect->client();
        } catch (Throwable $exception) {
            throw new PayoutProviderUnavailableException('preflight', $exception->getMessage(), $exception);
        }

        try {
            $transferObject = $client->transfers->create([
                'amount'         => $subunit,
                'currency'       => strtolower($order->currency),
                'destination'    => $accountId,
                'transfer_group' => $order->idempotencyKey,
                'description'    => __('Mass payout :reference', ['reference' => $order->reference]),
                'metadata'       => [
                    'mass_payout_reference' => $order->reference,
                    'provider_request_id'   => $order->idempotencyKey,
                ],
            ], [
                'idempotency_key' => $order->idempotencyKey,
            ]);
        } catch (AuthenticationException|PermissionException $exception) {
            throw new PayoutProviderUnavailableException('create', $exception->getMessage(), $exception);
        } catch (InvalidRequestException $exception) {
            if ($this->isDefinitiveRefusal($exception)) {
                throw new PayoutNotSentException('create', $exception->getMessage(), $exception);
            }

            throw $exception;
        }

        return $this->massPayoutResult($transferObject, RemittanceStatus::Submitted);
    }

    /**
     * Retrieve by the transfer id when it is known; otherwise search the
     * transfer group named after the key. More than one transfer in that
     * group cannot describe one line, so it is a contradiction.
     */
    public function lookup(PayoutOrder $order): PayoutResult
    {
        $transfers = $this->connect->client()->transfers;

        if (filled($order->gatewayReference)) {
            return $this->massPayoutResult($transfers->retrieve((string) $order->gatewayReference), RemittanceStatus::Processing);
        }

        $matches = $transfers->all(['transfer_group' => $order->idempotencyKey, 'limit' => 3])->data;

        return match (count($matches)) {
            0       => throw new PayoutNotFoundException(__('Stripe holds no transfer in this group.')),
            1       => $this->massPayoutResult($matches[0], RemittanceStatus::Processing),
            default => throw new PayoutContradictionException(__('Stripe holds more than one transfer in this group.')),
        };
    }

    public function supportsLookup(): bool
    {
        return true;
    }

    /** The client timeout for every attempt the Stripe client may make. */
    public function maxCallSeconds(): int
    {
        return max(1, (int) CurlClient::instance()->getTimeout()) * (max(0, (int) Stripe::getMaxNetworkRetries()) + 1);
    }

    /**
     * An InvalidRequestException is a refusal of the request itself, except
     * for its rate-limit and idempotency relatives and a conflict, all of
     * which can arrive while the original request is still in flight.
     */
    protected function isDefinitiveRefusal(InvalidRequestException $exception): bool
    {
        if ($exception instanceof RateLimitException || $exception instanceof IdempotencyException) {
            return false;
        }

        return ! in_array((int) $exception->getHttpStatus(), self::AMBIGUOUS_CLIENT_STATUSES, true);
    }

    /**
     * The remittance mapping, plus the transfer's own amount and currency as
     * an exact major-unit string and an upper-case code.
     */
    protected function massPayoutResult(StripeObject $transfer, RemittanceStatus $default): PayoutResult
    {
        $payload  = $transfer->toArray();
        $result   = $this->resultFrom($payload, $default);
        $currency = is_string($payload['currency'] ?? null) ? strtoupper($payload['currency']) : null;

        if ($currency !== null && is_int($payload['amount'] ?? null)) {
            $result->amount                    = StripeMoney::toMajorExact($payload['amount'], $currency);
            $result->payload['amount_subunit'] = $payload['amount'];
        }

        $result->currency = $currency;

        return $result;
    }
}
