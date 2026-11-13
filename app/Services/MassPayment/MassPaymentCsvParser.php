<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Services\Media\MediaSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Turns an uploaded payout file, or rows typed into the manual grid, into the
 * one line shape the draft service validates.
 *
 * The file is read in memory and never stored. It must carry a header, in any
 * column order and any case, using the names in {@see self::HEADER_ALIASES};
 * the delimiter (comma, semicolon or tab) is detected from that header, so a
 * spreadsheet saved with a European list separator still reads.
 *
 * Each line keeps the file's own line number, so the preview, the API and the
 * report point at the same line the merchant sees in their spreadsheet. Blank
 * lines are skipped without renumbering anything.
 *
 * A file with more lines than the cap is refused whole, never truncated: a
 * payout file cut short pays some people and silently leaves the rest out.
 *
 * Nothing here judges a recipient or an amount. Both are kept exactly as
 * typed (trimmed) and the draft service decides; the only cleaning is the
 * note, whose control characters and markup are stripped.
 */
class MassPaymentCsvParser
{
    public const NOTE_MAX_LENGTH = 255;

    /** The largest upload accepted, in KB, whatever the media settings allow. */
    public const MAX_UPLOAD_KB = 2048;

    /**
     * Every header name accepted, normalised (lower case, spaces and hyphens
     * as underscores), mapped to the field it fills.
     *
     * @var array<string, string>
     */
    public const HEADER_ALIASES = [
        'recipient'   => 'recipient',
        'phone'       => 'recipient',
        'email'       => 'recipient',
        'wallet'      => 'recipient',
        'wallet_id'   => 'recipient',
        'identifier'  => 'recipient',
        'amount'      => 'amount',
        'note'        => 'note',
        'description' => 'note',
        'reference'   => 'reference',
        'external_id' => 'reference',
        'currency'    => 'currency',
    ];

    /**
     * The header names a payout file shares with a wallet file. Account
     * field columns are added per method.
     *
     * @var array<string, string>
     */
    public const PAYOUT_HEADER_ALIASES = [
        'amount'      => 'amount',
        'note'        => 'note',
        'description' => 'note',
        'reference'   => 'reference',
        'external_id' => 'reference',
        'currency'    => 'currency',
    ];

    /** The field-map key under which an account field's column is held. */
    private const ACCOUNT_PREFIX = 'account:';

    /** @var list<string> */
    private const DELIMITERS = [',', ';', "\t"];

    private const UTF8_BOM = "\xEF\xBB\xBF";

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
     * The payment lines of an uploaded file, keyed by the file's line number.
     *
     * @return array<int, array{recipient: string, amount: string, note: string|null, reference: string|null, currency: string|null}>
     *
     * @throws ValidationException when the file is empty, has no usable header, has no rows or has more than `$maxRows`
     */
    public function parse(UploadedFile|string $file, int $maxRows): array
    {
        $content = $this->toUtf8($file instanceof UploadedFile ? (string) $file->get() : $file);
        $lines   = preg_split('/\r\n|\r|\n/', $content) ?: [];

        [$headerIndex, $delimiter] = $this->locateHeader($lines);

        $headers = $this->cells($lines[$headerIndex], $delimiter);
        $columns = $this->mapHeader($headers, self::HEADER_ALIASES);

        if (! isset($columns['recipient'], $columns['amount'])) {
            throw $this->missingColumns(__('The file needs a recipient column and an amount column. Columns found: :columns. Download the template to see the expected layout.', [
                'columns' => $this->foundColumns($headers),
            ]));
        }

        $rows = [];

        foreach ($this->dataLines($lines, $headerIndex, $delimiter, $maxRows) as $lineNo => $line) {
            $cells = $this->cells($line, $delimiter);

            $rows[$lineNo] = $this->line(
                fn (string $field): ?string => isset($columns[$field]) ? ($cells[$columns[$field]] ?? null) : null,
            );
        }

        return $rows;
    }

