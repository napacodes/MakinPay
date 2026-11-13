<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\P2P\OfferStatus;
use App\Http\Controllers\Controller;
use App\Models\P2P\Offer;
use App\Models\P2P\PaymentAccount;
use App\Models\P2P\PaymentMethod;
use App\Services\P2P\P2PPaymentAccountService;
use App\Support\ApiResponse;
use App\Support\P2PPaymentFileDisk;
use App\Support\P2PPaymentMethodManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The customer's own P2P payment accounts - where a buyer pays them - and the
 * payment methods an account can be added for, each with the fields its form
 * asks for. Adding, changing and removing one go through
 * P2PPaymentAccountService, the website's own.
 *
 * Both lists are sorted the website's way: the customer's own country first,
 * then methods they already hold an account for. Nobody else's accounts are
 * read here; the counterparty's details reach an order through that order.
 */
class P2PPaymentAccountController extends Controller
{
    public function __construct(private readonly P2PPaymentAccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $country = P2PPaymentMethodManager::resolveCountryCode($user);

        $accounts = P2PPaymentMethodManager::sortPaymentAccounts(
            PaymentAccount::query()
                ->with('paymentMethod')
                ->where('user_id', $user->id)
                ->latest('id')
                ->get(),
            $country,
        );

        $methods = P2PPaymentMethodManager::sortMethods(
            PaymentMethod::query()
                ->where('status', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            $country,
            $accounts->pluck('payment_method_id')->map(fn ($id): int => (int) $id)->filter()->values()->all(),
        );

        // How many of the customer's live ads offer each method - what an
        // account is holding up.
        $adsByMethod = Offer::query()
            ->join('p2p_offer_payment_method as pm', 'pm.offer_id', '=', 'p2p_offers.id')
            ->where('p2p_offers.user_id', $user->id)
            ->whereIn('p2p_offers.status', [OfferStatus::ACTIVE->value, OfferStatus::PAUSED->value])
            ->whereNull('p2p_offers.deleted_at')
            ->selectRaw('pm.payment_method_id, COUNT(*) as ads')
            ->groupBy('pm.payment_method_id')
            ->pluck('ads', 'pm.payment_method_id');

        return ApiResponse::ok([
            'accounts' => $accounts->map(fn (PaymentAccount $account): array => $this->account($account) + ['used_in_ads' => (int) ($adsByMethod[$account->payment_method_id] ?? 0)])->values(),
            'methods'  => $methods->map(fn (PaymentMethod $method): array => $this->method($method) + [
                'instructions' => $method->instructions,
                'fields'       => $method->normalizedFields(),
            ])->values(),
            'country' => $country,
        ]);
    }

    /**
     * Add an account for a payment method the customer does not have one for
     * yet. Multipart when the method asks for a file.
     */
    public function store(Request $request): JsonResponse
    {
        $account = $this->accounts->create($request->user(), $request);

        return ApiResponse::created($this->account($account->load('paymentMethod')));
    }

    public function update(Request $request, string $account): JsonResponse
    {
        $paymentAccount = $this->own($request, $account);

        $paymentAccount = $this->accounts->update($request->user(), $paymentAccount, $request);

        return ApiResponse::ok($this->account($paymentAccount->load('paymentMethod')));
    }

    /**
     * Remove an account - refused while an open trade uses it or an active
     * sell ad offers its method, as on the website.
     */
    public function destroy(Request $request, string $account): JsonResponse
    {
        $this->accounts->delete($request->user(), $this->own($request, $account));

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * A file on one of the customer's own accounts, from the private disk.
     */
    public function file(Request $request, string $account, string $field): StreamedResponse
    {
        return P2PPaymentFileDisk::response(P2PPaymentFileDisk::fromDetails($this->own($request, $account)->renderedDetails(), $field));
    }

    /**
     * One of the customer's own accounts; anyone else's does not exist.
     */
    private function own(Request $request, string $account): PaymentAccount
    {
        return PaymentAccount::query()
            ->with('paymentMethod')
            ->where('user_id', $request->user()->id)
            ->findOrFail($account);
    }

    /**
     * @return array<string, mixed>
     */
    private function account(PaymentAccount $account): array
    {
        return [
            'id'             => $account->id,
            'label'          => $account->effective_label,
            'display_name'   => $account->display_name,
            'display_value'  => $account->display_value,
            'payment_method' => $account->paymentMethod ? $this->method($account->paymentMethod) : null,
            'details'        => collect($account->renderedDetails())
                ->map(fn (array $detail): array => $detail['type'] === 'file'
                    ? ['value' => null, 'file_url' => route('api.mobile.p2p.payment-accounts.file', [$account->id, $detail['key']])] + $detail
                    : $detail)
                ->all(),
            'verification' => $account->verification_status ?? PaymentAccount::VERIFICATION_NONE,
            'verified_at'  => $account->verified_at?->toIso8601String(),
            'created_at'   => $account->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string, logo_url: ?string, country: ?string}
     */
    private function method(PaymentMethod $method): array
    {
        return [
            'id'       => $method->id,
            'name'     => $method->name,
            'logo_url' => filled($method->logo) ? asset('storage/'.ltrim((string) $method->logo, '/')) : null,
            'country'  => $method->country,
        ];
    }
}
