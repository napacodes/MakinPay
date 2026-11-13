<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Transaction;
use App\Notifications\BudgetAlertNotification;
use App\Notifications\MoneyRequestReminderNotification;
use App\Notifications\P2PMarketAlertNotification;
use App\Notifications\RateAlertTriggeredNotification;
use App\Notifications\ScheduledPaymentNotification;
use App\Notifications\TransactionStatusChangedNotification;
use App\Notifications\VaultNotification;

/**
 * Which of the app's inbox chips a notification sits under, and the inline
 * actions it offers. New rows carry their category in `data`; older ones
 * are placed by the template identifier that wrote them, or by the class
 * for the notifications that have no template.
 */
final class NotificationCategory
{
    public const SECURITY = 'security';

    public const MONEY_IN = 'money_in';

    public const MONEY_OUT = 'money_out';

    public const REQUESTS = 'requests';

    public const RATES = 'rates';

    public const REWARDS = 'rewards';

    public const UPDATES = 'updates';

    public const ALL = [self::SECURITY, self::MONEY_IN, self::MONEY_OUT, self::REQUESTS, self::RATES, self::REWARDS, self::UPDATES];

    /**
     * Template identifiers per category. Anything not listed is an update.
     *
     * @var array<string, list<string>>
     */
    private const IDENTIFIERS = [
        self::SECURITY  => ['security_user_notify_sign_in_blocked'],
        self::MONEY_IN  => ['deposit_user_auto_success', 'deposit_user_submitted', 'deposit_user_approved', 'deposit_user_rejected', 'send_money_user_received', 'payment_user_received', 'admin_balance_added', 'remittance_user_completed', 'agent_qr_cash_out_cash_paid', 'wallet_earn_user_reward_paid', 'wallet_earn_user_stake_completed'],
        self::MONEY_OUT => ['send_money_user_sent', 'payment_user_made', 'payment_receipt_customer', 'withdraw_user_requested', 'withdraw_user_approved', 'withdraw_user_rejected', 'exchange_money_user_exchanged', 'admin_balance_subtracted', 'subscription_user_renewed', 'subscription_user_trial_converted', 'subscription_user_started', 'remittance_user_submitted', 'remittance_user_failed', 'mobile_recharge_user_completed', 'mobile_recharge_user_failed', 'bill_payment_user_submitted', 'bill_payment_user_completed', 'bill_payment_user_failed', 'bill_payment_user_reminder', 'wallet_earn_user_stake_created', 'scheduled_payment_failed', 'scheduled_payment_reminder', 'scheduled_payment_paid', 'vault_autosave_skipped'],
        self::REQUESTS  => ['request_money_user_requested', 'request_money_user_received', 'request_money_user_approved', 'request_money_user_rejected', 'request_money_reminder', 'agent_qr_cash_out_requested', 'agent_assisted_cash_out_otp', 'agent_qr_cash_out_customer_confirmed'],
        self::REWARDS   => ['reward_user_referral', 'reward_user_system', 'signup_bonus_credited', 'gift_card_redeemed', 'voucher_user_redeemed', 'vault_goal_reached'],
        self::RATES     => ['rate_alert', 'rate_alert_triggered', 'budget_warning', 'budget_exceeded'],
        self::UPDATES   => ['p2p_market_alert'],
    ];

    /** @var array<string, list<class-string>> */
    private const CLASSES = [
        self::RATES     => [RateAlertTriggeredNotification::class, BudgetAlertNotification::class],
        self::REQUESTS  => [MoneyRequestReminderNotification::class],
        self::MONEY_OUT => [ScheduledPaymentNotification::class, VaultNotification::class],
        self::UPDATES   => [TransactionStatusChangedNotification::class, P2PMarketAlertNotification::class],
    ];

    public static function isValid(?string $category): bool
    {
        return $category !== null && in_array($category, self::ALL, true);
    }

