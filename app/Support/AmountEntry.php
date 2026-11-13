<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Currency;
use App\Models\Wallet;
use App\Services\CurrencyService;
use Throwable;

/**
 * Validation rules for an amount the customer types in a wallet's own currency.
 *
 * Every money form used to validate `min:0.01`, which is the smallest sensible
 * fiat amount and nothing else. Against BTC it rejects everything below roughly
 * six hundred dollars; against JPY, which has no minor unit at all, it invites
 * a fraction the ledger will round away.
 *
 * The floor is one unit at the currency's own precision — 0.01 for USD,
 * 1 for JPY, 0.00000001 for BTC — which is the smallest amount that can be
 * represented, and therefore the smallest that can be honestly accepted.
 */
class AmountEntry
{
    /**
     * Validation rules for an amount denominated in `$currencyCode`.
     *
     * @return list<string>
     */
    public static function rules(?string $currencyCode, bool $required = true): array
    {
        return array_values(array_filter([
            $required ? 'required' : 'nullable',
            'numeric',
            'min:'.self::smallestUnit($currencyCode),
            // decimal:0,N rejects a figure finer than the currency can hold
            // rather than silently rounding it into the ledger.
            'decimal:0,'.Money::decimals($currencyCode),
        ]));
    }

    /**
     * The smallest representable amount in a currency, as a plain decimal
     * string bcmath and the validator can both read.
     */
    public static function smallestUnit(?string $currencyCode): string
    {
        $decimals = Money::decimals($currencyCode);

        if ($decimals <= 0) {
            return '1';
        }

        return '0.'.str_repeat('0', $decimals - 1).'1';
    }

    /**
     * The currency code of a wallet the request refers to by uuid, or null when
     * it cannot be resolved — in which case the caller falls back to the base
     * currency and the server-side conversion still refuses an unusable rate.
     */
    public static function currencyForWalletUuid(?string $uuid, ?int $userId): ?string
    {
        if ($uuid === null || $uuid === '' || $userId === null) {
            return null;
        }

        try {
            return Wallet::query()
                ->where('uuid', $uuid)
                ->where('user_id', $userId)
                ->with('currency:id,code')
                ->first()?->currency?->code;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Same, for the forms that pass a wallet's primary key rather than its uuid.
     */
    public static function currencyForWalletId(int|string|null $walletId, ?int $userId): ?string
    {
        if ($walletId === null || $walletId === '' || $userId === null) {
            return null;
        }

        try {
            return Wallet::query()
                ->whereKey($walletId)
                ->where('user_id', $userId)
                ->with('currency:id,code')
                ->first()?->currency?->code;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A currency the platform actually knows, for labelling an input.
     */
    public static function resolve(?string $currencyCode): ?Currency
    {
        if ($currencyCode === null || trim($currencyCode) === '') {
            return null;
        }

        try {
            return app(CurrencyService::class)->getCurrencyByCode(strtoupper(trim($currencyCode)));
        } catch (Throwable) {
            return null;
        }
    }
}
