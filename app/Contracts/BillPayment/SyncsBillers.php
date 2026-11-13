<?php

declare(strict_types=1);

namespace App\Contracts\BillPayment;

use App\Exceptions\BillerSyncException;
use App\Services\BillPayment\Sync\SyncedBiller;

/**
 * A bill payment driver that can list its provider's billers, so an operator
 * can import the catalogue instead of typing it.
 *
 * Optional, checked with instanceof. The driver only reads and maps; what is
 * written, what the admin keeps, and what is disabled is decided by the
 * synchroniser, which never deletes a biller and never enables one.
 */
interface SyncsBillers
{
    /**
     * The countries this driver can list billers for.
     *
     * @return list<string> ISO 3166-1 alpha-2 codes; an empty list means any country
     */
    public function syncableCountries(): array;

    /**
     * The provider's own category codes this driver knows how to map to a
     * local bill category.
     *
     * @return list<string>
     */
    public function providerCategories(): array;

    /**
     * Every biller the provider lists for one country, in the given provider
     * categories.
     *
     * A biller the synchroniser must not import is still yielded, with its
     * skipReason set, so the report can count it.
     *
     * @param  list<string>           $providerCategories
     * @return iterable<SyncedBiller>
     *
     * @throws BillerSyncException on a transport or parse error; the caller then writes nothing
     */
    public function fetchBillers(string $country, array $providerCategories): iterable;
}
