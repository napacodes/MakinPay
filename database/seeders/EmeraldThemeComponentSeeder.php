<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ComponentType;
use App\Enums\Theme;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the Emerald theme's page-builder component library.
 *
 * Mirrors {@see GoldenThemeComponentSeeder}: rows are matched on
 * (component_key, theme) so a replay never duplicates them, and
 * {@see mergeContentData()} only fills keys an administrator has not set, so
 * re-running never discards edited copy. Safe in
 * `installer.reconcile_seeders`.
 *
 * Unlike Golden this theme ships no image assets — the gateway marquee is set
 * in type, the value props use FontAwesome, and the platform mocks are drawn
 * in CSS — so there is no asset-copy step.
 *
 * Earn and pricing figures are lifted from WalletEarnPlanSeeder and
 * SubscriptionPlanSeeder; none are invented here.
 */
class EmeraldThemeComponentSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('page_components')) {
            return;
        }

        $this->seedComponents();
    }

    protected function seedComponents(): void
    {
        $now             = now();
        $hasContentField = Schema::hasColumn('page_components', 'content_fields');
        $hasIsModal      = Schema::hasColumn('page_components', 'is_modal');
        $hasSort         = Schema::hasColumn('page_components', 'sort');
        $hasTheme        = Schema::hasColumn('page_components', 'theme');

        $baseSort = (int) (DB::table('page_components')->max('sort') ?? 0);

        foreach ($this->components() as $index => $component) {
            $existing = DB::table('page_components')
                ->where('component_key', $component['component_key'])
                ->when($hasTheme, fn ($query) => $query->where('theme', Theme::Emerald->value))
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
                $row['theme'] = Theme::Emerald->value;
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
        }
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
                    'subheading'   => ['en' => 'Digital wallet & payment gateway'],
                    'heading'      => ['en' => 'Move money anywhere. Keep control everywhere.'],
                    'description'  => ['en' => 'Send, receive, exchange and spend across currencies from one account — with the limits, verification and audit trail your business needs.'],
                    'button_text'  => ['en' => 'Create your account'],
                    'button_url'   => '/register',
                    'button2_text' => ['en' => 'See the live demo'],
                    'button2_url'  => '#platform',
                    'trust_items'  => ['en' => 'No setup fee | Two-factor auth | Tiered KYC'],
                ],
            ],
            [
                'component_name' => 'Gateway Marquee',
                'component_key'  => 'payment_partner',
                'content_data'   => [
                    'heading' => ['en' => 'Works with the rails you already use'],
                    'caption' => ['en' => '30+ integrations'],
                ],
                'repeated_content' => collect([
                    'PayPal', 'Stripe', 'Mollie', 'Coinbase', 'Paystack', 'Flutterwave', 'Razorpay',
                    'Binance Pay', 'MTN MoMo', 'Airtel Money', 'NOWPayments', 'CoinGate', 'BTCPay',
                ])->map(fn (string $name): array => ['gateway_name' => $name])->all(),
            ],
            [
                'component_name' => 'Value Props',
                'component_key'  => 'feature',
                'content_data'   => [
                    'subheading' => ['en' => 'Why DigiKash'],
                    'heading'    => ['en' => 'Built for money that has to arrive'],
                ],
                'repeated_content' => [
                    [
                        'feature_icon_class' => 'fa-solid fa-bolt',
                        'feature_title'      => ['en' => 'Instant global transfers'],
                        'feature_text'       => ['en' => 'Send to a wallet ID, a QR code or a payment link. Settlement is immediate between DigiKash accounts.'],
                    ],
                    [
                        'feature_icon_class' => 'fa-solid fa-coins',
                        'feature_title'      => ['en' => 'Multi-currency wallets'],
                        'feature_text'       => ['en' => 'Hold fiat and crypto side by side, exchange between them, and keep every balance in its own ledger.'],
                    ],
                    [
                        'feature_icon_class' => 'fa-solid fa-shield-halved',
                        'feature_title'      => ['en' => 'Two-factor auth and tiered KYC'],
                        'feature_text'       => ['en' => 'Verification tiers raise limits as trust grows, and every action lands in an audit trail.'],
                    ],
                ],
            ],
            [
                'component_name' => 'Platform Showcase',
                'component_key'  => 'service',
                'content_data'   => [
                    'heading' => ['en' => 'One account, the whole money stack'],
                ],
                'repeated_content' => [
                    [
                        'service_eyebrow' => ['en' => 'Wallets'],
                        'service_title'   => ['en' => 'Wallets & exchange'],
                        'service_text'    => ['en' => 'A ledger per currency, with exchange between them at the rate you configure.'],
                        'service_bullets' => ['en' => 'Fiat and crypto in one account | Per-currency precision | Full transaction history'],
                        'service_mock'    => 'wallet',
                    ],
                    [
                        'service_eyebrow' => ['en' => 'Cards'],
                        'service_title'   => ['en' => 'Virtual cards'],
                        'service_text'    => ['en' => 'Issue cards against a wallet balance, fund them, and freeze them the moment something looks wrong.'],
                        'service_bullets' => ['en' => 'Issue in seconds | Fund from any wallet | Freeze and terminate'],
                        'service_mock'    => 'card',
                    ],
                    [
                        'service_eyebrow' => ['en' => 'Accept'],
                        'service_title'   => ['en' => 'QR & Wallet-ID payments'],
                        'service_text'    => ['en' => 'Take payment in person from a QR code, or from anywhere with a wallet ID.'],
                        'service_bullets' => ['en' => 'Scan to pay | Wallet-ID transfers | Instant confirmation'],
                        'service_mock'    => 'qr',
                    ],
                    [
                        'service_eyebrow' => ['en' => 'Merchant'],
                        'service_title'   => ['en' => 'Payment links & merchant tools'],
                        'service_text'    => ['en' => 'Share a link, host a checkout, and reconcile through the merchant dashboard and API.'],
                        'service_bullets' => ['en' => 'Hosted checkout | Payment links | Merchant API and webhooks'],
                        'service_mock'    => 'merchant',
                    ],
                ],
            ],
            [
                'component_name' => 'P2P Escrow',
                'component_key'  => 'p2p_escrow',
                'content_data'   => [
                    'subheading'  => ['en' => 'P2P marketplace'],
                    'heading'     => ['en' => 'Trade peer to peer, with the funds held in escrow'],
                    'description' => ['en' => 'Escrow holds the asset from the moment an order opens until both sides confirm.'],
                    'step_one'    => ['en' => 'Post an ad with your rate, limits and payment methods.'],
                    'step_two'    => ['en' => 'Escrow holds the seller balance as soon as an order opens.'],
                    'step_three'  => ['en' => 'Funds release on confirmation — or go to dispute review.'],
                ],
            ],
            [
                'component_name' => 'Wallet Earn',
                'component_key'  => 'wallet_earn',
                'content_data'   => [
                    'subheading' => ['en' => 'Wallet Earn'],
                    'heading'    => ['en' => 'Put an idle balance to work'],
                    'disclosure' => ['en' => 'Rates are variable and are not guaranteed. Returns depend on plan terms and market conditions; capital is at risk.'],
                ],
            ],
            [
                'component_name' => 'Stats Band',
                'component_key'  => 'stats_band',
                'content_data'   => [
                    'subheading' => ['en' => 'Last 30 days'],
                    'caption'    => ['en' => 'Figures shown are demonstration data from this installation.'],
                ],
                'repeated_content' => [
                    ['stat_value' => '128000', 'stat_prefix' => '', 'stat_suffix' => '+', 'stat_label' => ['en' => 'Transfers settled']],
                    ['stat_value' => '42', 'stat_prefix' => '', 'stat_suffix' => '', 'stat_label' => ['en' => 'Currencies supported']],
                    ['stat_value' => '99.9', 'stat_prefix' => '', 'stat_suffix' => '%', 'stat_label' => ['en' => 'Gateway uptime']],
                    ['stat_value' => '30', 'stat_prefix' => '', 'stat_suffix' => '+', 'stat_label' => ['en' => 'Payment integrations']],
                ],
            ],
            [
                'component_name' => 'Pricing',
                'component_key'  => 'subscription_plans',
                'content_data'   => [
                    'subheading' => ['en' => 'Plans'],
                    'heading'    => ['en' => 'Start free, upgrade when the limits bind'],
                ],
            ],
            [
                'component_name' => 'How It Works',
                'component_key'  => 'work_process',
                'content_data'   => [
                    'subheading' => ['en' => 'Getting started'],
                    'heading'    => ['en' => 'Four steps from sign-up to spending'],
                ],
                'repeated_content' => [
                    ['step_title' => ['en' => 'Create an account'], 'step_text' => ['en' => 'Email, password, and two-factor authentication from the first login.']],
                    ['step_title' => ['en' => 'Verify your identity'], 'step_text' => ['en' => 'Submit KYC once; each tier raises your transaction and withdrawal limits.']],
                    ['step_title' => ['en' => 'Fund your wallet'], 'step_text' => ['en' => 'Top up from a card, bank transfer, mobile money or crypto deposit.']],
                    ['step_title' => ['en' => 'Send, spend or earn'], 'step_text' => ['en' => 'Transfer, issue a virtual card, accept payments, or stake an idle balance.']],
                ],
            ],
            [
                'component_name' => 'Security',
                'component_key'  => 'security',
                'content_data'   => [
                    'subheading' => ['en' => 'Security'],
                    'heading'    => ['en' => 'Controls that stay out of the way until they matter'],
                    'item_one'   => ['en' => 'Two-factor authentication: Required at login and re-checked before sensitive actions.'],
                    'item_two'   => ['en' => 'Tiered KYC: Verification tiers unlock higher limits instead of gating the whole account.'],
                    'item_three' => ['en' => 'Per-plan limits: Daily, monthly and balance ceilings enforced server-side on every request.'],
                    'item_four'  => ['en' => 'Sandbox mode: Test the merchant API and webhooks without touching live balances.'],
                    'item_five'  => ['en' => 'Audit trail: Every balance change is written as a traceable ledger entry.'],
                    'item_six'   => ['en' => 'Session controls: Lock screen, device sessions and forced re-authentication.'],
                ],
            ],
            [
                'component_name' => 'Testimonials',
                'component_key'  => 'testimonial',
                'content_data'   => [
                    'heading' => ['en' => 'What operators say'],
                ],
                'repeated_content' => [
                    [
                        'quote'          => ['en' => 'We replaced two providers with one account. Reconciliation went from a morning to a coffee.'],
                        'author_name'    => 'Amara Okafor',
                        'author_role'    => ['en' => 'Finance lead'],
                        'author_country' => 'Nigeria',
                    ],
                    [
                        'quote'          => ['en' => 'The escrow flow is what sold our traders. They can see exactly when funds are held and released.'],
                        'author_name'    => 'Diego Ramirez',
                        'author_role'    => ['en' => 'Marketplace operator'],
                        'author_country' => 'Mexico',
                    ],
                    [
                        'quote'          => ['en' => 'Virtual cards against a wallet balance meant we stopped waiting on bank issuance entirely.'],
                        'author_name'    => 'Priya Nair',
                        'author_role'    => ['en' => 'Operations manager'],
                        'author_country' => 'India',
                    ],
                ],
            ],
            [
                'component_name' => 'FAQ',
                'component_key'  => 'faq',
                'content_data'   => [
                    'heading' => ['en' => 'Questions, answered'],
                ],
                'repeated_content' => [
                    ['question' => ['en' => 'What does it cost?'], 'answer' => ['en' => 'Starter is free. Pro and Business are monthly subscriptions with lower effective rates on six-month and annual cycles. Transaction fees are set per gateway by the operator.']],
                    ['question' => ['en' => 'Which countries can I use it from?'], 'answer' => ['en' => 'Availability depends on the payment gateways and currencies the operator has enabled for your region.']],
                    ['question' => ['en' => 'Do I have to complete KYC?'], 'answer' => ['en' => 'You can hold an account without it, but verification tiers unlock higher transaction and withdrawal limits.']],
                    ['question' => ['en' => 'How long do withdrawals take?'], 'answer' => ['en' => 'Transfers between DigiKash accounts settle immediately. External withdrawals follow the chosen gateway; Pro and Business are queued ahead of Starter.']],
                    ['question' => ['en' => 'What happens if a P2P trade is disputed?'], 'answer' => ['en' => 'The asset stays in escrow. Neither side can move it until the dispute is resolved by review.']],
                    ['question' => ['en' => 'Is there an API?'], 'answer' => ['en' => 'Yes — merchant API keys, hosted checkout and webhooks are available on the Business plan, with a sandbox for testing.']],
                    ['question' => ['en' => 'Which currencies are supported?'], 'answer' => ['en' => 'Both fiat and crypto, each in its own wallet ledger. The exact list is whatever the operator has enabled.']],
                ],
            ],
            [
                'component_name' => 'Final CTA',
                'component_key'  => 'offer',
                'content_data'   => [
                    'heading'      => ['en' => 'Open a wallet and move your first payment today'],
                    'description'  => ['en' => 'No setup fee, no minimum balance. Upgrade only when your limits start to bind.'],
                    'button_text'  => ['en' => 'Create your account'],
                    'button_url'   => '/register',
                    'button2_text' => ['en' => 'Compare plans'],
                    'button2_url'  => '#pricing',
                    'reassurance'  => ['en' => 'Two-factor authentication from day one. Cancel a subscription at any time.'],
                ],
            ],
        ];
    }
}