    /**
     * The payout lines of an uploaded file, keyed by the file's line number.
     *
     * The header carries an amount column, optionally note, reference and
     * currency columns under the same names as a wallet file, and one column
     * per account field of the withdraw method, named after the field. A
     * column for a required account field may not be left out; a column the
     * method does not know is ignored.
     *
     * @param  list<string>                                                                                                                             $accountFields  the method's field names, in schema order
     * @param  list<string>                                                                                                                             $requiredFields the subset that is required
     * @return array<int, array{amount: string, note: string|null, reference: string|null, currency: string|null, account: array<string, string|null>}>
     *
     * @throws ValidationException when the file is empty, a needed column is missing, or it has no rows or more than `$maxRows`
     */
    public function parsePayout(UploadedFile|string $file, int $maxRows, array $accountFields, array $requiredFields): array
    {
        $content = $this->toUtf8($file instanceof UploadedFile ? (string) $file->get() : $file);
        $lines   = preg_split('/\r\n|\r|\n/', $content) ?: [];

        [$headerIndex, $delimiter] = $this->locateHeader($lines);

        $headers = $this->cells($lines[$headerIndex], $delimiter);
        $columns = $this->mapHeader($headers, $this->payoutAliases($accountFields));

        $missing = array_values(array_filter(
            $requiredFields,
            fn (string $field): bool => ! isset($columns[self::ACCOUNT_PREFIX.$field]),
        ));

        if (! isset($columns['amount']) || $missing !== []) {
            throw $this->missingColumns(__('The file needs an amount column and a column for each required account detail (:fields). Columns found: :columns. Download the template to see the expected layout.', [
                'fields'  => implode(', ', $requiredFields === [] ? [__('none')] : $requiredFields),
                'columns' => $this->foundColumns($headers),
            ]));
        }

        $rows = [];

        foreach ($this->dataLines($lines, $headerIndex, $delimiter, $maxRows) as $lineNo => $line) {
            $cells = $this->cells($line, $delimiter);

            $rows[$lineNo] = $this->payoutLine(
                fn (string $field): ?string => isset($columns[$field]) ? ($cells[$columns[$field]] ?? null) : null,
                $accountFields,
            );
        }

        return $rows;
    }

    /**
     * The payout lines typed into the manual grid, in the shape
     * {@see parsePayout()} returns. A row with no amount, no note, no
     * reference and no account detail is skipped; the rest are numbered by
     * their position, starting at 1.
     *
     * @param  array<int|string, mixed>                                                                                                                 $rows          each `['amount' => …, 'note' => …, 'reference' => …, 'account' => [field => value]]`
     * @param  list<string>                                                                                                                             $accountFields
     * @return array<int, array{amount: string, note: string|null, reference: string|null, currency: string|null, account: array<string, string|null>}>
     *
     * @throws ValidationException when there are no rows or more than `$maxRows`
     */
    public function payoutFromRows(array $rows, int $maxRows, array $accountFields): array
    {
        $lines    = [];
        $position = 0;

        foreach ($rows as $row) {
            $position++;
            $row     = is_array($row) ? $row : [];
            $account = is_array($row['account'] ?? null) ? $row['account'] : [];

            $line = $this->payoutLine(function (string $field) use ($row, $account): ?string {
                $value = str_starts_with($field, self::ACCOUNT_PREFIX)
                    ? ($account[substr($field, strlen(self::ACCOUNT_PREFIX))] ?? null)
                    : ($row[$field] ?? null);

                return is_scalar($value) ? (string) $value : null;
            }, $accountFields);

            $hasAccount = array_filter($line['account'], fn (?string $value): bool => $value !== null) !== [];

            if ($line['amount'] === '' && $line['note'] === null && $line['reference'] === null && ! $hasAccount) {
                continue;
            }

            $lines[$position] = $line;
        }

        $this->assertRowCount(count($lines), $maxRows, 'rows');

        return $lines;
    }

    /**
     * The payment lines typed into the manual grid, in the same shape as
     * {@see parse()} so both go through one validator. A grid row left
     * entirely blank is skipped; the rest are numbered by their position,
     * starting at 1.
     *
     * @param  array<int|string, mixed>                                                                                               $rows each `['recipient' => …, 'amount' => …, 'note' => …, 'reference' => …, 'currency' => …]`
     * @return array<int, array{recipient: string, amount: string, note: string|null, reference: string|null, currency: string|null}>
     *
     * @throws ValidationException when there are no rows or more than `$maxRows`
     */
    public function fromRows(array $rows, int $maxRows): array
    {
        $lines    = [];
        $position = 0;

        foreach ($rows as $row) {
            $position++;
            $row = is_array($row) ? $row : [];

            $line = $this->line(fn (string $field): ?string => is_scalar($row[$field] ?? null) ? (string) $row[$field] : null);

            if ($line['recipient'] === '' && $line['amount'] === '' && $line['note'] === null && $line['reference'] === null) {
                continue;
            }

            $lines[$position] = $line;
        }

        $this->assertRowCount(count($lines), $maxRows, 'rows');

        return $lines;
    }

