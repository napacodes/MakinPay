<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\BillPayment;

use App\Models\Biller;
use Illuminate\Validation\Rule;

/**
 * The same rules as a new biller, except that a submitted package may name
 * one of this biller's own packages to update it. A package id belonging to
 * another biller is refused, so one biller's form cannot rewrite another's
 * prices.
 *
 * On a biller the catalogue sync manages, only existing packages' on/off
 * switch and order can change (see StoreBillerRequest::syncedBiller()).
 */
class UpdateBillerRequest extends StoreBillerRequest
{
    /**
     * @return list<mixed>
     */
    protected function itemIdRules(): array
    {
        $biller   = $this->route('biller');
        $billerId = $biller instanceof Biller ? $biller->id : (int) $biller;

        return ['nullable', 'integer', Rule::exists('biller_items', 'id')->where('biller_id', $billerId)];
    }

    protected function editedBiller(): ?Biller
    {
        $biller = $this->route('biller');

        return $biller instanceof Biller ? $biller : null;
    }

    protected function syncedBiller(): ?Biller
    {
        $biller = $this->route('biller');

        return $biller instanceof Biller && $biller->isSyncManaged() ? $biller : null;
    }
}
