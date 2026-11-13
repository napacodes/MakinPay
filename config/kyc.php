<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automated KYC
    |--------------------------------------------------------------------------
    | The administrator switches automation on in KYC Verification Settings
    | (`kyc_auto_verification`); read it through KycAutomationSettings, never
    | from here directly. This value is only the fallback for an installation
    | whose settings rows have not been seeded yet, so a site that armed
    | automation through .env keeps working across the upgrade. Providers
    | themselves are configured per-country by admins in `kyc_providers`.
    */

    'auto' => (bool) env('KYC_AUTO_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | ISO 3166-1 alpha-2 -> alpha-3
    |--------------------------------------------------------------------------
    |
    | Sumsub requires alpha-3 on every document upload while this application
    | stores alpha-2 everywhere, and nothing already in the codebase converts
    | between them. It lives in config rather than in code so an operator can
    | correct or extend it for their own market without a release.
    |
    | A country that is NOT listed here is never guessed at: the submission is
    | routed to manual review instead, because sending a wrong country code
    | makes Sumsub reject the document with differentDocTypeOrCountry and the
    | applicant would wait on a verdict that is never coming.
    |
    */

    'iso3' => [
        'AD' => 'AND', 'AE' => 'ARE', 'AF' => 'AFG', 'AG' => 'ATG', 'AL' => 'ALB', 'AM' => 'ARM',
        'AO' => 'AGO', 'AR' => 'ARG', 'AT' => 'AUT', 'AU' => 'AUS', 'AZ' => 'AZE', 'BA' => 'BIH',
        'BB' => 'BRB', 'BD' => 'BGD', 'BE' => 'BEL', 'BF' => 'BFA', 'BG' => 'BGR', 'BH' => 'BHR',
        'BI' => 'BDI', 'BJ' => 'BEN', 'BN' => 'BRN', 'BO' => 'BOL', 'BR' => 'BRA', 'BS' => 'BHS',
        'BT' => 'BTN', 'BW' => 'BWA', 'BY' => 'BLR', 'BZ' => 'BLZ', 'CA' => 'CAN', 'CD' => 'COD',
        'CF' => 'CAF', 'CG' => 'COG', 'CH' => 'CHE', 'CI' => 'CIV', 'CL' => 'CHL', 'CM' => 'CMR',
        'CN' => 'CHN', 'CO' => 'COL', 'CR' => 'CRI', 'CU' => 'CUB', 'CV' => 'CPV', 'CY' => 'CYP',
        'CZ' => 'CZE', 'DE' => 'DEU', 'DJ' => 'DJI', 'DK' => 'DNK', 'DM' => 'DMA', 'DO' => 'DOM',
        'DZ' => 'DZA', 'EC' => 'ECU', 'EE' => 'EST', 'EG' => 'EGY', 'ER' => 'ERI', 'ES' => 'ESP',
        'ET' => 'ETH', 'FI' => 'FIN', 'FJ' => 'FJI', 'FR' => 'FRA', 'GA' => 'GAB', 'GB' => 'GBR',
        'GD' => 'GRD', 'GE' => 'GEO', 'GH' => 'GHA', 'GM' => 'GMB', 'GN' => 'GIN', 'GQ' => 'GNQ',
        'GR' => 'GRC', 'GT' => 'GTM', 'GW' => 'GNB', 'GY' => 'GUY', 'HN' => 'HND', 'HR' => 'HRV',
        'HT' => 'HTI', 'HU' => 'HUN', 'ID' => 'IDN', 'IE' => 'IRL', 'IL' => 'ISR', 'IN' => 'IND',
        'IQ' => 'IRQ', 'IR' => 'IRN', 'IS' => 'ISL', 'IT' => 'ITA', 'JM' => 'JAM', 'JO' => 'JOR',
        'JP' => 'JPN', 'KE' => 'KEN', 'KG' => 'KGZ', 'KH' => 'KHM', 'KM' => 'COM', 'KR' => 'KOR',
        'KW' => 'KWT', 'KZ' => 'KAZ', 'LA' => 'LAO', 'LB' => 'LBN', 'LK' => 'LKA', 'LR' => 'LBR',
        'LS' => 'LSO', 'LT' => 'LTU', 'LU' => 'LUX', 'LV' => 'LVA', 'LY' => 'LBY', 'MA' => 'MAR',
        'MD' => 'MDA', 'ME' => 'MNE', 'MG' => 'MDG', 'MK' => 'MKD', 'ML' => 'MLI', 'MM' => 'MMR',
        'MN' => 'MNG', 'MR' => 'MRT', 'MT' => 'MLT', 'MU' => 'MUS', 'MV' => 'MDV', 'MW' => 'MWI',
        'MX' => 'MEX', 'MY' => 'MYS', 'MZ' => 'MOZ', 'NA' => 'NAM', 'NE' => 'NER', 'NG' => 'NGA',
        'NI' => 'NIC', 'NL' => 'NLD', 'NO' => 'NOR', 'NP' => 'NPL', 'NZ' => 'NZL', 'OM' => 'OMN',
        'PA' => 'PAN', 'PE' => 'PER', 'PG' => 'PNG', 'PH' => 'PHL', 'PK' => 'PAK', 'PL' => 'POL',
        'PS' => 'PSE', 'PT' => 'PRT', 'PY' => 'PRY', 'QA' => 'QAT', 'RO' => 'ROU', 'RS' => 'SRB',
        'RU' => 'RUS', 'RW' => 'RWA', 'SA' => 'SAU', 'SC' => 'SYC', 'SD' => 'SDN', 'SE' => 'SWE',
        'SG' => 'SGP', 'SI' => 'SVN', 'SK' => 'SVK', 'SL' => 'SLE', 'SN' => 'SEN', 'SO' => 'SOM',
        'SR' => 'SUR', 'SS' => 'SSD', 'SV' => 'SLV', 'SY' => 'SYR', 'SZ' => 'SWZ', 'TD' => 'TCD',
        'TG' => 'TGO', 'TH' => 'THA', 'TJ' => 'TJK', 'TM' => 'TKM', 'TN' => 'TUN', 'TR' => 'TUR',
        'TT' => 'TTO', 'TW' => 'TWN', 'TZ' => 'TZA', 'UA' => 'UKR', 'UG' => 'UGA', 'US' => 'USA',
        'UY' => 'URY', 'UZ' => 'UZB', 'VE' => 'VEN', 'VN' => 'VNM', 'YE' => 'YEM', 'ZA' => 'ZAF',
        'ZM' => 'ZMB', 'ZW' => 'ZWE',
    ],

    /*
    | Risk-score thresholds (0 = clean .. 100 = high risk) used by the decision
    | engine to split provider results into auto-approve / auto-reject / manual.
    */

    'thresholds' => [
        'auto_approve_below' => (int) env('KYC_AUTO_APPROVE_BELOW', 20),
        'auto_reject_above'  => (int) env('KYC_AUTO_REJECT_ABOVE', 70),

        /*
        | How long an automated submission may sit untouched before
        | kyc:reconcile-pending asks the provider what became of it. The
        | webhook is the normal path; this is only for the ones that never
        | arrive, so the window is long enough not to race a callback that is
        | merely slow.
        */
        'reconcile_after_minutes' => (int) env('KYC_RECONCILE_AFTER_MINUTES', 30),

        /*
        | How long a submission may sit in the manual queue before the admin
        | queue counts it as breaching. A reading, not an enforcement: nothing
        | is auto-decided when it elapses, the row just sorts and tints as late.
        */
        'review_sla_hours' => (int) env('KYC_REVIEW_SLA_HOURS', 48),
    ],

    /*
    | Drivers admins may pick from when creating a provider row. The key is
    | stored in `kyc_providers.driver`; the label is shown in the admin UI.
    */

    'drivers' => [
        'smile_id' => 'Smile ID',
        'shufti'   => 'ShuftiPro',
        'sumsub'   => 'Sumsub',
    ],

    /*
    | Catalog metadata for the admin provider gallery. Every driver is always
    | shown as a card; the admin configures + activates exactly one at a time.
    */

    'catalog' => [
        'smile_id' => [
            'name'    => 'Smile ID',
            'tagline' => 'Africa-first identity verification with document + biometric checks.',
            'regions' => ['Nigeria', 'Kenya', 'Ghana', 'South Africa', 'Uganda'],
            'logo'    => 'backend/images/kyc/smile-id.svg',
            'icon'    => 'fa-solid fa-face-smile',
            'accent'  => 'emerald',
            'site'    => 'https://usesmileid.com',
        ],
        'shufti' => [
            'name'    => 'ShuftiPro',
            'tagline' => 'Global coverage (240+ countries) with OCR, face match and AML screening.',
            'regions' => ['Global', 'Middle East', 'Asia', 'Europe'],
            'logo'    => 'backend/images/kyc/shufti.svg',
            'icon'    => 'fa-solid fa-earth-americas',
            'accent'  => 'azure',
            'site'    => 'https://shuftipro.com',
        ],
        'sumsub' => [
            'name'    => 'Sumsub',
            'tagline' => 'Enterprise-grade KYC/AML with liveness, PEP and sanctions screening.',
            'regions' => ['Europe', 'UK', 'UAE', 'Singapore', 'Global'],
            'logo'    => 'backend/images/kyc/sumsub.svg',
            'icon'    => 'fa-solid fa-building-shield',
            'accent'  => 'violet',
            'site'    => 'https://sumsub.com',
        ],
    ],

];
