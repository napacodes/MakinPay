<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Money;

use App\Enums\Api\ApiErrorCode;
use App\Enums\PayoutMethod;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Remittance\RemittanceCorridorResource;
use App\Http\Resources\Api\V1\Remittance\RemittanceFigures;
use App\Http\Resources\Api\V1\Remittance\RemittanceQuoteResource;
use App\Http\Resources\Api\V1\Remittance\RemittanceTransferResource;
use App\Models\Beneficiary;
use App\Models\RemittanceCorridor;
use App\Models\RemittanceQuote;
use App\Models\RemittanceTransfer;
use App\Models\Wallet as WalletModel;
use App\Services\Api\IdempotencyGuard;
use App\Services\Money\WalletPinGuard;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use App\Services\Remittance\PayoutRouter;
use App\Services\Remittance\QuoteService;
use App\Services\Remittance\RemittanceService;
use App\Support\AmountEntry;
use App\Support\ApiResponse;
use App\Support\DecimalMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Sending money abroad, from a phone.
 *
 * A thin surface over the same three services the web form and the
 * integration API use - PayoutRouter finds the corridor, QuoteService prices
 * and locks the rate, RemittanceService screens, debits and hands the
 * transfer to its payout partner. Nothing is priced, screened or debited
 * here. What this controller adds is the app's envelope: money as strings,
 * the caller's own rows only, and a refusal the app can branch on by code.
 *
 * UNLIKE SEND MONEY, THERE IS A QUOTE TO EXPIRE. The rate is locked when the
 * quote is written and held for the corridor's quote_ttl_minutes; the send
 * names the quote rather than repeating the amount, so what is debited is
 * exactly what the customer was shown. A quote whose hold ran out - or that
 * already paid for a transfer - answers QUOTE_EXPIRED, which the app turns
 * into "the rate changed, here is the new one" rather than an error.
 *
 * EVERY ID IN A REQUEST IS SCOPED TO THE CALLER BEFORE THE SERVICE SEES IT.
 * The service checks ownership too, but it says so in a sentence ("Quote does
 * not belong to current user."), and a sentence that differs from "not found"
 * confirms the row exists. A quote, recipient or wallet belonging to somebody
 * else is RESOURCE_NOT_FOUND, exactly as one that never existed.
 *
 * THE WALLET PIN IS REQUIRED HERE as it is for send money, and checked before
 * any of the ids are looked up, so a wrong PIN learns nothing about which
 * quotes or recipients exist.
 */
class RemittanceController extends Controller
{
    /**
     * History page size. Fixed rather than caller-chosen: the app shows a
     * day-grouped list and asks for the next page as it scrolls.
     */
    private const PER_PAGE = 20;

    public function __construct(
        private readonly PayoutRouter $router,
        private readonly QuoteService $quotes,
        private readonly RemittanceService $remittances,
        private readonly WalletPinGuard $pins,
        private readonly PayoutGatewayFactory $gateways,
        private readonly IdempotencyGuard $idempotency,
    ) {}

    /**
     * Where the customer can send to.
     *
     * Active corridors whose payout partner is configured on this
     * installation - the same filter PayoutRouter applies when a quote is
     * asked for, so the app is never shown a destination it cannot price.
     */
    public function corridors(Request $request): JsonResponse
    {
        $corridors = RemittanceCorridor::query()
            ->active()
            ->whereIn('payout_gateway', $this->gateways->availableCodes())
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return ApiResponse::ok(RemittanceCorridorResource::collection($corridors)->resolve($request));
    }

    /**
     * Price a transfer and lock its rate.
     *
     * Every call writes a quote row, which is why this sits on the
     * remittance-quotes bucket rather than the read one. It moves nothing and
     * holds no money, so it carries no replay key: a duplicate is a second
     * quote nobody sends against.
     *
     * The corridor is resolved before validation because the amount's rules
     * depend on the currency it is typed in - one unit at that currency's
     * precision, as on every other money form.
     */
    public function quote(Request $request): JsonResponse
    {
        [$corridor, $corridorRefusal] = $this->activeCorridor($request->input('corridor_id'));

        $validated = $request->validate([
            'corridor_id'   => ['required', 'integer'],
            'send_amount'   => AmountEntry::rules($corridor?->source_currency),
            'payout_method' => ['required', Rule::enum(PayoutMethod::class)],
            'recipient_id'  => ['nullable', 'integer'],
        ]);

        if ($corridor === null) {
            return $this->invalid($corridorRefusal ?? __('Selected corridor is no longer active.'), 'corridor_id');
        }

        $recipientId = isset($validated['recipient_id']) ? (int) $validated['recipient_id'] : null;

        if ($recipientId !== null && ! Beneficiary::forUser($request->user()->id)->whereKey($recipientId)->exists()) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $method = PayoutMethod::from($validated['payout_method']);

        try {
            $quote = $this->quotes->quote(
                user: $request->user(),
                corridor: $corridor,
                sendAmount: (string) $validated['send_amount'],
                payoutMethod: $method,
                beneficiaryId: $recipientId,
            );
        } catch (InvalidArgumentException $e) {
            // The corridor refused: below the minimum, above the maximum, a
            // method it does not carry, or a partner that went away. The
            // service's sentence is written for the customer; the field it
            // is about is worked out from the corridor rather than the
            // sentence, which is translated.
            return $this->invalid($e->getMessage(), $this->quoteRefusalField($corridor, $method, (string) $validated['send_amount']));
        } catch (Throwable $e) {
            // Most often the rate provider being unreachable. Its message can
            // name a provider or a URL, so the customer is told only to try
            // again.
            report($e);

            return ApiResponse::error(ApiErrorCode::TemporarilyUnavailable);
        }

        return ApiResponse::ok([
            'quote' => (new RemittanceQuoteResource($quote))->resolve($request),
        ]);
    }

    /**
     * Send against a quote.
     *
     * Behind api.idempotency like every other money write, and the key is
     * ALSO handed to RemittanceService, which keeps its own per-user record
     * of it on the transfer. The two cover different failures: the
     * middleware replays the stored reply; the service's record survives a
     * request that debited the wallet and then died before a reply could be
     * stored, which the middleware would otherwise let run a second time.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quote_id'        => ['required', 'string', 'max:64'],
            'recipient_id'    => ['required', 'integer'],
            'wallet_id'       => ['required', 'string', 'max:64'],
            'purpose'         => ['nullable', 'string', 'max:120'],
            'source_of_funds' => ['nullable', 'string', 'max:120'],
            'wallet_pin'      => ['nullable', 'string'],
        ]);

        $user = $request->user();

        // Before anything is looked up, so a wrong PIN cannot be used to probe
        // which quotes, recipients or wallets exist.
        $this->pins->assert($user, $validated['wallet_pin'] ?? null);

        $quote = RemittanceQuote::query()
            ->with('corridor')
            ->where('user_id', $user->id)
            ->where('quote_id', $validated['quote_id'])
            ->first();

        $recipient = Beneficiary::forUser($user->id)->find((int) $validated['recipient_id']);

        $wallet = WalletModel::query()
            ->where('user_id', $user->id)
            ->where('uuid', $validated['wallet_id'])
            ->first();

        if ($quote === null || $recipient === null || $wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        try {
            $transfer = $this->remittances->send(
                user: $user,
                quote: $quote,
                beneficiary: $recipient,
                context: [
                    // The service takes the primary key, keeping its signature
                    // the one the web form uses; it re-scopes to the caller.
                    'wallet_id'       => $wallet->id,
                    'purpose'         => $validated['purpose']         ?? null,
                    'source_of_funds' => $validated['source_of_funds'] ?? null,
                    'idempotency_key' => $this->serviceKey($request),
                ],
            );
        } catch (IdempotencyConflictException $e) {
            return ApiResponse::error(ApiErrorCode::IdempotencyConflict, $e->getMessage() !== '' ? $e->getMessage() : null);
        } catch (NotifyErrorException $e) {
            // Already a customer-facing refusal with its own code - a wallet
            // switched off, a currency that cannot send. It renders itself.
            throw $e;
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->sendRefusal($e, $quote);
        } catch (Throwable $e) {
            // Everything that can move money runs inside the service's
            // transaction, so an unexpected failure here left the wallet as it
            // was. The key is released by the middleware on a 5xx, and the
            // service's own record answers a retry if the debit did commit.
            report($e);

            return ApiResponse::error(ApiErrorCode::TemporarilyUnavailable);
        }

        $replayed = (bool) $transfer->getAttribute('idempotent_replay');

        return ApiResponse::created(
            ['transfer' => (new RemittanceTransferResource($transfer->loadMissing('beneficiary')))->resolve($request)],
            ['Idempotency-Replayed' => $replayed ? 'true' : 'false'],
        );
    }

    /**
     * The caller's transfers abroad, newest first.
     *
     * `status` filters by the same group the transfer reports as
     * `status_group`, so a tab and a row's colour cannot disagree. It may
     * name several, comma-separated: the app's Failed tab asks for
     * `failed,refunded` in one list, so its pages stay in order and each row
     * still reads as what it is.
     */
    public function index(Request $request): JsonResponse
    {
        $groups = RemittanceTransferResource::groups();

        $validated = $request->validate([
            'status' => [
                'nullable',
                'string',
                'max:64',
                static function (string $attribute, mixed $value, \Closure $fail) use ($groups): void {
                    foreach (explode(',', (string) $value) as $group) {
                        if (! array_key_exists($group, $groups)) {
                            $fail(__('validation.in', ['attribute' => $attribute]));

                            return;
                        }
                    }
                },
            ],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $wanted   = isset($validated['status']) ? array_unique(explode(',', $validated['status'])) : [];
        $statuses = array_map(
            static fn ($case): string => $case->value,
            array_merge(...array_map(static fn (string $group): array => $groups[$group], $wanted)),
        );

        $transfers = RemittanceTransfer::query()
            ->forUser($request->user()->id)
            ->with('beneficiary')
            ->when($wanted !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->latest()
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return ApiResponse::paginated(RemittanceTransferResource::collection($transfers));
    }

    /**
     * One transfer, by its reference. Somebody else's is not found rather
     * than refused, because a refusal confirms it exists.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $transfer = RemittanceTransfer::query()
            ->forUser($request->user()->id)
            ->with('beneficiary')
            ->where('reference', $reference)
            ->first();

        if ($transfer === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        return ApiResponse::ok([
            'transfer' => (new RemittanceTransferResource($transfer))->resolve($request),
        ]);
    }

    /**
     * The corridor through PayoutRouter, or null with the router's reason.
     *
     * Guarded on is_numeric so an array sent where an id belongs fails
     * validation rather than reaching a cast.
     *
     * @return array{0: RemittanceCorridor|null, 1: string|null}
     */
    private function activeCorridor(mixed $id): array
    {
        if (! is_numeric($id)) {
            return [null, null];
        }

        try {
            return [$this->router->findActiveById((int) $id), null];
        } catch (Throwable $e) {
            // The router's one refusal is "no longer active", a sentence
            // written for the customer and safe to pass on.
            return [null, $e->getMessage()];
        }
    }

    /**
     * Which field a corridor refusal is about.
     *
     * Read from the corridor in the same order QuoteService checks it, so the
     * message the service chose and the field named here describe the same
     * failure.
     */
    private function quoteRefusalField(RemittanceCorridor $corridor, PayoutMethod $method, string $amount): string
    {
        if (! $corridor->supportsPayoutMethod($method)) {
            return 'payout_method';
        }

        $min = (string) (RemittanceFigures::raw($corridor, 'min_amount') ?? '0');
        $max = (string) (RemittanceFigures::raw($corridor, 'max_amount') ?? '0');

        if (DecimalMoney::compare($amount, $min) < 0 || DecimalMoney::compare($amount, $max) > 0) {
            return 'send_amount';
        }

        return 'corridor_id';
    }

    /**
     * A refusal from RemittanceService, sorted into the code the app needs.
     *
     * The service throws plain InvalidArgumentException and RuntimeException
     * for every refusal, so the kind has to be recovered:
     *
     *   - the quote is read again, and one that can no longer be used is
     *     QUOTE_EXPIRED whatever sentence the service chose. Checked first: a
     *     quote that ran out is the refusal to fix first, because every
     *     other check is against figures the customer is about to replace.
     *   - a short balance is recognised by the service's own sentence, put
     *     through the same translator in the same request, because the
     *     balance check sits after screening and a state check would report
     *     a compliance refusal as a short balance whenever both are true.
     *   - everything else - screening, a recipient that does not fit the
     *     corridor, a partner refusing the account - is the service's
     *     message as a validation failure, which is what the web form shows.
     */
    private function sendRefusal(Throwable $e, RemittanceQuote $quote): JsonResponse
    {
        $fresh = $quote->fresh();

        if ($fresh === null || ! $fresh->isUsable()) {
            return ApiResponse::error(ApiErrorCode::QuoteExpired);
        }

        if ($e->getMessage() === __('Insufficient balance.')) {
            return ApiResponse::error(ApiErrorCode::InsufficientBalance);
        }

        return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage());
    }

    /**
     * The Idempotency-Key as RemittanceService stores it.
     *
     * Hashed, because the header may be up to 255 characters and the
     * transfer's column holds 64 - and prefixed before hashing, so a key the
     * app chose can never collide with one an integration sent for the same
     * account through /v1/remittance/send.
     */
    private function serviceKey(Request $request): ?string
    {
        $key = $this->idempotency->keyFrom($request);

        return $key === '' ? null : hash('sha256', 'app:'.$key);
    }

    private function invalid(string $message, string $field): JsonResponse
    {
        return ApiResponse::error(ApiErrorCode::ValidationFailed, $message, [$field => [$message]]);
    }
}
