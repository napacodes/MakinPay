<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Enums\BillPayment\BillerAmountType;
use App\Http\Requests\Backend\BillPayment\StoreBillerRequest;
use App\Models\BillCategory;
use App\Models\Biller;
use App\Models\BillerItem;
use App\Models\BillPaymentProvider;
use App\Services\Media\MediaSettings;
use App\Support\Money;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BP5: hand-made billers imported from a spreadsheet, under one provider
 * that pays by hand.
 *
 * The file is read in memory and never stored. Each row is validated by the
 * same rules as the biller form (StoreBillerRequest, rules, messages and
 * after-checks alike), so a file can never create a biller the form would
 * refuse: a third decimal on a USD price, a pattern that does not compile,
 * an example that does not match, a range upside down. A row is also
 * refused when the file lists the biller twice, or when the provider already
 * has one by that name in that country - this import creates and never
 * changes a biller, and never touches one the catalogue sync manages (a
 * provider whose billers come from the sync is refused whole).
 *
 * The admin sees every row's verdict first. A file with any refused row is
 * imported in no part: fix the file and upload it again. Imported billers
 * arrive switched off unless their status column says on, as new billers
 * from the form and the sync do, so the admin checks them before customers
 * see them. The reviewed rows wait in the cache for half an hour, keyed to
 * the admin who uploaded them, and are reviewed again before anything is
 * written, since a category may have gone in between.
 */
class BillerCsvImporter
{
    /** A file with more rows than this is refused whole, never truncated. */
    public const MAX_ROWS = 500;

    /** The largest upload accepted, in KB, whatever the media settings allow. */
    public const MAX_UPLOAD_KB = 2048;

    public const CACHE_TTL_MINUTES = 30;

    /** The label a row leaves blank gets, as the biller form starts with. */
    public const DEFAULT_CUSTOMER_NUMBER_LABEL = 'Account number';

    /** Packages are written "Name=amount|Name=amount". */
    public const PACKAGE_SEPARATOR = '|';

    /**
     * The columns, in the template's order.
     *
     * @var list<string>
     */
    public const TEMPLATE_HEADER = [
        'name', 'category', 'country', 'currency', 'customer_number_label', 'customer_number_pattern',
        'customer_number_example', 'customer_number_help', 'amount_type', 'min_amount', 'max_amount',
        'packages', 'fee_percent', 'fee_fixed', 'token_label', 'processing_note', 'instructions', 'description', 'status',
    ];

    /**
     * @var list<list<string>>
     */
    public const TEMPLATE_EXAMPLES = [
        ['Acme Power', 'electricity', 'US', 'USD', 'Meter number', '^[0-9]{11}$', '12345678901', 'The 11 digits printed on your bill', 'open', '5', '500', '', '', '', 'Prepaid token', 'Paid within one working day', 'Enter the token on your meter.', 'Prepaid electricity', 'no'],
        ['Sky TV', 'tv', 'US', 'USD', 'Smartcard number', '', '', '', 'fixed', '', '', 'Basic=10|Family=18.50|Premium=25', '1.5', '0', '', '', '', '', 'no'],
    ];

    /**
     * Every header name accepted, normalised (lower case, spaces and hyphens
     * as underscores), mapped to the column it fills.
     *
     * @var array<string, string>
     */
    public const HEADER_ALIASES = [
        'name'                    => 'name',
        'biller'                  => 'name',
        'category'                => 'category',
        'category_code'           => 'category',
        'country'                 => 'country',
        'currency'                => 'currency',
        'customer_number_label'   => 'customer_number_label',
        'label'                   => 'customer_number_label',
        'customer_number_pattern' => 'customer_number_pattern',
        'pattern'                 => 'customer_number_pattern',
        'customer_number_example' => 'customer_number_example',
        'example'                 => 'customer_number_example',
        'customer_number_help'    => 'customer_number_help',
        'help'                    => 'customer_number_help',
        'amount_type'             => 'amount_type',
        'type'                    => 'amount_type',
        'min_amount'              => 'min_amount',
        'min'                     => 'min_amount',
        'max_amount'              => 'max_amount',
        'max'                     => 'max_amount',
        'packages'                => 'packages',
        'items'                   => 'packages',
        'fee_percent'             => 'fee_percent',
        'fee_fixed'               => 'fee_fixed',
        'token_label'             => 'token_label',
        'processing_note'         => 'processing_note',
        'instructions'            => 'instructions',
        'description'             => 'description',
        'status'                  => 'status',
    ];

    /** @var list<string> */
    private const DELIMITERS = [',', ';', "\t"];

