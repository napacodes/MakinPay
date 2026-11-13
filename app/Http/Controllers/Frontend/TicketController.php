<?php

namespace App\Http\Controllers\Frontend;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReplyTicketRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Models\SupportCategory;
use App\Models\Ticket;
use App\Services\Support\TicketService;
use Illuminate\Http\Request;

/**
 * The customer's support tickets on the website.
 *
 * Every ticket is resolved through Ticket::ownedBy. show() and reply() once
 * looked it up by id alone: any signed-in customer could read any ticket by
 * changing the number in the address, and post into it as if they were its
 * owner. Another customer's ticket now answers 404, the same as one that does
 * not exist - a 403 would confirm it does.
 */
class TicketController extends Controller
{
    public function index()
    {
        $tickets = Ticket::with('category')->ownedBy(auth()->user())->orderBy('created_at', 'desc')->paginate(5);

        return view('frontend.user.support_ticket.index', compact('tickets'));
    }

    public function create()
    {
        $categories = SupportCategory::where('status', Status::ACTIVE)->get();

        return view('frontend.user.support_ticket.create', compact('categories'));
    }

    public function store(StoreTicketRequest $request, TicketService $tickets)
    {
        $tickets->open($request->user(), $request->validated(), $request->file('attachment'));

        notifyEvs('success', __('Ticket created successfully'));

        return redirect()->route('user.support-ticket.index');
    }

    public function show(Request $request, string $ticket)
    {
        $title = __('Support Ticket Details');

        $ticket = Ticket::ownedBy($request->user())->findOrFail($ticket);

        return view('frontend.user.support_ticket.show', compact('title', 'ticket'));
    }

    public function reply(ReplyTicketRequest $request, TicketService $tickets, string $ticket)
    {
        $ticket = Ticket::ownedBy($request->user())->findOrFail($ticket);

        $tickets->reply($ticket, $request->user(), $request->validated('message'), $request->file('attachment'));

        notifyEvs('success', __('Reply created successfully'));

        return redirect()->back();
    }

    /**
     * The route has always existed and pointed at a method that did not, so
     * it answered 500. It is a POST now: closing is a change, and a GET would
     * let any page the customer visits close their tickets with an image tag.
     */
    public function close(Request $request, TicketService $tickets, string $ticket)
    {
        $tickets->close(Ticket::ownedBy($request->user())->findOrFail($ticket));

        notifyEvs('success', __('Ticket closed successfully'));

        return redirect()->back();
    }
}
