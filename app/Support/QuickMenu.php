<?php

namespace App\Support;

use App\Models\User;
use App\Services\FeatureManager;

/**
 * The catalog of user-dashboard quick-menu shortcuts, and the personal
 * selection each user layers on top of it.
 *
 * The catalog is the only source of truth for which shortcuts exist. A saved
 * selection is never trusted on its own: it is always intersected with the
 * catalog the current user is entitled to, so disabling a feature - or
 * revoking a role - removes the shortcut even for a user who had pinned it.
 */
final class QuickMenu
{
    /**
     * Upper bound on a stored selection.
     *
     * Not a product limit - the catalog is smaller than this - only a guard so
     * a forged request cannot persist an unbounded array against a user row.
     */
    public const MAX_ITEMS = 40;

    /**
     * Every shortcut the panel knows about, in its natural declaration order.
     *
     * Tuple shape: [title, icon, route name, feature key, ability]. The route
     * name doubles as the stable key a selection is saved against; it outlives
     * a re-worded title or a re-themed icon, and it is unique where the icon
     * is not (History and Trades share one, Voucher and Gift Cards another).
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: string|null, 4: string|null}>
     */
    private static function definitions(): array
    {
        return [
            ['Merchant', 'merchant', 'user.merchant.index', null, 'merchant'],
            ['Agent Services', 'sidebar-agent', 'user.agent.index', null, 'agent'],
            ['Wallet', 'wallet', 'user.wallet.index', null, null],
            ['Send Money', 'send-money', 'user.send-money.create', 'send_money', null],
            ['Request Money', 'request-money', 'user.request-money.create', 'request_money', null],
            ['Deposit', 'deposit', 'user.deposit.create', 'deposit_money', null],
            ['Withdraw', 'withdraw', 'user.withdraw.create', 'withdraw_money', null],
            ['History', 'history', 'user.transaction.index', null, null],
            ['Payment Links', 'linked', 'user.payment-links.index', 'payment_link', null],
            ['Mobile Recharge', 'mobile-recharge', 'user.mobile-recharge.create', 'mobile_recharge', null],
            ['Pay Bills', 'receipt', 'user.bill-payment.index', 'bill_payment', null],
            ['Mass Payments', 'user-group', 'user.mass-payment.index', 'mass_payment', null],
            ['Exchange', 'exchange', 'user.exchange-money.create', 'exchange_money', null],
            ['Remittance', 'currency-exchange', 'user.remittance.create', 'remittance', null],
            ['Recipients', 'sidebar-users', 'user.beneficiaries.index', 'remittance', null],
            ['Cards', 'card', 'user.virtual-card.index', 'virtual_card', null],
            ['P2P Market', 'p2p_trading', 'user.p2p.offers.index', 'p2p_marketplace', null],
            ['My Ads', 'list-2', 'user.p2p.offers.my', 'p2p_marketplace', null],
            ['Create Ad', 'add', 'user.p2p.offers.create', 'p2p_marketplace', null],
            ['Trades', 'history', 'user.p2p.orders.index', 'p2p_marketplace', null],
            ['Voucher', 'voucher', 'user.voucher.my', 'vouchers', null],
            ['Gift Cards', 'voucher', 'user.gift-card.index', 'gift_cards', null],
            ['Referral', 'referrals', 'user.referral.index', 'referral_program', null],
            ['Subscriptions', 'layer', 'user.subscription.plans', 'subscription_system', null],
            ['Wallet Earn', 'trending-up', 'user.wallet-earn.plans', 'wallet_earn', null],
            ['Support', 'support', 'user.support-ticket.index', null, null],
        ];
    }

    /**
     * Every shortcut this user is entitled to see.
     *
     * @return array<int, array{key: string, title: string, icon: string, link: string}>
     */
    public static function catalogFor(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $manager    = app(FeatureManager::class);
        $p2pEnabled = (bool) setting('p2p_enabled', false);
        $catalog    = [];

        foreach (self::definitions() as [$title, $icon, $routeName, $feature, $ability]) {
            if ($ability !== null && ! $user->can($ability)) {
                continue;
            }

            if ($feature === 'p2p_marketplace' && ! $p2pEnabled) {
                continue;
            }

            if ($feature !== null && ! $manager->isVisible($feature)) {
                continue;
            }

            $catalog[] = [
                'key'   => $routeName,
                'title' => __($title),
                'icon'  => $icon,
                'link'  => route($routeName),
            ];
        }

        return $catalog;
    }

    /**
     * The keys this user is allowed to pin.
     *
     * @return array<int, string>
     */
    public static function allowedKeysFor(?User $user): array
    {
        return array_column(self::catalogFor($user), 'key');
    }

    /**
     * The user's stored selection, or null when they have never edited it.
     *
     * @return array<int, string>|null
     */
    public static function savedKeysFor(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $items = $user->quickMenuPreference?->items;

        if (! is_array($items)) {
            return null;
        }

        $keys = array_values(array_filter($items, 'is_string'));

        return $keys === [] ? null : $keys;
    }

    /**
     * Order a catalog by a saved selection and flag which entries are pinned.
     *
     * Pinned entries come first, in the order the user arranged them; the rest
     * follow in catalog order so the editor can offer them without a second
     * query. With no usable selection every entry is pinned, which is exactly
     * how the panel behaved before it became editable.
     *
     * @param  array<int, array<string, mixed>> $catalog
     * @param  array<int, string>|null          $saved
     * @return array<int, array<string, mixed>>
     */
    public static function arrange(array $catalog, ?array $saved): array
    {
        if ($saved === null || $saved === []) {
            return self::allSelected($catalog);
        }

        $byKey = [];

        foreach ($catalog as $item) {
            if (isset($item['key']) && is_string($item['key'])) {
                $byKey[$item['key']] = $item;
            }
        }

        $arranged = [];

        foreach ($saved as $key) {
            if (is_string($key) && isset($byKey[$key]) && ! isset($arranged[$key])) {
                $arranged[$key] = ['selected' => true] + $byKey[$key];
            }
        }

        if ($arranged === []) {
            return self::allSelected($catalog);
        }

        foreach ($byKey as $key => $item) {
            if (! isset($arranged[$key])) {
                $arranged[$key] = ['selected' => false] + $item;
            }
        }

        return array_values($arranged);
    }

    /**
     * @param  array<int, array<string, mixed>> $catalog
     * @return array<int, array<string, mixed>>
     */
    private static function allSelected(array $catalog): array
    {
        return array_values(array_map(
            static fn (array $item): array => ['selected' => true] + $item,
            $catalog
        ));
    }
}