    private const UTF8_BOM = "\xEF\xBB\xBF";

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Validation rules for the upload itself.
     *
     * @return list<string>
     */
    public static function uploadRules(): array
    {
        return [
            'file',
            'mimes:csv,txt',
            'max:'.min(MediaSettings::effectiveMaxUploadSizeKb(), self::MAX_UPLOAD_KB),
        ];
    }

    /**
     * The file's rows as typed, keyed by the file's line number, each cell
     * trimmed and keyed by its column. Blank lines are skipped without
     * renumbering anything.
     *
     * @return array<int, array<string, string>>
     *
     * @throws ValidationException when the file is empty, has no name column, names a column twice, has no rows or more than MAX_ROWS
     */
    public function parse(UploadedFile|string $file): array
    {
        $content = $this->toUtf8($file instanceof UploadedFile ? (string) $file->get() : $file);
        $lines   = preg_split('/\r\n|\r|\n/', $content) ?: [];

        [$headerIndex, $delimiter] = $this->locateHeader($lines);

        $headers = $this->cells($lines[$headerIndex], $delimiter);
        $columns = $this->mapHeader($headers);

        if (! isset($columns['name'])) {
            throw ValidationException::withMessages(['file' => __('The file needs a name column. Columns found: :columns. Download the template to see the expected layout.', [
                'columns' => $this->foundColumns($headers),
            ])]);
        }

        $rows = [];

        foreach ($lines as $index => $line) {
            if ($index <= $headerIndex || $this->isBlank($line, $delimiter)) {
                continue;
            }

            $cells = $this->cells($line, $delimiter);
            $row   = [];

            foreach ($columns as $field => $cellIndex) {
                $row[$field] = (string) ($cells[$cellIndex] ?? '');
            }

            $rows[$index + 1] = $row;
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('There are no biller rows to read.')]);
        }

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => __('This file has :count biller rows, more than the :max allowed. Split it into smaller files; nothing was imported.', [
                'count' => count($rows),
                'max'   => self::MAX_ROWS,
            ])]);
        }

        return $rows;
    }

    /**
     * Every row's verdict under the provider, in file order.
     *
     * @param  array<int, array<string, string>>                                                                                                                                                                                                      $rows line number => cells by column, as parse() returns them
     * @return list<array{line: int, name: string, category: string|null, country: string, currency: string, amount: string, status: bool, errors: list<string>, attributes: array<string, mixed>, items: list<array{name: string, amount: string}>}>
     *
     * @throws ValidationException when the provider's billers come from the catalogue sync
     */
    public function review(array $rows, BillPaymentProvider $provider): array
    {
        if ($provider->billersComeFromSync()) {
            throw ValidationException::withMessages(['bill_payment_provider_id' => __('Billers for :provider come from the catalogue sync. Run the sync on the provider screen, then switch on the billers it imports.', [
                'provider' => $provider->name,
            ])]);
        }

        $categories = BillCategory::query()->get(['id', 'code', 'name', 'status']);
        $existing   = Biller::query()
            ->where('bill_payment_provider_id', $provider->id)
            ->get(['name', 'country'])
            ->map(fn (Biller $biller): string => $this->identity((string) $biller->name, (string) $biller->country))
            ->flip()
            ->all();

        $seen   = [];
        $review = [];

        foreach ($rows as $line => $cells) {
            $verdict = $this->reviewRow((int) $line, $cells, $provider, $categories);

            $identity = $this->identity($verdict['name'], $verdict['country']);

            if ($verdict['name'] !== '') {
                if (isset($existing[$identity])) {
                    $verdict['errors'][] = __(':provider already has a biller named ":name" in :country. This import never changes an existing biller.', [
                        'provider' => $provider->name,
                        'name'     => $verdict['name'],
                        'country'  => $verdict['country'] !== '' ? $verdict['country'] : __('that country'),
                    ]);
                } elseif (isset($seen[$identity])) {
                    $verdict['errors'][] = __('Line :line already lists this biller.', ['line' => $seen[$identity]]);
                } else {
                    $seen[$identity] = (int) $line;
                }
            }

            $review[] = $verdict;
        }

        return $review;
    }

    /**
     * Create every reviewed biller, with its packages, or none of them.
     *
     * @param  array<int, array<string, string>> $rows as parse() returns them
     * @return int                               the billers created
     *
     * @throws ValidationException when any row is refused on this review
     */
    public function import(array $rows, BillPaymentProvider $provider): int
    {
        $review  = $this->review($rows, $provider);
        $refused = array_values(array_filter($review, static fn (array $verdict): bool => $verdict['errors'] !== []));

        if ($refused !== []) {
            throw ValidationException::withMessages(['file' => trans_choice(
                '{1} :count row is refused; nothing was imported. Fix the file and upload it again.|[2,*] :count rows are refused; nothing was imported. Fix the file and upload it again.',
                count($refused),
                ['count' => count($refused)],
            )]);
        }

        return DB::transaction(function () use ($review): int {
            foreach ($review as $verdict) {
                $biller = Biller::query()->create($verdict['attributes']);

                foreach ($verdict['items'] as $position => $item) {
                    BillerItem::query()->create([
                        'biller_id'  => $biller->id,
                        'name'       => $item['name'],
                        'amount'     => $item['amount'],
                        'status'     => true,
                        'sort_order' => $position,
                    ]);
                }
            }

            return count($review);
        });
    }

    /**
     * Keep a parsed file for the confirm step, for the admin who uploaded
     * it alone. The token is what the preview URL carries.
     *
     * @param array<int, array<string, string>> $rows
     */
    public function remember(array $rows, BillPaymentProvider $provider, int $adminId, string $filename): string
    {
        $token = Str::lower(Str::random(32));

        $this->cache->put($this->cacheKey($adminId, $token), [
            'rows'        => $rows,
            'provider_id' => (int) $provider->id,
            'filename'    => $filename,
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        return $token;
    }

    /**
     * @return array{rows: array<int, array<string, string>>, provider_id: int, filename: string}|null
     */
    public function recall(int $adminId, string $token): ?array
    {
        if (preg_match('/^[a-z0-9]{32}$/', $token) !== 1) {
            return null;
        }

        $kept = $this->cache->get($this->cacheKey($adminId, $token));

        return is_array($kept) && is_array($kept['rows'] ?? null) && is_int($kept['provider_id'] ?? null)
            ? ['rows' => $kept['rows'], 'provider_id' => $kept['provider_id'], 'filename' => (string) ($kept['filename'] ?? '')]
            : null;
    }

    public function forget(int $adminId, string $token): void
    {
        $this->cache->forget($this->cacheKey($adminId, $token));
    }

    /**
     * The blank file an admin fills in: the header the parser reads and two
     * example billers, one open and one with packages.
     */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                throw new RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($stream, self::UTF8_BOM);
            fputcsv($stream, self::TEMPLATE_HEADER, escape: '');

            foreach (self::TEMPLATE_EXAMPLES as $example) {
                fputcsv($stream, $example, escape: '');
            }

            fclose($stream);
        }, 'billers-template.csv', [
            'Content-Type'           => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store, private',
            'Pragma'                 => 'no-cache',
        ]);
    }

    /**
     * One row's verdict: the form's own validation on the row written as the
     * form would post it, plus what only a file can get wrong (an unknown
     * category, a package that is not "Name=amount", a status that is not
     * yes or no).
     *
     * @param  array<string, string>                                                                                                                                                                                                            $cells
     * @param  Collection<int, BillCategory>                                                                                                                                                                                                    $categories
     * @return array{line: int, name: string, category: string|null, country: string, currency: string, amount: string, status: bool, errors: list<string>, attributes: array<string, mixed>, items: list<array{name: string, amount: string}>}
     */
    private function reviewRow(int $line, array $cells, BillPaymentProvider $provider, $categories): array
    {
        $value = static fn (string $field): ?string => isset($cells[$field]) && trim($cells[$field]) !== '' ? trim($cells[$field]) : null;

        $errors   = [];
        $country  = strtoupper((string) $value('country'));
        $currency = strtoupper((string) $value('currency'));
        $type     = strtolower((string) ($value('amount_type') ?? BillerAmountType::Open->value));

        $categoryCode = $value('category');
        $category     = $categoryCode === null ? null : $categories->first(
            static fn (BillCategory $candidate): bool => strcasecmp((string) $candidate->code, $categoryCode) === 0
                || strcasecmp((string) $candidate->name, $categoryCode)                                       === 0,
        );

        if ($categoryCode !== null && $category === null) {
            $errors[] = __('No category has the code or name ":category". Use a code from the Categories screen.', ['category' => $categoryCode]);
        }

        [$items, $packageError] = $this->packages($value('packages'));

        if ($packageError !== null) {
            $errors[] = $packageError;
        }

        [$status, $statusError] = $this->status($value('status'));

        if ($statusError !== null) {
            $errors[] = $statusError;
        }

        $posted = [
            'name'                     => $value('name') ?? '',
            'bill_category_id'         => $category?->id,
            'bill_payment_provider_id' => $provider->id,
            'country'                  => $country,
            'currency'                 => $currency,
            'description'              => $value('description'),
            'customer_number_label'    => $value('customer_number_label') ?? self::DEFAULT_CUSTOMER_NUMBER_LABEL,
            'customer_number_pattern'  => $value('customer_number_pattern'),
            'customer_number_example'  => $value('customer_number_example'),
            'customer_number_help'     => $value('customer_number_help'),
            'amount_type'              => $type,
            'min_amount'               => $value('min_amount'),
            'max_amount'               => $value('max_amount'),
            'items'                    => $items,
            'fee_percent'              => $value('fee_percent'),
            'fee_fixed'                => $value('fee_fixed'),
            'token_label'              => $value('token_label'),
            'processing_note'          => $value('processing_note'),
            'instructions'             => $value('instructions'),
            'status'                   => $status,
        ];

        $request   = new StoreBillerRequest($posted);
        $validator = Validator::make($posted, $request->rules(), $request->messages(), $request->attributes());

        foreach ($request->after() as $after) {
            $validator->after($after);
        }

        foreach ($validator->errors()->toArray() as $field => $messages) {
            // The category's own message says which code was unknown; the
            // "required" the form adds on top says nothing a file can use.
            if ($field === 'bill_category_id' && $categoryCode !== null && $category === null) {
                continue;
            }

            foreach ($messages as $message) {
                $errors[] = (string) $message;
            }
        }

        $data    = $errors === [] ? $validator->validated() : $posted;
        $isFixed = $type   === BillerAmountType::Fixed->value;

        return [
            'line'       => $line,
            'name'       => (string) $posted['name'],
            'category'   => $category?->name,
            'country'    => $country,
            'currency'   => $currency,
            'amount'     => $this->amountLabel($isFixed, $items, $posted['min_amount'], $posted['max_amount'], $currency),
            'status'     => $status,
            'errors'     => array_values(array_unique($errors)),
            'attributes' => [
                'bill_category_id'         => (int) ($data['bill_category_id'] ?? 0),
                'bill_payment_provider_id' => (int) $provider->id,
                'name'                     => (string) ($data['name'] ?? ''),
                'country'                  => (string) ($data['country'] ?? ''),
                'currency'                 => (string) ($data['currency'] ?? ''),
                'description'              => $data['description'] ?? null,
                'customer_number_label'    => (string) ($data['customer_number_label'] ?? ''),
                'customer_number_pattern'  => $data['customer_number_pattern'] ?? null,
                'customer_number_example'  => $data['customer_number_example'] ?? null,
                'customer_number_help'     => $data['customer_number_help']    ?? null,
                'amount_type'              => $type,
                'min_amount'               => $isFixed ? null : ($data['min_amount'] ?? null),
                'max_amount'               => $isFixed ? null : ($data['max_amount'] ?? null),
                'fee_percent'              => $data['fee_percent']     ?? null,
                'fee_fixed'                => $data['fee_fixed']       ?? null,
                'token_label'              => $data['token_label']     ?? null,
                'processing_note'          => $data['processing_note'] ?? null,
                'instructions'             => $data['instructions']    ?? null,
                'status'                   => $status,
                'sort_order'               => 0,
            ],
            'items' => $isFixed ? $items : [],
        ];
    }

    /**
     * "Name=amount|Name=amount" as the form's package rows. The amounts are
     * kept as typed; the form's own rules price them at the currency's
     * precision.
     *
     * @return array{0: list<array{name: string, amount: string}>, 1: string|null}
     */
    private function packages(?string $typed): array
    {
        if ($typed === null) {
            return [[], null];
        }

        $items = [];

        foreach (explode(self::PACKAGE_SEPARATOR, $typed) as $package) {
            $package = trim($package);

            if ($package === '') {
                continue;
            }

            $parts = explode('=', $package);

            if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                return [[], __('Each package is written as Name=amount, separated by |, such as Basic=10|Premium=25.')];
            }

            $items[] = ['name' => trim($parts[0]), 'amount' => trim($parts[1])];
        }

        return [$items, null];
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function status(?string $typed): array
    {
        if ($typed === null) {
            return [false, null];
        }

        $on = filter_var($typed, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($on === null) {
            return [false, __('The status is yes or no.')];
        }

        return [$on, null];
    }

    /**
     * @param list<array{name: string, amount: string}> $items
     */
    private function amountLabel(bool $isFixed, array $items, ?string $min, ?string $max, string $currency): string
    {
        $unit = $currency !== '' ? ' '.$currency : '';

        if ($isFixed) {
            return trans_choice('{0} No packages|{1} :count package|[2,*] :count packages', count($items), ['count' => count($items)]);
        }

        $format = static fn (?string $amount): ?string => is_numeric($amount) ? Money::format($amount, $currency !== '' ? $currency : null, false) : $amount;

        return match (true) {
            $min !== null && $max !== null => $format($min).' – '.$format($max).$unit,
            $min !== null                  => __('From :amount', ['amount' => $format($min).$unit]),
            $max !== null                  => __('Up to :amount', ['amount' => $format($max).$unit]),
            default                        => __('Any amount:unit', ['unit' => $currency !== '' ? ' in '.$currency : '']),
        };
    }

    /** A biller is the same biller as another when its name and country read the same. */
    private function identity(string $name, string $country): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name))).'|'.strtoupper(trim($country));
    }

    private function cacheKey(int $adminId, string $token): string
    {
        return 'bill-payment:biller-import:'.$adminId.':'.$token;
    }

    /**
     * The index of the header line and the delimiter it uses. An Excel
     * `sep=;` hint line, when present, names the delimiter and the header is
     * the line after it.
     *
     * @param  list<string>             $lines
     * @return array{0: int, 1: string}
     */
    private function locateHeader(array $lines): array
    {
        $delimiter = null;

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            if ($delimiter === null && preg_match('/^\s*sep=(,|;|\t)\s*$/i', $line, $match) === 1) {
                $delimiter = $match[1];

                continue;
            }

            return [$index, $delimiter ?? $this->detectDelimiter($line)];
        }

        throw ValidationException::withMessages(['file' => __('The file is empty. Download the template to see the expected layout.')]);
    }

    /**
     * The candidate delimiter that appears most often in the header outside
     * quotes; a comma when the header has none of them, or on a tie.
     */
    private function detectDelimiter(string $header): string
    {
        $unquoted = (string) preg_replace('/"[^"]*"/', '', $header);
        $best     = ',';
        $bestSeen = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $seen = substr_count($unquoted, $delimiter);

            if ($seen > $bestSeen) {
                $best     = $delimiter;
                $bestSeen = $seen;
            }
        }

        return $best;
    }

    /**
     * Which cell index fills which column.
     *
     * @param  list<string>       $headers
     * @return array<string, int>
     *
     * @throws ValidationException when a column is named twice
     */
    private function mapHeader(array $headers): array
    {
        $columns = [];
        $named   = [];

        foreach ($headers as $index => $header) {
            $field = self::HEADER_ALIASES[$this->normaliseHeader($header)] ?? null;

            if ($field === null) {
                continue;
            }

            $named[$field][] = trim($header);
            $columns[$field] ??= $index;
        }

        foreach ($named as $field => $headersForField) {
            if (count($headersForField) > 1) {
                throw ValidationException::withMessages(['file' => __('The file has more than one :field column (:columns). Keep one and upload it again.', [
                    'field'   => $field,
                    'columns' => implode(', ', $headersForField),
                ])]);
            }
        }

        return $columns;
    }

    /** @param list<string> $headers */
    private function foundColumns(array $headers): string
    {
        $found = array_values(array_filter(array_map('trim', $headers), static fn (string $header): bool => $header !== ''));

        return $found === [] ? __('none') : implode(', ', $found);
    }

    /** Lower case, with runs of spaces and hyphens as one underscore. */
    private function normaliseHeader(string $header): string
    {
        return (string) preg_replace('/[\s\-]+/', '_', mb_strtolower(trim($header)));
    }

    /**
     * @return list<string>
     */
    private function cells(string $line, string $delimiter): array
    {
        return array_map(
            static fn (?string $cell): string => trim((string) $cell),
            str_getcsv($line, $delimiter, '"', ''),
        );
    }

    /** A line with nothing on it but delimiters, quotes and whitespace. */
    private function isBlank(string $line, string $delimiter): bool
    {
        return trim($line, " \t\"".$delimiter) === '';
    }

    /**
     * The content as UTF-8 without a byte-order mark. Spreadsheet programs
     * also save UTF-16 and Windows-1252, and both are converted rather than
     * refused.
     */
    private function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xFF\xFE")) {
            $content = (string) mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($content, "\xFE\xFF")) {
            $content = (string) mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        }

        if (str_starts_with($content, self::UTF8_BOM)) {
            $content = substr($content, strlen(self::UTF8_BOM));
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return $content;
    }
}
