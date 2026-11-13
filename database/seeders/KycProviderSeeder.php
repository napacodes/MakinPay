<?php

namespace Database\Seeders;

use App\Models\KycProvider;
use Illuminate\Database\Seeder;

class KycProviderSeeder extends Seeder
{
    /**
     * Seed disabled, credential-less example providers so admins have a
     * ready-made routing template to enable once their keys are added.
     */
    public function run(): void
    {
        $examples = [
            [
                'code'      => 'smile-africa',
                'name'      => 'Smile ID – Africa',
                'driver'    => 'smile_id',
                'countries' => ['NG', 'KE', 'GH', 'ZA', 'UG'],
                'priority'  => 20,
            ],
            [
                'code'      => 'shufti-global',
                'name'      => 'ShuftiPro – Global',
                'driver'    => 'shufti',
                'countries' => ['*'],
                'priority'  => 10,
            ],
            [
                'code'      => 'sumsub-enterprise',
                'name'      => 'Sumsub – Enterprise',
                'driver'    => 'sumsub',
                'countries' => ['GB', 'DE', 'FR', 'AE', 'SG'],
                'priority'  => 30,
            ],
        ];

        foreach ($examples as $example) {
            KycProvider::query()->updateOrCreate(
                ['code' => $example['code']],
                array_merge($example, [
                    'mode'        => 'sandbox',
                    'credentials' => null,
                    'status'      => false,
                ])
            );
        }
    }
}
