<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TransactionResource;
use App\Models\Contact;
use App\Services\Api\ContactsService;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A customer's people. The list is derived from the ledger and merged with
 * the rows they touched; the writes are the flags on one person.
 */
class ContactController extends Controller
{
    public function __construct(private readonly ContactsService $contacts) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::ok(['contacts' => $this->contacts->listFor($request->user())]);
    }

    /**
     * Phone-book matching, by hash. Numbers never arrive here.
     */
    public function match(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hashes'   => ['required', 'array', 'max:2000'],
            'hashes.*' => ['string', 'size:64'],
        ]);

        return ApiResponse::ok(['matched' => $this->contacts->match($request->user(), $validated['hashes'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['recipient' => ['required', 'string', 'max:255']]);

        $row = $this->contacts->add($request->user(), $validated['recipient']);

        if ($row === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound, __('Nobody on the platform matches that.'));
        }

        return ApiResponse::created(['contact' => $this->one($request, $row)]);
    }

    /**
     * A nickname, a star or a block for somebody - by their user id, so a
     * person only ever seen in the ledger gets a row the first time.
     */
    public function update(Request $request, int $user): JsonResponse
    {
        $validated = $request->validate([
            'nickname' => ['sometimes', 'nullable', 'string', 'max:60'],
            'favorite' => ['sometimes', 'boolean'],
            'blocked'  => ['sometimes', 'boolean'],
        ]);

        if ($user === (int) $request->user()->id) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('That is you.'));
        }

        $row = $this->contacts->rowFor($request->user(), $user);
        $row->fill(array_intersect_key($validated, array_flip(['nickname', 'favorite', 'blocked'])));
        $row->save();

        return ApiResponse::ok(['contact' => $this->one($request, $row)]);
    }

    public function destroy(Request $request, int $user): JsonResponse
    {
        Contact::query()->where('user_id', $request->user()->id)->where('contact_user_id', $user)->delete();

        return ApiResponse::ok(['removed' => true]);
    }

    /**
     * Everything between the two of them.
     */
    public function between(Request $request, int $user): JsonResponse
    {
        $history = $this->contacts->between($request->user(), $user);

        return ApiResponse::ok([
            'transfers'    => $history['transfers'],
            'currency'     => $history['currency'],
            'sent'         => $history['currency'] === null ? null : ApiMoney::amount($history['sent'], $history['currency']),
            'received'     => $history['currency'] === null ? null : ApiMoney::amount($history['received'], $history['currency']),
            'transactions' => TransactionResource::collection(collect($history['transactions']))->resolve($request),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function one(Request $request, Contact $row): ?array
    {
        foreach ($this->contacts->listFor($request->user()) as $contact) {
            if (($contact['user_id'] ?? null) === $row->contact_user_id) {
                return $contact;
            }
        }

        return null;
    }
}
