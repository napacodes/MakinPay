<?php

namespace App\Services\Observability;

use App\Enums\Observability\RedactionVerdict;

/**
 * The single place a field is judged before it is written.
 *
 * Both the collector and the debug report builder run through this class, so an
 * export can never be less strict than capture, and tightening a rule here
 * tightens both at once.
 *
 * Three verdicts do the work:
 *
 *   DROPPED — removed entirely, nothing to reverse. PANs, CVVs, API keys,
 *             bearer tokens, webhook signatures, session material.
 *   MASKED  — shape kept, value hidden, so the record is still debuggable.
 *             Emails keep the domain, phones keep the country code, IPs keep
 *             three octets, names become initials.
 *   KEPT    — internal identifiers, not personal data.
 *
 * A fourth, UNCLASSIFIED, catches the long opaque string no rule recognises.
 * It is dropped at capture like anything else suspicious, but its path is
 * recorded, because a strict-mode export must refuse rather than hand a reader
 * a bundle whose contents nobody can vouch for.
 *
 * Nothing in here may throw. It runs inside the collector, which runs while
 * something else is already going wrong.
 */
class RedactionPipeline
{
    /**
     * Field names whose value never reaches disk in any form.
     *
     * A bare "token" is a bill payment token (a prepaid-meter code or a
     * voucher PIN) on an operator's mark-paid request: what the customer paid
     * for, and too short for the opaque-value rule to catch.
     */
    private const DROP_KEYS = '/(password|passwd|secret|api[_\-]?key|apikey|access[_\-]?token|refresh[_\-]?token|id[_\-]?token|bearer|authorization|auth[_\-]?token|signature|webhook[_\-]?secret|private[_\-]?key|client[_\-]?secret|encryption[_\-]?key|salt|cvv|cvc|csc|card[_\-]?number|cardnumber|card[_\-]?token|^token$|^pan$|(^|[_\-])pin([_\-]|$)|otp|one[_\-]?time|verification[_\-]?code|account[_\-]?no|totp|two[_\-]?factor|2fa|google2fa|security[_\-]?code|iban|swift|routing[_\-]?number|account[_\-]?number|^ssn$|tax[_\-]?id|session|cookie|csrf|_token|remember[_\-]?token|credentials?)/i';

    /**
     * Field names kept in shape but not in substance. A bill payment's
     * customer number is a meter, smartcard or account number that names one
     * household, so it is masked like a phone number.
     */
    private const MASK_KEYS = '/(e?mail|phone|mobile|msisdn|contact[_\-]?number|customer[_\-]?number|meter[_\-]?number|smartcard|^ip$|ip[_\-]?address|remote[_\-]?addr|first[_\-]?name|last[_\-]?name|full[_\-]?name|^name$|holder[_\-]?name|sender[_\-]?name|recipient[_\-]?name|beneficiary|address|street|city|postcode|postal|^zip$|^dob$|date[_\-]?of[_\-]?birth|birth|passport|^nid$|national[_\-]?id|document[_\-]?number|latitude|longitude)/i';

    /**
     * Field names that are internal identifiers, checked before the value
     * heuristics so a numeric order id is never mistaken for a card number.
     */
    private const KEEP_KEYS = '/(^id$|_id$|_ids$|^uuid$|^ulid$|gateway|provider|^route$|^method$|^status$|error_code|^level$|^source$|^release$|commit|branch|environment|^php$|^laravel$|^host$|version|duration|^ms$|_ms$|count|trace_id|span_id|^line$|^file$|^class$|^function$|^queue$|attempts|worker|currency|^locale$|timezone)/i';

    /**
     * Request headers are an allow-list. An unknown header is dropped in
     * strict mode rather than guessed at, because the next integration to
     * invent one will put a token in it.
     *
     * @var list<string>
     */
    private const HEADER_ALLOW_LIST = [
        'accept',
        'accept-encoding',
        'accept-language',
        'cache-control',
        'connection',
        'content-length',
        'content-type',
        'dnt',
        'host',
        'origin',
        'pragma',
        'referer',
        'sec-fetch-dest',
        'sec-fetch-mode',
        'sec-fetch-site',
        'sec-fetch-user',
        'traceparent',
        'tracestate',
        'upgrade-insecure-requests',
        'user-agent',
        'x-forwarded-proto',
        'x-request-id',
        'x-requested-with',
        'x-trace-id',
    ];

