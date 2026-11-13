<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\BillPayment;
use App\Models\SavedBill;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the customer's "My bills": the biller, the account number and the
 * name they gave it, with the last payment made to it.
 *
 * The biller is a summary for the list; the app opens the pay screen from
 * GET bill-payments/billers/{id} and fills in `customer_number`. The last
 * payment is read from bill_payments, so it is exactly what the receipt says.
 *
 * Built from SavedBillBook::forUser() entries, which load `biller.category`.
 *
 * @property-read array{bill: SavedBill, last: BillPayment|null} $resource
 */
class SavedBillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $bill   = $this->resource['bill'];
        $last   = $this->resource['last'];
        $biller = $bill->biller;

        return [
            'id'              => $bill->id,
            'nickname'        => $bill->nickname,
            'title'           => $bill->title(),
            'customer_number' => $bill->customer_number,
            // BP3: the day of each month the customer asked to be reminded on, or null.
            'remind_day' => $bill->remind_day,
            'biller'     => [
                'id'       => $biller->id,
                'name'     => $biller->name,
                'country'  => strtoupper((string) $biller->country),
                'currency' => strtoupper((string) $biller->currency),
                'logo_url' => filled($biller->logo) ? asset((string) $biller->logo) : null,
                'category' => $biller->category === null ? null : (new BillCategoryResource($biller->category))->resolve($request),
            ],
            'last_payment' => $last === null ? null : [
                'uuid'           => $last->uuid,
                'status'         => $last->status?->value,
                'status_label'   => $last->status?->customerLabel(),
                'total'          => ApiMoney::amount((string) $last->total_amount, (string) $last->currency),
                'currency'       => $last->currency,
                'biller_item_id' => $last->biller_item_id,
                'item_name'      => $last->item_name,
                'created_at'     => $last->created_at?->toIso8601String(),
            ],
            'last_used_at' => $bill->last_used_at?->toIso8601String(),
        ];
    }
}
