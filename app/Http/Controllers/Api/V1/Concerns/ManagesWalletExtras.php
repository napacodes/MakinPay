<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\Api\ApiErrorCode;
use App\Exceptions\NotifyErrorException;
use App\Http\Resources\Api\V1\WalletResource;
use App\Services\Api\StatementService;
use App\Services\PaymentLinkService;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The wallet screen's extras: naming, pausing, a personal receive link and
 * monthly statements. Split out of WalletController for length only;
 * every lookup still goes through its owner-scoped find().
 */
trait ManagesWalletExtras
{
    /**
     * Give a wallet a name of the customer's own.
     */
    public function update(Request $request, string $wallet): JsonResponse
    {
        $record = $this->find($request, $wallet);

        if ($record === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $validated = $request->validate(['label' => ['present', 'nullable', 'string', 'max:40']]);
        $record->update(['label' => filled($validated['label']) ? trim((string) $validated['label']) : null]);

        return ApiResponse::ok(new WalletResource($record->fresh('currency.roles')));
    }

    /**
     * Pause or resume a wallet - the website's status toggle. Resuming
     * needs the currency to be operational, as there. Found without the
     * active scope, or a paused wallet could never be resumed.
     */
    public function status(Request $request, string $wallet): JsonResponse
    {
        $record = $request->user()->wallets()->with('currency.roles')->where('uuid', $wallet)->first();

        if ($record === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];

        if ($active) {
            try {
                $this->wallets->assertCurrencyOperational($record->currency);
            } catch (NotifyErrorException $e) {
                return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage());
            }
        }

        $record->update(['status' => $active]);

        return ApiResponse::ok(new WalletResource($record->fresh('currency.roles')));
    }

    /**
     * A one-off link anyone can pay into this wallet - the personal cousin
     * of a merchant payment link, on the same model and the same pay page.
     */
    public function receiveLink(Request $request, string $wallet, PaymentLinkService $links): JsonResponse
    {
        $record = $this->find($request, $wallet);

        if ($record === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'note'   => ['nullable', 'string', 'max:120'],
            'days'   => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        try {
            $link = $links->create($request->user(), [
                'currency_id'  => $record->currency_id,
                'title'        => filled($validated['note'] ?? null) ? $validated['note'] : __('Payment to :name', ['name' => $request->user()->name]),
                'amount'       => $validated['amount'] ?? null,
                'expires_at'   => now()->addDays((int) ($validated['days'] ?? 7)),
                'max_payments' => 1,
            ]);
        } catch (NotifyErrorException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage());
        }

        return ApiResponse::created([
            'url'        => $link->publicUrl(),
            'token'      => $link->token,
            'amount'     => $link->amount === null ? null : ApiMoney::amount($link->amount, $record->currency?->code),
            'currency'   => $record->currency?->code,
            'expires_at' => $link->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * The months this wallet has statements for.
     */
    public function statements(Request $request, string $wallet, StatementService $statements): JsonResponse
    {
        $record = $this->find($request, $wallet);

        return $record === null
            ? ApiResponse::error(ApiErrorCode::ResourceNotFound)
            : ApiResponse::ok(['months' => $statements->months($record)]);
    }

    /**
     * One month as a PDF or CSV file. Carries the customer's name and the
     * wallet id, so it is handled like a bank statement.
     */
    public function statement(Request $request, string $wallet, string $period, string $format, StatementService $statements)
    {
        $record = $this->find($request, $wallet);

        if ($record === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        if (! StatementService::isPeriod($period) || ! in_array($format, ['pdf', 'csv'], true)) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed);
        }

        $rows = $statements->rows($record, $period);
        $name = 'statement-'.$record->currency?->code.'-'.$period.'.'.$format;

        if ($format === 'csv') {
            return response($statements->csv($rows), 200, [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$name.'"',
            ]);
        }

        $closing = collect($statements->months($record))->firstWhere('period', $period)['closing'] ?? null;
        $pdf     = $statements->pdf($rows, [
            'title'    => __('Statement :period', ['period' => $period]),
            'name'     => $request->user()->name,
            'wallet'   => $record->uuid,
            'currency' => $record->currency?->code,
            'closing'  => $closing,
        ]);

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
