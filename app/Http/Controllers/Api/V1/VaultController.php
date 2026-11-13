<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Models\Vault;
use App\Models\Wallet;
use App\Services\Money\WalletPinGuard;
use App\Services\Vaults\VaultService;
use App\Support\AmountEntry;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vaults: savings goals ring-fenced from a wallet. Moving money in or
 * out takes the wallet PIN, like any other move of the customer's money.
 */
class VaultController extends Controller
{
    public function __construct(private readonly VaultService $vaults, private readonly WalletPinGuard $pins) {}

    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $display = $user->displayCurrency();
        $list    = $user->vaults()->where('status', '!=', 'closed')->with('wallet.currency')->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderBy('target_date')->orderBy('id')->get();

        return ApiResponse::ok([
            'summary' => $this->vaults->summary($user, $display),
            'vaults'  => $list->map(fn (Vault $v): array => $this->vaults->describe($v, $display))->values()->all(),
            'icons'   => Vault::ICONS,
        ]);
    }

    public function show(Request $request, Vault $vault): JsonResponse
    {
        $this->own($request, $vault);

        return ApiResponse::ok(['vault' => $this->vaults->describe($vault->load('wallet.currency'), $request->user()->displayCurrency(), withEntries: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user   = $request->user();
        $uuid   = $request->input('wallet_id');
        $wallet = is_string($uuid) ? $user->wallets()->with('currency')->where('uuid', $uuid)->first() : null;
        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('Add a wallet in that currency first.'), ['wallet_id' => [__('Add a wallet in that currency first.')]]);
        }
        $validated = $this->validated($request, $wallet);

        $vault = $user->vaults()->create($validated + ['wallet_id' => $wallet->id, 'status' => 'active']);
        $vault->update(['next_auto_at' => VaultService::nextAutoAt($vault, now()->toImmutable())]);

        return ApiResponse::created(['vault' => $this->vaults->describe($vault->refresh()->load('wallet.currency'), $user->displayCurrency())]);
    }

    public function update(Request $request, Vault $vault): JsonResponse
    {
        $this->own($request, $vault);
        if ($vault->status === 'closed') {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('This vault is closed.'));
        }
        $validated = $this->validated($request, $vault->wallet()->with('currency')->firstOrFail(), $vault);
        $status    = $request->input('status');
        if (is_string($status) && in_array($status, ['active', 'paused'], true)) {
            $validated['status']    = $status;
            $validated['paused_at'] = $status === 'paused' ? now() : null;
        }
        $vault->fill($validated);
        $vault->next_auto_at = $vault->status === 'active' ? VaultService::nextAutoAt($vault, now()->toImmutable()) : null;
        $vault->save();

        return ApiResponse::ok(['vault' => $this->vaults->describe($vault->refresh()->load('wallet.currency'), $request->user()->displayCurrency(), withEntries: true)]);
    }

    public function deposit(Request $request, Vault $vault): JsonResponse
    {
        $this->own($request, $vault);
        $code      = (string) $vault->wallet()->with('currency')->firstOrFail()->currency->code;
        $validated = $request->validate(['amount' => AmountEntry::rules($code), 'wallet_pin' => ['nullable', 'string'], 'note' => ['nullable', 'string', 'max:120']]);
        $this->pins->assert($request->user(), $validated['wallet_pin'] ?? null);

        $entry = $this->vaults->deposit($request->user(), $vault, (string) $validated['amount'], 'deposit', $validated['note'] ?? null);

        return ApiResponse::created(['entry_id' => $entry->id, 'reference' => $entry->transaction?->trx_id, 'vault' => $this->vaults->describe($vault->refresh()->load('wallet.currency'), $request->user()->displayCurrency(), withEntries: true)]);
    }

    public function withdraw(Request $request, Vault $vault): JsonResponse
    {
        $this->own($request, $vault);
        $code      = (string) $vault->wallet()->with('currency')->firstOrFail()->currency->code;
        $validated = $request->validate(['amount' => AmountEntry::rules($code), 'wallet_pin' => ['nullable', 'string']]);
        $this->pins->assert($request->user(), $validated['wallet_pin'] ?? null);

        $entry = $this->vaults->withdraw($request->user(), $vault, (string) $validated['amount']);

        return ApiResponse::created(['entry_id' => $entry->id, 'reference' => $entry->transaction?->trx_id, 'vault' => $this->vaults->describe($vault->refresh()->load('wallet.currency'), $request->user()->displayCurrency(), withEntries: true)]);
    }

    public function close(Request $request, Vault $vault): JsonResponse
    {
        $this->own($request, $vault);
        $validated = $request->validate(['wallet_pin' => ['nullable', 'string']]);
        $this->pins->assert($request->user(), $validated['wallet_pin'] ?? null);

        $closed = $this->vaults->close($request->user(), $vault);

        return ApiResponse::ok(['vault' => $this->vaults->describe($closed->load('wallet.currency'), $request->user()->displayCurrency())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Wallet $wallet, ?Vault $vault = null): array
    {
        $code = (string) $wallet->currency->code;

        $validated = $request->validate([
            'name'           => [$vault === null ? 'required' : 'sometimes', 'string', 'max:80'],
            'icon'           => ['nullable', 'string', Rule::in(Vault::ICONS)],
            'target_amount'  => AmountEntry::rules($code, required: false),
            'target_date'    => ['nullable', 'date', 'after:today'],
            'auto_amount'    => AmountEntry::rules($code, required: false),
            'auto_frequency' => ['nullable', Rule::in(Vault::FREQUENCIES)],
            'auto_day'       => ['nullable', 'integer', 'min:1', 'max:28'],
            'round_ups'      => ['nullable', 'boolean'],
            'round_up_to'    => AmountEntry::rules($code, required: false),
        ]);

        // A partial edit - pausing, renaming - keeps the rules it did not mention.
        if ($vault !== null && ! $request->has('auto_amount') && ! $request->has('auto_frequency')) {
            $validated['auto_amount']    = $vault->auto_amount === null ? null : (string) $vault->auto_amount;
            $validated['auto_frequency'] = $vault->auto_frequency;
            $validated['auto_day']       = $vault->auto_day;
        }
        if ($vault !== null && ! $request->has('round_ups')) {
            $validated['round_ups']   = $vault->round_ups;
            $validated['round_up_to'] = $vault->round_up_to === null ? null : (string) $vault->round_up_to;
        }
        $auto = isset($validated['auto_amount'], $validated['auto_frequency']);
        $out  = [
            'target_amount'  => array_key_exists('target_amount', $validated) ? ($validated['target_amount'] === null ? null : (string) $validated['target_amount']) : $vault?->target_amount,
            'target_date'    => array_key_exists('target_date', $validated) ? $validated['target_date'] : $vault?->target_date,
            'auto_amount'    => $auto ? (string) $validated['auto_amount'] : null,
            'auto_frequency' => $auto ? $validated['auto_frequency'] : null,
            'auto_day'       => $auto ? (int) ($validated['auto_day'] ?? 1) : null,
            'round_ups'      => (bool) ($validated['round_ups'] ?? false),
            'round_up_to'    => ! empty($validated['round_ups']) ? (string) ($validated['round_up_to'] ?? '1') : null,
        ];
        if (isset($validated['name'])) {
            $out['name'] = $validated['name'];
        }
        if (isset($validated['icon'])) {
            $out['icon'] = $validated['icon'];
        } elseif ($vault === null) {
            $out['icon'] = 'piggy-bank';
        }

        return $out;
    }

    private function own(Request $request, Vault $vault): void
    {
        abort_unless((int) $vault->user_id === (int) $request->user()->id, 404);
    }
}
