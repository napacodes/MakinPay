<?php

namespace App\Support;

/**
 * Validates crypto wallet addresses per network and maps user-facing
 * network codes to Bitnob chain identifiers.
 */
class CryptoAddressValidator
{
    /** @var array<string, string> */
    private const ADDRESS_PATTERNS = [
        'trc20' => '/\AT[1-9A-HJ-NP-Za-km-z]{33}\z/',
        'bep20' => '/\A0x[a-fA-F0-9]{40}\z/',
        'erc20' => '/\A0x[a-fA-F0-9]{40}\z/',
    ];

    /** @var array<string, string> */
    private const CHAINS = [
        'trc20' => 'tron',
        'bep20' => 'bsc',
        'erc20' => 'eth',
    ];

    /** @var array<string, string> */
    private const LABELS = [
        'trc20' => 'TRON (TRC20)',
        'bep20' => 'BNB Smart Chain (BEP20)',
        'erc20' => 'Ethereum (ERC20)',
    ];

    public static function supports(?string $network): bool
    {
        return $network !== null && array_key_exists(strtolower($network), self::ADDRESS_PATTERNS);
    }

    public static function isValid(string $network, string $address): bool
    {
        $pattern = self::ADDRESS_PATTERNS[strtolower($network)] ?? null;

        return $pattern !== null && preg_match($pattern, trim($address)) === 1;
    }

    public static function chainFor(string $network): ?string
    {
        return self::CHAINS[strtolower($network)] ?? null;
    }

    public static function labelFor(string $network): string
    {
        return self::LABELS[strtolower($network)] ?? strtoupper($network);
    }

    /** @var array<string, string> */
    private const EXPLORERS = [
        'trc20' => 'https://tronscan.org/#',
        'bep20' => 'https://bscscan.com',
        'erc20' => 'https://etherscan.io',
    ];

    public static function explorerAddressUrl(string $network, string $address): ?string
    {
        $base = self::EXPLORERS[strtolower($network)] ?? null;

        return $base !== null ? "{$base}/address/".rawurlencode(trim($address)) : null;
    }

    public static function explorerTxUrl(string $network, string $hash): ?string
    {
        $base = self::EXPLORERS[strtolower($network)] ?? null;

        if ($base === null) {
            return null;
        }

        $path = strtolower($network) === 'trc20' ? 'transaction' : 'tx';

        return "{$base}/{$path}/".rawurlencode(trim($hash));
    }

    /**
     * Resolve a network code from free-form input such as a select value or
     * a method code like `usdt-trc20-manual`.
     */
    public static function detectNetwork(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower($value);

        foreach (array_keys(self::ADDRESS_PATTERNS) as $network) {
            if (str_contains($value, $network)) {
                return $network;
            }
        }

        return null;
    }
}
