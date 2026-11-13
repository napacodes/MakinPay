<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\BillCategory;
use App\Services\BillPayment\BillerDirectory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bill category as the app's hub draws it: its code (what the biller list
 * filters on), its name, its icon and, on the hub, how many billers it has in
 * the selected country.
 *
 * The icon is a shipped SVG chosen from config('bill_payments.category_icons'),
 * so the URL never points at a file that does not exist.
 *
 * @property-read BillCategory $resource
 */
class BillCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->resource;
        $icon     = app(BillerDirectory::class)->categoryIconKey($category->icon);

        return [
            'code'     => $category->code,
            'name'     => $category->name,
            'icon_url' => asset('general/static/svg/'.$icon.'.svg'),
            // Counted for one country by the hub; absent elsewhere.
            'billers_count' => $this->when(
                $category->getAttribute('billers_count') !== null,
                fn (): int => (int) $category->getAttribute('billers_count'),
            ),
        ];
    }
}
