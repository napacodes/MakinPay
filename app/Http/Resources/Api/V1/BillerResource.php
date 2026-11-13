<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\BillPayment\BillerAmountType;
use App\Models\Biller;
use App\Models\BillerItem;
use App\Services\BillPayment\BillerDirectory;
use App\Services\BillPayment\BillPaymentPricer;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One biller, with everything the app needs to draw its pay screen: how the
 * customer number is asked for, what may be paid (a range or a list of
 * packages), the fee rule, and whether a token comes back.
 *
 * Every amount is a string at the biller currency's own precision, as
 * ApiMoney sends it. The fee is the rule BillPaymentPricer::feeRule() applies,
 * including what the biller inherits from its provider, so the screen and the
 * quote never disagree. It is shown, not computed: the app asks for a quote.
 *
 * `customer_number.pattern` is the admin's PCRE body. It is a hint for the
 * screen only; the server checks the number on submit, and a regex dialect
 * the app cannot read must not stop a customer from trying.
 *
 * Expects `category`, `provider` and `activeItems` loaded.
 *
 * @property-read Biller $resource
 */
class BillerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $biller     = $this->resource;
        $currency   = strtoupper((string) $biller->currency);
        $isFixed    = $biller->amount_type === BillerAmountType::Fixed;
        $tokenLabel = trim((string) $biller->token_label);

        [$feeFixed, $feePercent] = app(BillPaymentPricer::class)->feeRule($biller, $currency);

        return [
            'id'          => $biller->id,
            'name'        => $biller->name,
            'category'    => $biller->category === null ? null : (new BillCategoryResource($biller->category))->resolve($request),
            'country'     => strtoupper((string) $biller->country),
            'currency'    => $currency,
            'decimals'    => ApiMoney::decimals($currency),
            'logo_url'    => filled($biller->logo) ? asset((string) $biller->logo) : null,
            'description' => $biller->description,

            // BP1: its provider is paused, so a quote or a payment is refused
            // until it resumes; the app shows the biller as unavailable.
            'temporarily_unavailable' => $biller->provider?->isPaused() === true,

            'customer_number' => [
                'label'   => trim((string) $biller->customer_number_label) !== '' ? $biller->customer_number_label : __('Account number'),
                'example' => $biller->customer_number_example,
                'help'    => $biller->customer_number_help,
                'pattern' => $biller->customer_number_pattern,
            ],

            'amount' => [
                'type'  => $biller->amount_type?->value,
                'min'   => ! $isFixed && $biller->min_amount !== null ? ApiMoney::amount((string) $biller->min_amount, $currency) : null,
                'max'   => ! $isFixed && $biller->max_amount !== null ? ApiMoney::amount((string) $biller->max_amount, $currency) : null,
                'items' => $isFixed
                    ? $biller->activeItems->map(fn (BillerItem $item): array => [
                        'id'     => $item->id,
                        'name'   => $item->name,
                        'amount' => ApiMoney::amount((string) $item->amount, $currency),
                    ])->values()->all()
                    : [],
            ],

            'fee' => [
                'fixed'       => ApiMoney::amount($feeFixed, $currency),
                'percent'     => $this->trimmedPercent($feePercent),
                'description' => Money::describeFee($feeFixed, $feePercent, $currency),
            ],

            'delivers_token'  => $tokenLabel !== '',
            'token_label'     => $tokenLabel !== '' ? $tokenLabel : null,
            'processing_note' => app(BillerDirectory::class)->processingNote($biller),
            'instructions'    => $biller->instructions,
        ];
    }

    /**
     * "1.5000" as "1.5" and "2.0000" as "2", without going through a float.
     */
    private function trimmedPercent(string $percent): string
    {
        $normalized = DecimalMoney::normalize($percent, 4);

        if (! str_contains($normalized, '.')) {
            return $normalized;
        }

        return rtrim(rtrim($normalized, '0'), '.');
    }
}
