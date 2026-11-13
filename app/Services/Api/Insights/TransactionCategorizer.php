<?php

declare(strict_types=1);

namespace App\Services\Api\Insights;

use App\Enums\AmountFlow;
use App\Enums\TrxType;
use App\Models\MerchantCategory;
use App\Models\Transaction;

/**
 * Files a customer's spending into eight categories from the row's type
 * and the merchant's name, and remembers what the customer said when they
 * corrected it for a merchant. Money in and the internal moves - an
 * exchange between two of their own wallets, a stake - are not spending
 * and get no category.
 */
final class TransactionCategorizer
{
    public const TRANSFERS = 'transfers';

    public const SHOPPING = 'shopping';

    public const BILLS = 'bills';

    public const FOOD = 'food';

    public const TRANSPORT = 'transport';

    public const ENTERTAINMENT = 'entertainment';

    public const CASH = 'cash';

    public const OTHER = 'other';

    public const ALL = [self::TRANSFERS, self::SHOPPING, self::BILLS, self::FOOD, self::TRANSPORT, self::ENTERTAINMENT, self::CASH, self::OTHER];

    private const NOT_SPENDING = [TrxType::EXCHANGE_MONEY, TrxType::P2P_ESCROW, TrxType::P2P_RELEASE, TrxType::P2P_REFUND, TrxType::WALLET_EARN_STAKE, TrxType::CARD_TOPUP, TrxType::ADD_BALANCE, TrxType::VAULT_DEPOSIT, TrxType::VAULT_WITHDRAW];

    /** @var array<string, list<string>> */
    private const WORDS = [
        self::FOOD          => ['cafe', 'café', 'coffee', 'restaurant', 'pizza', 'burger', 'kitchen', 'grill', 'food', 'bakery', 'diner', 'bistro', 'sushi', 'eat', 'kebab', 'biryani'],
        self::TRANSPORT     => ['uber', 'taxi', 'fuel', 'petrol', 'gas station', 'ride', 'bus', 'rail', 'train', 'airline', 'airways', 'flight', 'parking', 'lyft', 'bolt', 'pathao', 'metro'],
        self::ENTERTAINMENT => ['netflix', 'spotify', 'cinema', 'movie', 'game', 'steam', 'playstation', 'xbox', 'music', 'ticket', 'concert', 'theatre', 'youtube', 'disney'],
        self::BILLS         => ['electric', 'utility', 'internet', 'broadband', 'water', 'phone', 'insurance', 'rent', 'bill', 'telecom', 'power', 'energy', 'wifi'],
    ];

    /** @var array<int, self> */
    private static array $forUser = [];

    /**
     * @param array<string, string> $overrides merchant key => category, as the customer taught it
     */
    public function __construct(private readonly array $overrides = []) {}

    public static function forUser(int $userId): self
    {
        return self::$forUser[$userId] ??= new self(MerchantCategory::query()->where('user_id', $userId)->pluck('category', 'merchant_key')->all());
    }

    public static function forget(int $userId): void
    {
        unset(self::$forUser[$userId]);
    }

    public static function isValid(?string $category): bool
    {
        return $category !== null && in_array($category, self::ALL, true);
    }

    /**
     * The row's category, or null when it is not spending.
     */
    public function categoryOf(Transaction $trx): ?string
    {
        if ($trx->amount_flow !== AmountFlow::MINUS || in_array($trx->trx_type, self::NOT_SPENDING, true)) {
            return null;
        }

        $own = data_get($trx->trx_data, 'category');
        if (is_string($own) && self::isValid($own)) {
            return $own;
        }

        $key = self::merchantKey($trx);
        if ($key !== null && isset($this->overrides[$key])) {
            return $this->overrides[$key];
        }

        return self::guess($trx, $key);
    }

    /**
     * The merchant behind a payment as the customer would name it, or null
     * when the row has no merchant (a transfer, a withdrawal).
     */
    public static function merchantName(Transaction $trx): ?string
    {
        $description = (string) $trx->description;

        return match ($trx->trx_type) {
            TrxType::PAYMENT         => preg_match('/Payment to (.+?) via/i', $description, $m) === 1 ? trim($m[1]) : null,
            TrxType::MOBILE_RECHARGE => (string) (data_get($trx->trx_data, 'operator') ?: __('Mobile recharge')),
            TrxType::BILL_PAYMENT    => (string) (data_get($trx->trx_data, 'biller_name') ?: __('Bill payment')),
            TrxType::SUBSCRIPTION, TrxType::SUBSCRIPTION_RENEWAL => (string) (data_get($trx->trx_data, 'plan_name') ?: __('Subscription')),
            TrxType::GIFT_CARD => __('Gift card'),
            TrxType::VOUCHER   => __('Voucher'),
            default            => null,
        };
    }

    /**
     * The name folded for matching: lower-case, single-spaced.
     */
    public static function merchantKey(Transaction $trx): ?string
    {
        $name = self::merchantName($trx);

        return $name === null ? null : mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }

    private static function guess(Transaction $trx, ?string $key): string
    {
        return match ($trx->trx_type) {
            TrxType::SEND_MONEY, TrxType::REQUEST_MONEY, TrxType::REMITTANCE_SEND, TrxType::MASS_PAYMENT => self::TRANSFERS,
            TrxType::WITHDRAW, TrxType::AGENT_CASH_OUT => self::CASH,
            TrxType::MOBILE_RECHARGE, TrxType::BILL_PAYMENT, TrxType::SUBSCRIPTION, TrxType::SUBSCRIPTION_RENEWAL => self::BILLS,
            TrxType::GIFT_CARD, TrxType::VOUCHER => self::SHOPPING,
            TrxType::PAYMENT => self::byWords($key ?? ''),
            default          => self::OTHER,
        };
    }

    private static function byWords(string $key): string
    {
        foreach (self::WORDS as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($key, $word)) {
                    return $category;
                }
            }
        }

        return self::SHOPPING;
    }
}
