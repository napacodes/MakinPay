<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Drivers\Flutterwave;

/**
 * Defensive reading of Flutterwave v3 bills replies.
 *
 * Every reply is the envelope {status: "success"|"error", message, data}.
 * Nothing here trusts a field to exist or to have the documented type: a
 * missing or oddly typed value reads as null, and the driver treats null as
 * "no clear answer".
 *
 * @link https://developer.flutterwave.com/docs/bill-payment
 */
final class FlutterwavePayload
{
    /** The longest provider message passed on to the customer. */
    public const MESSAGE_MAX = 300;

    /**
     * The envelope's `data` object, or [] when there is none.
     *
     * @param  array<array-key, mixed>|null $json
     * @return array<array-key, mixed>
     */
    public static function data(?array $json): array
    {
        $data = $json['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    /**
     * The envelope's `status`, lower-cased: "success", "error" or null.
     *
     * @param array<array-key, mixed>|null $json
     */
    public static function envelopeStatus(?array $json): ?string
    {
        $status = self::string($json['status'] ?? null);

        return $status === null ? null : strtolower($status);
    }

    /**
     * The envelope's message, cut to MESSAGE_MAX characters, never the body.
     *
     * @param array<array-key, mixed>|null $json
     */
    public static function message(?array $json): ?string
    {
        $message = self::string($json['message'] ?? null);

        return $message === null ? null : mb_substr($message, 0, self::MESSAGE_MAX);
    }

    /**
     * The bill-history row for exactly our reference, compared on our side,
     * whatever the history filter matched on.
     *
     * @param  array<array-key, mixed>|null $json
     * @return array<array-key, mixed>|null
     */
    public static function historyRow(?array $json, string $providerRequestId): ?array
    {
        $rows = $json['data'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (is_array($row) && self::string($row['customer_reference'] ?? null) === $providerRequestId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The prepaid token a bill status names: `token`, then `recharge_token`,
     * then `extra` (where the guide says electricity tokens arrive) only when
     * the biller issues a token at all.
     *
     * @param array<array-key, mixed> $data
     */
    public static function token(array $data, bool $readExtra): ?string
    {
        $keys = $readExtra ? ['token', 'recharge_token', 'extra'] : ['token', 'recharge_token'];

        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) || is_int($value)) {
                $value = trim((string) $value);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** A webhook event id, stable for one reference pair and status. */
    public static function eventId(string $transactionId, string $requestId, string $status): string
    {
        return 'flw:'.sha1($transactionId.'|'.$requestId.'|'.strtolower(trim($status)));
    }

    /** A scalar as a trimmed string, or null when it is missing, blank or not a scalar. */
    public static function string(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /** Letters and digits only, upper-cased, for comparing account numbers. */
    public static function alphanumeric(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }
}