    public static function forIdentifier(string $identifier): string
    {
        foreach (self::IDENTIFIERS as $category => $identifiers) {
            if (in_array($identifier, $identifiers, true)) {
                return $category;
            }
        }

        return str_starts_with($identifier, 'p2p_') ? self::REQUESTS : self::UPDATES;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function forRow(string $type, array $data): string
    {
        if (self::isValid($data['category'] ?? null)) {
            return (string) $data['category'];
        }

        if (is_string($data['identifier'] ?? null) && $data['identifier'] !== '') {
            return self::forIdentifier($data['identifier']);
        }

        foreach (self::CLASSES as $category => $classes) {
            if (in_array($type, $classes, true)) {
                return $category;
            }
        }

        return self::guess((string) ($data['title'] ?? ''));
    }

    /**
     * Rows written before categories existed carry only a title; the words
     * in it are the best there is.
     */
    private static function guess(string $title): string
    {
        $t = strtolower($title);

        return match (true) {
            (bool) preg_match('/sign.?in|security|password|device|blocked/', $t)     => self::SECURITY,
            (bool) preg_match('/request/', $t)                                       => self::REQUESTS,
            (bool) preg_match('/reward|bonus|referral|cashback|gift/', $t)           => self::REWARDS,
            (bool) preg_match('/received|deposit|credited|added/', $t)               => self::MONEY_IN,
            (bool) preg_match('/sent|withdraw|payment|recharge|exchange|debit/', $t) => self::MONEY_OUT,
            default                                                                  => self::UPDATES,
        };
    }

    /**
     * @return list<string>
     */
    public static function identifiersFor(string $category): array
    {
        return self::IDENTIFIERS[$category] ?? [];
    }

    /**
     * @return list<class-string>
     */
    public static function classesFor(string $category): array
    {
        return self::CLASSES[$category] ?? [];
    }

    /**
     * The buttons an inbox row offers, each naming the thing it acts on.
     * Paying, declining and "that was me" go through the endpoints that
     * already exist; the app calls them and then marks the row read.
     *
     * @param  array<string, mixed>                                                                      $data
     * @return list<array{key: string, label: string, style: string, reference?: string, link?: string}>
     */
    public static function actionsFor(string $type, array $data, ?int $userId = null): array
    {
        $identifier = (string) ($data['identifier'] ?? '');
        $reference  = (string) ($data['reference'] ?? $data['trx'] ?? '');

        if ($reference !== '' && $userId !== null && ($identifier === 'request_money_user_received' || $identifier === 'request_money_reminder' || $type === MoneyRequestReminderNotification::class)) {
            $mine = self::payerLeg($userId, $reference);

            return $mine === null ? [] : [
                ['key' => 'pay_request', 'label' => __('Pay'), 'style' => 'primary', 'reference' => $mine],
                ['key' => 'decline_request', 'label' => __('Decline'), 'style' => 'secondary', 'reference' => $mine],
            ];
        }

        if (self::forRow($type, $data) === self::SECURITY) {
            return [
                ['key' => 'that_was_me', 'label' => __('That was me'), 'style' => 'secondary'],
                ['key' => 'secure_account', 'label' => __('Secure account'), 'style' => 'danger', 'link' => '/more/password'],
            ];
        }

        if (($identifier === 'budget_warning' || $identifier === 'budget_exceeded') && ! empty($data['budget_id'])) {
            return [
                ['key' => 'raise_budget', 'label' => __('Raise to :amount', ['amount' => (string) ($data['suggested_amount'] ?? '')]), 'style' => 'primary', 'reference' => (string) $data['budget_id'], 'amount' => $data['suggested_amount'] ?? null],
                ['key' => 'got_it', 'label' => __('Got it'), 'style' => 'secondary'],
            ];
        }

        if ($identifier === 'scheduled_payment_failed' && ! empty($data['schedule_id'])) {
            return [
                ['key' => 'pay_now', 'label' => __('Pay now'), 'style' => 'primary', 'reference' => (string) $data['schedule_id'], 'link' => '/home/scheduled/'.$data['schedule_id']],
                ['key' => 'top_up', 'label' => __('Top up'), 'style' => 'secondary', 'reference' => (string) $data['schedule_id'], 'link' => '/home/exchange'],
            ];
        }

        return [];
    }

    /**
     * The customer's own pending leg of a request the notification names -
     * by its id when the notification carried that, or by the requester's
     * id it points back at. Null once it is paid or declined, so a stale
     * row offers nothing.
     */
    private static function payerLeg(int $userId, string $reference): ?string
    {
        return Transaction::query()
            ->where('user_id', $userId)
            ->where('trx_type', TrxType::REQUEST_MONEY)
            ->where('amount_flow', AmountFlow::MINUS)
            ->where('status', TrxStatus::PENDING)
            ->where(fn ($q) => $q->where('trx_id', $reference)->orWhere('trx_reference', $reference))
            ->value('trx_id');
    }
}
