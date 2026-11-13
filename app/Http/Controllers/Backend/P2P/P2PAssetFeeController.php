<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\P2P;

use App\Http\Controllers\Backend\BaseController;
use App\Http\Requests\Backend\P2P\P2PAssetFeeRequest;
use App\Models\P2P\P2PAssetFee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Per-asset overrides of the global P2P maker/taker fee.
 *
 * These live on the marketplace settings screen rather than a page of their
 * own: an operator sets the global pair and then overrides the assets whose
 * settlement cost differs, so the two belong side by side.
 */
class P2PAssetFeeController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'show|store|update|destroy' => 'p2p-manage',
        ];
    }

    public function show(P2PAssetFee $assetFee): JsonResponse
    {
        return response()->json([
            'id'              => $assetFee->id,
            'currency_id'     => $assetFee->currency_id,
            'maker_fee_pct'   => $assetFee->maker_fee_pct,
            'maker_fee_fixed' => $assetFee->maker_fee_fixed,
            'maker_fee_min'   => $assetFee->maker_fee_min,
            'maker_fee_max'   => $assetFee->maker_fee_max,
            'taker_fee_pct'   => $assetFee->taker_fee_pct,
            'taker_fee_fixed' => $assetFee->taker_fee_fixed,
            'taker_fee_min'   => $assetFee->taker_fee_min,
            'taker_fee_max'   => $assetFee->taker_fee_max,
            'is_active'       => $assetFee->is_active,
        ]);
    }

    public function store(P2PAssetFeeRequest $request): RedirectResponse
    {
        P2PAssetFee::query()->create($request->feeAttributes());

        notifyEvs('success', __('Asset fee override created.'));

        return redirect()->route('admin.p2p.settings.edit');
    }

    public function update(P2PAssetFeeRequest $request, P2PAssetFee $assetFee): RedirectResponse
    {
        $assetFee->update($request->feeAttributes());

        notifyEvs('success', __('Asset fee override updated.'));

        return redirect()->route('admin.p2p.settings.edit');
    }

    public function destroy(P2PAssetFee $assetFee): RedirectResponse
    {
        // Removing the row is how an asset goes back to the global pair, so
        // this is a revert rather than a destructive delete.
        $assetFee->delete();

        notifyEvs('success', __('Asset fee override removed. This asset now uses the default fee.'));

        return redirect()->route('admin.p2p.settings.edit');
    }
}
