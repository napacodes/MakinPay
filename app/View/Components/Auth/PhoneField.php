<?php

declare(strict_types=1);

namespace App\View\Components\Auth;

use App\Support\HomeCountry;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The phone field of the customer sign-in forms: a country picker beside the
 * number, the home country pinned at the top of the list.
 *
 * It posts two fields: the number as typed (`login`) and the picked country's
 * ISO-2 code (`login_country`). The server reads a number typed under the
 * home country locally, exactly as before, and puts a number typed under any
 * other country into that country's international form
 * (App\Http\Requests\Concerns\ReadsLoginCountry).
 *
 * The picker starts on the country posted last, else the home country, else
 * nothing - with no home country chosen there is no sensible guess, and the
 * hint then asks for the + code instead.
 *
 * Props:
 *   - id        : the number input's DOM id
 *   - value     : the number to show, when there is one to keep
 *   - golden    : true on the Golden theme, which restyles the same markup
 *   - disabled  : true when the field sits in an inactive tab, so the form
 *                 posts the other tab's `login` and no country at all
 */
class PhoneField extends Component
{
    /**
     * @var array{code: string, name: string, dial_code: string}|null
     */
    public ?array $home;

    /**
     * @var list<array{code: string, name: string, dial: string}>
     */
    public array $countries;

    /**
     * @var array{code: string, name: string, dial: string}|null
     */
    public ?array $selected;

    public function __construct(
        public string $id = 'login-phone',
        public string $name = 'login',
        public string $countryName = 'login_country',
        public ?string $value = null,
        public bool $golden = false,
        public bool $disabled = false,
        public bool $autofocus = false,
    ) {
        $this->home      = HomeCountry::country();
        $this->countries = $this->countryList();
        $this->selected  = $this->find((string) old($this->countryName, $this->home['code'] ?? ''));
    }

    /**
     * Whether the picked country is the home one.
     */
    public function isLocal(): bool
    {
        return $this->selected !== null && $this->home !== null && $this->selected['code'] === $this->home['code'];
    }

    /**
     * Every country but home, by name.
     *
     * @return list<array{code: string, name: string, dial: string}>
     */
    public function others(): array
    {
        return array_values(array_filter($this->countries, fn (array $country): bool => $country['code'] !== ($this->home['code'] ?? null)));
    }

    public function render(): View|Closure|string
    {
        return view('components.auth.phone-field');
    }

    /**
     * @return list<array{code: string, name: string, dial: string}>
     */
    private function countryList(): array
    {
        $countries = [];

        foreach (getCountries() as $country) {
            $code = strtoupper(trim((string) ($country['code'] ?? '')));
            $dial = trim((string) ($country['dial_code'] ?? ''));

            if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || $dial === '') {
                continue;
            }

            $countries[] = ['code' => $code, 'name' => (string) ($country['name'] ?? $code), 'dial' => $dial];
        }

        usort($countries, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $countries;
    }

    /**
     * @return array{code: string, name: string, dial: string}|null
     */
    private function find(string $code): ?array
    {
        $code = strtoupper(trim($code));

        foreach ($this->countries as $country) {
            if ($country['code'] === $code) {
                return $country;
            }
        }

        return null;
    }
}
