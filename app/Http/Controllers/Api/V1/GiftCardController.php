<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Models\GiftCardTemplate;
use App\Models\User;
use App\Services\GiftCardService;
use App\Services\Money\WalletPinGuard;
use App\Support\AmountEntry;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gift cards from the app. Buying and redeeming go through GiftCardService,
 * the website's own: a card is redeemed only into a wallet in its currency,
 * and credits once. Cancelling is not offered here: the website's cancel
 * voids a card without returning the sender's money, and whether it should
 * is still to be decided.
 *
 * The list is the three tabs of the website's page - the
 * ones the customer sent, the ones sent to them (by account or by their email
 * address), and the redeemed ones.
 *
 * Each card carries its code, as it does on every tab of the website. The
 * counts come without the website's "total value", which adds amounts in
 * different currencies into one number.
 */
class GiftCardController extends Controller
{
    private const TABS = ['sent', 'received', 'redeemed'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['tab' => ['sometimes', Rule::in(self::TABS)]]);
        $user      = $request->user();
        $tab       = $validated['tab'] ?? 'sent';

        $query = match ($tab) {
            'received' => $this->received($user),
            'redeemed' => GiftCard::query()
                ->where(fn (Builder $q) => $q->where('redeemed_by', $user->id)->orWhere('user_id', $user->id))
                ->where('status', 'redeemed')
                ->latest('redeemed_at'),
            default => $this->sent($user),
        };

        $page = $query->with(['currency', 'template'])->latest('id')->paginate(8);

        $page->through(fn (GiftCard $card): array => $this->card($card));

        $response = ApiResponse::paginated($page);
        $payload  = $response->getData(true);

        $payload['meta']['counts'] = [
            'sent'       => $this->sent($user)->count(),
            'received'   => $this->received($user)->count(),
            'unredeemed' => $this->received($user)->whereIn('status', ['pending', 'delivered'])->count(),
        ];
        $payload['meta']['tab'] = $tab;