    /**
     * Value prefixes that identify a credential no matter what it is called.
     *
     * @var list<string>
     */
    private const CREDENTIAL_PREFIXES = [
        'sk_live_',
        'sk_test_',
        'rk_live_',
        'rk_test_',
        'whsec_',
        'shpss_',
        'xoxb-',
        'xoxp-',
        'ghp_',
        'AKIA',
        'ASIA',
        'Bearer ',
        'Basic ',
        '-----BEGIN',
    ];

    /**
     * Every judgement made during the current pass.
     *
     * @var array<int, array{path: string, verdict: string}>
     */
    private array $judgements = [];

    public function __construct(private bool $strict = true) {}

    /**
     * Start a fresh pass. The report belongs to one captured event, not to
     * the lifetime of the container binding.
     */
    public function reset(): self
    {
        $this->judgements = [];

        return $this;
    }

    public function strict(bool $strict = true): self
    {
        $this->strict = $strict;

        return $this;
    }

    /**
     * Walk an arbitrary payload, replacing every field the rules condemn.
     *
     * Keys are always preserved so the shape of the payload stays readable —
     * knowing that a card_number was present and removed is a different, more
     * useful fact than the key silently not being there.
     */
    public function scrub(mixed $value, string $path = ''): mixed
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $item) {
                $childPath = $path === '' ? (string) $key : $path.'.'.$key;

                if (is_array($item)) {
                    $out[$key] = $this->scrub($item, $childPath);

                    continue;
                }

                $out[$key] = $this->apply((string) $key, $item, $childPath);
            }

            return $out;
        }

        return $this->apply($this->leafKey($path), $value, $path);
    }

    /**
     * An exception message is written as prose, so it never went through the
     * field rules — and Laravel writes the bound values into a QueryException
     * ("... (Connection: mysql, SQL: insert into users ... values (jane@x.com,
     * $2y$...))") while Guzzle appends the provider's response body to a
     * RequestException, tokens and all. Both suffixes are cut before the
     * message is stored or alerted, and every remaining token is judged by the
     * value rules so a card number or credential in the prose is dropped too.
     */
    public function scrubMessage(string $message, string $path = 'message'): string
    {
        $message = preg_replace('/\s*\((?:Connection: [^,)]+, )?SQL: .*\)\s*$/s', ' (SQL redacted)', $message) ?? $message;

        if (preg_match('/^(.*? response):\s*\R/s', $message, $matches) === 1) {
            $message = $matches[1].': [body redacted]';
        }

        $parts = preg_split('/(\s+)/', $message, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$message];

        foreach ($parts as $index => $part) {
            $token = trim($part, "\"'`,;:()[]{}<>");

            if ($token === '' || preg_match('/^\s*$/', $part) === 1) {
                continue;
            }

            $judged = $this->applyValueRules($token, $path);

            if ($judged !== $token) {
                $parts[$index] = str_replace($token, (string) $judged, $part);
            }
        }

        return implode('', $parts);
    }

    /**
     * Allow-list headers. Everything outside the list is dropped in strict
     * mode and masked otherwise, and the deny rules still run over what
     * survives so an allow-listed header carrying a token is still caught.
     *
     * @param  array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public function scrubHeaders(array $headers, string $path = 'request.headers'): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            $normalised = strtolower((string) $name);
            $childPath  = $path.'.'.$normalised;
            $flat       = is_array($value) ? implode(', ', array_map('strval', $value)) : $value;

            if (! in_array($normalised, self::HEADER_ALLOW_LIST, true)) {
                $verdict          = $this->strict ? RedactionVerdict::Dropped : RedactionVerdict::Masked;
                $out[$normalised] = $this->record($childPath, $verdict, $flat);

                continue;
            }

            $out[$normalised] = $this->apply($normalised, $flat, $childPath);
        }

        return $out;
    }

    /**
     * Scrub query bindings by the column each one belongs to.
     *
     * A hashed password binding must not appear even in a development log, so
     * a binding whose column cannot be recovered from the SQL is judged on its
     * content alone rather than kept on the assumption that it is harmless.
     *
     * @param  list<mixed> $bindings
     * @return list<mixed>
     */
    public function scrubBindings(array $bindings, ?string $sql = null, string $path = 'state.queries.bindings'): array
    {
        $columns = $this->columnsFromSql((string) $sql);
        $out     = [];

        foreach (array_values($bindings) as $index => $binding) {
            $column    = $columns[$index] ?? '';
            $childPath = $path.'.'.$index;

            $out[] = $this->apply($column, $binding, $childPath);
        }

        return $out;
    }

    /**
     * Frame arguments, judged by the namespace the frame belongs to.
     *
     * Every argument inside a payment, gateway, card or KYC namespace is
     * dropped whole. There is no safe subset there: the arguments are card
     * data, provider credentials and identity documents by definition, and a
     * per-key rule would only have to be right every single time.
     *
     * @param  array<int|string, mixed> $args
     * @return array<int|string, mixed>
     */
    public function scrubFrameArgs(array $args, ?string $file = null, string $path = 'context.stack.args'): array
    {
        if ($this->isSensitiveNamespace((string) $file)) {
            $out = [];

            foreach (array_keys($args) as $key) {
                $out[$key] = $this->record($path.'.'.$key, RedactionVerdict::Dropped, null);
            }

            return $out;
        }

        return (array) $this->scrub($args, $path);
    }

    /**
     * What the pass decided, in the shape the export carries and the UI
     * lists under "What was redacted".
     *
     * @return array{dropped: int, masked: int, unclassified: int, fields: array<int, array{path: string, verdict: string}>}
     */
    public function report(): array
    {
        $counts = array_count_values(array_column($this->judgements, 'verdict'));

        return [
            'dropped'      => $counts[RedactionVerdict::Dropped->value]      ?? 0,
            'masked'       => $counts[RedactionVerdict::Masked->value]       ?? 0,
            'unclassified' => $counts[RedactionVerdict::Unclassified->value] ?? 0,
            'fields'       => array_values(array_filter(
                $this->judgements,
                static fn (array $judgement): bool => $judgement['verdict'] !== RedactionVerdict::Kept->value
            )),
        ];
    }

    /**
     * Judge one scalar and return what should be stored in its place.
     */
    private function apply(string $key, mixed $value, string $path): mixed
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }

        if ($key !== '' && preg_match(self::DROP_KEYS, $key) === 1) {
            return $this->record($path, RedactionVerdict::Dropped, $value);
        }

        /*
         * A bare "code" is a currency or provider code on most records and a
         * one-time password on a two-factor or cash-out request. Digits alone
         * are the second kind, and that value never reaches disk.
         */
        if (strtolower($key) === 'code' && preg_match('/^[0-9]{4,10}$/', (string) $value) === 1) {
            return $this->record($path, RedactionVerdict::Dropped, $value);
        }

        if ($key !== '' && preg_match(self::KEEP_KEYS, $key) === 1) {
            return $this->keep($path, $value);
        }

        if ($key !== '' && preg_match(self::MASK_KEYS, $key) === 1) {
            /*
             * A bare "name" is ambiguous: it is a person on a KYC form and a
             * class on a failed job. Masking both would turn
             * App\Jobs\SettleMerchantPayout into "A." and cost the reader the
             * one fact the record existed to carry, so the bare key masks only
             * when the value actually reads as a human name. The explicit
             * first_name, last_name and holder_name keys are not in doubt and
             * skip this check.
             */
            if (strtolower($key) === 'name' && ! $this->looksLikeHumanName((string) $value)) {
                return $this->keep($path, $value);
            }

            return $this->record($path, RedactionVerdict::Masked, $value, $key);
        }

        if (! is_string($value)) {
            return $this->keep($path, $value);
        }

        return $this->applyValueRules($value, $path);
    }

    /**
     * Content-based rules, for the field whose name gave nothing away.
     */
    private function applyValueRules(string $value, string $path): mixed
    {
        foreach (self::CREDENTIAL_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return $this->record($path, RedactionVerdict::Dropped, $value);
            }
        }

        if (preg_match('/^ey[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]*$/', $value) === 1) {
            return $this->record($path, RedactionVerdict::Dropped, $value);
        }

        if ($this->looksLikeCardNumber($value)) {
            return $this->record($path, RedactionVerdict::Dropped, $value);
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return $this->record($path, RedactionVerdict::Masked, $value, 'email');
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $this->record($path, RedactionVerdict::Masked, $value, 'ip');
        }

        if (preg_match('/^\+[0-9][0-9 \-().]{7,17}$/', $value) === 1) {
            return $this->record($path, RedactionVerdict::Masked, $value, 'phone');
        }

        if ($this->looksOpaque($value)) {
            return $this->record($path, RedactionVerdict::Unclassified, $value);
        }

        return $this->keep($path, $value);
    }

    private function keep(string $path, mixed $value): mixed
    {
        $this->judgements[] = ['path' => $path, 'verdict' => RedactionVerdict::Kept->value];

        return $value;
    }

    /**
     * Record a judgement and return the replacement value.
     */
    private function record(string $path, RedactionVerdict $verdict, mixed $value, string $hint = ''): string
    {
        $this->judgements[] = ['path' => $path, 'verdict' => $verdict->value];

        if ($verdict !== RedactionVerdict::Masked) {
            return $verdict->placeholder();
        }

        return $this->mask((string) $value, $hint !== '' ? $hint : $path);
    }

    /**
     * Keep enough shape to debug with, and nothing that identifies a person.
     */
    private function mask(string $value, string $hint): string
    {
        if ($value === '') {
            return RedactionVerdict::Masked->placeholder();
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            [, $domain] = explode('@', $value, 2);

            return '***@'.$domain;
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets    = explode('.', $value);
            $octets[3] = 'x';

            return implode('.', $octets);
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return implode(':', array_slice(explode(':', $value), 0, 4)).'::x';
        }

        if (preg_match('/phone|mobile|msisdn|contact/i', $hint) === 1 || preg_match('/^\+[0-9]/', $value) === 1) {
            return $this->maskPhone($value);
        }

        if (preg_match('/name/i', $hint) === 1) {
            return $this->maskName($value);
        }

        return RedactionVerdict::Masked->placeholder();
    }

    /**
     * Country code survives, the subscriber number does not. Enough to see
     * that a Nigerian number failed where a British one did not.
     */
    private function maskPhone(string $value): string
    {
        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';
        $plus   = str_starts_with(trim($value), '+') ? '+' : '';
        $keep   = substr($digits, 0, min(3, max(1, strlen($digits) - 4)));

        return $plus.$keep.str_repeat('*', max(0, strlen($digits) - strlen($keep)));
    }

    /**
     * Letters, spaces, apostrophes and hyphens, up to five words. A class
     * name, a route name and a currency code all fail this; a person does
     * not.
     */
    private function looksLikeHumanName(string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return false;
        }

        return preg_match("/^\p{L}[\p{L}'\-]*(\s+\p{L}[\p{L}'\-]*){0,4}$/u", $trimmed) === 1;
    }

    private function maskName(string $value): string
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];

        $initials = array_map(
            static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)).'.',
            array_filter($parts, static fn (string $part): bool => $part !== '')
        );

        return $initials === [] ? RedactionVerdict::Masked->placeholder() : implode(' ', $initials);
    }

    /**
     * Thirteen to nineteen digits that pass Luhn. Order ids and timestamps
     * do not, which is what keeps this from eating the whole payload.
     */
    private function looksLikeCardNumber(string $value): bool
    {
        $digits = preg_replace('/[ \-]/', '', $value) ?? '';

        if (preg_match('/^[0-9]{13,19}$/', $digits) !== 1) {
            return false;
        }

        $sum    = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    /**
     * A long mixed-case token-shaped string nobody named. Not proof of a
     * secret, which is exactly why it is flagged rather than assumed safe.
     */
    private function looksOpaque(string $value): bool
    {
        $length = strlen($value);

        if ($length < 32 || $length > 4096) {
            return false;
        }

        if (preg_match('/^[A-Za-z0-9+\/=_\-]+$/', $value) !== 1) {
            return false;
        }

        return preg_match('/[A-Za-z]/', $value) === 1 && preg_match('/[0-9]/', $value) === 1;
    }

    /**
     * Frames whose arguments are card data, provider credentials or identity
     * documents by construction.
     */
    private function isSensitiveNamespace(string $file): bool
    {
        return preg_match('#[\\\\/](Payment|Gateway|VirtualCard|Card|Kyc|KYC|Crypto|Bitnob|Stripe|Remittance)[\\\\/]#', $file) === 1;
    }

    /**
     * Recover column names from a statement so a positional binding can be
     * judged by the column it fills. Deliberately shallow — a binding whose
     * column is not obvious falls through to the content rules instead.
     *
     * @return array<int, string>
     */
    private function columnsFromSql(string $sql): array
    {
        if ($sql === '') {
            return [];
        }

        $columns = [];

        if (preg_match('/\binsert\s+into\s+\S+\s*\(([^)]*)\)/i', $sql, $insert) === 1) {
            $columns = array_map(
                static fn (string $column): string => trim($column, " `\"[]\t\n"),
                explode(',', $insert[1])
            );
        }

        if (preg_match_all('/[`"\[]?([a-zA-Z_][a-zA-Z0-9_]*)[`"\]]?\s*(?:=|<=|>=|<>|!=|<|>|\blike\b)\s*\?/i', $sql, $matches) === 1 || ! empty($matches[1])) {
            $columns = array_merge($columns, $matches[1] ?? []);
        }

        return array_values($columns);
    }

    private function leafKey(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $segments = explode('.', $path);

        return (string) end($segments);
    }
}
