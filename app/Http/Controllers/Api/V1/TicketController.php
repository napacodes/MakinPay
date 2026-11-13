<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Constants\Status;
use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReplyTicketRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Resources\Api\V1\TicketResource;
use App\Models\SupportCategory;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Support tickets, from the app.
 *
 * Every ticket is resolved through Ticket::ownedBy, so another customer's
 * answers 404 exactly like one that does not exist - the website's show() and
 * reply() once skipped that step. The rules are the website's form requests
 * and the steps are TicketService's.
 */
class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tickets = Ticket::ownedBy($request->user())
            ->with('category')
            ->latest()
            ->latest('id')
            ->paginate(15);

        return ApiResponse::paginated(TicketResource::collection($tickets));
    }

    /**
     * What the "new ticket" form offers: the categories switched on, and the
     * priorities - so the app never sends one the server will refuse.
     */
    public function options(): JsonResponse
    {
        return ApiResponse::ok([
            'categories' => SupportCategory::query()
                ->where('status', Status::ACTIVE)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (SupportCategory $category): array => ['id' => $category->id, 'name' => $category->name])
                ->values(),
            'priorities' => array_map(fn (TicketPriority $priority): string => $priority->value, TicketPriority::cases()),
            'channels'   => $this->channels(),
            'sla_hours'  => is_numeric($sla = setting('sla_ticket_hours', null)) ? (int) $sla : null,
        ]);
    }

    /**
     * The other ways to reach support, from the admin's settings: a phone
     * line and a live chat page, each with the hours it is staffed. A
     * channel the admin has not filled in is null, and the app shows no row.
     *
     * @return array{phone: array{number: string, hours: string|null}|null, live_chat: array{url: string, hours: string|null}|null}
     */
    private function channels(): array
    {
        $phone = trim((string) setting('support_phone', ''));
        $chat  = trim((string) setting('support_live_chat_url', ''));

        return [
            'phone' => $phone === '' ? null : [
                'number' => $phone,
                'hours'  => filled($hours = setting('support_phone_hours', null)) ? (string) $hours : null,
            ],
            'live_chat' => $chat === '' ? null : [
                'url'   => $chat,
                'hours' => filled($hours = setting('support_live_chat_hours', null)) ? (string) $hours : null,
            ],
        ];
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        return ApiResponse::ok(new TicketResource($this->owned($request->user(), $ticket)));
    }

    public function store(StoreTicketRequest $request, TicketService $tickets): JsonResponse
    {
        $ticket = $tickets->open($request->user(), $request->validated(), $request->file('attachment'));

        return ApiResponse::created(new TicketResource($this->owned($request->user(), (string) $ticket->id)));
    }

    public function reply(ReplyTicketRequest $request, TicketService $tickets, string $ticket): JsonResponse
    {
        $owned = $this->owned($request->user(), $ticket);

        $tickets->reply($owned, $request->user(), $request->validated('message'), $request->file('attachment'));

        return ApiResponse::created(new TicketResource($this->owned($request->user(), $ticket)));
    }

    public function close(Request $request, TicketService $tickets, string $ticket): JsonResponse
    {
        $tickets->close($this->owned($request->user(), $ticket));

        return ApiResponse::ok(new TicketResource($this->owned($request->user(), $ticket)));
    }

    public function reopen(Request $request, TicketService $tickets, string $ticket): JsonResponse
    {
        $tickets->reopen($this->owned($request->user(), $ticket), $request->user());

        return ApiResponse::ok(new TicketResource($this->owned($request->user(), $ticket)));
    }

    /**
     * This customer's ticket with its conversation, oldest message first, or a
     * 404 - for a stranger's ticket as for one that does not exist.
     */
    private function owned(User $user, string $ticket): Ticket
    {
        return Ticket::ownedBy($user)
            ->with(['category', 'messages' => fn ($query) => $query->oldest()->oldest('id')])
            ->findOrFail($ticket);
    }
}
