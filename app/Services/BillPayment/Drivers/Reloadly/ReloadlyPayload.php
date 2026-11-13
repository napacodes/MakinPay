<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Drivers\Reloadly;

/**
 * Reads Reloadly Utility Payments replies defensively.
 *
 * The published schema and the published examples disagree with each other
 * in places, so each reader accepts both:
 *
 *   - a biller's country is `countryCode` (schema) or `countryIsoCode`
 *     (example);
 *   - its local currency is `localTransactionCurencyCode` (schema, sic) or
 *     `localTransactionCurrencyCode` (example);
 *   - international support is `internationalAmountSupported` (schema) or
 *     `internatonalAmountSupported` (example, sic);
 *   - `fx` is an object `{rate, currencyCode}` (schema) or a list
 *     `[{rate}, {curencyCode}]` (example);
 *   - a transaction comes as `{code, message, transaction}`, as a top-level
 *     list wrapping that (`[{...}, [], []]`), as a list under `content`, or
 *     as a bare transaction object;
 *   - `pinDetails` sits under `billDetails` in the examples and at the top
 *     of the transaction in the spec's prose.
 *
 * Pure: no I/O, no logging.
 */
final class ReloadlyPayload
{
    /**
     * Every transaction in a reply, each with the code and message that came
     * with it.
     *
     * @param  array<array-key, mixed>|null                                                    $json
     * @return list<array{code: ?string, message: ?string, transaction: array<string, mixed>}>
     */
    public static function transactions(?array $json): array
    {
        if ($json === null || $json === []) {
            return [];
        }

        if (array_is_list($json)) {
            return self::fromList($json);
        }

        if (isset($json['content']) && is_array($json['content'])) {
            return self::fromList(array_values($json['content']));
        }

        $entry = self::entry($json);

        return $entry === null ? [] : [$entry];
    }

    /**
     * The one transaction whose referenceId is exactly ours, or null.
     *
     * @param  array<array-key, mixed>|null                                                   $json
     * @return array{code: ?string, message: ?string, transaction: array<string, mixed>}|null
     */
    public static function matching(?array $json, string $referenceId): ?array
    {
        if ($referenceId === '') {
            return null;
        }

        foreach (self::transactions($json) as $entry) {
            $reference = $entry['transaction']['referenceId'] ?? null;

            if (is_string($reference) && $reference === $referenceId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The prepaid token of a transaction as a string with every digit, or
     * null. A token sent as a bare number arrives as a string because the
     * reply is decoded with JSON_BIGINT_AS_STRING; a float is not trusted.
     *
     * @param array<string, mixed> $transaction
     */
    public static function token(array $transaction): ?string
    {
        $token = self::pinDetails($transaction)['token'] ?? null;

        if (is_int($token)) {
            return (string) $token;
        }

        if (! is_string($token)) {
            return null;
        }

        $token = trim($token);

        return $token === '' ? null : $token;
    }

    /**
     * @param  array<string, mixed> $transaction
     * @return array<string, mixed>
     */
    public static function pinDetails(array $transaction): array
    {
        $pin = $transaction['billDetails']['pinDetails'] ?? $transaction['pinDetails'] ?? null;

        return is_array($pin) ? $pin : [];
    }

    /**
     * The billers on one page of GET /billers.
     *
     * @param  array<array-key, mixed>|null $json
     * @return list<array<string, mixed>>
     */
    public static function billers(?array $json): array
    {
        if ($json === null) {
            return [];
        }

        $rows = isset($json['content']) && is_array($json['content']) ? $json['content'] : (array_is_list($json) ? $json : []);

        return array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => is_array($row) && isset($row['id']) && ! array_is_list($row),
        ));
    }

    /**
     * @param array<string, mixed> $biller
     */
    public static function billerCountry(array $biller): ?string
    {
        return self::upperString($biller['countryCode'] ?? $biller['countryIsoCode'] ?? null);
    }

    /**
     * @param array<string, mixed> $biller
     */
    public static function billerLocalCurrency(array $biller): ?string
    {
        return self::upperString($biller['localTransactionCurencyCode'] ?? $biller['localTransactionCurrencyCode'] ?? null);
    }

    /**
     * @param array<string, mixed> $biller
     */
    public static function internationalSupported(array $biller): ?bool
    {
        $value = $biller['internationalAmountSupported'] ?? $biller['internatonalAmountSupported'] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * The biller's exchange rate to the account currency, from either shape.
     *
     * @param  array<string, mixed>                      $biller
     * @return array{rate: mixed, currencyCode: ?string}
     */
    public static function fx(array $biller): array
    {
        $fx = $biller['fx'] ?? null;

        if (! is_array($fx)) {
            return ['rate' => null, 'currencyCode' => null];
        }

        if (! array_is_list($fx)) {
            return [
                'rate'         => $fx['rate'] ?? null,
                'currencyCode' => self::upperString($fx['currencyCode'] ?? $fx['curencyCode'] ?? null),
            ];
        }

        $rate     = null;
        $currency = null;

        foreach ($fx as $part) {
            if (! is_array($part)) {
                continue;
            }

            $rate     ??= $part['rate'] ?? null;
            $currency ??= self::upperString($part['currencyCode'] ?? $part['curencyCode'] ?? null);
        }

        return ['rate' => $rate, 'currencyCode' => $currency];
    }

    /**
     * The provider's error code on a refusal, upper-cased, or null.
     *
     * @param array<array-key, mixed>|null $json
     */
    public static function errorCode(?array $json): ?string
    {
        $code = $json['errorCode'] ?? null;

        return is_string($code) && trim($code) !== '' ? strtoupper(trim($code)) : null;
    }

    /**
     * The provider's own message, cut to 300 characters, or null.
     *
     * @param array<array-key, mixed>|null $json
     */
    public static function message(?array $json): ?string
    {
        $message = $json['message'] ?? null;

        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        return mb_substr(trim($message), 0, 300);
    }

    /**
     * @param  list<mixed>                                                                     $rows
     * @return list<array{code: ?string, message: ?string, transaction: array<string, mixed>}>
     */
    private static function fromList(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            if (! is_array($row) || $row === [] || array_is_list($row)) {
                continue;
            }

            $entry = self::entry($row);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param  array<array-key, mixed>                                                        $row
     * @return array{code: ?string, message: ?string, transaction: array<string, mixed>}|null
     */
    private static function entry(array $row): ?array
    {
        if (isset($row['transaction']) && is_array($row['transaction']) && ! array_is_list($row['transaction'])) {
            return [
                'code'        => is_string($row['code'] ?? null) ? $row['code'] : null,
                'message'     => is_string($row['message'] ?? null) ? $row['message'] : null,
                'transaction' => $row['transaction'],
            ];
        }

        if (array_key_exists('id', $row) && array_key_exists('status', $row)) {
            return [
                'code'        => is_string($row['code'] ?? null) ? $row['code'] : null,
                'message'     => is_string($row['message'] ?? null) ? $row['message'] : null,
                'transaction' => $row,
            ];
        }

        return null;
    }

    private static function upperString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : null;
    }
}
