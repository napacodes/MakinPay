<?php

namespace App\Http\Controllers\Backend;

use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Services\GiftCardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GiftCardController extends Controller
{
    public function index(Request $request): View
    {
        $giftCards = GiftCard::query()
            ->with(['sender', 'recipient', 'template', 'currency'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->input('q');
                $q->where(function ($qq) use ($term) {
                    $qq->where('code', 'like', "%{$term}%")
                        ->orWhere('recipient_email', 'like', "%{$term}%")
                        ->orWhere('recipient_name', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total'     => GiftCard::count(),
            'delivered' => GiftCard::where('status', 'delivered')->count(),
            'redeemed'  => GiftCard::where('status', 'redeemed')->count(),
            'value'     => GiftCard::sum('amount'),
        ];

        return view('backend.gift-cards.index', compact('giftCards', 'stats'));
    }

    public function cancel(GiftCard $giftCard, GiftCardService $giftCards): RedirectResponse
    {
        if (! in_array($giftCard->status, ['pending', 'scheduled', 'delivered'], true)) {
            notifyEvs('error', __('Only undelivered or unredeemed cards can be cancelled.'));

            return back();
        }

        try {
            $giftCards->cancel($giftCard, ['pending', 'scheduled', 'delivered']);
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());

            return back();
        }

        notifyEvs('success', __('Gift card cancelled and its value refunded to the sender.'));

        return back();
    }
}
