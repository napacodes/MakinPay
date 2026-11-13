<?php

namespace App\Http\Controllers\Frontend\P2P;

use App\Http\Controllers\Controller;
use App\Http\Requests\P2P\StoreDisputeRequest;
use App\Models\P2P\Order;
use App\Services\P2P\P2POrderAftercare;
use Illuminate\Http\RedirectResponse;

class DisputeController extends Controller
{
    public function store(StoreDisputeRequest $request, Order $order, P2POrderAftercare $disputes): RedirectResponse
    {
        $this->authorize('dispute', $order);

        $disputes->dispute(auth()->user(), $order, $request->string('reason')->toString());

        notifyEvs('success', __('Trade dispute opened. Support will review.'));

        return back();
    }
}
