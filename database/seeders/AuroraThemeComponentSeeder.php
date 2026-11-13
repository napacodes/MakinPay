<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ComponentType;
use App\Enums\Theme;
use App\Services\ThemePageComposer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the Aurora theme's page-builder component library.
 *
 * Mirrors {@see EmeraldThemeComponentSeeder}: rows are matched on
 * (component_key, theme) so a replay never duplicates them, and
 * {@see mergeContentData()} only fills keys an administrator has not set, so
 * re-running never discards edited copy. Safe in `installer.reconcile_seeders`.
 *
 * The feature catalog, card matrix, remittance corridors and tier limits are
 * read live by their section views, so those components ship headings only —
 * no figure here is invented. Copy that would otherwise date carries tokens
 * the views fill in: {version} and {features}.
 *
 * Finally the home page is given a starting Aurora composition, in the order
 * below, for the first time an administrator switches to Aurora. A key-based
 * remap could only carry over blocks the previous theme also had, which would
 * leave out every section unique to Aurora.
 */
class AuroraThemeComponentSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('page_components')) {
            return;
        }

        $ids = $this->seedComponents();

        $this->seedHomeComposition($ids);
    }

    /**
     * @return list<int> the Aurora component ids, in page order
     */
    protected function seedComponents(): array
    {
        $now             = now();
        $hasContentField = Schema::hasColumn('page_components', 'content_fields');
        $hasIsModal      = Schema::hasColumn('page_components', 'is_modal');
        $hasSort         = Schema::hasColumn('page_components', 'sort');
        $hasTheme        = Schema::hasColumn('page_components', 'theme');

        $baseSort = (int) (DB::table('page_components')->max('sort') ?? 0);
        $ids      = [];

        foreach ($this->components() as $index => $component) {
            $existing = DB::table('page_components')
                ->where('component_key', $component['component_key'])
                ->when($hasTheme, fn ($query) => $query->where('theme', Theme::Aurora->value))
                ->first();

            if ($existing) {
                $existingData = json_decode((string) $existing->content_data, true) ?: [];
                $merged       = $this->mergeContentData($existingData, $component['content_data'] ?? []);

                if ($merged !== $existingData) {
                    DB::table('page_components')
                        ->where('id', $existing->id)
                        ->update(['content_data' => json_encode($merged), 'updated_at' => $now]);
                }

                $this->seedRepeatedContent((int) $existing->id, $component['repeated_content'] ?? []);
                $ids[] = (int) $existing->id;

                continue;
            }

            $row = [
                'component_name'   => $component['component_name'],
                'component_key'    => $component['component_key'],
                'content_data'     => json_encode($component['content_data'] ?? []),
                'type'             => ComponentType::Static->value,
                'is_active'        => 1,
                'repeated_content' => ! empty($component['repeated_content']) ? 1 : 0,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];

            if ($hasTheme) {
                $row['theme'] = Theme::Aurora->value;
            }
            if ($hasContentField) {
                $row['content_fields'] = '{}';
            }
            if ($hasIsModal) {
                $row['is_modal'] = 0;
            }
            if ($hasSort) {
                $row['sort'] = $baseSort + $index + 1;
            }

            $componentId = DB::table('page_components')->insertGetId($row);

            $this->seedRepeatedContent((int) $componentId, $component['repeated_content'] ?? []);
            $ids[] = (int) $componentId;
        }

        return $ids;
    }

    /**
     * @param list<int> $ids
     */
    protected function seedHomeComposition(array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable('pages') || ! Schema::hasTable('settings') || ! Schema::hasColumn('page_components', 'theme')) {
            return;
        }

        $homeId = DB::table('pages')->where('slug', '/')->value('id');

        if ($homeId === null) {
            return;
        }

        app(ThemePageComposer::class)->seedComposition(Theme::Aurora, (int) $homeId, $ids);
    }

    /**
     * Fill only keys that are absent or empty, so a replay backfills new fields
     * without trampling copy an administrator has already edited.
     *
     * @param  array<string,mixed> $existing
     * @param  array<string,mixed> $fresh
     * @return array<string,mixed>
     */
    protected function mergeContentData(array $existing, array $fresh): array
    {
        foreach ($fresh as $key => $value) {
            if (! array_key_exists($key, $existing)) {
                $existing[$key] = $value;

                continue;
            }

            $current = $existing[$key];

            if (is_array($current) && is_array($value)) {
                $existing[$key] = $this->mergeContentData($current, $value);
            } elseif ($current === '' || $current === null) {
                $existing[$key] = $value;
            }
        }

        return $existing;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    protected function seedRepeatedContent(int $componentId, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $alreadySeeded = DB::table('page_component_repeated_contents')
            ->where('component_id', $componentId)
            ->exists();

        if ($alreadySeeded) {
            return;
        }

        $now = now();

        DB::table('page_component_repeated_contents')->insert(
            collect($rows)->map(fn (array $row): array => [
                'component_id' => $componentId,
                'content_data' => json_encode($row),
                'created_at'   => $now,
                'updated_at'   => $now,
            ])->all()
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    protected function components(): array
    {
        return [
            [
                'component_name' => 'Hero',
                'component_key'  => 'banner',
                'content_data'   => [
                    'badge_label'       => ['en' => 'NEW'],
                    'badge_text'        => ['en' => 'v{version} — private KYC vault & automatic IP blocking'],
                    'heading'           => ['en' => 'Every way money moves.'],
                    'heading_highlight' => ['en' => 'One wallet.'],
                    'description'       => ['en' => 'DigiKash is a complete digital-wallet platform — deposits, P2P trading, virtual cards, international remittance, merchant payments and savings — for users, merchants and agents, all run from a single control panel.'],
                    'button_text'       => ['en' => 'Launch your wallet'],
                    'button_url'        => '/user/register',
                    'button2_text'      => ['en' => 'Explore all features'],
                    'button2_url'       => '#features',
                    'stat_one'          => ['en' => '3: portals — user, merchant, agent'],
                    'stat_two'          => ['en' => '{features}: switchable platform features'],
                    'stat_three'        => ['en' => '{card_issuers}: virtual-card issuers built in'],
                ],
            ],
            [
                'component_name' => 'Integrations Strip',
                'component_key'  => 'payment_partner',
                'content_data'   => [
                    'caption' => ['en' => 'Integrated out of the box'],
                ],
                'repeated_content' => collect([
                    'Stripe Issuing', 'StroWallet', 'Bitnob', 'Sumsub', 'Shufti Pro', 'Smile ID', 'WooCommerce', 'Twilio', 'Firebase',
                ])->map(fn (string $name): array => ['gateway_name' => $name])->all(),
            ],
            [
                'component_name' => 'Portals',
                'component_key'  => 'portals',
                'content_data'   => [
                    'subheading' => ['en' => 'One platform, three portals'],
                    'heading'    => ['en' => 'Built for everyone who touches the money.'],
                ],
                'repeated_content' => [
                    [
                        'portal_key' => 'user',
                        'tab_label'  => ['en' => 'User'],
                        'tab_icon'   => 'fa-solid fa-user',
                        'title'      => ['en' => 'For everyday users'],
                        'blurb'      => ['en' => 'A multi-currency wallet with cards, savings and global transfers built in — share one wallet ID and get paid.'],
                        'cta_text'   => ['en' => 'Open user account'],
                        'cta_url'    => '/user/register',
                        'items'      => ['en' => implode("\n", [
                            'fa-solid fa-paper-plane | Send & request by wallet ID or email',
                            'fa-solid fa-right-left | Exchange between wallet currencies',
                            'fa-solid fa-credit-card | Issue virtual cards in seconds',
                            'fa-solid fa-earth-americas | Send abroad at locked FX rates',
                            'fa-solid fa-vault | Vaults with auto-save & round-ups',
                            'fa-solid fa-people-arrows | Trade on the P2P marketplace',
                        ])],
                    ],
                    [
                        'portal_key' => 'merchant',
                        'tab_label'  => ['en' => 'Merchant'],
                        'tab_icon'   => 'fa-solid fa-store',
                        'title'      => ['en' => 'For merchants'],
                        'blurb'      => ['en' => 'Accept payments online, in-store and in WooCommerce — settled straight into the merchant wallet.'],
                        'cta_text'   => ['en' => 'Become a merchant'],
                        'cta_url'    => '/merchant/register',
                        'items'      => ['en' => implode("\n", [
                            'fa-solid fa-link | Shareable payment links',
                            'fa-solid fa-qrcode | QR code payments in-store',
                            'fa-brands fa-wordpress | WooCommerce checkout plugin',
                            'fa-solid fa-code | Enterprise-grade merchant API',
                            'fa-solid fa-shop | Vendor storefront, split payouts',
                            'fa-solid fa-arrows-rotate | Subscription plans & renewals',
                        ])],
                    ],
                    [
                        'portal_key' => 'agent',
                        'tab_label'  => ['en' => 'Agent'],
                        'tab_icon'   => 'fa-solid fa-user-tie',
                        'title'      => ['en' => 'For agents'],
                        'blurb'      => ['en' => 'Put a cash network on the map. Agents serve customers from their own dashboard, login and wallet.'],
                        'cta_text'   => ['en' => 'Join as an agent'],
                        'cta_url'    => '/agent/register',
                        'items'      => ['en' => implode("\n", [
                            'fa-solid fa-gauge | Dedicated agent login & dashboard',
                            'fa-solid fa-qrcode | QR cash-out for customers',
                            'fa-solid fa-circle-arrow-down | Deposits & withdrawals',
                            'fa-solid fa-mobile-screen-button | Mobile recharge from balance',
                            'fa-solid fa-link | Payment links',
                            'fa-solid fa-toggle-on | Program switchable by admin',
                        ])],
                    ],
                ],
            ],
            [
                'component_name' => 'Feature Catalog',
                'component_key'  => 'feature',
                'content_data'   => [
                    'subheading'  => ['en' => 'Feature catalog'],
                    'heading'     => ['en' => '{features} features. Every one a switch.'],
                    'description' => ['en' => 'Turn any capability on or off per portal from Feature Management — with KYC, verified-phone and country rules attached — without touching code.'],
                ],
            ],
            [
                'component_name' => 'Virtual Cards',
                'component_key'  => 'virtual_cards',
                'content_data'   => [
                    'subheading'  => ['en' => 'Virtual cards'],
                    'heading'     => ['en' => 'Multi-provider cards, issued in seconds.'],
                    'description' => ['en' => 'Plug in Stripe, StroWallet or Bitnob — adding another issuer is a single line. Cards fund from any wallet currency with the conversion recorded on every row, and the UI only shows actions the provider supports.'],
                ],
            ],
            [
                'component_name' => 'Remittance',
                'component_key'  => 'remittance',
                'content_data'   => [
                    'subheading'  => ['en' => 'International remittance'],
                    'heading'     => ['en' => 'Borders are just another corridor.'],
                    'description' => ['en' => 'Locked FX quotes, saved beneficiaries and pluggable payout partners. Every leg lands in its own currency’s precision — three decimals for Kuwaiti dinar, none for yen.'],
                    'fee_label'   => ['en' => 'Transfer fee'],
                    'point_one'   => ['en' => 'Payout partners: Bitnob, Stripe Connect or manual approval'],
                    'point_two'   => ['en' => 'Auto reconciliation: Stuck transfers are polled and flagged'],
                    'point_three' => ['en' => 'Beneficiaries: Save recipients, send again in two taps'],
                    'point_four'  => ['en' => 'Compliance tiers: Limits scale with verification level'],
                ],
            ],
            [
                'component_name' => 'Business & Merchant',
                'component_key'  => 'business',
                'content_data'   => [
                    'subheading'  => ['en' => 'Business & merchant'],
                    'heading'     => ['en' => 'Get paid everywhere your customers are.'],
                    'description' => ['en' => 'Links, QR, checkout plugins and an enterprise-grade API — all settling straight into the merchant wallet.'],
                ],
                'repeated_content' => [
                    ['icon' => 'fa-solid fa-link', 'feature_key' => 'payment_link', 'title' => ['en' => 'Payment links'], 'text' => ['en' => 'Share a link anywhere. Anyone can pay from a wallet or any supported gateway.']],
                    ['icon' => 'fa-solid fa-qrcode', 'feature_key' => 'merchant_payment', 'title' => ['en' => 'QR code payments'], 'text' => ['en' => 'Customers scan the merchant’s code and pay in-store from their wallet.']],
                    ['icon' => 'fa-brands fa-wordpress', 'feature_key' => 'merchant_payment', 'title' => ['en' => 'WooCommerce plugin'], 'text' => ['en' => 'Drop DigiKash into any WooCommerce store as a checkout method.']],
                    ['icon' => 'fa-solid fa-code', 'feature_key' => 'merchant_payment', 'title' => ['en' => 'Merchant API'], 'text' => ['en' => 'Enterprise-structured API for custom checkouts, with refunds from the transaction.']],
                    ['icon' => 'fa-solid fa-shop', 'feature_key' => 'vendor_marketplace', 'title' => ['en' => 'Vendor marketplace'], 'text' => ['en' => 'In-platform storefronts with split payments — vendors get net, you keep commission.']],
                    ['icon' => 'fa-solid fa-arrows-rotate', 'feature_key' => 'subscription_system', 'title' => ['en' => 'Subscriptions'], 'text' => ['en' => 'Plans, checkout, renewals, cancellations and history, fully managed.']],
                ],
            ],
            [
                'component_name' => 'Save & Grow',
                'component_key'  => 'save_grow',
                'content_data'   => [
                    'subheading'  => ['en' => 'Save & grow'],
                    'heading'     => ['en' => 'A wallet that helps money stay.'],
                    'description' => ['en' => 'Ring-fence savings, cap spending, automate payments and earn on idle balances — engagement tools that keep customers coming back.'],
                    'bullets'     => ['en' => implode("\n", [
                        'fa-solid fa-chart-pie | Budgets & alerts | budgets',
                        'fa-solid fa-calendar-check | Scheduled payments | scheduled_payments',
                        'fa-solid fa-seedling | Wallet Earn staking | wallet_earn',
                        'fa-solid fa-ranking-star | User ranks & tiers | user_ranks',
                        'fa-solid fa-share-nodes | Referral tree | referral_program',
                        'fa-solid fa-gift | Designed gift cards | gift_cards',
                    ])],
                ],
            ],
            [
                'component_name' => 'Security',
                'component_key'  => 'security',
                'content_data'   => [
                    'subheading'  => ['en' => 'Security & compliance'],
                    'heading'     => ['en' => 'Bank-grade guardrails, switched on by default.'],
                    'description' => ['en' => 'Automated identity checks, private document storage and a risk engine that judges every sign-in against that account’s own history.'],
                    'item_one'    => ['en' => 'Automated KYC: Sumsub, Shufti Pro and Smile ID with hosted capture and webhook verdicts.'],
                    'icon_one'    => 'fa-solid fa-id-card',
                    'item_two'    => ['en' => 'Private document vault: Identity documents live outside the web root, streamed only to authorised reviewers.'],
                    'icon_two'    => 'fa-solid fa-folder-closed',
                    'item_three'  => ['en' => 'Automatic IP blocking: Abusive addresses are scored and blocked, escalating on repeat offences.'],
                    'icon_three'  => 'fa-solid fa-shield-halved',
                    'item_four'   => ['en' => 'Impossible-travel detection: Suspicious sign-ins are blocked and the account holder alerted by email and push.'],
                    'icon_four'   => 'fa-solid fa-location-crosshairs',
                    'item_five'   => ['en' => '2FA & wallet PIN: Two-factor sign-in plus a PIN before money leaves the wallet.'],
                    'icon_five'   => 'fa-solid fa-key',
                    'item_six'    => ['en' => 'Precision-safe ledger: Decimal money at each currency’s own precision — from BTC to JPY.'],
                    'icon_six'    => 'fa-solid fa-calculator',
                ],
            ],
            [
                'component_name' => 'Operator Tools',
                'component_key'  => 'operator_grid',
                'content_data'   => [
                    'subheading' => ['en' => 'For operators'],
                    'heading'    => ['en' => 'Run the whole fintech from one control panel.'],
                ],
                'repeated_content' => [
                    ['icon' => 'fa-solid fa-toggle-on', 'title' => ['en' => 'Feature management'], 'text' => ['en' => 'Per-portal switches with KYC and country rules.']],
                    ['icon' => 'fa-solid fa-gauge-high', 'title' => ['en' => 'Action-first dashboard'], 'text' => ['en' => 'Review queues with SLA targets, treasury and platform health.']],
                    ['icon' => 'fa-solid fa-magnifying-glass', 'title' => ['en' => 'Command palette'], 'text' => ['en' => 'Search pages, users and transactions from the top bar.']],
                    ['icon' => 'fa-solid fa-book', 'title' => ['en' => 'Ledger & CSV export'], 'text' => ['en' => 'Approve and refund straight from the transaction record.']],
                    ['icon' => 'fa-solid fa-file-code', 'title' => ['en' => 'Custom landing uploader'], 'text' => ['en' => 'Launch your own HTML/CSS front page in minutes.']],
                    ['icon' => 'fa-solid fa-palette', 'title' => ['en' => 'Theme manager'], 'text' => ['en' => 'Switch landing themes and tune brand colours without touching code.']],
                    ['icon' => 'fa-solid fa-mobile-screen-button', 'title' => ['en' => 'PWA & web push'], 'text' => ['en' => 'Installable app experience with real-time notifications.']],
                    ['icon' => 'fa-solid fa-clock-rotate-left', 'title' => ['en' => 'One-click updater'], 'text' => ['en' => 'Install releases with snapshot and file rollback.']],
                ],
            ],
            [
                'component_name' => 'Final CTA',
                'component_key'  => 'offer',
                'content_data'   => [
                    'heading'      => ['en' => 'Launch your digital wallet with DigiKash.'],
                    'description'  => ['en' => 'Wallets, cards, remittance, merchants and agents — ready for your brand today.'],
                    'button_text'  => ['en' => 'Open free account'],
                    'button_url'   => '/user/register',
                    'button2_text' => ['en' => 'Become a merchant'],
                    'button2_url'  => '/merchant/register',
                ],
            ],
        ];
    }
}
