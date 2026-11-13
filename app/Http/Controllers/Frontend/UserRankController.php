<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Services\UserRankProgress;
use Illuminate\View\View;

class UserRankController extends Controller
{
    /**
     * The rank ladder and where the customer stands on it. The reading is
     * UserRankProgress's, shared with the app's GET api/v1/rank, so the page
     * and the app cannot disagree about the current or the next rank.
     */
    public function showcase(UserRankProgress $progress): View
    {
        $rankProgress = $progress->for(auth()->user());
        $userRanks    = $rankProgress['ranks'];

        return view('frontend.user.rank.showcase', compact('userRanks', 'rankProgress'));
    }
}
