<?php

declare(strict_types=1);

namespace App\Support;

use App\Constants\CurrencyType;

/**
 * ISO 4217 minor units, plus sane defaults for crypto assets.
 *
 * A single site-wide decimal setting cannot serve JPY (0), USD (2), KWD (3)
 * and BTC (8) at once — that is an ISO 4217 conformance problem, not just a
 * crypto one. `resources/json/currencies.json` carries only code/name/symbol,
 * so the table lives here.
 */
class CurrencyPrecision
{
    /** Ledger columns are decimal(24,8), so nothing may exceed 8. */
    public const MAX_SCALE = 8;

    public const DEFAULT_FIAT_SCALE = 2;

    public const DEFAULT_CRYPTO_SCALE = 8;

    /**
     * Currencies whose ISO 4217 minor unit is not 2.
     *
     * @var array<string, int>
     */
    private const ISO_MINOR_UNITS = [
        // Zero-decimal currencies — a "cent" does not exist.
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0,
        'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0,
        'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0,
        'XOF' => 0, 'XPF' => 0,

        // Three-decimal currencies — mostly Gulf and North African dinars.
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3,
        'OMR' => 3, 'TND' => 3,

        // Four-decimal units of account.
        'CLF' => 4, 'UYW' => 4,
    ];

    /**
     * Crypto assets whose conventional display precision is below 8.
     *
     * ETH is 18 decimals on chain (wei) but is capped at MAX_SCALE because the
     * ledger cannot store more.
     *
     * @var array<string, int>
     */
    private const CRYPTO_SCALES = [
        'USDT' => 6, 'USDC' => 6, 'BUSD' => 2, 'DAI' => 6, 'TUSD' => 2,
        'XRP'  => 6, 'TRX' => 6, 'ADA' => 6, 'XLM' => 7, 'SOL' => 6,
    ];

    /**
     * The ISO 4217 minor unit for a fiat code, or null when the code is
     * unknown to the table (which means the ordinary default of 2 applies).
     */
    public static function iso(?string $code): ?int
    {
        $code = strtoupper(trim((string) $code));

        return self::ISO_MINOR_UNITS[$code] ?? null;
    }

    /**
     * The precision a currency should be created with, before any operator
     * override.
     */
    public static function for(?string $code, string $type = CurrencyType::FIAT): int
    {
        $code = strtoupper(trim((string) $code));

        if ($type === CurrencyType::CRYPTO) {
            return min(self::CRYPTO_SCALES[$code] ?? self::DEFAULT_CRYPTO_SCALE, self::MAX_SCALE);
        }

        return min(self::iso($code) ?? self::DEFAULT_FIAT_SCALE, self::MAX_SCALE);
    }

    /**
     * Clamp an arbitrary operator-supplied scale into what the ledger can hold.
     */
    public static function clamp(int|float|string|null $scale, int $fallback = self::DEFAULT_FIAT_SCALE): int
    {
        if ($scale === null || $scale === '') {
            return $fallback;
        }

        return max(0, min((int) $scale, self::MAX_SCALE));
    }
}
