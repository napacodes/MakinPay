<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Currency;
use App\Models\Feature;
use App\Models\FeatureAccessRule;
use App\Models\RemittanceCorridor;
use App\Models\VirtualCardProvider;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only figures behind the Aurora landing page's module-driven sections.
 *
 * Every number here comes from the platform's own configuration — the feature
 * catalog as Feature Management left it, the card providers that are switched
 * on, the remittance corridors that are open and the rates stored against the
 * base currency — so the page never advertises something the site cannot do.
 * Nothing calls out to a provider: the landing page renders on every visit and
 * must not wait on, or spend, a third-party API.
 */
class AuroraLandingData
{
    /**
     * Icon per catalog feature. A key added to the catalog later falls back
     * to a neutral icon rather than breaking the grid.
     */
    public const FEATURE_ICONS = [
        'deposit_money'       => 'fa-solid fa-circle-arrow-down',
        'withdraw_money'      => 'fa-solid fa-circle-arrow-up',
        'send_money'          => 'fa-solid fa-paper-plane',
        'request_money'       => 'fa-solid fa-hand-holding-dollar',
        'exchange_money'      => 'fa-solid fa-right-left',
        'wallet_earn'         => 'fa-solid fa-seedling',
        'mobile_recharge'     => 'fa-solid fa-mobile-screen-button',
        'bill_payment'        => 'fa-solid fa-receipt',
        'budgets'             => 'fa-solid fa-chart-pie',
        'vaults'              => 'fa-solid fa-vault',
        'scheduled_payments'  => 'fa-solid fa-calendar-check',
        'bank_transfer'       => 'fa-solid fa-building-columns',
        'remittance'          => 'fa-solid fa-earth-americas',
        'p2p_marketplace'     => 'fa-solid fa-people-arrows',
        'payment_link'        => 'fa-solid fa-link',
        'merchant_payment'    => 'fa-solid fa-store',
        'vendor_marketplace'  => 'fa-solid fa-shop',
        'agent_program'       => 'fa-solid fa-user-tie',
        'subscription_system' => 'fa-solid fa-arrows-rotate',
        'virtual_card'        => 'fa-solid fa-credit-card',
        'referral_program'    => 'fa-solid fa-share-nodes',
        'user_ranks'          => 'fa-solid fa-ranking-star',
        'vouchers'            => 'fa-solid fa-ticket',
        'gift_cards'          => 'fa-solid fa-gift',
    ];

    /**
     * Card capabilities the comparison matrix lists, in display order. Keys
     * are the ones VirtualCardProvider::supports() understands.
     */
    public const CARD_CAPABILITIES = ['issue', 'card_details', 'topup', 'withdraw', 'freeze', 'limits', 'controls', 'activity'];

    /**
     * The five card designs a cardholder can pick, as validated by the card
     * request endpoints.
     */
    public const CARD_THEMES = ['midnight', 'ocean', 'graphite', 'emerald', 'violet'];

    /**
     * Used only when no corridor can be priced from stored data, and always
     * labelled as sample rates on the page. Precision is still read from the
     * currency table when the code exists there.
     *
     * @var list<array{code: string, name: string, rate: string, decimals: int}>
     */
    public const SAMPLE_CORRIDORS = [
        ['code' => 'BDT', 'name' => 'Bangladeshi taka', 'rate' => '121.84', 'decimals' => 2],
        ['code' => 'EUR', 'name' => 'Euro', 'rate' => '0.9234', 'decimals' => 2],
        ['code' => 'GBP', 'name' => 'British pound', 'rate' => '0.7891', 'decimals' => 2],
        ['code' => 'AED', 'name' => 'UAE dirham', 'rate' => '3.6725', 'decimals' => 2],
        ['code' => 'PHP', 'name' => 'Philippine peso', 'rate' => '57.41', 'decimals' => 2],
        ['code' => 'NGN', 'name' => 'Nigerian naira', 'rate' => '1532.5', 'decimals' => 2],
        ['code' => 'KWD', 'name' => 'Kuwaiti dinar', 'rate' => '0.3071', 'decimals' => 3],
        ['code' => 'JPY', 'name' => 'Japanese yen', 'rate' => '148.62', 'decimals' => 0],
    ];

