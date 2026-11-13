<?php

namespace App\Services;

use InvalidArgumentException;

class BackgroundTaskRegistry
{
    /** @var array<string, array<string, mixed>> */
    private static array $commands = [
        'wallet_earn_process' => [
            'key'         => 'wallet_earn_process',
            'label'       => 'Wallet Earn Reward Processing',
            'signature'   => 'wallet-earn:process',
            'description' => 'Process due Wallet Earn reward payouts and matured principal returns.',
            'options'     => [
                'limit' => [
                    'type'    => 'integer',
                    'default' => 100,
                    'min'     => 1,
                    'max'     => 1000,
                    'label'   => 'Limit',
                    'help'    => 'Maximum number of stakes to process in one run (1-1000).',
                ],
            ],
        ],
        'subscription_process' => [
            'key'         => 'subscription_process',
            'label'       => 'Subscription Lifecycle Processing',
            'signature'   => 'subscription:process',
            'description' => 'Expire overdue subscriptions, convert completed trials, and optionally process auto-renewals.',
            'options'     => [
                'renewals' => [
                    'type'    => 'boolean',
                    'default' => true,
                    'label'   => 'Process auto-renewals',
                    'help'    => 'Attempt due wallet auto-renewals before expiry and grace checks.',
                ],
            ],
        ],
        'merchant_subscription_process' => [
            'key'         => 'merchant_subscription_process',
            'label'       => 'Merchant Subscription Invoice Processing',
            'signature'   => 'merchant-subscriptions:process',
            'description' => 'Create hosted checkout invoices for due external Merchant API subscriptions.',
            'options'     => [
                'limit' => [
                    'type'    => 'integer',
                    'default' => 100,
                    'min'     => 1,
                    'max'     => 1000,
                    'label'   => 'Limit',
                    'help'    => 'Maximum number of due merchant subscriptions to process (1-1000).',
                ],
            ],
        ],
        'p2p_promotions_expire' => [
            'key'         => 'p2p_promotions_expire',
            'label'       => 'P2P Offer Promotions Expiry',
            'signature'   => 'p2p:promotions:expire',
            'description' => 'Expire active P2P offer promotions that passed their end time.',
            'options'     => [],
        ],
        'p2p_orders_expire' => [
            'key'         => 'p2p_orders_expire',
            'label'       => 'P2P Order Expiry',
            'signature'   => 'p2p:orders:expire',
            'description' => 'Expire pending P2P orders that passed expiry time and refund escrow if applicable.',
            'options'     => [],
        ],
        'remittance_reconcile' => [
            'key'         => 'remittance_reconcile',
            'label'       => 'Remittance Provider Reconciliation',
            'signature'   => 'remittance:reconcile',
            'description' => 'Safely poll provider status for stale in-flight remittances without resubmitting payouts.',
            'options'     => [
                'limit' => [
                    'type'    => 'integer',
                    'default' => 100,
                    'min'     => 1,
                    'max'     => 1000,
                    'label'   => 'Limit',
                    'help'    => 'Maximum number of in-flight transfers to enqueue (1-1000).',
                ],
            ],
        ],
        'bill_payment_reconcile' => [
            'key'         => 'bill_payment_reconcile',
            'label'       => 'Bill Payment Provider Reconciliation',
            'signature'   => 'bill-payment:reconcile',
            'description' => 'Refund never-sent bill payments, recover stuck dispatches and poll providers for pending payments. Never resubmits a payment.',
            'options'     => [
                'limit' => [
                    'type'    => 'integer',
                    'default' => 50,
                    'min'     => 1,
                    'max'     => 1000,
                    'label'   => 'Limit',
                    'help'    => 'Maximum number of payments each step handles in one run (1-1000).',
                ],
            ],
        ],
        'bill_payment_auto_sync' => [
            'key'         => 'bill_payment_auto_sync',
            'label'       => 'Bill Payment Biller Catalogue Sync',
            'signature'   => 'bill-payment:auto-sync-billers',
            'description' => 'Queue the daily biller catalogue sync for every provider that has it switched on. New billers arrive switched off; nothing is deleted.',
            'options'     => [],
        ],
        'bill_payment_remind_saved' => [
            'key'         => 'bill_payment_remind_saved',
            'label'       => 'Saved Bill Reminders',
            'signature'   => 'bill-payment:remind-saved',
            'description' => 'Remind customers to pay the saved bills whose reminder day is today, once a month each. Pays nothing by itself.',
            'options'     => [],
        ],
        'remittance_alert_stuck' => [
            'key'         => 'remittance_alert_stuck',
            'label'       => 'Remittance Stuck-Payout Alerts',
            'signature'   => 'remittance:alert-stuck',
            'description' => 'Send deduplicated database alerts to remittance admins for unresolved payouts.',
            'options'     => [
                'limit' => [
                    'type'    => 'integer',
                    'default' => 500,
                    'min'     => 1,
                    'max'     => 1000,
                    'label'   => 'Limit',
                    'help'    => 'Maximum number of unresolved transfers to include (1-1000).',
                ],
            ],
        ],
        'dashboard_alert_sla' => [
            'key'         => 'dashboard_alert_sla',
            'label'       => 'Review Queue SLA Alerts',
            'signature'   => 'dashboard:alert-sla',
            'description' => 'Notify administrators when a review queue passes its SLA target. Does nothing until SLA alerts are switched on in Dashboard settings.',
            'options'     => [
                'repeat' => [
                    'type'    => 'integer',
                    'default' => 360,
                    'min'     => 1,
                    'max'     => 10080,
                    'label'   => 'Repeat After (Minutes)',
                    'help'    => 'How long before the same queue may alert again (1-10080).',
                ],
            ],
        ],
        'currency_refresh_rates' => [
            'key'         => 'currency_refresh_rates',
            'label'       => 'Exchange Rate Refresh',
            'signature'   => 'currency:refresh-rates',
            'description' => 'Pull the latest rate for every currency and payment method marked as live. A currency the provider cannot price keeps its configured rate.',
            'options'     => [
                'dry-run' => [
                    'type'    => 'boolean',
                    'default' => false,
                    'label'   => 'Dry run',
                    'help'    => 'Report what would change without writing anything.',
                ],
            ],
        ],
        'money_audit_precision' => [
            'key'         => 'money_audit_precision',
            'label'       => 'Ledger Precision Audit',
            'signature'   => 'money:audit-precision',
            'description' => 'Read-only check for truncated amounts, wallets drifted from their entries, unusable exchange rates and method rates that no longer match their currency. Never modifies anything.',
            'options'     => [
                'notify' => [
                    'type'    => 'boolean',
                    'default' => false,
                    'label'   => 'Notify administrators',
                    'help'    => 'Send a database alert to currency administrators when findings appear. The nightly run uses this; a manual run normally does not need to.',
                ],
                'csv' => [
                    'type'    => 'boolean',
                    'default' => false,
                    'label'   => 'Write CSV',
                    'help'    => 'Also write the findings to storage/app/money-audit.',
                ],
            ],
        ],
    ];

