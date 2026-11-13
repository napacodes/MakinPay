<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend\P2P;

use App\Http\Controllers\Controller;
use App\Http\Requests\P2P\StoreOrderMessageRequest;
use App\Models\P2P\Order;
use App\Models\P2P\OrderMessage;
use App\Services\P2P\P2POrderChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderChatController extends Controller
{
    public function __construct(private readonly P2POrderChatService $chat) {}

    /**
     * Incremental message feed for the order chat. Pass ?after={id} to fetch
     * only newer messages; fetching also marks the counterpart's messages as
     * read for the viewing participant.
     */
    public function index(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        $afterId = max(0, (int) $request->query('after', 0));

        $messages = OrderMessage::query()
            ->with('sender:id,first_name,last_name,username')
            ->where('order_id', $order->id)
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit(200)
            ->get();

        OrderMessage::query()
            ->where('order_id', $order->id)
            ->where('sender_id', '!=', (int) auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'messages' => $messages->map(fn (OrderMessage $message) => $this->presentMessage($order, $message))->values(),
            'last_id'  => (int) ($messages->last()->id ?? $afterId),
            'can_chat' => Gate::allows('chat', $order),
        ]);
    }

    public function store(StoreOrderMessageRequest $request, Order $order): JsonResponse
    {
        $message = $this->chat->store(
            order: $order,
            sender: $request->user(),
            body: $request->validated('body'),
            proof: $request->file('proof'),
        );

        return response()->json([
            'message'  => $this->presentMessage($order, $message),
            'can_chat' => true,
        ], 201);
    }

    public function attachment(Order $order, OrderMessage $message): StreamedResponse
    {
        $this->authorize('view', $order);

        abort_unless((int) $message->order_id === (int) $order->id && filled($message->attachment_path), 404);
        abort_unless(Storage::disk('local')->exists($message->attachment_path), 404);

        return Storage::disk('local')->response(
            $message->attachment_path,
            $message->attachment_name ?: __('payment-proof'),
            [
                'Content-Type'            => $message->attachment_mime ?: 'application/octet-stream',
                'X-Content-Type-Options'  => 'nosniff',
                'Content-Security-Policy' => 'sandbox',
                'Referrer-Policy'         => 'no-referrer',
            ],
        );
    }

    /**
     * @return array{id: int, body: string, mine: bool, sender_name: string, sent_at: string, sent_at_human: string, attachment: array{url: string, name: string, mime: string, is_image: bool, size_label: string}|null}
     */
    private function presentMessage(Order $order, OrderMessage $message): array
    {
        return [
            'id'            => (int) $message->id,
            'body'          => (string) $message->body,
            'mine'          => (int) $message->sender_id === (int) auth()->id(),
            'sender_name'   => (string) ($message->sender?->name ?? __('User')),
            'sent_at'       => (string) $message->created_at?->toIso8601String(),
            'sent_at_human' => (string) $message->created_at?->format('M d, H:i'),
            'attachment'    => $message->attachment_path ? [
                'url'        => route('user.p2p.orders.messages.attachment', [$order, $message]),
                'name'       => (string) ($message->attachment_name ?: __('Payment proof')),
                'mime'       => (string) $message->attachment_mime,
                'is_image'   => str_starts_with((string) $message->attachment_mime, 'image/'),
                'size_label' => $message->attachment_size ? number_format($message->attachment_size / 1024, 1).' KB' : '',
            ] : null,
        ];
    }
}
