<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\UpdateQuickMenuRequest;
use App\Models\QuickMenuPreference;
use Illuminate\Http\JsonResponse;

class QuickMenuController extends Controller
{
    /**
     * Save the shortcuts the user wants to keep, in the order they arranged.
     *
     * One row per user, so a resubmitted form overwrites rather than stacking.
     */
    public function update(UpdateQuickMenuRequest $request): JsonResponse
    {
        $items = $request->selectedKeys();

        QuickMenuPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['items' => $items]
        );

        return response()->json([
            'success' => true,
            'message' => __('Quick menu updated.'),
            'items'   => $items,
        ]);
    }

    /**
     * Drop the personal selection so the full catalog shows again.
     *
     * Deleting rather than storing every key on purpose: a reset account also
     * picks up shortcuts the site enables later.
     */
    public function destroy(): JsonResponse
    {
        QuickMenuPreference::query()
            ->where('user_id', auth()->id())
            ->delete();

        return response()->json([
            'success' => true,
            'message' => __('Quick menu reset to the default shortcuts.'),
        ]);
    }
}