    public const SAMPLE_FEE = '2.99';

    public function __construct(
        protected FeatureManager $features,
        protected CurrencyService $currencies,
        protected PayoutGatewayFactory $payoutGateways,
    ) {}

    public function isEnabled(string $feature): bool
    {
        return $this->features->isEnabled($feature);
    }

    /**
     * Every feature switched on in Feature Management, in catalog order.
     *
     * Portal chips list only the panels the feature is visible on, and drop
     * Agent altogether while the agent programme is off. The KYC badge reads
     * the live access rule an admin can edit, falling back to the catalog
     * default when a feature has no rules yet.
     *
     * @return list<array{key: string, label: string, category: string, description: string, icon: string, core: bool, kyc: bool, panels: list<string>}>
     */
    public function featureCatalog(): array
    {
        $agentsOn = $this->features->isEnabled('agent_program');

        $panels = [
            FeatureAccessRule::PANEL_USER     => __('User'),
            FeatureAccessRule::PANEL_MERCHANT => __('Merchant'),
            FeatureAccessRule::PANEL_AGENT    => __('Agent'),
        ];

        if (! $agentsOn) {
            unset($panels[FeatureAccessRule::PANEL_AGENT]);
        }

        return $this->features->enabled()
            ->map(function (Feature $feature) use ($panels): array {
                $visible = array_keys(array_filter(
                    $panels,
                    fn (string $label, string $panel): bool => $this->features->isVisible($feature->key, $panel),
                    ARRAY_FILTER_USE_BOTH,
                ));

                return [
                    'key'         => $feature->key,
                    'label'       => __($feature->label),
                    'category'    => (string) $feature->category,
                    'description' => $this->featureBlurb($feature),
                    'icon'        => self::FEATURE_ICONS[$feature->key] ?? 'fa-solid fa-puzzle-piece',
                    'core'        => (bool) $feature->is_core,
                    'kyc'         => $this->requiresKyc($feature, $visible),
                    'panels'      => array_values(array_map(fn (string $panel): string => $panels[$panel], $visible)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Filter pills for the catalog: every category that has at least one
     * enabled feature, in the catalog's configured order, with its count.
     *
     * @param  list<array{category: string}>                       $features
     * @return list<array{key: string, label: string, count: int}>
     */
    public function featureCategories(array $features): array
    {
        $counts     = array_count_values(array_column($features, 'category'));
        $categories = (array) config('feature_catalog.categories', []);

        uasort($categories, fn ($a, $b): int => (int) data_get($a, 'order', 99) <=> (int) data_get($b, 'order', 99));

        $pills = [];

        foreach ($categories as $key => $meta) {
            if (($counts[$key] ?? 0) > 0) {
                $pills[] = ['key' => (string) $key, 'label' => __((string) data_get($meta, 'label', $key)), 'count' => $counts[$key]];
            }
        }

        return $pills;
    }

    /**
     * Visitor-facing one-liner for a feature. The catalog's own description
     * is written for the administrator ("Allow users to…"), so the landing
     * page carries its own shorter copy, keyed the same way.
     */
    protected function featureBlurb(Feature $feature): string
    {
        $blurbs = [
            'deposit_money'       => __('Top up the wallet using any active payment gateway or manual deposit method.'),
            'withdraw_money'      => __('Move balance to linked bank, mobile money or crypto payout methods.'),
            'send_money'          => __('Transfer balance instantly to another user by wallet ID or email.'),
            'request_money'       => __('Raise payment requests another user can approve and pay from their wallet.'),
            'exchange_money'      => __('Convert balance between supported wallet currencies with the rate engine.'),
            'wallet_earn'         => __('Stake balances in supported currencies and earn scheduled rewards.'),
            'mobile_recharge'     => __('Recharge any mobile number from wallet balance via the configured provider.'),
            'bill_payment'        => __('Pay electricity, water, TV, internet and school bills from wallet balance.'),
            'budgets'             => __('Spending limits per category, measured against Insights, with alerts.'),
            'vaults'              => __('Ring-fence savings goals with auto-save rules and round-ups.'),
            'scheduled_payments'  => __('Schedule sends, vault top-ups and recharges once or on a cadence.'),
            'bank_transfer'       => __('Linked-bank withdraw methods, hidden everywhere when switched off.'),
            'remittance'          => __('Send abroad with locked FX, beneficiaries and pluggable payout partners.'),
            'p2p_marketplace'     => __('Peer-to-peer ads, trading rooms, payment methods and disputes.'),
            'payment_link'        => __('Shareable links anyone can pay from a wallet or supported gateway.'),
            'merchant_payment'    => __('Customers pay registered merchants from wallet or connected methods.'),
            'vendor_marketplace'  => __('Vendor storefronts with split payments and platform commission.'),
            'agent_program'       => __('Agent registration, login and dashboard — one switch for every surface.'),
            'subscription_system' => __('Plans, checkout, renewals, cancellations and subscription history.'),
            'virtual_card'        => __('Issue, manage and transact on cards from multiple card providers.'),
            'referral_program'    => __('Referral link, referral tree and configurable reward settings.'),
            'user_ranks'          => __('Rank progression with wallet limits, referral levels and reward tiers.'),
            'vouchers'            => __('Redeemable gift and top-up vouchers — usable at checkout too.'),
            'gift_cards'          => __('Designed gift cards delivered by email with a private redeem link.'),
        ];

        return $blurbs[$feature->key] ?? __((string) $feature->description);
    }

    /**
     * @param list<string> $visiblePanels
     */
    protected function requiresKyc(Feature $feature, array $visiblePanels): bool
    {
        $rules = $feature->accessRules->whereIn('panel', $visiblePanels);

        if ($rules->isEmpty()) {
            return (bool) config("feature_catalog.features.{$feature->key}.rules.requires_kyc", false);
        }

        return $rules->contains(fn (FeatureAccessRule $rule): bool => $rule->requiresKyc());
    }

    /**
     * Card issuers that are switched on and have a driver, with what each
     * one supports — the same capability map the card UI reads to decide
     * which actions to offer.
     *
     * @return list<array{code: string, name: string, capabilities: array<string, bool>}>
     */
    public function cardProviders(): array
    {
        if (! $this->hasTable('virtual_card_providers')) {
            return [];
        }

        $registered = array_keys((array) config('virtual_card.providers', []));

        return VirtualCardProvider::query()
            ->active()
            ->whereIn('code', $registered)
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (VirtualCardProvider $provider): array => [
                'code'         => (string) $provider->code,
                'name'         => (string) $provider->name,
                'capabilities' => collect(self::CARD_CAPABILITIES)
                    ->mapWithKeys(fn (string $capability): array => [$capability => $provider->supports($capability)])
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function cardCapabilityLabels(): array
    {
        return [
            'issue'        => __('Issue cards'),
            'card_details' => __('Card details'),
            'topup'        => __('Top-up'),
            'withdraw'     => __('Withdraw'),
            'freeze'       => __('Freeze / unfreeze'),
            'limits'       => __('Spending limits'),
            'controls'     => __('Channel controls'),
            'activity'     => __('Card activity'),
        ];
    }

    /**
     * Corridors for the calculator, priced the way QuoteService prices a real
     * quote — the mid rate less the corridor's FX spread, the fee on top of
     * the amount sent — but from the rates stored against the base currency
     * rather than a live provider call. Only open corridors whose payout
     * provider is available are listed; the busiest source currency wins.
     *
     * Falls back to {@see SAMPLE_CORRIDORS} when nothing can be priced, and
     * says so through `live`.
     *
     * The figures leave here as floats because the page recalculates them in
     * the browser as the visitor types; they illustrate, they never settle.
     *
     * @return array{live: bool, ttl_minutes: int, source: array{code: string, decimals: int}, corridors: list<array{code: string, name: string, rate: float, decimals: int, fixed_fee: float, percent_fee: float}>}
     */
    public function remittanceCorridors(int $limit = 8): array
    {
        return $this->liveCorridors($limit) ?? $this->sampleCorridors();
    }

    /**
     * @return array{live: true, ttl_minutes: int, source: array{code: string, decimals: int}, corridors: list<array<string, mixed>>}|null
     */
    protected function liveCorridors(int $limit): ?array
    {
        if (! $this->hasTable('remittance_corridors')) {
            return null;
        }

        $corridors = RemittanceCorridor::query()
            ->active()
            ->orderBy('id')
            ->get()
            ->filter(fn (RemittanceCorridor $corridor): bool => $this->payoutGateways->supports((string) $corridor->payout_gateway));

        if ($corridors->isEmpty()) {
            return null;
        }

        $source = (string) $corridors
            ->countBy(fn (RemittanceCorridor $corridor): string => strtoupper((string) $corridor->source_currency))
            ->sortDesc()
            ->keys()
            ->first();

        $names = Currency::query()
            ->whereIn('code', $corridors->map(fn (RemittanceCorridor $corridor): string => strtoupper((string) $corridor->destination_currency))->all())
            ->pluck('name', 'code');

        $rows = [];
        $ttl  = null;

        foreach ($corridors as $corridor) {
            $destination = strtoupper((string) $corridor->destination_currency);

            if (strtoupper((string) $corridor->source_currency) !== $source || isset($rows[$destination])) {
                continue;
            }

            $mid = $this->currencies->crossRate($source, $destination);

            if ($mid === null || bccomp($mid, '0', DecimalMoney::RATE_SCALE) <= 0) {
                continue;
            }

            $applied = DecimalMoney::subtractPercentage($mid, (string) $corridor->fx_spread_percent);

            $rows[$destination] = [
                'code'        => $destination,
                'name'        => (string) ($names[$destination] ?? $destination),
                'rate'        => (float) DecimalMoney::round($applied, 6),
                'decimals'    => currencyDecimals($destination),
                'fixed_fee'   => (float) $corridor->fixed_fee,
                'percent_fee' => (float) $corridor->percent_fee,
            ];

            $ttl ??= (int) $corridor->quote_ttl_minutes;

            if (count($rows) >= $limit) {
                break;
            }
        }

        if ($rows === []) {
            return null;
        }

        return [
            'live'        => true,
            'ttl_minutes' => max(1, (int) $ttl),
            'source'      => ['code' => $source, 'decimals' => currencyDecimals($source)],
            'corridors'   => array_values($rows),
        ];
    }

    /**
     * @return array{live: false, ttl_minutes: int, source: array{code: string, decimals: int}, corridors: list<array<string, mixed>>}
     */
    protected function sampleCorridors(): array
    {
        $decimals = fn (string $code, int $fallback): int => Money::decimalsIfKnown($code) ?? $fallback;

        return [
            'live'        => false,
            'ttl_minutes' => 15,
            'source'      => ['code' => 'USD', 'decimals' => $decimals('USD', 2)],
            'corridors'   => array_map(fn (array $row): array => [
                'code'        => $row['code'],
                'name'        => __($row['name']),
                'rate'        => (float) $row['rate'],
                'decimals'    => $decimals($row['code'], $row['decimals']),
                'fixed_fee'   => (float) self::SAMPLE_FEE,
                'percent_fee' => 0.0,
            ], self::SAMPLE_CORRIDORS),
        ];
    }

    /**
     * Compliance tier limits as configured, for the tier table. Whole amounts
     * drop their ".00", as the table is read at a glance.
     *
     * @return list<array{tier: string, per_transaction: string, daily: string, monthly: string, top: bool}>
     */
    public function remittanceTiers(): array
    {
        $tiers = (array) config('remittance.compliance.tiers', []);
        ksort($tiers);

        $last   = array_key_last($tiers);
        $figure = static function (mixed $value): string {
            $value = DecimalMoney::round((string) $value, 2);
            $whole = bccomp($value, DecimalMoney::round($value, 0), 2) === 0;

            return number_format((float) $value, $whole ? 0 : 2);
        };

        $rows = [];

        foreach ($tiers as $level => $limits) {
            $rows[] = [
                'tier'            => 'T'.$level,
                'per_transaction' => $figure($limits['per_transaction'] ?? 0),
                'daily'           => $figure($limits['daily'] ?? 0),
                'monthly'         => $figure($limits['monthly'] ?? 0),
                'top'             => $level === $last,
            ];
        }

        return $rows;
    }

    protected function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