    /** @var array<string, true> Tasks an add-on contributed, as opposed to core's own. */
    private static array $contributed = [];

    /**
     * Lets an add-on's provider list one of its scheduled commands on the
     * Background tasks panel. A core task can never be replaced, nor its
     * command claimed; an add-on's task keeps the definition it was first
     * given, so registering it again with the same one is harmless.
     *
     * @param array{label: string, signature: string, description: string, options?: array<string, mixed>} $task
     *
     * @throws InvalidArgumentException
     */
    public static function register(string $key, array $task): void
    {
        $signature = $task['signature'] ?? null;

        if (preg_match('/^[a-z0-9_]{3,64}$/', $key) !== 1 || ! is_string($signature) || preg_match('/^[a-z0-9][a-z0-9:_-]*$/', $signature) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid background task %s.', $key));
        }

        $definition = [
            'key'         => $key,
            'label'       => (string) ($task['label'] ?? $key),
            'signature'   => $signature,
            'description' => (string) ($task['description'] ?? ''),
            'options'     => (array) ($task['options'] ?? []),
        ];

        if (isset(self::$commands[$key])) {
            if (! isset(self::$contributed[$key])) {
                throw new InvalidArgumentException(sprintf('Background task %s belongs to core.', $key));
            }

            if (self::$commands[$key] !== $definition) {
                throw new InvalidArgumentException(sprintf('Background task %s is already registered.', $key));
            }

            return;
        }

        foreach (self::$commands as $existing) {
            if ($existing['signature'] === $signature) {
                throw new InvalidArgumentException(sprintf('Command %s is already a background task.', $signature));
            }
        }

        self::$commands[$key]    = $definition;
        self::$contributed[$key] = true;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return self::$commands;
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        return self::$commands[$key] ?? null;
    }

    public function exists(string $key): bool
    {
        return isset(self::$commands[$key]);
    }

    public function count(): int
    {
        return count(self::$commands);
    }

    /**
     * The task a scheduled event belongs to, or null for one this panel does
     * not manage.
     *
     * The scheduler hands out its command as the whole shell string Laravel
     * built - `"/usr/bin/php" "artisan" p2p:orders:expire --limit=100` - so the
     * signature is matched on token boundaries rather than as a substring.
     * Without them `remittance:reconcile` would claim a run of
     * `remittance:reconcile-anything`, and the panel would report the wrong
     * task as healthy.
     */
    public function keyForCommand(?string $command): ?string
    {
        if ($command === null || $command === '') {
            return null;
        }

        foreach (self::$commands as $key => $task) {
            $pattern = '/(?:^|\s)'.preg_quote($task['signature'], '/').'(?=\s|$)/';

            if (preg_match($pattern, $command) === 1) {
                return $key;
            }
        }

        return null;
    }
}
