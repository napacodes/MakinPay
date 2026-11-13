<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Marketplace\OrderStatus;
use App\Enums\Marketplace\VendorStatus;
use App\Enums\UserRole;
use App\Models\Currency;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceSetting;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Seeds a browsable Vendor Marketplace on top of the demo accounts created by
 * DemoAccountSeeder. A single vendor makes the directory look broken, so this
 * fills every category with an approved business, then backdates released
 * orders so the trust signals the directory sorts on (completed sales, recent
 * vendors) render the way they do in production.
 *
 * Orders are written directly rather than through MarketplaceOrderHandler:
 * the demo wallets are already funded to specific balances for the dashboard,
 * and settling real splits here would move that money.
 */
class MarketplaceDemoSeeder extends Seeder
{
    /**
     * The demo buyer whose order history drives the "recently paid" strip.
     * Kept out of the vendor list so the directory is never self-excluded.
     */
    private const BUYER_EMAIL = 'ayesha.rahman@digikash.test';

    /**
     * One approved business per category, keyed by the demo account that owns it.
     *
     * @var array<int, array{email: string, display_name: string, category: string, about: string, commission_pct_override: float|null, approved_days_ago: int, sales: int}>
     */
    private const VENDOR_BOOK = [
        [
            'email'                   => 'imran.hossain@digikash.test',
            'display_name'            => 'Nexa Electronics',
            'category'                => 'shops',
            'about'                   => 'Authorised reseller for phones, laptops and accessories. Same-day dispatch on every order placed before 4pm.',
            'commission_pct_override' => null,
            'approved_days_ago'       => 96,
            'sales'                   => 5,
        ],
        [
            'email'                   => 'sadia.islam@digikash.test',
            'display_name'            => 'Skyline Broadband',
            'category'                => 'internet_bills',
            'about'                   => 'Pay your fibre, electricity and water bills in one place. Confirmations arrive within two minutes.',
            'commission_pct_override' => 2.5,
            'approved_days_ago'       => 74,
            'sales'                   => 4,
        ],
        [
            'email'                   => 'mahin.chowdhury@digikash.test',
            'display_name'            => 'Meridian Studio',
            'category'                => 'services',
            'about'                   => 'Brand identity, packaging and print design for small businesses. Fixed-price packages, no hourly billing.',
            'commission_pct_override' => null,
            'approved_days_ago'       => 51,
            'sales'                   => 3,
        ],
        [
            'email'                   => 'priya.das@digikash.test',
            'display_name'            => 'Priya Das — Web Developer',
            'category'                => 'freelancers',
            'about'                   => 'Full-stack Laravel and Vue developer. Available for retainers, audits and one-off builds.',
            'commission_pct_override' => 7.5,
            'approved_days_ago'       => 23,
            'sales'                   => 2,
        ],
    ];

    /** Plausible order values so the directory never shows round placeholder numbers. */
    private const ORDER_AMOUNTS = [149.00, 84.50, 312.75, 47.20, 268.40, 96.00, 520.10, 133.65];

    public function run(): void
    {
        $accounts = $this->resolveDemoAccounts();

        if ($accounts->isEmpty()) {
            $this->command?->warn('MarketplaceDemoSeeder skipped: demo accounts (@digikash.test) were not found. Run DemoAccountSeeder first.');

            return;
        }

        $currency = Currency::query()->where('code', 'USD')->first() ?? Currency::query()->first();

        if (! $currency) {
            $this->command?->warn('MarketplaceDemoSeeder skipped: no currency is configured.');

            return;
        }

        $buyer = $accounts->get(self::BUYER_EMAIL);

        $this->backfillLegacyVendorCategories();

        $seededVendors = 0;
        $seededOrders  = 0;

        foreach (self::VENDOR_BOOK as $index => $spec) {
            $owner = $accounts->get($spec['email']);

            if (! $owner) {
                continue;
            }

            $vendor = $this->upsertVendor($owner, $spec);
            $seededVendors++;

            if ($buyer && $buyer->isNot($owner)) {
                $seededOrders += $this->seedReleasedOrders($buyer, $vendor, $currency->id, $spec['sales'], $index);
            }
        }

        $this->command?->info(sprintf(
            'Marketplace demo seeded: %d vendors, %d released orders. Directory now holds %d active vendors.',
            $seededVendors,
            $seededOrders,
            Vendor::query()->where('status', VendorStatus::ACTIVE->value)->count(),
        ));
    }

    /**
     * @return Collection<string, User>
     */
    private function resolveDemoAccounts(): Collection
    {
        $emails = array_merge(
            [self::BUYER_EMAIL],
            array_column(self::VENDOR_BOOK, 'email'),
        );

        return User::query()
            ->whereIn('email', $emails)
            ->where('role', UserRole::USER->value)
            ->get()
            ->keyBy('email');
    }

    /**
     * Vendors created before the category column existed render as "Others"
     * and sit outside every category filter. Give them a home without
     * touching any business that already chose one.
     */
    private function backfillLegacyVendorCategories(): void
    {
        Vendor::query()
            ->whereNull('category')
            ->update(['category' => 'shops']);
    }

    /**
     * @param array{display_name: string, category: string, about: string, commission_pct_override: float|null, approved_days_ago: int} $spec
     */
    private function upsertVendor(User $owner, array $spec): Vendor
    {
        return Vendor::query()->updateOrCreate(
            ['user_id' => $owner->id],
            [
                'display_name'            => $spec['display_name'],
                'category'                => $spec['category'],
                'about'                   => $spec['about'],
                'status'                  => VendorStatus::ACTIVE->value,
                'commission_pct_override' => $spec['commission_pct_override'],
                'approved_at'             => now()->subDays($spec['approved_days_ago']),
                'suspended_at'            => null,
                'suspend_reason'          => null,
            ],
        );
    }

    /**
     * Backdated released orders, spread over the last few months so the
     * directory's "sales" sort and the buyer's order history both look
     * lived-in. Re-running the seeder replaces this vendor's demo orders
     * rather than stacking a second set on top.
     */
    private function seedReleasedOrders(User $buyer, Vendor $vendor, int $currencyId, int $count, int $vendorIndex): int
    {
        MarketplaceOrder::query()
            ->where('vendor_id', $vendor->id)
            ->where('trx_reference', 'like', 'DEMO-MP-%')
            ->delete();

        $settings      = MarketplaceSetting::current();
        $commissionPct = $vendor->commission_pct_override !== null
            ? (float) $vendor->commission_pct_override
            : (float) ($settings?->commission_pct ?? 0);
        $commissionFixed = (float) ($settings?->commission_fixed ?? 0);

        for ($i = 0; $i < $count; $i++) {
            $gross      = self::ORDER_AMOUNTS[($vendorIndex * 2 + $i) % count(self::ORDER_AMOUNTS)];
            $commission = round($gross * $commissionPct / 100 + $commissionFixed, 2);
            $releasedAt = now()->subDays(7 + $vendorIndex * 11 + $i * 9)->subHours($i * 3);

            MarketplaceOrder::query()->create([
                'buyer_id'          => $buyer->id,
                'vendor_id'         => $vendor->id,
                'currency_id'       => $currencyId,
                'gross_amount'      => $gross,
                'commission_amount' => $commission,
                'vendor_net'        => round($gross - $commission, 2),
                'status'            => OrderStatus::RELEASED->value,
                'provider'          => 'wallet',
                'trx_reference'     => 'DEMO-MP-'.Str::upper(Str::random(10)),
                'released_at'       => $releasedAt,
                'created_at'        => $releasedAt,
                'updated_at'        => $releasedAt,
            ]);
        }

        return $count;
    }
}
