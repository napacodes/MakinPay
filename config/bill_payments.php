<?php

use App\Services\BillPayment\Drivers\Flutterwave\FlutterwaveBillPaymentProvider;
use App\Services\BillPayment\Drivers\ManualBillPaymentProvider;
use App\Services\BillPayment\Drivers\Reloadly\ReloadlyBillPaymentProvider;

/*
|--------------------------------------------------------------------------
| Bill payments
|--------------------------------------------------------------------------
|
| Whether the module is on is decided by the `bill_payment` key in
| config/feature_catalog.php (Feature Management), not here. This file only
| holds the fixed vocabulary the module is built from.
|
*/

return [

    /*
    | Driver key => class implementing the bill payment provider contract. A
    | provider's `driver` column must be one of these keys.
    */
    'drivers' => [
        'manual'      => ManualBillPaymentProvider::class,
        'reloadly'    => ReloadlyBillPaymentProvider::class,
        'flutterwave' => FlutterwaveBillPaymentProvider::class,
    ],

    /*
    | Driver key => the label the admin sees when choosing a provider's driver.
    | Every key of `drivers` has one.
    */
    'driver_labels' => [
        'manual'      => 'Manual (operator pays the biller)',
        'reloadly'    => 'Reloadly Utility Payments (API)',
        'flutterwave' => 'Flutterwave Bills (API, Nigeria)',
    ],

    /*
    | Catalogue sync: driver key => [provider category => bill_categories.code].
    | A provider category that is not listed here is never fetched or synced,
    | and the sync never creates a local category: a mapped code with no
    | category row is skipped and reported.
    */
    'category_map' => [
        'reloadly' => [
            'ELECTRICITY_BILL_PAYMENT' => 'electricity',
            'WATER_BILL_PAYMENT'       => 'water',
            'TV_BILL_PAYMENT'          => 'tv',
            'INTERNET_BILL_PAYMENT'    => 'internet',
        ],
        'flutterwave' => [
            'UTILITYBILLS' => 'electricity',
            'CABLEBILLS'   => 'tv',
            'INTSERVICE'   => 'internet',
        ],
    ],

    /*
    | Flutterwave lists no currency on a biller, so each country it can sync
    | names its billing currency here. Only Nigeria is documented; add a
    | country after a live check. A country missing here is skipped.
    */
    'flutterwave_country_currencies' => [
        'NG' => 'NGN',
    ],

    /*
    | How a provider catalogue is paged: rows per page, and the most pages one
    | country and category may take. A list longer than that is refused
    | rather than half read, because a half-read list would disable the
    | billers on the pages that were never fetched.
    */
    'sync' => [
        'page_size' => 100,
        'max_pages' => 50,
    ],

    /*
    | The category icons an admin may choose from. Each one is a static SVG in
    | public/general/static/svg, rendered through <x-icon>.
    */
    'category_icons' => [
        'bill-electricity',
        'bill-water',
        'bill-tv',
        'bill-internet',
        'bill-education',
        'bill-insurance',
        'bill-other',
    ],

    /*
    | How long an operator's claim on a payment holds before another operator
    | may take it without choosing "take over". Claims are advisory only.
    */
    'claim_ttl_minutes' => 30,

    /*
    | How long a provider's "this account exists" or "no such account" answer
    | is reused, so the pay form's check and the submit that follows ask the
    | provider once. An unavailable answer is never reused.
    */
    'customer_lookup_cache_minutes' => 10,

    /*
    | bill-payment:reconcile, which runs every five minutes.
    |
    |   stale_reserved_minutes    a reserved payment never handed to its
    |                             driver this long is refunded (never sent)
    |   stale_submitting_minutes  a payment stuck handing over this long is
    |                             recorded as an unclear answer
    |   backoff_minutes           the wait before each status check while a
    |                             provider is working; the last value repeats
    |   deadline_hours            how long after the hand-over a provider may
    |                             take, when it gave no final-status time
    |   grace_minutes             added to that deadline before a person is
    |                             asked to look
    |   max_attempts              status checks before a person is asked
    |   recheck_terminal_days     how long a provider notice or an admin check
    |                             may still ask about a resolved payment
    |   max_seconds               the default time budget of one run
    |
    | A payment the provider never resolves goes to review. It is never
    | refunded and never sent again on its own.
    */
    'reconcile' => [
        'stale_reserved_minutes'   => 10,
        'stale_submitting_minutes' => 10,
        'backoff_minutes'          => [5, 10, 15, 30, 60],
        'deadline_hours'           => 48,
        'grace_minutes'            => 30,
        'max_attempts'             => 60,
        'recheck_terminal_days'    => 7,
        'max_seconds'              => 240,
    ],

];
