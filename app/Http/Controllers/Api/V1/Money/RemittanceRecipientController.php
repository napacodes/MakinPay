<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Money;

use App\Enums\Api\ApiErrorCode;
use App\Enums\PayoutMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Beneficiary\StoreBeneficiaryRequest;
use App\Http\Requests\Beneficiary\UpdateBeneficiaryRequest;
use App\Http\Resources\BeneficiaryResource;
use App\Models\Beneficiary;
use App\Services\Remittance\BeneficiaryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The people a customer sends money abroad to, from a phone.
 *
 * The same Beneficiary rows the web panel and the integration API's
 * /v1/beneficiaries manage, through the same BeneficiaryService, answered in
 * the same masked BeneficiaryResource. A recipient saved in the app is there
 * on the website and the other way round.
 *
 * THE RULES ARE THE FORM REQUESTS', read rather than copied, so a field the
 * web form starts accepting is accepted here on the same day. The requests
 * are not type-hinted, though: UpdateBeneficiaryRequest authorises by
 * ownership, and a FormRequest that fails authorisation answers FORBIDDEN -
 * which tells a caller probing ids that the row exists. Here somebody else's
 * recipient is RESOURCE_NOT_FOUND, like one that was never saved.
 *
 * THE PAYOUT METHOD'S ACCOUNT FIELDS ARE VALIDATED PER FIELD. BeneficiaryService
 * checks the same list, PayoutMethod::requiredFields(), but refuses with one
 * sentence for the first gap; the app needs every missing field named, under
 * `account_details.<name>`, so it can mark each input on the form.
 */
class RemittanceRecipientController extends Controller
{
    public function __construct(private readonly BeneficiaryService $beneficiaries) {}

    /**
     * Saved recipients, favourites first, then the most recently used.
     *
     * Filtered to one destination when the send screen asks for the people
     * a corridor can pay; unfiltered, every recipient the caller has.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country'  => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $currency = isset($validated['currency']) ? strtoupper($validated['currency']) : null;

        $recipients = $this->beneficiaries
            ->listForUser($request->user(), $validated['country'] ?? null)
            ->when($currency !== null, fn ($list) => $list->filter(
                static fn (Beneficiary $recipient): bool => strtoupper((string) $recipient->currency) === $currency,
            ))
            ->values();

        return ApiResponse::ok(BeneficiaryResource::collection($recipients)->resolve($request));
    }

    /**
     * Save a new recipient.
     */
    public function store(Request $request): JsonResponse
    {
        $form   = new StoreBeneficiaryRequest;
        $method = $this->methodFrom($request->input('payout_method'));

        $validated = $request->validate(
            $form->rules()    + $this->accountFieldRules($method),
            $form->messages() + $this->accountFieldMessages($method),
        );

        try {
            $recipient = $this->beneficiaries->create($request->user(), $validated);
        } catch (InvalidArgumentException $e) {
            // The service's own required-field check; unreachable after the
            // rules above, and kept as a refusal rather than a fault if the
            // two lists ever drift apart.
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage());
        }

        return ApiResponse::created([
            'recipient' => (new BeneficiaryResource($recipient))->resolve($request),
        ]);
    }

    /**
     * Change a recipient - most often only `is_favorite`.
     *
     * A new payout method or new account details are checked against the
     * method the recipient will have afterwards, against the details it will
     * have afterwards: the service replaces account_details whole, so a
     * method changed on its own must already be satisfied by the details
     * on file.
     */
    public function update(Request $request, int $recipient): JsonResponse
    {
        $found = $this->own($request, $recipient);

        if ($found === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $form          = new UpdateBeneficiaryRequest;
        $changesMethod = $request->has('payout_method');
        $method        = $changesMethod ? $this->methodFrom($request->input('payout_method')) : $found->payout_method;
        $rules         = $form->rules();
        $messages      = $form->messages();

        if ($request->has('account_details')) {
            $rules    += $this->accountFieldRules($method);
            $messages += $this->accountFieldMessages($method);
        }

        $validated = $request->validate($rules, $messages);

        if ($changesMethod && ! $request->has('account_details') && $method !== null) {
            $this->assertDetailsOnFileFit($method, (array) ($found->account_details ?? []));
        }

        // The service uppercases these on create and not on update; a
        // lower-case country would otherwise drop the row out of the
        // country filter above.
        foreach (['country_code', 'currency'] as $key) {
            if (isset($validated[$key])) {
                $validated[$key] = strtoupper((string) $validated[$key]);
            }
        }

        try {
            $updated = $this->beneficiaries->update($found, $validated);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage());
        }

        return ApiResponse::ok([
            'recipient' => (new BeneficiaryResource($updated))->resolve($request),
        ]);
    }

    /**
     * Forget a recipient.
     *
     * One with transfer history is kept - the transfers point at it, and the
     * receipt screens read it - and the refusal says so in the service's
     * words.
     */
    public function destroy(Request $request, int $recipient): JsonResponse
    {
        $found = $this->own($request, $recipient);

        if ($found === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        try {
            $this->beneficiaries->delete($found);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage());
        }

        return ApiResponse::noContent();
    }

    private function own(Request $request, int $id): ?Beneficiary
    {
        return Beneficiary::forUser($request->user()->id)->find($id);
    }

    /**
     * The method named in the body, or null when it names none the
     * enum knows - in which case the enum rule refuses it and there are no
     * account fields to ask for.
     */
    private function methodFrom(mixed $value): ?PayoutMethod
    {
        return is_string($value) ? PayoutMethod::tryFrom($value) : null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function accountFieldRules(?PayoutMethod $method): array
    {
        $rules = [];

        foreach ($method?->requiredFields() ?? [] as $field) {
            $rules['account_details.'.$field['name']] = $field['required']
                ? ['required', 'string', 'max:191']
                : ['nullable', 'string', 'max:191'];
        }

        return $rules;
    }

    /**
     * The service's sentence for a missing field, so the app and the web
     * form name a gap the same way.
     *
     * @return array<string, string>
     */
    private function accountFieldMessages(?PayoutMethod $method): array
    {
        $messages = [];

        foreach ($method?->requiredFields() ?? [] as $field) {
            if ($field['required']) {
                $messages['account_details.'.$field['name'].'.required'] = __('The :field field is required.', ['field' => $field['label']]);
            }
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $details
     *
     * @throws ValidationException
     */
    private function assertDetailsOnFileFit(PayoutMethod $method, array $details): void
    {
        $missing = [];

        foreach ($method->requiredFields() as $field) {
            if ($field['required'] && empty($details[$field['name']])) {
                $missing['account_details.'.$field['name']] = [__('The :field field is required.', ['field' => $field['label']])];
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }
}