    /**
     * A note with control characters, bidirectional overrides and markup
     * removed and whitespace collapsed, or null when nothing is left. The
     * length is not cut here: an over-long note is the draft service's
     * `note_too_long`, never a silently shortened one.
     */
    public static function cleanNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $note = strip_tags($note);
        $note = (string) preg_replace('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}]+/u', ' ', $note);
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));

        return $note === '' ? null : $note;
    }

    /**
     * One line in the shared shape, reading each field through `$value`.
     *
     * @param  callable(string): (string|null)                                                                            $value
     * @return array{recipient: string, amount: string, note: string|null, reference: string|null, currency: string|null}
     */
    private function line(callable $value): array
    {
        $reference = trim((string) $value('reference'));
        $currency  = trim((string) $value('currency'));

        return [
            'recipient' => trim((string) $value('recipient')),
            'amount'    => trim((string) $value('amount')),
            'note'      => self::cleanNote($value('note')),
            'reference' => $reference === '' ? null : $reference,
            'currency'  => $currency  === '' ? null : $currency,
        ];
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

        throw ValidationException::withMessages([
            'file' => __('The file is empty. Download the template to see the expected layout.'),
        ]);
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
     * One payout line in the shared shape, reading each field through
     * `$value`. Account values are trimmed, and a blank one is null.
     *
     * @param  callable(string): (string|null)                                                                                              $value
     * @param  list<string>                                                                                                                 $accountFields
     * @return array{amount: string, note: string|null, reference: string|null, currency: string|null, account: array<string, string|null>}
     */
    private function payoutLine(callable $value, array $accountFields): array
    {
        $line    = $this->line(fn (string $field): ?string => $field === 'recipient' ? null : $value($field));
        $account = [];

        foreach ($accountFields as $field) {
            $typed           = trim((string) $value(self::ACCOUNT_PREFIX.$field));
            $account[$field] = $typed === '' ? null : $typed;
        }

        return [
            'amount'    => $line['amount'],
            'note'      => $line['note'],
            'reference' => $line['reference'],
            'currency'  => $line['currency'],
            'account'   => $account,
        ];
    }

    /**
     * The shared payout header names plus one per account field, each field
     * under its own normalised name. A field whose name is already a shared
     * header cannot be told apart from it and is left to the shared meaning.
     *
     * @param  list<string>          $accountFields
     * @return array<string, string>
     */
    private function payoutAliases(array $accountFields): array
    {
        $aliases = self::PAYOUT_HEADER_ALIASES;

        foreach ($accountFields as $field) {
            $aliases[$this->normaliseHeader($field)] ??= self::ACCOUNT_PREFIX.$field;
        }

        return $aliases;
    }

    /**
     * The data lines after the header, keyed by their line number in the
     * file, blank lines left out, refused whole above the cap.
     *
     * @param  list<string>       $lines
     * @return array<int, string>
     *
     * @throws ValidationException
     */
    private function dataLines(array $lines, int $headerIndex, string $delimiter, int $maxRows): array
    {
        $dataLines = [];

        foreach ($lines as $index => $line) {
            if ($index <= $headerIndex || $this->isBlank($line, $delimiter)) {
                continue;
            }

            $dataLines[$index + 1] = $line;
        }

        $this->assertRowCount(count($dataLines), $maxRows, 'file');

        return $dataLines;
    }

    private function missingColumns(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }

    /** @param list<string> $headers */
    private function foundColumns(array $headers): string
    {
        $found = array_values(array_filter(array_map('trim', $headers), fn (string $header): bool => $header !== ''));

        return $found === [] ? __('none') : implode(', ', $found);
    }

    /** Lower case, with runs of spaces and hyphens as one underscore. */
    private function normaliseHeader(string $header): string
    {
        return (string) preg_replace('/[\s\-]+/', '_', mb_strtolower(trim($header)));
    }

    /**
     * Which cell index fills which field.
     *
     * @param  list<string>          $headers
     * @param  array<string, string> $aliases normalised header name => field
     * @return array<string, int>
     *
     * @throws ValidationException when a field is named twice
     */
    private function mapHeader(array $headers, array $aliases): array
    {
        $columns = [];
        $named   = [];

        foreach ($headers as $index => $header) {
            $field = $aliases[$this->normaliseHeader($header)] ?? null;

            if ($field === null) {
                continue;
            }

            $named[$field][] = trim($header);
            $columns[$field] ??= $index;
        }

        foreach ($named as $field => $headersForField) {
            if (count($headersForField) > 1) {
                throw ValidationException::withMessages([
                    'file' => __('The file has more than one :field column (:columns). Keep one and upload it again.', [
                        'field'   => str_starts_with($field, self::ACCOUNT_PREFIX) ? substr($field, strlen(self::ACCOUNT_PREFIX)) : $field,
                        'columns' => implode(', ', $headersForField),
                    ]),
                ]);
            }
        }

        return $columns;
    }

    /**
     * @throws ValidationException
     */
    private function assertRowCount(int $count, int $maxRows, string $field): void
    {
        if ($count === 0) {
            throw ValidationException::withMessages([
                $field => __('There are no payment rows to read.'),
            ]);
        }

        if ($count > $maxRows) {
            throw ValidationException::withMessages([
                $field => __('This batch has :count payment rows, more than the :max allowed. Split it into smaller batches; nothing was imported.', [
                    'count' => $count,
                    'max'   => $maxRows,
                ]),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function cells(string $line, string $delimiter): array
    {
        return array_map(
            fn (?string $cell): string => trim((string) $cell),
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
     * also save UTF-16 ("Unicode text") and Windows-1252, and both are
     * converted rather than refused.
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