        return $response->setData($payload);
    }

    /**
     * The designs a card can be bought in, as the website's form offers them.
     */
    public function templates(): JsonResponse
    {
        return ApiResponse::ok([
            'templates' => GiftCardTemplate::query()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (GiftCardTemplate $template): array => [
                    'id'               => $template->id,
                    'name'             => $template->name,
                    'category'         => $template->category,
                    'ribbon_text'      => $template->ribbon_text,
                    'background_color' => $template->background_color,
                    'text_color'       => $template->text_color,
                    'default_amount'   => $template->default_amount !== null ? (string) $template->default_amount : null,
                    'image_url'        => filled($template->image) ? asset($template->image) : null,
                    'thumbnail_url'    => filled($template->thumbnail) ? asset($template->thumbnail) : null,
                ])
                ->values(),
            'categories' => GiftCardTemplate::CATEGORIES,
            'currency'   => siteCurrency(),
        ]);
    }

    /**
     * Buy a card for someone, from one of the customer's wallets.
     *
     * The amount is entered in the site currency, as on the website's form,
     * and becomes a card in the wallet's currency. The wallet PIN is asked for
     * when one is set - money leaves a wallet; the website does not ask. Only
     * a template that is on offer can be bought; the website takes any id.
     */
    public function store(Request $request, GiftCardService $giftCards, WalletPinGuard $pins): JsonResponse
    {
        $uuid   = $request->input('wallet_id');
        $wallet = is_string($uuid)
            ? $request->user()->wallets()->with('currency.roles')->where('uuid', $uuid)->first()
            : null;

        $validated = $request->validate([
            'template_id'    => ['required', 'integer'],
            'wallet_id'      => ['required', 'string'],
            'amount'         => AmountEntry::rules(siteCurrency()),
            'recipient_mode' => ['required', Rule::in(['user', 'email'])],
            // A recipient picked from Contacts arrives as their account id,
            // because the app only ever sees a contact's address masked.
            'recipient_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'recipient_name'    => ['required_without:recipient_user_id', 'nullable', 'string', 'max:120'],
            'recipient_email'   => ['required_without:recipient_user_id', 'nullable', 'email', 'max:191'],
            'sender_name'       => ['required', 'string', 'max:120'],
            'message'           => ['nullable', 'string', 'max:200'],
            'scheduled_at'      => ['nullable', 'date', 'after:now'],
            'wallet_pin'        => ['nullable', 'string'],
        ]);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $template = GiftCardTemplate::query()->active()->findOrFail($validated['template_id']);

        $recipient = isset($validated['recipient_user_id'])
            ? User::query()->findOrFail($validated['recipient_user_id'])
            : null;

        if ($recipient !== null && (int) $recipient->id === (int) $request->user()->id) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('You cannot send a gift card to yourself.'));
        }

        $pins->assert($request->user(), $validated['wallet_pin'] ?? null);

        $card = $giftCards->issue($request->user(), $wallet, $template, $validated['amount'], [
            'recipient_mode'  => $recipient !== null ? 'user' : $validated['recipient_mode'],
            'recipient_name'  => $recipient?->name  ?? $validated['recipient_name'],
            'recipient_email' => $recipient?->email ?? $validated['recipient_email'],
            'sender_name'     => $validated['sender_name'],
            'message'         => $validated['message'] ?? null,
            'schedule'        => filled($validated['scheduled_at'] ?? null),
            'scheduled_at'    => $validated['scheduled_at'] ?? null,
        ]);

        return ApiResponse::created($this->card($card->load(['currency', 'template'])));
    }

    /**
     * What a code is worth, before anything is redeemed: the amount, who
     * sent it and what they wrote, and whether this customer may redeem it.
     * The code is the secret, as it is on the website's redeem page; an
     * unknown one is simply not found.
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:64']]);

        $code = strtoupper(str_replace([' ', '_'], '-', trim($validated['code'])));
        $card = GiftCard::query()->with(['currency', 'template'])->where('code', $code)->first();

        if ($card === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $own    = (int) $card->user_id === (int) $request->user()->id;
        $reason = match (true) {
            $own                     => 'own',
            $card->isRedeemed()      => 'redeemed',
            $card->isExpired()       => 'expired',
            ! $card->canBeRedeemed() => 'inactive',
            default                  => null,
        };

        return ApiResponse::ok($this->card($card) + [
            'redeemable' => $reason === null,
            'reason'     => $reason,
        ]);
    }

    /**
     * Redeem a card someone sent, into the customer's wallet in its currency.
     */
    public function redeem(Request $request, GiftCardService $giftCards): JsonResponse
    {
        $uuid   = $request->input('wallet_id');
        $wallet = is_string($uuid) ? $request->user()->wallets()->where('uuid', $uuid)->first() : null;

        $validated = $request->validate([
            'code'      => ['required', 'string', 'max:64'],
            'wallet_id' => ['required', 'string'],
        ]);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $transaction = $giftCards->redeem($request->user(), $validated['code'], (int) $wallet->id);
        $code        = (string) $transaction->payable_currency;

        return ApiResponse::created([
            'reference' => (string) $transaction->trx_id,
            'amount'    => ApiMoney::amount((string) $transaction->net_amount, $code),
            'currency'  => $code,
        ]);
    }

    /**
     * Cancel a card the customer sent that has not gone out or been opened
     * yet - the states the website allows - and return its value to their
     * wallet in its currency. Only one's own card; anyone else's is not found.
     */
    public function cancel(Request $request, string $card, GiftCardService $giftCards): JsonResponse
    {
        $found = $this->sent($request->user())->with('currency')->find($card);

        if ($found === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        if (! in_array($found->status, ['pending', 'scheduled'], true)) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('Only pending or scheduled cards can be cancelled.'));
        }

        $refund = $giftCards->cancel($found, ['pending', 'scheduled']);
        $code   = (string) $refund->payable_currency;

        return ApiResponse::ok([
            'card'   => $this->card($found->refresh()),
            'refund' => [
                'reference' => (string) $refund->trx_id,
                'amount'    => ApiMoney::amount((string) $refund->net_amount, $code),
                'currency'  => $code,
            ],
        ]);
    }

    private function sent(User $user): Builder
    {
        return GiftCard::query()->where('user_id', $user->id)->latest();
    }

    private function received(User $user): Builder
    {
        return GiftCard::query()
            ->where(fn (Builder $q) => $q->where('recipient_user_id', $user->id)->orWhere('recipient_email', $user->email))
            ->latest();
    }

    /**
     * @return array<string, mixed>
     */
    private function card(GiftCard $card): array
    {
        $code = $card->currency?->code;

        return [
            'id'       => $card->id,
            'code'     => $card->code,
            'amount'   => ApiMoney::amount((string) $card->amount, $code),
            'currency' => $code,
            'status'   => $card->status instanceof BackedEnum ? $card->status->value : $card->status,
            'template' => $card->template?->name,
            // The artwork the card was bought in, so the app can draw it
            // rather than a grey box; the colours stand in when there is
            // no image.
            'template_id'      => $card->gift_card_template_id,
            'image_url'        => filled($card->template?->image) ? asset($card->template->image) : null,
            'background_color' => $card->template?->background_color,
            'text_color'       => $card->template?->text_color,
            'recipient_name'   => $card->recipient_name,
            'recipient_email'  => $card->recipient_email,
            'sender_name'      => $card->sender_name,
            'message'          => $card->message,
            'delivery_method'  => $card->delivery_method,
            'scheduled_at'     => $card->scheduled_at?->toIso8601String(),
            'delivered_at'     => $card->delivered_at?->toIso8601String(),
            'expires_at'       => $card->expires_at?->toIso8601String(),
            'redeemed_at'      => $card->redeemed_at?->toIso8601String(),
            'created_at'       => $card->created_at?->toIso8601String(),
        ];
    }
}
