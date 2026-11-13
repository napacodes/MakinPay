<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Enums\KycStatus;
use App\Enums\VirtualCard\CardholderStatus;
use App\Enums\VirtualCard\CardholderType;
use App\Enums\VirtualCard\VirtualCardNetwork;
use App\Enums\VirtualCard\VirtualCardRequestStatus;
use App\Enums\VirtualCard\VirtualCardStatus;
use App\Enums\VirtualCard\VirtualCardTopupStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Models\Cardholders;
use App\Models\VirtualCard;
use App\Models\VirtualCardActivity;
use App\Models\VirtualCardProvider;
use App\Models\VirtualCardRequest;
use App\Models\Wallet;
use App\Services\Media\MediaSettings;
use App\Services\Money\WalletPinGuard;
use App\Services\VirtualCard\VirtualCardActivityService;
use App\Services\VirtualCard\VirtualCardControls;
use App\Services\VirtualCard\VirtualCardManager;
use App\Services\VirtualCard\VirtualCardProviderFactory;
use App\Services\VirtualCard\VirtualCardRequestService;
use App\Support\AmountEntry;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\DecimalMoney;
use App\Traits\FileManageTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Virtual cards from the app: the customer's cards, their card requests,
 * which wallets can fund a card on a given network, asking for a card,
 * freezing it and its settings, putting money on it and taking money off,
 * and - behind the wallet PIN - the full card.
 *
 * A card is shown by its last four digits only. The full number and CVV live
 * at the provider and never pass through here; the provider's own card id
 * and the raw provider metadata are not the app's business either.
 */
class VirtualCardController extends Controller
{
    use FileManageTrait;

    public function __construct(private readonly VirtualCardManager $cards, private readonly VirtualCardActivityService $activity) {}

    public function index(Request $request): JsonResponse
    {
        $cards = VirtualCard::query()
            ->with(['wallet.currency', 'provider', 'request.cardholder'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->latest('id')
            ->get();

        return ApiResponse::ok([
            'cards' => $cards->map(fn (VirtualCard $card): array => $this->card($card))->values(),
        ]);
    }

    public function requests(Request $request): JsonResponse
    {
        $user = $request->user();

        $requests = VirtualCardRequest::query()
            ->with(['wallet.currency', 'card'])
            ->where('user_id', $user->id)
            ->latest('id')
            ->get();

        return ApiResponse::ok([
            'requests' => $requests->map(fn (VirtualCardRequest $cardRequest): array => [
                'id'                  => $cardRequest->id,
                'reference'           => $cardRequest->uuid,
                'status'              => $cardRequest->status->value,
                'kind'                => $cardRequest->kind ?? 'new',
                'network'             => $cardRequest->network?->value,
                'currency'            => $cardRequest->wallet?->currency?->code,
                'admin_note'          => $cardRequest->admin_note,
                'initial_load_amount' => $cardRequest->wallet?->currency?->code === null ? null : ApiMoney::amount((string) $cardRequest->initial_load_amount, $cardRequest->wallet->currency->code),
                'card'                => $cardRequest->card ? ['id' => $cardRequest->card->id, 'last4' => $cardRequest->card->last4] : null,
                'created_at'          => $cardRequest->created_at?->toIso8601String(),
            ])->values(),
            // The issue fee spans every provider, as the website's form shows it.
            'fees' => [
                'currency'          => siteCurrency(),
                'min_issue_fee'     => $this->money(VirtualCardProvider::query()->min('issue_fee')),
                'max_issue_fee'     => $this->money(VirtualCardProvider::query()->max('issue_fee')),
                'min_issue_fee_pct' => $this->decimal(VirtualCardProvider::query()->min('issue_fee_pct')),
                'max_issue_fee_pct' => $this->decimal(VirtualCardProvider::query()->max('issue_fee_pct')),
            ],
            'networks'    => array_map(fn (VirtualCardNetwork $network): string => $network->value, VirtualCardNetwork::cases()),
            'cardholders' => Cardholders::query()
                ->where('user_id', $user->id)
                ->where('status', CardholderStatus::APPROVED)
                ->get()
                ->map(fn (Cardholders $cardholder): array => [
                    'id'   => $cardholder->id,
                    'name' => $cardholder->full_name,
                    'type' => $cardholder->card_type->value,
                ])
                ->values(),
            'pending_cardholders' => Cardholders::query()
                ->where('user_id', $user->id)
                ->where('status', CardholderStatus::PENDING)
                ->count(),
            // Copy the request form shows: how long approval takes, and that a
            // cardholder can be applied for in the app.
            'approval_time'        => (string) setting('virtual_card_approval_time', __('Usually within 1 business day')),
            'can_apply_cardholder' => true,
            'push_provisioning'    => false,
        ]);
    }

    /**
     * Apply to be a cardholder from the app: the personal form the website
     * has, with the address and one identity document. An administrator
     * reviews it; until then the app shows it as pending.
     */
    public function applyCardholder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name'    => ['required', 'string', 'max:191'],
            'last_name'     => ['required', 'string', 'max:191'],
            'middle_name'   => ['nullable', 'string', 'max:191'],
            'dob'           => ['required', 'date', 'before:-18 years'],
            'gender'        => ['nullable', 'string', 'max:16'],
            'nationality'   => ['nullable', 'string', 'max:10'],
            'email'         => ['nullable', 'email', 'max:191'],
            'mobile'        => ['nullable', 'string', 'max:30'],
            'address_line1' => ['required', 'string', 'max:191'],
            'address_line2' => ['nullable', 'string', 'max:191'],
            'city'          => ['required', 'string', 'max:100'],
            'state'         => ['nullable', 'string', 'max:100'],
            'postal_code'   => ['required', 'string', 'max:20'],
            'country'       => ['required', 'string', 'max:10'],
            'id_type'       => ['required', 'string', 'max:32'],
            'id_number'     => ['required', 'string', 'max:191'],
            'id_expiry'     => ['nullable', 'date', 'after:today'],
            'id_document'   => ['required', 'file', 'mimes:'.implode(',', MediaSettings::customerSafe(MediaSettings::identityDocumentExtensions())), 'max:'.MediaSettings::effectiveMaxUploadSizeKb()],
            // Optional; some card programs (BSICARDS Mastercard) need them.
            'id_document_back' => ['nullable', 'file', 'mimes:'.implode(',', Cardholders::KYC_PHOTO_EXTENSIONS), 'max:'.MediaSettings::effectiveMaxUploadSizeKb()],
            'selfie'           => ['nullable', 'file', 'mimes:'.implode(',', Cardholders::KYC_PHOTO_EXTENSIONS), 'max:'.MediaSettings::effectiveMaxUploadSizeKb()],
        ]);

