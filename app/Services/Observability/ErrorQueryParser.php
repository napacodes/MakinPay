<?php

namespace App\Services\Observability;

use App\Enums\Observability\ErrorLevel;
use App\Enums\Observability\ErrorSource;
use App\Enums\Observability\ErrorStatus;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The Issues search box: level:fatal source:gateway is:unresolved.
 *
 * This class fails soft, always. A token nobody recognises comes back marked
 * invalid and is left out of the query; it never throws and never silently
 * changes what the reader asked for. That distinction matters because the
 * alternative failure mode — quietly dropping a filter — shows an empty list
 * that looks like good news when it is really a typo.
 */
class ErrorQueryParser
{
    /**
     * Keys the syntax accepts. Anything else is an invalid pill.
     *
     * @var list<string>
     */
    private const KEYS = [
        'level',
        'source',
        'is',
        'status',
        'gateway',
        'assigned',
        'error_code',
        'release',
        'trace_id',
        'age',
    ];

    /**
     * Values the is: key accepts, on top of the six stored statuses.
     *
     * @var list<string>
     */
    private const IS_ALIASES = ['unresolved', 'resolved', 'ignored', 'assigned', 'unassigned'];

    /**
     * Break a query string into tokens, each one marked valid or not.
     *
     * @return array<int, array{raw: string, key: string, value: string, valid: bool, reason: string}>
     */
    public function parse(string $query): array
    {
        $tokens = [];

        foreach ($this->split($query) as $raw) {
            $tokens[] = $this->classify($raw);
        }

        return $tokens;
    }

    /**
     * Apply every valid token to the builder. Invalid ones are skipped here
     * and shown as invalid pills in the UI, so the list and the pills always
     * agree about what was actually filtered.
     *
     * @param array<int, array{raw: string, key: string, value: string, valid: bool, reason: string}> $tokens
     */
    public function apply(Builder $builder, array $tokens, ?int $currentAdminId = null): Builder
    {
        foreach ($tokens as $token) {
            if (! $token['valid']) {
                continue;
            }

            $this->applyToken($builder, $token['key'], $token['value'], $currentAdminId);
        }

        return $builder;
    }

    /**
     * The query string a saved view stores, rebuilt from its tokens so a
     * removed pill produces a clean string rather than a double space.
     *
     * @param array<int, array{raw: string}> $tokens
     */
    public function toQueryString(array $tokens): string
    {
        return Str::squish(implode(' ', array_column($tokens, 'raw')));
    }

    /**
     * Split on whitespace, keeping quoted values together so a free-text
     * search for two words stays one token.
     *
     * @return list<string>
     */
    private function split(string $query): array
    {
        $trimmed = trim($query);

        if ($trimmed === '') {
            return [];
        }

        if (preg_match_all('/"[^"]*"|\S+/', $trimmed, $matches) === false) {
            return [];
        }

        return array_values(array_filter($matches[0] ?? [], static fn (string $part): bool => $part !== ''));
    }

    /**
     * @return array{raw: string, key: string, value: string, valid: bool, reason: string}
     */
    private function classify(string $raw): array
    {
        if (! str_contains($raw, ':')) {
            return [
                'raw'    => $raw,
                'key'    => 'text',
                'value'  => trim($raw, '"'),
                'valid'  => true,
                'reason' => '',
            ];
        }

        [$key, $value] = explode(':', $raw, 2);

        $key   = mb_strtolower(trim($key));
        $value = trim($value, ' "');

        if (! in_array($key, self::KEYS, true)) {
            return [
                'raw'    => $raw,
                'key'    => $key,
                'value'  => $value,
                'valid'  => false,
                'reason' => __('Unknown filter :key.', ['key' => $key]),
            ];
        }

        if ($value === '') {
            return [
                'raw'    => $raw,
                'key'    => $key,
                'value'  => $value,
                'valid'  => false,
                'reason' => __('This filter needs a value.'),
            ];
        }

        $reason = $this->rejectValue($key, $value);

        return [
            'raw'    => $raw,
            'key'    => $key,
            'value'  => $value,
            'valid'  => $reason === '',
            'reason' => $reason,
        ];
    }

