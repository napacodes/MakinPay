<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\AmountFlow;
use App\Enums\Api\ApiErrorCode;
use App\Enums\TrxStatus;
use App\Services\Api\StatementService;
use App\Services\CurrencyService;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Activity screen's extras: a month's totals, an export of the current
 * filter, and "notify me when it lands" on a pending row.
 */
trait TransactionExtras
{
    /**
     * Money in, out and net for one month, restated in the display currency
     * (or ?currency=), with the count. Completed rows only: a pending or
     * failed row moved nothing, and nor did a sandbox payment.
     */
    public function summary(Request $request, CurrencyService $currencies): JsonResponse
    {
        $validated = $request->validate([
            'month'    => ['sometimes', 'date_format:Y-m'],
            'currency' => ['sometimes', 'string', 'max:10'],
        ]);

        $user    = $request->user();
        $start   = isset($validated['month']) ? CarbonImmutable::createFromFormat('Y-m', $validated['month'])->startOfMonth() : CarbonImmutable::now()->startOfMonth();
        $display = $this->displayCurrencyFor($request, $user, $currencies);
        $base    = strtoupper((string) siteCurrency());

        $rows = $user->transactions()
            ->where('status', TrxStatus::COMPLETED)
            ->withoutSandbox()
            ->whereBetween('created_at', [$start, $start->endOfMonth()])
            ->get(['amount', 'currency', 'amount_flow']);

        $rates  = $currencies->ratesByCode($rows->pluck('currency')->push($display));
        $factor = $display === $base ? '1' : ($rates->get($display) ?? '1');
        $in     = '0';
        $out    = '0';

        foreach ($rows as $row) {
            $restated = DecimalMoney::multiply($currencies->toBaseAmount($row->amount, $row->currency, $rates), $factor);

            if ($row->amount_flow === AmountFlow::PLUS) {
                $in = DecimalMoney::add($in, $restated, DecimalMoney::CALCULATION_SCALE);
            } elseif ($row->amount_flow === AmountFlow::MINUS) {
                $out = DecimalMoney::add($out, $restated, DecimalMoney::CALCULATION_SCALE);
            }
        }

        return ApiResponse::ok([
            'month'        => $start->format('Y-m'),
            'currency'     => $display,
            'decimals'     => ApiMoney::decimals($display),
            'transactions' => $rows->count(),
            'in'           => ApiMoney::amount($in, $display),
            'out'          => ApiMoney::amount($out, $display),
            'net'          => ApiMoney::amount(DecimalMoney::subtract($in, $out, DecimalMoney::CALCULATION_SCALE), $display),
        ]);
    }

    /**
     * The current filter as a CSV or PDF, capped so a phone never asks for
     * the whole ledger at once.
     */
    public function export(Request $request, string $format, StatementService $statements)
    {
        if (! in_array($format, ['pdf', 'csv'], true)) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed);
        }

        $filters = $this->validatedFilters($request);
        $rows    = $this->applyExtraFilters($request->user()->transactions()->applyFilters($filters + ['user_id' => null]), $filters)
            ->latest('id')
            ->limit(2000)
            ->get();
        $name = 'activity-'.now()->format('Y-m-d').'.'.$format;

        if ($format === 'csv') {
            return response($statements->csv($rows), 200, [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$name.'"',
            ]);
        }

        $pdf = $statements->pdf($rows, [
            'title'    => __('Activity'),
            'name'     => $request->user()->name,
            'wallet'   => __('All wallets'),
            'currency' => '',
            'closing'  => null,
        ]);

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /**
     * Ask to hear when this pending row changes state. Idempotent: asking
     * twice is one flag. A settled row has nothing left to announce.
     */
    public function notify(Request $request, string $transaction): JsonResponse
    {
        $record = $request->user()->transactions()->where('trx_id', $transaction)->first();

        if ($record === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        if ($record->status !== TrxStatus::PENDING) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('This transaction has already settled.'));
        }

        $data                     = (array) ($record->trx_data ?? []);
        $data['notify_on_status'] = true;
        $record->update(['trx_data' => $data]);

        return ApiResponse::ok(['notify' => true]);
    }

    /**
     * The date, currency and direction filters the app adds on top of the
     * model's own scope.
     *
     * @param array<string, mixed> $filters
     */
    private function applyExtraFilters($query, array $filters)
    {
        return $query
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', CarbonImmutable::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', CarbonImmutable::parse($to)->endOfDay()))
            ->when($filters['currency'] ?? null, fn ($q, $code) => $q->where(fn ($w) => $w->where('currency', strtoupper($code))->orWhere('payable_currency', strtoupper($code))))
            ->when($filters['flow'] ?? null, fn ($q, $flow) => $q->where('amount_flow', $flow));
    }

    private function displayCurrencyFor(Request $request, $user, CurrencyService $currencies): string
    {
        $code = strtoupper(trim((string) $request->query('currency', '')));

        if ($code !== '' && $code !== $user->displayCurrency()) {
            $currency = $currencies->getCurrencyByCode($code);

            if ($currency !== null && $currency->status && $currency->isConvertible()) {
                return strtoupper((string) $currency->code);
            }
        }

        return $user->displayCurrency();
    }
}
