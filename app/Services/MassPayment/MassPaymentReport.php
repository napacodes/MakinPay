<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Models\MassPaymentBatch;
use App\Models\MassPaymentRow;
use App\Support\CsvCell;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The two CSV files a mass payment hands out: the per-line report of a
 * batch, and the blank template a merchant fills in.
 *
 * Both are streamed, never built in memory, with a UTF-8 byte order mark so
 * Excel does not read a non-Latin name as the local codepage, and with
 * `fputcsv(escape: '')` so a backslash in a note is a backslash and not an
 * escape character.
 *
 * Every cell a customer typed (the recipient, the reference, the note) goes
 * through {@see CsvCell::safe()}, so a line that starts with `=` opens as
 * text. The recipient name is only ever the masked label the preview showed.
 * Figures are {@see Money::quantize()}d at the batch currency's precision and
 * left unformatted, so a spreadsheet reads them as numbers.
 *
 * The response is marked no-store: a payout report names who was paid what
 * and must not be kept by a shared cache or the browser's disk cache.
 *
 * Whoever calls this has already decided the reader may see the batch: the
 * customer web and API load it through the owner's relation, and the admin
 * screen behind `mass-payment-list`.
 */
class MassPaymentReport
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    private const CHUNK = 500;

    /** @var list<string> */
    public const TEMPLATE_HEADER = ['recipient', 'amount', 'note', 'reference'];

    /**
     * The columns every payout template starts with; the method's account
     * fields follow.
     *
     * @var list<string>
     */
    public const PAYOUT_TEMPLATE_HEADER = ['amount', 'note', 'reference'];

    /**
     * Two example lines. Both recipients are email addresses: a spreadsheet
     * opens `+15551234567` as the number 15551234567, so a mobile example
     * would teach the merchant to lose the plus sign.
     *
     * @var list<list<string>>
     */
    public const TEMPLATE_EXAMPLES = [
        ['customer@example.com', '25.00', 'March commission', 'INV-1001'],
        ['supplier@example.com', '10.50', 'Refund for order 2045', 'INV-1002'],
    ];

    /**
     * The report of one batch, one CSV line per batch line in line order.
     */
    public function download(MassPaymentBatch $batch): StreamedResponse
    {
        if ($batch->isPayout()) {
            return $this->payoutDownload($batch);
        }

        $currency = (string) $batch->currency_code;
        $batchId  = (int) $batch->id;

        return response()->streamDownload(function () use ($batchId, $currency): void {
            $stream = $this->openOutput();

            fputcsv($stream, [
                __('Line'),
                __('Recipient as typed'),
                __('Recipient'),
                __('Reference'),
                __('Amount'),
                __('Fee'),
                __('Total debited'),
                __('Currency'),
                __('Status'),
                __('Reason'),
                __('Debit transaction'),
                __('Credit transaction'),
                __('Refund transaction'),
                __('Processed at'),
                __('Note'),
            ], escape: '');

            MassPaymentRow::query()
                ->where('mass_payment_batch_id', $batchId)
                ->with([
                    'debitTransaction:id,trx_id',
                    'creditTransaction:id,trx_id',
                    'refundTransaction:id,trx_id',
                ])
                ->chunkById(self::CHUNK, function (Collection $rows) use ($stream, $currency): void {
                    foreach ($rows->sortBy('line_no') as $row) {
                        fputcsv($stream, $this->reportLine($row, $currency), escape: '');
                    }
                });

            fclose($stream);
        }, $this->filename($batch), $this->headers());
    }

    /**
     * The blank file a merchant fills in: the header the parser reads and two
     * example lines.
     */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $stream = $this->openOutput();

            fputcsv($stream, self::TEMPLATE_HEADER, escape: '');

            foreach (self::TEMPLATE_EXAMPLES as $example) {
                fputcsv($stream, $example, escape: '');
            }

            fclose($stream);
        }, 'mass-payment-template.csv', $this->headers());
    }

    /**
     * The blank file for a payout batch through one method: the shared
     * columns and one column per account field of the method, named as the
     * parser reads them. Header only: an example account would be paid if a
     * merchant left it in.
     *
     * @param list<string> $accountFields
     */
    public function payoutTemplate(array $accountFields, string $methodName): StreamedResponse
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($methodName)), '-') ?: 'method';

        return response()->streamDownload(function () use ($accountFields): void {
            $stream = $this->openOutput();

            fputcsv($stream, array_merge(self::PAYOUT_TEMPLATE_HEADER, array_map(
                static fn (string $field): string => CsvCell::safe($field),
                $accountFields,
            )), escape: '');

            fclose($stream);
        }, 'mass-payout-template-'.$slug.'.csv', $this->headers());
    }

    /**
     * The report of one payout batch: the masked account, the provider's
     * reference and where each payout stands with it. Never the account
     * details themselves.
     */
    private function payoutDownload(MassPaymentBatch $batch): StreamedResponse
    {
        $currency = (string) $batch->currency_code;
        $batchId  = (int) $batch->id;

        return response()->streamDownload(function () use ($batchId, $currency): void {
            $stream = $this->openOutput();

            fputcsv($stream, [
                __('Line'),
                __('Account'),
                __('Reference'),
                __('Amount'),
                __('Fee'),
                __('Total debited'),
                __('Currency'),
                __('Status'),
                __('Payout'),
                __('Reason'),
                __('Provider reference'),
                __('Debit transaction'),
                __('Refund transaction'),
                __('Processed at'),
                __('Note'),
            ], escape: '');

            MassPaymentRow::query()
                ->where('mass_payment_batch_id', $batchId)
                ->with([
                    'debitTransaction:id,trx_id',
                    'refundTransaction:id,trx_id',
                ])
                ->chunkById(self::CHUNK, function (Collection $rows) use ($stream, $currency): void {
                    foreach ($rows->sortBy('line_no') as $row) {
                        fputcsv($stream, $this->payoutLine($row, $currency), escape: '');
                    }
                });

            fclose($stream);
        }, $this->filename($batch), $this->headers());
    }

    /**
     * One payout report line.
     *
     * @return list<string>
     */
    private function payoutLine(MassPaymentRow $row, string $currency): array
    {
        $status      = $row->status();
        $processedAt = $row->settled_at ?? $row->paid_at ?? $row->failed_at;

        return [
            (string) $row->line_no,
            CsvCell::safe($row->recipient_label),
            CsvCell::safe($row->client_reference),
            $row->net_amount     === null ? CsvCell::safe($row->amount_input) : Money::quantize($row->net_amount, $currency),
            $row->wallet_fee     === null ? '' : Money::quantize($row->wallet_fee, $currency),
            $row->payable_amount === null ? '' : Money::quantize($row->payable_amount, $currency),
            $currency,
            $status?->label() ?? (string) $row->status,
            (string) $row->dispatchState()?->customerLabel(),
            $row->issueMessage() ?? '',
            CsvCell::safe($row->provider_reference),
            (string) $row->debitTransaction?->trx_id,
            (string) $row->refundTransaction?->trx_id,
            (string) $processedAt?->toIso8601String(),
            CsvCell::safe($row->note),
        ];
    }

    /**
     * One report line. A line that was never priced (invalid or skipped) has
     * no fee or total; its amount column then shows what was typed, through
     * the formula guard, so the merchant can find the line to fix.
     *
     * @return list<string>
     */
    private function reportLine(MassPaymentRow $row, string $currency): array
    {
        $status      = $row->status();
        $processedAt = $row->paid_at ?? $row->failed_at;

        return [
            (string) $row->line_no,
            CsvCell::safe($row->recipient_input),
            CsvCell::safe($row->recipient_label),
            CsvCell::safe($row->client_reference),
            $row->net_amount     === null ? CsvCell::safe($row->amount_input) : Money::quantize($row->net_amount, $currency),
            $row->wallet_fee     === null ? '' : Money::quantize($row->wallet_fee, $currency),
            $row->payable_amount === null ? '' : Money::quantize($row->payable_amount, $currency),
            $currency,
            $status?->label()    ?? (string) $row->status,
            $row->issueMessage() ?? '',
            (string) $row->debitTransaction?->trx_id,
            (string) $row->creditTransaction?->trx_id,
            (string) $row->refundTransaction?->trx_id,
            (string) $processedAt?->toIso8601String(),
            CsvCell::safe($row->note),
        ];
    }

    /** @return resource */
    private function openOutput()
    {
        $stream = fopen('php://output', 'wb');

        if ($stream === false) {
            throw new RuntimeException('Unable to open the CSV output stream.');
        }

        fwrite($stream, self::UTF8_BOM);

        return $stream;
    }

    private function filename(MassPaymentBatch $batch): string
    {
        $reference = preg_replace('/[^A-Za-z0-9]/', '', (string) $batch->reference) ?: 'batch';

        return ($batch->isPayout() ? 'mass-payout-' : 'mass-payment-').$reference.'.csv';
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Content-Type'           => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store, private',
            'Pragma'                 => 'no-cache',
        ];
    }
}
