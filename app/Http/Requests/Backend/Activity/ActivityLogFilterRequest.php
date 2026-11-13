<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\Activity;

use App\Enums\LoginRisk;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filters the Activity history screen sends, on the page and on the
 * export.
 *
 * Every one of them is a GET parameter that also has to survive pagination
 * links, so they are validated in one place rather than trusted twice.
 */
class ActivityLogFilterRequest extends FormRequest
{
    /** The row counts the footer's select offers. */
    public const PER_PAGE = [25, 50, 100];

    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'type'   => ['nullable', 'string', Rule::in(UserRole::all())],
            'risk'   => ['nullable', 'string', Rule::in([...LoginRisk::all(), 'flagged'])],
            /*
             * The picker writes exactly this, and nothing else may reach the
             * query. `range` is this screen's field; `daterange` is the shared
             * filter key the controller maps it onto, accepted here so a
             * hand-typed or bookmarked URL is validated the same way.
             */
            'range'     => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2},\d{4}-\d{2}-\d{2}$/'],
            'daterange' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2},\d{4}-\d{2}-\d{2}$/'],
            'per_page'  => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
        ];
    }

    public function perPage(): int
    {
        $requested = (int) $this->input('per_page', self::PER_PAGE[0]);

        return in_array($requested, self::PER_PAGE, true) ? $requested : self::PER_PAGE[0];
    }

    /**
     * The filters that are actually set, for the chip row and the export link.
     *
     * @return array<string, string>
     */
    public function activeFilters(): array
    {
        return array_filter([
            'search' => (string) $this->input('search', ''),
            'type'   => (string) $this->input('type', ''),
            'risk'   => (string) $this->input('risk', ''),
            'range'  => (string) $this->input('range', ''),
        ], static fn (string $value): bool => $value !== '');
    }
}
