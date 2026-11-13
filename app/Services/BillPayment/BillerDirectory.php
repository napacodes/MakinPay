<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Models\Biller;
use App\Models\BillPayment;
use App\Models\User;
use App\Support\HomeCountry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which billers a customer can pay, and where: the one definition the web
 * pages and the mobile API both read.
 *
 * Read-only. It answers "which countries have billers", "which country does
 * this customer open on", "what a biller query is narrowed to" and "how long
 * the customer should expect to wait", so the two surfaces cannot drift on
 * any of them.
 */
class BillerDirectory
{
    /** Category icon used when a category names none, or one that is not shipped. */
    public const FALLBACK_ICON = 'bill-other';

    /**
     * Restrict a biller query to billers a customer may pay in one country.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder $query
     * @return TBuilder
     */
    public function payableIn(Builder $query, string $country): Builder
    {
        return $query->payable()->where($query->getModel()->qualifyColumn('country'), $country);
    }

    /**
     * Every country with at least one payable biller, as ISO-2 codes in
     * alphabetical order.
     *
     * @return list<string>
     */
    public function payableCountries(): array
    {
        return Biller::query()
            ->payable()
            ->distinct()
            ->orderBy('country')
            ->pluck('country')
            ->map(fn (mixed $code): string => strtoupper(trim((string) $code)))
            ->filter(fn (string $code): bool => $this->isCountryCode($code))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The country a customer opens on: the one they asked for, then their own
     * country, then the operator's home country, then the first country that
     * has billers. Each is used only when it has billers, so a customer never
     * lands on an empty country while others are full.
     *
     * @param list<string> $countries the payable countries, from payableCountries()
     */
    public function defaultCountry(User $user, ?string $requested, array $countries): ?string
    {
        $candidates = [
            strtoupper(trim((string) $requested)),
            strtoupper(trim((string) $user->country)),
            strtoupper(trim((string) HomeCountry::code())),
        ];

        foreach ($candidates as $candidate) {
            if ($this->isCountryCode($candidate) && in_array($candidate, $countries, true)) {
                return $candidate;
            }
        }

        return $countries[0] ?? null;
    }

    /**
     * @param  list<string>                            $countries
     * @return list<array{code: string, name: string}>
     */
    public function countryOptions(array $countries): array
    {
        return array_map(fn (string $code): array => [
            'code' => $code,
            'name' => title((string) (getCountryByCode($code)['name'] ?? $code)),
        ], $countries);
    }

    /**
     * How long the customer should expect to wait: the biller's own note,
     * then its provider's (the payment's pinned provider when there is one).
     */
    public function processingNote(?Biller $biller, ?BillPayment $payment = null): ?string
    {
        $note = trim((string) $biller?->processing_note);

        if ($note === '') {
            $note = trim((string) ($payment?->provider?->processing_note ?? $biller?->provider?->processing_note));
        }

        return $note === '' ? null : $note;
    }

    /**
     * A category's icon key when it is one of the shipped SVGs, else the
     * generic bill icon. The key names a file, so nothing else is accepted.
     */
    public function categoryIconKey(?string $icon): string
    {
        return in_array($icon, (array) config('bill_payments.category_icons', []), true)
            ? (string) $icon
            : self::FALLBACK_ICON;
    }

    private function isCountryCode(string $code): bool
    {
        return preg_match('/^[A-Z]{2}$/', $code) === 1;
    }
}
