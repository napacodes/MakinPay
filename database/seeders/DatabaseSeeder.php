<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $this->call([
            LanguageSeeder::class,
            PermissionTableSeeder::class,
            NotificationTuneSettingSeeder::class,
            P2PPaymentMethodSeeder::class,
            // Must precede every seeder that references a currency. The
            // installer creates only the buyer's base currency, so without
            // this the demo accounts have nowhere to hold a balance and the
            // payment methods below settle in currencies that do not exist.
            CurrencySeeder::class,
            DemoAccountSeeder::class,
        ]);
        //        $this->call(WithdrawScheduleSeeder::class);
        $this->call(VirtualCardProviderSeeder::class);
        // DemoCardholder must run *after* DemoAccountSeeder (uses its
        // user records) and after VirtualCardProviderSeeder (so any
        // provider-specific seed data already exists).
        $this->call(DemoCardholderSeeder::class);
        // Bitnob's KYC enumeration only accepts African ID types, so the
        // demo accounts also get a parallel set of Nigeria-based card-
        // holders for Bitnob sandbox issuance.
        $this->call(DemoBitnobCardholderSeeder::class);
        $this->call(WalletEarnPlanSeeder::class);
        $this->call(PaymentGatewaySeeder::class);
        $this->call(NotificationTemplateSeeder::class);
        $this->call(FeatureSeeder::class);
        $this->call(KycProviderSeeder::class);
        $this->call(AgentCommissionRuleSeeder::class);
        $this->call(SubscriptionPlanSeeder::class);
        $this->call(BitnobSeeder::class);
        $this->call(PaymentGatewayMethodSeeder::class);
        $this->call(CryptoManualMethodSeeder::class);
        $this->call(BitnobStablecoinMethodSeeder::class);
        $this->call(MobileRechargePluginSeeder::class);
        $this->call(MobileRechargeProviderSeeder::class);
        $this->call(GoldenThemeComponentSeeder::class);
        $this->call(EmeraldThemeComponentSeeder::class);
        $this->call(AuroraThemeComponentSeeder::class);
        $this->call(CustomLandingSeeder::class);
        $this->call(P2PPromotionPackageSeeder::class);
        // Depends on DemoAccountSeeder (traders), P2PPaymentMethodSeeder (methods),
        // and currency records — must run after all of them.
        $this->call(P2PDemoTradingSeeder::class);
    }
}
