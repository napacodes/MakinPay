<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Contracts\BillPayment\ValidatesBillCustomer;
use App\Enums\BillPayment\BillCustomerLookupStatus;
use App\Enums\BillPayment\BillerAmountType;
use App\Http\Requests\Backend\BillPayment\StoreBillPaymentProviderRequest;
use App\Models\Biller;
use App\Models\BillerItem;
use App\Models\BillPaymentProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Asks a biller's provider who owns a customer number, before any money moves.
 *
 * A lookup is advice about the account, never about money. The provider's
 * `config.customer_lookup` policy decides what an answer does at submit:
 *
 *   advisory  (the default) a definite NotFound blocks; an outage lets it through
 *   required  NotFound and an outage both block
 *   off       the provider is never asked
 *
 * A biller is checked only when the policy is not `off`, its driver class
 * implements {@see ValidatesBillCustomer} and the driver says this biller (or
 * item) can be looked up. Anything else answers Verified with no name and no
 * call: "not checked", which never blocks. {@see self::willCheck()} tells the
 * two apart for a caller that has to.
 *
 * Verified and NotFound are cached for `customer_lookup_cache_minutes`, so the
 * blur check on the pay form and the submit that follows ask the provider
 * once. The cache key carries a hash of the number, never the number itself;
 * Unavailable is never cached. Neither the number nor the account name is
 * ever logged.
 */
class BillCustomerVerifier
{
    public const CACHE_PREFIX = 'bill_customer_lookup:';

    public function __construct(
        private readonly BillPaymentProviderManager $providers,
        private readonly BillerRules $rules,
    ) {}

    /**
     * The verify endpoints' question: the number as the customer typed it,
     * and the package they picked, if any.
     *
     * The number is normalised and checked against the biller's own rules
     * first, exactly as submit() does, so the answer is cached under the key
     * the submit that follows reads. Null means "not checked": the biller is
     * not looked up, or it takes a package and none is picked yet.
     *
     * @throws ValidationException on `customer_number` or `biller_item_id`
     */
    public function verifyEntered(Biller $biller, ?int $billerItemId, string $customerNumber): ?BillCustomerLookup
    {
        $number = $this->rules->normalizeCustomerNumber($customerNumber);
        $this->rules->assertCustomerNumber($biller, $number);

        if ($biller->amount_type === BillerAmountType::Fixed && $billerItemId === null) {
            return null;
        }

        $item = $this->rules->resolveItem($biller, $billerItemId);

        if (! $this->willCheck($biller, $item)) {
            return null;
        }

        return $this->verify($biller, $item, $number);
    }

    /**
     * Who owns this customer number at the biller's provider.
     *
     * Never throws. A driver that cannot be built is "not checked", like one
     * with no lookup, so a broken setup changes nothing at submit; a driver
     * that throws despite its contract is Unavailable.
     */
    public function verify(Biller $biller, ?BillerItem $item, string $customerNumber): BillCustomerLookup
    {
        $driver = $this->lookupDriver($biller, $item);

        if ($driver === null) {
            return BillCustomerLookup::verified();
        }

        $provider = $biller->provider;
        $cacheKey = self::cacheKey((int) $provider->id, (string) $biller->external_code, $item?->external_code, $customerNumber);
        $cached   = $this->cached($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $lookup = $driver->lookupCustomer(new BillCustomerQuery(
                billerExternalCode: (string) $biller->external_code,
                itemCode: $item?->external_code,
                country: strtoupper((string) $biller->country),
                customerNumber: $customerNumber,
            ));
        } catch (Throwable $exception) {
            // The class only: a transport message can carry the request URL,
            // and the customer number travels in it.
            Log::warning('Bill payment customer lookup threw', [
                'biller_id'                => $biller->id,
                'bill_payment_provider_id' => $provider->id,
                'exception'                => $exception::class,
            ]);

            return BillCustomerLookup::unavailable();
        }

        if ($lookup->status !== BillCustomerLookupStatus::Unavailable) {
            Cache::put($cacheKey, [
                'status' => $lookup->status->value,
                'name'   => $lookup->customerName,
            ], now()->addMinutes(max(1, (int) config('bill_payments.customer_lookup_cache_minutes', 10))));
        }

        return $lookup;
    }