        $user = $request->user();

        $documents = [];
        foreach (['id_document', 'id_document_back', 'selfie'] as $document) {
            if ($request->hasFile($document)) {
                $documents[$document] = $this->uploadFile($request->file($document));
            }
        }

        $cardholder = Cardholders::query()->create(array_merge(
            collect($validated)->except(['id_document', 'id_document_back', 'selfie'])->all(),
            [
                'user_id'       => $user->id,
                'email'         => $validated['email'] ?? $user->email,
                'card_type'     => CardholderType::PERSONAL->value,
                'kyc_type'      => $validated['id_type'],
                'kyc_status'    => KycStatus::PENDING->value,
                'kyc_documents' => $documents,
                'status'        => CardholderStatus::PENDING->value,
            ],
        ));

        return ApiResponse::created([
            'id'         => $cardholder->id,
            'name'       => $cardholder->full_name,
            'status'     => $cardholder->status->value,
            'created_at' => $cardholder->created_at?->toIso8601String(),
        ]);
    }

    /**
     * What a top-up or withdrawal would cost before the customer commits:
     * the fee, what the card gains or loses, and the wallet figure after.
     */
    public function quote(Request $request, string $card): JsonResponse
    {
        $found     = $this->ownCard($request, $card);
        $validated = $request->validate([
            'operation' => ['required', Rule::in(['topup', 'withdraw'])],
            'amount'    => AmountEntry::rules($found->wallet?->currency?->code ?? siteCurrency()),
        ]);

        try {
            $quote = $this->cards->quote($found, $validated['operation'], (string) $validated['amount']);
        } catch (NotifyErrorException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage());
        }

        $balance = isset($found->meta['balance']) ? (string) $found->meta['balance'] : '0';
        $after   = $validated['operation'] === 'withdraw'
            ? DecimalMoney::subtract($balance, $quote['card_amount'], DecimalMoney::CALCULATION_SCALE)
            : DecimalMoney::add($balance, $quote['card_amount'], DecimalMoney::CALCULATION_SCALE);

        return ApiResponse::ok($quote + [
            'card_balance_after' => ApiMoney::amount(DecimalMoney::compare($after, '0') < 0 ? '0' : $after, $quote['card_currency']),
        ]);
    }

    /**
     * The card's own ledger: purchases, refunds, top-ups, withdrawals and
     * declines with why, newest first, and the figures the tiles show.
     * The provider is asked for fresh rows when it can answer and has not
     * been asked in the last few minutes.
     */
    public function activity(Request $request, string $card): JsonResponse
    {
        $validated = $request->validate([
            'filter' => ['sometimes', Rule::in(VirtualCardActivityService::FILTERS)],
        ]);
        $found = $this->ownCard($request, $card);

        $this->activity->sync($found);

        $page = $this->activity->list($found, $validated['filter'] ?? 'all');
        $page->through(fn (VirtualCardActivity $row): array => $this->activityRow($row));

        $response = ApiResponse::paginated($page);
        $payload  = $response->getData(true);

        $payload['meta']['summary'] = $this->activity->summary($found) + [
            'currency'      => $this->cardCurrency($found),
            'monthly_limit' => isset($found->meta['limits']['monthly']) ? ApiMoney::amount((string) $found->meta['limits']['monthly'], $this->cardCurrency($found)) : null,
        ];

        return $response->setData($payload);
    }

    /**
     * Ask for a new card in place of this one - blocked by the issuer, lost
     * or stolen, or expired. A platform request like any other: nothing is
     * debited now, an administrator approves, the provider issues. One at
     * a time per card.
     */
    public function replace(Request $request, string $card): JsonResponse
    {
        $found  = $this->ownCard($request, $card);
        $reason = (string) ($found->meta['freeze']['reason'] ?? '');

        $allowed = match ($found->status) {
            VirtualCardStatus::Blocked, VirtualCardStatus::Expired => true,
            VirtualCardStatus::Inactive => in_array($reason, ['lost', 'suspicious'], true),
            default                     => false,
        };
        if (! $allowed) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('Only a blocked, expired, lost or suspicious card can be replaced.'));
        }
        if (! ($found->provider?->supports('replace') ?? true)) {
            return ApiResponse::error(ApiErrorCode::FeatureNotAccessible, __('This card provider does not offer replacements.'));
        }

        $open = $found->replaced_by_request_id === null ? null : VirtualCardRequest::query()->find($found->replaced_by_request_id);
        if ($open !== null && ! in_array($open->status, [VirtualCardRequestStatus::Rejected, VirtualCardRequestStatus::Failed], true)) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('A replacement for this card has already been requested.'));
        }

        $original = $found->request;
        if ($original === null || $original->cardholder === null || $found->wallet === null) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('This card cannot be replaced from the app.'));
        }

        $kind        = $found->status === VirtualCardStatus::Expired ? 'renewal' : 'replacement';
        $cardRequest = VirtualCardRequest::query()->create([
            'cardholder_id'       => $original->cardholder_id,
            'wallet_id'           => $found->wallet_id,
            'user_id'             => $found->user_id,
            'network'             => $found->network,
            'theme'               => $original->theme ?? 'midnight',
            'status'              => VirtualCardRequestStatus::Pending,
            'initial_load_amount' => 0,
            'kind'                => $kind,
            'replaces_card_id'    => $found->id,
        ]);
        $found->forceFill(['replaced_by_request_id' => $cardRequest->id])->save();

        return ApiResponse::created([
            'id'         => $cardRequest->id,
            'reference'  => $cardRequest->uuid,
            'kind'       => $kind,
            'status'     => $cardRequest->status->value,
            'network'    => $cardRequest->network?->value,
            'currency'   => $found->wallet->currency?->code,
            'created_at' => $cardRequest->created_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function activityRow(VirtualCardActivity $row): array
    {
        return [
            'id'                => $row->id,
            'kind'              => $row->kind,
            'status'            => $row->status,
            'merchant'          => $row->merchant,
            'category'          => $row->category,
            'channel'           => $row->channel,
            'amount'            => ApiMoney::amount((string) $row->amount, $row->currency),
            'currency'          => $row->currency,
            'original_amount'   => $row->original_amount === null ? null : ApiMoney::amount((string) $row->original_amount, $row->original_currency),
            'original_currency' => $row->original_currency,
            'decline_reason'    => $row->decline_reason,
            'occurred_at'       => $row->occurred_at?->toIso8601String(),
        ];
    }

    /**
     * Ask for a card, funded from one of the customer's wallets (by uuid) for
     * one of their approved cardholders. Nothing is debited now - the initial
     * load leaves the wallet when an administrator approves - but an approval
     * turns into money, so this carries the money limit and a replay key: a
     * retried request files one request, not two.
     */
    public function requestCard(Request $request, VirtualCardRequestService $requests): JsonResponse
    {
        $uuid   = $request->input('wallet_id');
        $wallet = is_string($uuid) ? $request->user()->wallets()->with('currency')->where('uuid', $uuid)->first() : null;

        $validated = $request->validate([
            'cardholder_id'       => ['required', 'integer'],
            'wallet_id'           => ['required', 'string'],
            'network'             => ['required', Rule::enum(VirtualCardNetwork::class)],
            'initial_load_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'theme'               => ['nullable', 'string', Rule::in(['midnight', 'ocean', 'graphite', 'emerald', 'violet'])],
        ]);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $cardRequest = $requests->submit(
            user: $request->user(),
            wallet: $wallet,
            cardholderId: (int) $validated['cardholder_id'],
            network: $validated['network'],
            initialLoad: $validated['initial_load_amount'],
            theme: $validated['theme'] ?? null,
        );

        return ApiResponse::created([
            'id'                  => $cardRequest->id,
            'reference'           => $cardRequest->uuid,
            'status'              => $cardRequest->status->value,
            'network'             => $cardRequest->network?->value,
            'currency'            => $wallet->currency->code,
            'initial_load_amount' => ApiMoney::amount((string) $cardRequest->initial_load_amount, $wallet->currency->code),
            'created_at'          => $cardRequest->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Freeze one of the customer's cards. The provider is asked first; the
     * card is marked frozen here only once it answers.
     */
    public function freeze(Request $request, string $card, VirtualCardControls $controls): JsonResponse
    {
        $validated = $request->validate([
            'reason'   => ['nullable', Rule::in(['lost', 'suspicious', 'pause'])],
            'duration' => ['nullable', Rule::in(['1h', '24h', '7d', 'indefinite'])],
        ]);

        $found = $this->ownCard($request, $card);

        $controls->freeze($found, $validated['reason'] ?? null, $validated['duration'] ?? null);

        return ApiResponse::ok($this->card($found->refresh()));
    }

    public function unfreeze(Request $request, string $card, VirtualCardControls $controls): JsonResponse
    {
        $found = $this->ownCard($request, $card);

        $controls->unfreeze($found);

        return ApiResponse::ok($this->card($found->refresh()));
    }

    /**
     * The cardholder's own spend limits - advisory: kept on the card, not yet
     * enforced by any provider, as on the website.
     */
    public function limits(Request $request, string $card, VirtualCardControls $controls): JsonResponse
    {
        $validated = $request->validate([
            'per_transaction' => ['nullable', 'numeric', 'gte:0'],
            'daily'           => ['nullable', 'numeric', 'gte:0'],
            'monthly'         => ['nullable', 'numeric', 'gte:0'],
            'alert_at'        => ['nullable', 'integer', 'between:0,100'],
            'auto_freeze'     => ['nullable', 'boolean'],
        ]);

        $found = $this->ownCard($request, $card);

        $controls->saveLimits($found, $validated, $request->boolean('auto_freeze'));

        return ApiResponse::ok($this->card($found->refresh()));
    }

    /**
     * Where the card may be used - advisory, as the limits are.
     */
    public function controls(Request $request, string $card, VirtualCardControls $controls): JsonResponse
    {
        $request->validate([
            'online'      => ['nullable', 'boolean'],
            'atm'         => ['nullable', 'boolean'],
            'intl'        => ['nullable', 'boolean'],
            'contactless' => ['nullable', 'boolean'],
        ]);

        $found = $this->ownCard($request, $card);

        $controls->saveControls($found, $request->boolean('online'), $request->boolean('atm'), $request->boolean('intl'), $request->boolean('contactless'));

        return ApiResponse::ok($this->card($found->refresh()));
    }

    /**
     * The full card - number, CVV, expiry and billing address - fetched from
     * the provider for its owner, to pay with.
     *
     * An app token never expires, so the token alone is not enough to read a
     * card number off: the wallet PIN is asked for every time, and a customer
     * who has not set one is told to. A wrong PIN spends the same allowance as
     * everywhere else it is asked for. The answer is never stored by a cache
     * and never logged; only the fields a payment form needs are passed on.
     */
    public function reveal(Request $request, string $card, VirtualCardProviderFactory $providers, WalletPinGuard $pins): JsonResponse
    {
        $validated = $request->validate([
            'wallet_pin' => ['nullable', 'string'],
        ]);

        $user  = $request->user();
        $found = $this->ownCard($request, $card);

        if (! $user->hasWalletPin()) {
            return $this->noStore(ApiResponse::error(ApiErrorCode::WalletPinRequired, __('Set a wallet PIN before viewing your card details.')));
        }

        $pins->assert($user, $validated['wallet_pin'] ?? null);

        if (! $found->provider) {
            return $this->noStore(ApiResponse::error(ApiErrorCode::ValidationFailed, __('No virtual card provider is linked to this card.')));
        }

        $details = $providers->getProvider($found->provider->code)->getCardDetails($found);

        if (is_string($details)) {
            // A provider that renders its own secure view instead of data.
            return $this->noStore(ApiResponse::ok(['available' => true, 'html' => $details]));
        }

        $fields = array_intersect_key((array) $details, array_flip([
            'card_number', 'cvv', 'expiry', 'card_holder_name', 'card_brand', 'last4', 'currency',
            'billing_street', 'billing_city', 'billing_country', 'billing_zip_code',
        ]));

        if (blank($fields['card_number'] ?? null)) {
            return $this->noStore(ApiResponse::ok([
                'available' => false,
                'message'   => $details['message'] ?? __('Card details are not available for this provider.'),
            ]));
        }

        return $this->noStore(ApiResponse::ok(['available' => true] + $fields));
    }

    /**
     * Put money on a card from its wallet, through VirtualCardManager::topup:
     * the wallet is debited and the pending row written in one committed
     * transaction, the provider is called with nothing open, and the attempt
     * is settled, refunded or parked for review by what the provider says.
     *
     * The request's replay key is handed to the manager as its own funding
     * key, so a retried request funds the card once even where the replay
     * guard has no answer to hand back. A completed top-up answers 200, one
     * still being confirmed 202, a refused one 422 - its amount already back
     * in the wallet. The wallet PIN is asked for when one is set.
     */
    public function topup(Request $request, string $card, VirtualCardManager $manager, WalletPinGuard $pins): JsonResponse
    {
        $found = $this->ownCard($request, $card);

        $validated = $request->validate([
            'amount'     => AmountEntry::rules($found->wallet?->currency?->code ?? siteCurrency()),
            'wallet_pin' => ['nullable', 'string'],
        ]);

        $pins->assert($request->user(), $validated['wallet_pin'] ?? null);

        $key = (string) $request->header('Idempotency-Key', '');

        try {
            $attempt = $manager->topup((int) $found->id, (string) $validated['amount'], $key !== '' ? 'api:'.hash('sha256', $key) : null);
        } catch (IdempotencyConflictException $e) {
            return ApiResponse::error(ApiErrorCode::IdempotencyConflict, $e->getMessage());
        }

        $body = ['state' => $this->topupState($attempt->status), 'card' => $this->card($found->refresh())];

        return match ($body['state']) {
            'completed' => ApiResponse::ok($body),
            'failed'    => ApiResponse::error(ApiErrorCode::ValidationFailed, __('The top-up did not go through. The amount has been returned to your wallet.'), [], null, [], ['data' => $body]),
            default     => ApiResponse::accepted($body),
        };
    }

    /**
     * Take money off a card back into its wallet, through
     * VirtualCardManager::withdraw: the pending row is written and the amount
     * taken off the card before the provider is asked; a refusal puts it back,
     * no answer leaves the row for reconciliation, a confirmed unload credits
     * the wallet. The wallet PIN is asked for when one is set.
     */
    public function withdraw(Request $request, string $card, VirtualCardManager $manager, WalletPinGuard $pins): JsonResponse
    {
        $found = $this->ownCard($request, $card);

        $validated = $request->validate([
            'amount'     => AmountEntry::rules($found->wallet?->currency?->code ?? siteCurrency()),
            'wallet_pin' => ['nullable', 'string'],
        ]);

        $pins->assert($request->user(), $validated['wallet_pin'] ?? null);

        $manager->withdraw((int) $found->id, (string) $validated['amount']);

        return ApiResponse::ok(['state' => 'completed', 'card' => $this->card($found->refresh())]);
    }

    private function topupState(VirtualCardTopupStatus $status): string
    {
        return match ($status) {
            VirtualCardTopupStatus::Completed => 'completed',
            VirtualCardTopupStatus::Failed    => 'failed',
            default                           => 'pending',
        };
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * One of the customer's own cards; anyone else's does not exist.
     */
    private function ownCard(Request $request, string $card): VirtualCard
    {
        return VirtualCard::query()
            ->with(['wallet.currency', 'provider', 'request.cardholder'])
            ->where('user_id', $request->user()->id)
            ->findOrFail($card);
    }

    public function eligibleWallets(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'network' => ['required', Rule::enum(VirtualCardNetwork::class)],
        ]);

        $currencies = VirtualCardProvider::query()
            ->active()
            ->whereJsonContains('supported_networks', $validated['network'])
            ->get()
            ->flatMap(fn (VirtualCardProvider $provider): array => $provider->supported_currencies ?: [])
            ->unique()
            ->values();

        $wallets = $request->user()->wallets()
            ->active()
            ->with('currency')
            ->whereHas('currency', fn ($query) => $query->whereIn('code', $currencies))
            ->get();

        return ApiResponse::ok([
            'wallets' => $wallets->map(fn (Wallet $wallet): array => [
                'uuid'     => $wallet->uuid,
                'currency' => $wallet->currency->code,
                'symbol'   => $wallet->currency->symbol,
                'balance'  => ApiMoney::amount((string) $wallet->balance, $wallet->currency->code),
            ])->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(VirtualCard $card): array
    {
        return [
            'id'            => $card->id,
            'status'        => $card->status->value,
            'network'       => $card->network?->value,
            'brand'         => $card->brand,
            'last4'         => $card->last4,
            'masked_number' => '**** **** **** '.$card->last4,
            'expiry_month'  => $card->expiry_month,
            'expiry_year'   => $card->expiry_year,
            'holder_name'   => $card->request?->cardholder?->full_name,
            'currency'      => $this->cardCurrency($card),
            'wallet'        => $card->wallet ? ['uuid' => $card->wallet->uuid, 'currency' => $card->wallet->currency?->code] : null,
            'provider'      => $card->provider?->name,
            // The cardholder's own settings - never the provider's raw meta.
            'frozen'     => $card->status === VirtualCardStatus::Inactive,
            'limits'     => (array) ($card->meta['limits'] ?? []),
            'controls'   => (array) ($card->meta['controls'] ?? []),
            'created_at' => $card->created_at?->toIso8601String(),
            // What the provider last said the card holds, in the card's currency.
            'balance'                => isset($card->meta['balance']) ? ApiMoney::amount((string) $card->meta['balance'], $this->cardCurrency($card)) : null,
            'frozen_until'           => $card->frozen_until?->toIso8601String(),
            'freeze'                 => isset($card->meta['freeze']) ? ['reason' => $card->meta['freeze']['reason'] ?? null, 'duration' => $card->meta['freeze']['duration'] ?? null, 'at' => $card->meta['freeze']['frozen_at'] ?? null] : null,
            'replacement_request_id' => $card->replaced_by_request_id,
            'summary'                => $this->activity->summary($card),
            'capabilities'           => [
                'topup'             => $card->provider?->supports('topup')             ?? false,
                'withdraw'          => $card->provider?->supports('withdraw')          ?? false,
                'freeze'            => $card->provider?->supports('freeze')            ?? false,
                'activity'          => $card->provider?->supports('activity')          ?? false,
                'replace'           => $card->provider?->supports('replace')           ?? true,
                'push_provisioning' => $card->provider?->supports('push_provisioning') ?? false,
            ],
        ];
    }

    /**
     * The currency the card is kept in - the provider's card currency, or the
     * funding wallet's when the card is kept in that.
     */
    private function cardCurrency(VirtualCard $card): ?string
    {
        $walletCurrency = $card->wallet?->currency?->code;

        if (! $card->provider || ! $card->wallet) {
            return $walletCurrency;
        }

        try {
            return $this->cards->cardCurrencyInfo($card->provider, $card->wallet)['card_currency'] ?? $walletCurrency;
        } catch (NotifyErrorException) {
            return $card->provider->cardCurrency() ?? $walletCurrency;
        }
    }

    private function decimal(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * A site-currency amount at the currency's own scale.
     */
    private function money(mixed $value): ?string
    {
        return $value === null ? null : ApiMoney::amount((string) $value, siteCurrency());
    }
}
