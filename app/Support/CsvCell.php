<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One cell of a CSV a person will open in a spreadsheet.
 *
 * Anything a customer typed can start with `=`, `+`, `-` or `@`, which Excel,
 * LibreOffice and Google Sheets read as a formula: a recipient field of
 * `=HYPERLINK(...)` turns a payout report into a phishing link, and
 * `=cmd|...` into a DDE prompt. A leading apostrophe makes the spreadsheet
 * show the text as typed instead.
 *
 * Copied from TransactionController::csvCell so new exports share one rule;
 * the existing copies are left where they are.
 */
final class CsvCell
{
    /** The cell as text that no spreadsheet evaluates. NUL bytes are dropped. */
    public static function safe(mixed $value): string
    {
        $value = str_replace("\0", '', trim((string) $value));

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