    /**
     * Whether verify() would ask the provider about this biller or item, as
     * opposed to answering "not checked". Pure: no I/O beyond building the
     * driver, which sends nothing.
     */
    public function willCheck(Biller $biller, ?BillerItem $item): bool
    {
        return $this->lookupDriver($biller, $item) !== null;
    }

    /**
     * Whether any account of this biller may be looked up: the provider's
     * policy is not `off` and its driver can look accounts up. Decided from
     * the rows and the driver class alone, so the pay page can ask it to
     * decide whether to offer the check at all. Whether one package can be
     * looked up is willCheck()'s question.
     */
    public function offersLookup(Biller $biller): bool
    {
        $provider = $biller->provider;

        return $provider                   !== null
            && $this->policyFor($provider) !== 'off'
            && $this->driverLooksUp($provider);
    }

    /**
     * The provider's lookup policy. A provider saved before the setting
     * existed, or holding a value that is not a policy, is `advisory`.
     */
    public function policyFor(?BillPaymentProvider $provider): string
    {
        $config = is_array($provider?->config) ? $provider->config : [];
        $policy = $config['customer_lookup'] ?? null;

        return is_string($policy) && in_array($policy, StoreBillPaymentProviderRequest::CUSTOMER_LOOKUP_POLICIES, true)
            ? $policy
            : StoreBillPaymentProviderRequest::CUSTOMER_LOOKUP_DEFAULT;
    }

    /**
     * The cache key for one lookup. The number is hashed with the biller and
     * item codes, so the key never holds it in the clear.
     */
    public static function cacheKey(int $providerId, string $billerExternalCode, ?string $itemCode, string $customerNumber): string
    {
        return self::CACHE_PREFIX.$providerId.':'.hash('sha256', $billerExternalCode.'|'.(string) $itemCode.'|'.$customerNumber);
    }

    /**
     * The driver that will look this biller up, or null when it will not be
     * looked up at all. The driver class is checked before it is built, so a
     * driver with no lookup is never constructed for nothing.
     */
    private function lookupDriver(Biller $biller, ?BillerItem $item): ?ValidatesBillCustomer
    {
        if (! $this->offersLookup($biller)) {
            return null;
        }

        $provider = $biller->provider;

        try {
            $driver = $this->providers->driverFor($provider);
        } catch (Throwable $exception) {
            Log::warning('Bill payment customer lookup driver could not be built', [
                'biller_id'                => $biller->id,
                'bill_payment_provider_id' => $provider->id,
                'exception'                => $exception::class,
            ]);

            return null;
        }

        if (! $driver instanceof ValidatesBillCustomer || ! $driver->supportsCustomerLookup($biller, $item)) {
            return null;
        }

        return $driver;
    }

    /** Whether the provider's registered driver class can look accounts up. */
    private function driverLooksUp(BillPaymentProvider $provider): bool
    {
        $class = ((array) config('bill_payments.drivers', []))[(string) $provider->driver] ?? null;

        return is_string($class) && is_a($class, ValidatesBillCustomer::class, true);
    }

    /** A cached Verified or NotFound answer, or null when there is none worth trusting. */
    private function cached(string $cacheKey): ?BillCustomerLookup
    {
        $value = Cache::get($cacheKey);

        if (! is_array($value)) {
            return null;
        }

        $status = BillCustomerLookupStatus::tryFrom((string) ($value['status'] ?? ''));
        $name   = isset($value['name']) && is_string($value['name']) ? $value['name'] : null;

        return match ($status) {
            BillCustomerLookupStatus::Verified => BillCustomerLookup::verified($name),
            BillCustomerLookupStatus::NotFound => BillCustomerLookup::notFound(),
            default                            => null,
        };
    }
}