    /**
     * Why this value cannot be used, or an empty string when it can.
     */
    private function rejectValue(string $key, string $value): string
    {
        return match ($key) {
            'level' => in_array(mb_strtolower($value), array_merge(ErrorLevel::badges(), ErrorLevel::all()), true)
                ? ''
                : __('Unknown level :value.', ['value' => $value]),
            'source' => ErrorSource::tryFrom(mb_strtolower($value)) !== null
                ? ''
                : __('Unknown source :value.', ['value' => $value]),
            'is', 'status' => in_array(mb_strtolower($value), array_merge(self::IS_ALIASES, ErrorStatus::all()), true)
                ? ''
                : __('Unknown state :value.', ['value' => $value]),
            'age' => $this->parseAge($value) !== null
                ? ''
                : __('Use a duration such as -24h or -7d.'),
            default => '',
        };
    }

    private function applyToken(Builder $builder, string $key, string $value, ?int $currentAdminId): void
    {
        $lower = mb_strtolower($value);

        match ($key) {
            'level'  => $this->applyLevel($builder, $lower),
            'source' => $builder->where('source', $lower),
            'is', 'status' => $this->applyState($builder, $lower),
            'gateway'    => $builder->whereJsonContains('tags', 'gateway:'.$lower),
            'assigned'   => $this->applyAssigned($builder, $lower, $currentAdminId),
            'error_code' => $this->applyWildcard($builder, 'error_code', $value),
            'release'    => $builder->where('last_release', $value),
            'trace_id'   => $builder->whereHas('events', fn ($query) => $query->where('trace_id', $value)),
            'age'        => $builder->where('last_seen', '>=', $this->parseAge($value)),
            'text'       => $this->applyFreeText($builder, $value),
            default      => null,
        };
    }

    /**
     * level:fatal is one badge covering three stored PSR-3 names, so it
     * expands rather than matching the string literally.
     */
    private function applyLevel(Builder $builder, string $value): void
    {
        if (in_array($value, ErrorLevel::badges(), true)) {
            $builder->whereIn('level', ErrorLevel::namesForBadge($value));

            return;
        }

        $builder->where('level', $value);
    }

    private function applyState(Builder $builder, string $value): void
    {
        match ($value) {
            'unresolved' => $builder->whereIn('status', ErrorStatus::openValues()),
            'assigned'   => $builder->whereNotNull('assignee_id'),
            'unassigned' => $builder->whereNull('assignee_id'),
            default      => $builder->where('status', $value),
        };
    }

    private function applyAssigned(Builder $builder, string $value, ?int $currentAdminId): void
    {
        if ($value === 'me') {
            $builder->where('assignee_id', $currentAdminId);

            return;
        }

        if ($value === 'none') {
            $builder->whereNull('assignee_id');

            return;
        }

        $builder->whereHas('assignee', fn ($query) => $query->where(
            fn ($admin) => $admin->where('name', 'like', $value)->orWhere('email', 'like', $value)
        ));
    }

    /**
     * error_code:DK-PAY-* is the common case, so a trailing star becomes a
     * prefix match rather than a literal.
     */
    private function applyWildcard(Builder $builder, string $column, string $value): void
    {
        if (! str_contains($value, '*')) {
            $builder->where($column, $value);

            return;
        }

        $builder->where($column, 'like', str_replace('*', '%', $value));
    }

    private function applyFreeText(Builder $builder, string $value): void
    {
        $builder->where(function (Builder $inner) use ($value): void {
            $inner->where('title', 'like', '%'.$value.'%')
                ->orWhere('message', 'like', '%'.$value.'%')
                ->orWhere('culprit', 'like', '%'.$value.'%');
        });
    }

    /**
     * -24h, -7d, -30m. Returns null when the value is not a duration, which
     * is what marks the pill invalid.
     */
    private function parseAge(string $value): ?Carbon
    {
        if (preg_match('/^-?(\d+)([mhdw])$/i', trim($value), $matches) !== 1) {
            return null;
        }

        $amount = (int) $matches[1];

        return match (mb_strtolower($matches[2])) {
            'm'     => Carbon::now()->subMinutes($amount),
            'h'     => Carbon::now()->subHours($amount),
            'd'     => Carbon::now()->subDays($amount),
            'w'     => Carbon::now()->subWeeks($amount),
            default => null,
        };
    }
}
