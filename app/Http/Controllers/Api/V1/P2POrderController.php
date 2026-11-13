<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Enums\P2P\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\P2P\Dispute;
use App\Models\P2P\Offer;
use App\Models\P2P\Order;
use App\Models\P2P\OrderMessage;
use App\Models\User;
use App\Services\Money\WalletPinGuard;
use App\Services\P2P\P2POrderAftercare;
use App\Services\P2P\P2POrderChatService;
use App\Services\P2P\P2POrderPlacement;
use App\Services\P2P\P2POrderService;
use App\Services\P2P\P2POrderState;
use App\Support\AmountEntry;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\P2PPaymentFileDisk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * P2P orders from the app: the customer's orders on either side of a trade,
 * one order in full, its live status, its chat - and taking an ad and each
 * step of the trade after it, through the website's own P2POrderService.
 *
 * Only the two parties to an order can read it; to anyone else it does not
 * exist - the website answers them 403, which says there is something there.
 * An order is found by its number or, for a link from before orders had
 * numbers, its id. Both parties see both payment snapshots, as on the
 * website: the buyer needs the seller's account to pay into, the seller the
 * account the payment should come from.
 */
class P2POrderController extends Controller
{
    public function __construct(
        private readonly P2POrderState $states,
        private readonly P2POrderService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
        ]);

        $user = $request->user();

        $page = $this->forParty($user)
            ->with(['offer.quoteCurrency', 'wallet.currency', 'paymentMethod', 'maker', 'taker', 'feedbacks'])
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest()
            ->latest('id')
            ->paginate(15);

        $page->through(fn (Order $order): array => $this->order($order, $user));

        return ApiResponse::paginated($page);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        $user  = $request->user();
        $order = $this->find($user, $order, ['offer.quoteCurrency', 'wallet.currency', 'paymentMethod', 'maker', 'taker', 'feedbacks']);

        $myFeedback = $order->feedbacks->firstWhere('user_id', $user->id);
        $dispute    = Dispute::query()->where('order_id', $order->id)->first();
        $released   = $order->paid_at !== null && $order->completed_at !== null ? max(0, $order->completed_at->getTimestamp() - $order->paid_at->getTimestamp()) : null;

        return ApiResponse::ok($this->order($order, $user) + [
            'now'             => now()->toIso8601String(),
            'release_seconds' => $released,
            'dispute'         => $dispute === null ? null : [
                'case'         => 'DSP-'.$dispute->id,
                'status'       => $dispute->status->value,
                'reason'       => $dispute->reason,
                'resolution'   => $dispute->resolution,
                'raised_by_me' => (int) $dispute->raised_by === (int) $user->id,
                'opened_at'    => $dispute->created_at?->toIso8601String(),
            ],
            'terms'            => $order->offer?->terms_text,
            'payer_account'    => $this->snapshot($order, 'payer', $order->payer_payment_account_snapshot),
            'receiver_account' => $this->snapshot($order, 'receiver', $order->receiver_payment_account_snapshot),
            'my_feedback'      => $myFeedback ? [
                'rating'  => (int) $myFeedback->rating,
                'comment' => $myFeedback->comment,
            ] : null,
        ]);
    }

    public function status(Request $request, string $order): JsonResponse
    {
        $user  = $request->user();
        $order = $this->find($user, $order, ['offer', 'feedbacks']);
        $state = $this->states->for($order, (int) $user->id);

        return ApiResponse::ok([
            'id'             => $order->id,
            'order_number'   => $order->order_number,
            'status'         => $order->status->value,
            'display_status' => $state['display_status']->value,
            'is_expired'     => $state['is_expired'],
            'expires_at'     => $order->expires_at?->toIso8601String(),
            'now'            => now()->toIso8601String(),
        ] + $this->actions($order, $user, $state));
    }

    /**
     * Take a trade ad: pick one of the customer's own payment accounts and an
     * amount of the asset. P2POrderPlacement pairs it with the advertiser's
     * account and P2POrderService holds the seller's escrow in the same
     * transaction that writes the order. The plan's trade quota answers 403
     * FEATURE_NOT_ACCESSIBLE.
     */
    public function store(Request $request, P2POrderPlacement $placement): JsonResponse
    {
        $user = $request->user();

        if (($refusal = $placement->tradeQuotaRefusal($user)) !== null) {
            return ApiResponse::error(ApiErrorCode::FeatureNotAccessible, $refusal);
        }

        $offer = is_numeric($request->input('offer_id'))
            ? Offer::query()->with(['wallet.currency', 'paymentMethods', 'user'])->find((int) $request->input('offer_id'))
            : null;

        $validated = $request->validate([
            'offer_id'           => ['required', 'integer'],
            'payment_account_id' => ['required', 'integer'],
            'amount'             => AmountEntry::rules($offer?->wallet?->currency?->code ?? siteCurrency()),
        ]);

        if ($offer === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $order = $placement->place($user, $offer, (int) $validated['payment_account_id'], $validated['amount']);

        return ApiResponse::created($this->fresh($user, $order));
    }

    /**
     * The buyer says the payment has been sent.
     */
    public function markPaid(Request $request, string $order): JsonResponse
    {
        return $this->step($request, $order, 'markPaid', fn (Order $found, int $actor) => $this->service->markPaid($found, $actor));
    }

    /**
     * The seller confirms the payment arrived and releases the escrow. A
     * second release finds the order completed and releases nothing.
     *
     * A release hands the asset over for good, and an app token never
     * expires, so the wallet PIN is asked for when the seller has one - as
     * every other API step that moves money out does. It is checked inside
     * the step, after the order is found and the step is known to be the
     * seller's, so a stranger still learns nothing and a wrong PIN leaves the
     * escrow where it was.
     */
    public function release(Request $request, string $order, WalletPinGuard $pins): JsonResponse
    {
        $validated = $request->validate([
            'wallet_pin' => ['nullable', 'string'],
        ]);

        return $this->step($request, $order, 'release', function (Order $found, int $actor) use ($request, $pins, $validated): Order {
            $pins->assert($request->user(), $validated['wallet_pin'] ?? null, context: ['p2p_order_id' => $found->id]);

            return $this->service->release($found, $actor);
        });
    }

    /**
     * The buyer calls the trade off before paying; the escrow goes back.
     */
    public function cancel(Request $request, string $order): JsonResponse
    {
        return $this->step($request, $order, 'cancel', fn (Order $found, int $actor) => $this->service->cancel($found, $actor));
    }

    /**
     * Review a completed trade - the taker's, once.
     */
    public function review(Request $request, string $order, P2POrderAftercare $aftercare): JsonResponse
    {
        $validated = $request->validate([
            'rating'  => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:500'],
            'tags'    => ['sometimes', 'array', 'max:5'],
            'tags.*'  => ['string', Rule::in(P2POfferController::REVIEW_TAGS)],
        ]);

        $user  = $request->user();
        $found = $this->find($user, $order, ['offer']);

        $aftercare->review($user, $found, (int) $validated['rating'], $validated['comment'] ?? null, array_values(array_unique($validated['tags'] ?? [])));

        return ApiResponse::created($this->fresh($user, $found));
    }

    /**
     * Open a dispute on a pending or paid trade - either party's, once, inside
     * the dispute window. The trade is frozen for an administrator to settle.
     */
    public function dispute(Request $request, string $order, P2POrderAftercare $aftercare): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $user  = $request->user();
        $found = $this->find($user, $order, ['offer']);

        $aftercare->dispute($user, $found, $validated['reason']);

        return ApiResponse::created($this->fresh($user, $found));
    }

    /**
     * One step of the trade, taken by the party the website lets take it.
     * Anyone who is not a party is told the order does not exist; the other
     * party is told the step is not theirs.
     *
     * @param \Closure(Order, int): Order $action
     */
    private function step(Request $request, string $order, string $ability, \Closure $action): JsonResponse
    {
        $user  = $request->user();
        $found = $this->find($user, $order, ['offer']);

        if (! $user->can($ability, $found)) {
            return ApiResponse::error(ApiErrorCode::Forbidden, __('This step is not yours to take on this trade.'));
        }

        $action($found, (int) $user->id);

        return ApiResponse::ok($this->fresh($user, $found));
    }

    /**
     * @return array<string, mixed>
     */
    private function fresh(User $user, Order $order): array
    {
        $order = Order::query()
            ->with(['offer.quoteCurrency', 'wallet.currency', 'paymentMethod', 'maker', 'taker', 'feedbacks'])
            ->findOrFail($order->id);

        return $this->order($order, $user);
    }

    /**
     * The order's chat, oldest first; ?after={id} fetches only what is newer,
     * for polling. Reading marks the other party's messages read, as the
     * website's feed does.
     */
    public function messages(Request $request, string $order): JsonResponse
    {
        $validated = $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'],
        ]);

        $user    = $request->user();
        $order   = $this->find($user, $order, ['offer']);
        $afterId = (int) ($validated['after'] ?? 0);

        $messages = OrderMessage::query()
            ->with('sender:id,first_name,last_name,username')
            ->where('order_id', $order->id)
            ->when($afterId > 0, fn (Builder $query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit(200)
            ->get();

        OrderMessage::query()
            ->where('order_id', $order->id)
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return ApiResponse::ok([
            'messages' => $messages->map(fn (OrderMessage $message): array => $this->message($message, $order, $user))->values(),
            'last_id'  => (int) ($messages->last()->id ?? $afterId),
            'can_chat' => $user->can('chat', $order),
        ]);
    }

    /**
     * Say something in the order's chat, or - the buyer only - upload the
     * payment proof, as multipart. The chat is open while the trade can still
     * move; after that it is read-only, as on the website.
     */
    public function send(Request $request, string $order, P2POrderChatService $chat): JsonResponse
    {
        $user  = $request->user();
        $found = $this->find($user, $order, ['offer']);

        if (! $user->can('chat', $found)) {
            return ApiResponse::error(ApiErrorCode::Forbidden, __('This chat is closed.'));
        }

        // Evidence in a dispute is the same upload: either party, while the
        // agent is looking.
        if ($request->hasFile('proof') && ! $user->can('markPaid', $found) && $found->status !== OrderStatus::DISPUTED) {
            return ApiResponse::error(ApiErrorCode::Forbidden, __('Only the buyer can upload a payment proof.'));
        }

        $validated = $request->validate([
            'body'  => ['nullable', 'required_without:proof', 'string', 'max:1000'],
            'proof' => ['nullable', File::types(['jpg', 'jpeg', 'png', 'webp', 'pdf'])->max(5 * 1024)],
        ]);

        $message = $chat->store($found, $user, $validated['body'] ?? null, $request->file('proof'));

        return ApiResponse::created($this->message($message, $found, $user));
    }

    /**
     * @return array<string, mixed>
     */
    private function message(OrderMessage $message, Order $order, User $user): array
    {
        return [
            'id'          => $message->id,
            'body'        => (string) $message->body,
            'mine'        => (int) $message->sender_id === (int) $user->id,
            'sender_name' => $message->sender?->name,
            'sent_at'     => $message->created_at?->toIso8601String(),
            'attachment'  => $message->attachment_path ? [
                'url'      => route('api.mobile.p2p.orders.messages.attachment', [$order->order_number, $message->id]),
                'name'     => $message->attachment_name ?: __('Payment proof'),
                'mime'     => $message->attachment_mime,
                'is_image' => str_starts_with((string) $message->attachment_mime, 'image/'),
                'size'     => $message->attachment_size !== null ? (int) $message->attachment_size : null,
            ] : null,
        ];
    }

    /**
     * A payment proof from the order's chat, streamed from the private disk
     * with the website's headers - never rendered in the app's own origin.
     */
    public function attachment(Request $request, string $order, string $message): StreamedResponse
    {
        $order = $this->find($request->user(), $order, []);

        $message = OrderMessage::query()
            ->where('order_id', $order->id)
            ->whereNotNull('attachment_path')
            ->findOrFail($message);

        abort_unless(Storage::disk('local')->exists($message->attachment_path), 404);

        return Storage::disk('local')->response(
            $message->attachment_path,
            $message->attachment_name ?: __('payment-proof'),
            [
                'Content-Type'            => $message->attachment_mime ?: 'application/octet-stream',
                'X-Content-Type-Options'  => 'nosniff',
                'Content-Security-Policy' => 'sandbox',
                'Referrer-Policy'         => 'no-referrer',
                'Cache-Control'           => 'private, no-store',
            ],
        );
    }

    /**
     * A file on either party's payment account, as the order recorded it,
     * from the private disk - for the two parties only.
     */
    public function paymentFile(Request $request, string $order, string $side, string $field): StreamedResponse
    {
        $order    = $this->find($request->user(), $order, []);
        $snapshot = $side === 'payer' ? $order->payer_payment_account_snapshot : $order->receiver_payment_account_snapshot;

        return P2PPaymentFileDisk::response(P2PPaymentFileDisk::fromDetails((array) ($snapshot['details'] ?? []), $field));
    }

    /**
     * Orders the customer is a party to, on either side.
     *
     * @return Builder<Order>
     */
    private function forParty(User $user): Builder
    {
        return Order::query()->where(fn (Builder $query) => $query
            ->where('maker_id', $user->id)
            ->orWhere('taker_id', $user->id));
    }

    /**
     * @param list<string> $with
     */
    private function find(User $user, string $key, array $with): Order
    {
        return $this->forParty($user)
            ->with($with)
            ->where(fn (Builder $query) => ctype_digit($key)
                ? $query->whereKey($key)
                : $query->where('order_number', $key))
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function order(Order $order, User $user): array
    {
        $state        = $this->states->for($order, (int) $user->id);
        $asset        = $order->wallet?->currency?->code;
        $fiat         = $order->offer?->quoteCurrency?->code;
        $counterparty = (int) $order->maker_id === (int) $user->id ? $order->taker : $order->maker;

        return [
            'id'             => $order->id,
            'order_number'   => $order->order_number,
            'offer_id'       => $order->offer_id,
            'side'           => $order->offer?->side->value,
            'role'           => $state['role'],
            'status'         => $order->status->value,
            'display_status' => $state['display_status']->value,
            'is_expired'     => $state['is_expired'],
            'asset'          => $asset,
            'fiat'           => $fiat,
            'amount'         => ApiMoney::amount((string) $order->amount, $asset),
            'price'          => ApiMoney::amount((string) $order->price, $fiat),
            'total'          => ApiMoney::amount((string) $order->total, $fiat),
            'payment_method' => $order->paymentMethod ? ['id' => $order->paymentMethod->id, 'name' => $order->paymentMethod->name] : null,
            'counterparty'   => $counterparty ? [
                'id'         => $counterparty->id,
                'name'       => $counterparty->name,
                'username'   => $counterparty->username,
                'avatar_url' => asset($counterparty->avatar_alt),
            ] : null,
            'created_at'   => $order->created_at?->toIso8601String(),
            'expires_at'   => $order->expires_at?->toIso8601String(),
            'paid_at'      => $order->paid_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'disputed_at'  => $order->disputed_at?->toIso8601String(),
        ] + $this->actions($order, $user, $state);
    }

    /**
     * The next steps that are this party's to take - the website's buttons.
     *
     * @param  array{role: string, is_expired: bool, display_status: OrderStatus, can_mark_paid: bool, can_release: bool, can_cancel: bool} $state
     * @return array<string, bool>
     */
    private function actions(Order $order, User $user, array $state): array
    {
        $hasReviewed = $order->feedbacks->where('user_id', $user->id)->isNotEmpty();

        return [
            'can_mark_paid' => $state['can_mark_paid'],
            'can_release'   => $state['can_release'],
            'can_cancel'    => $state['can_cancel'],
            'can_dispute'   => in_array($order->status->value, ['PENDING', 'PAID'], true),
            'can_review'    => $order->status === OrderStatus::COMPLETED
                && (int) $order->taker_id     === (int) $user->id
                && ! $hasReviewed,
        ];
    }

    /**
     * The payment account as it stood when the order was placed - only the
     * parts the website prints. A file field is a link to the file, never
     * its stored path.
     *
     * @param  array<string, mixed>|null $snapshot
     * @return array<string, mixed>|null
     */
    private function snapshot(Order $order, string $side, ?array $snapshot): ?array
    {
        if (! is_array($snapshot) || $snapshot === []) {
            return null;
        }

        return [
            'payment_method' => $snapshot['payment_method_name'] ?? null,
            'logo_url'       => $snapshot['payment_method_logo'] ?? null,
            'account'        => $snapshot['account_label']       ?? $snapshot['display_name'] ?? null,
            'details'        => collect($snapshot['details'] ?? [])
                ->filter(fn ($detail): bool => is_array($detail) && trim((string) ($detail['value'] ?? '')) !== '')
                ->map(fn (array $detail): array => ($detail['type'] ?? '') === 'file'
                    ? [
                        'label'    => (string) ($detail['label'] ?? ''),
                        'value'    => null,
                        'type'     => 'file',
                        'file_url' => route('api.mobile.p2p.orders.payment-file', [$order->id, $side, (string) ($detail['key'] ?? '')]),
                    ]
                    : [
                        'label' => (string) ($detail['label'] ?? ''),
                        'value' => (string) $detail['value'],
                        'type'  => (string) ($detail['type'] ?? 'text'),
                    ])
                ->values()
                ->all(),
            'instructions' => filled($snapshot['method_instructions'] ?? null) ? (string) $snapshot['method_instructions'] : null,
        ];
    }
}
