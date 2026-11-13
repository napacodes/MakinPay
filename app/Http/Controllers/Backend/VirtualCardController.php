<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Enums\VirtualCard\VirtualCardNetwork;
use App\Enums\VirtualCard\VirtualCardRequestStatus;
use App\Enums\VirtualCard\VirtualCardStatus;
use App\Exceptions\NotifyErrorException;
use App\Exceptions\ProviderOutcomeUnknownException;
use App\Http\Requests\Backend\UpdateVirtualCardProviderRequest;
use App\Models\PaymentGateway;
use App\Models\Transaction as TransactionModel;
use App\Models\VirtualCard;
use App\Models\VirtualCardProvider;
use App\Models\VirtualCardRequest;
use App\Models\Wallet as WalletModel;
use App\Notifications\TemplateNotification;
use App\Services\VirtualCard\Drivers\Bitnob\BitnobCardProvider;
use App\Services\VirtualCard\VirtualCardControls;
use App\Services\VirtualCard\VirtualCardManager;
use App\Services\VirtualCard\VirtualCardProviderFactory;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Transaction;
use Wallet;

class VirtualCardController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'virtualCardList|requestAwaiting|requestAll'                                 => 'virtual-card-list',
            'review|statusUpdate|refreshCard'                                            => 'virtual-card-action',
            'provider|providerManage|providerUpdate|providerShow|providerTestConnection' => 'virtual-card-provider-manage',
        ];
    }

    /**
     * List all virtual cards with admin filters.
     */
    public function virtualCardList(Request $request)
    {
        $cards = VirtualCard::with(['user', 'wallet.currency', 'provider'])
            ->filter($request)
            ->latest('id')
            ->paginate(15)
            ->withQueryString();
        $providers = VirtualCardProvider::all();
        $statuses  = VirtualCardStatus::options();

        return view('backend.virtual_card.list', compact('cards', 'providers', 'statuses'));
    }

    /**
     * List all pending virtual card requests with filters.
     */
    public function requestAwaiting(Request $request)
    {
        $requests = VirtualCardRequest::where('status', VirtualCardRequestStatus::Pending)
            ->with('wallet.currency', 'user', 'cardholder.business')
            ->filter($request)
            ->latest('id')
            ->paginate(10);
        $providers = VirtualCardProvider::all();

        return view('backend.virtual_card.awaiting', compact('requests', 'providers'));
    }

    /**
     * List all virtual card requests with filters.
     */
    public function requestAll(Request $request)
    {
        $requests = VirtualCardRequest::with(['wallet.currency', 'user', 'card', 'cardholder.business'])
            ->filter($request)
            ->latest('id')
            ->paginate(10)
            ->withQueryString();
        $providers = VirtualCardProvider::all();
        $statuses  = VirtualCardRequestStatus::options();

        return view('backend.virtual_card.all', compact('requests', 'providers', 'statuses'));
    }

    /**
     * List all virtual card providers (paginated) with per-provider
     * runtime stats so the admin can see issuance volume + health
     * at a glance without drilling into each provider.
     */
    public function provider()
    {
        $providers = VirtualCardProvider::with('paymentGateway')
            ->orderBy('order')
            ->orderBy('id')
            ->paginate(10);

        // Aggregate per-provider card counts in one query — far cheaper
        // than N+1ing per row and works the same regardless of how many
        // providers the script has wired in.
        $cardStats = VirtualCard::query()
            ->selectRaw('provider_id, status, COUNT(*) as total')
            ->groupBy('provider_id', 'status')
            ->get()
            ->groupBy('provider_id');

        $pendingRequests = VirtualCardRequest::query()
            ->where('status', VirtualCardRequestStatus::Pending)
            ->selectRaw('provider_id, COUNT(*) as total')
            ->groupBy('provider_id')
            ->pluck('total', 'provider_id');

        // Decorate each provider on the page with the stats it cares about.
        $providers->getCollection()->transform(function ($provider) use ($cardStats, $pendingRequests) {
            $rows                            = $cardStats->get($provider->id, collect());
            $provider->stat_total            = (int) $rows->sum('total');
            $provider->stat_active           = (int) ($rows->firstWhere('status', VirtualCardStatus::Active->value)->total ?? 0);
            $provider->stat_pending          = (int) ($rows->firstWhere('status', VirtualCardStatus::Pending->value)->total ?? 0);
            $provider->stat_failed           = (int) ($rows->firstWhere('status', VirtualCardStatus::Failed->value)->total ?? 0);
            $provider->stat_inactive         = (int) ($rows->firstWhere('status', VirtualCardStatus::Inactive->value)->total ?? 0);
            $provider->stat_pending_requests = (int) ($pendingRequests[$provider->id] ?? 0);

            return $provider;
        });

        return view('backend.virtual_card.provider', compact('providers'));
    }

    /**
     * Per-provider drill-down — shows capability matrix, recent cards
     * issued through this provider, recent failures, and the provider's
     * own diagnostic information. Works for any registered provider
     * without per-provider conditionals.
     */
    public function providerShow(int $id)
    {
        $provider = VirtualCardProvider::with('paymentGateway')->findOrFail($id);

        $recentCards = VirtualCard::query()
            ->with(['user', 'wallet.currency', 'request.cardholder'])
            ->where('provider_id', $id)
            ->latest('id')
            ->limit(20)
            ->get();

        $recentRequests = VirtualCardRequest::query()
            ->with(['user', 'wallet.currency', 'cardholder'])
            ->where('provider_id', $id)
            ->latest('id')
            ->limit(10)
            ->get();

        $statBuckets = VirtualCard::query()
            ->where('provider_id', $id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('backend.virtual_card.provider_show', compact(
            'provider',
            'recentCards',
            'recentRequests',
            'statBuckets'
        ));
    }

    /**
     * Run the provider's connection test and return a JSON envelope so
     * the admin UI can show success/failure inline without a full page
     * reload. Provider-agnostic — every concrete provider implements
     * the same `testConnection()` shape on AbstractVirtualCardProvider.
     */
    public function providerTestConnection(int $id, VirtualCardProviderFactory $factory)
    {
        $provider = VirtualCardProvider::findOrFail($id);

        try {
            $impl   = $factory->getProvider($provider->code);
            $result = $impl->testConnection();
        } catch (\Throwable $e) {
            $result = [
                'ok'         => false,
                'mode'       => null,
                'message'    => $e->getMessage(),
                'latency_ms' => null,
                'details'    => ['exception' => get_class($e)],
            ];
        }

        return response()->json([
            'provider' => [
                'id'   => $provider->id,
                'code' => $provider->code,
                'name' => $provider->name,
            ],
            'result' => $result,
        ]);
    }

    /**
     * Re-fetch a card's live details from the gateway and persist any
     * changed last4/expiry/status onto the local row. Useful for
     * recovering from async-issuance providers (Bitnob) that may
     * settle a previously-failed card later.
     */
    public function refreshCard(int $card, VirtualCardProviderFactory $factory)
    {
        $card = VirtualCard::with('provider')->findOrFail($card);
        if (! $card->provider) {
            notifyEvs('error', __('This card has no provider attached.'));

            return back();
        }

        try {
            // One sync path for the webhook, the scheduler and this button, so
            // a provider-verified `failed` returns the initial load here too.
            $changed = app(VirtualCardManager::class)->syncCardFromProvider($card);

            notifyEvs('success', __('Card refreshed from :provider. :count fields updated.', [
                'provider' => $card->provider->name,
                'count'    => count($changed),
            ]));
        } catch (\Throwable $e) {
            Log::error('Admin card refresh failed', [
                'card_id' => $card->id,
                'error'   => $e->getMessage(),
            ]);
            notifyEvs('error', __('Refresh failed: :err', ['err' => $e->getMessage()]));
        }

        return back();
    }

    /**
     * Show provider management form.
     */
    public function providerManage(int $id)
    {
        $provider = VirtualCardProvider::findOrFail($id);
        $gateways = PaymentGateway::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'status']);
        $capabilityLabels = $this->providerCapabilityLabels();

        return view('backend.virtual_card.partials.provider_manage', compact(
            'provider',
            'gateways',
            'capabilityLabels'
        ));
    }

    /**
     * Update a virtual card provider.
     */
    public function providerUpdate(UpdateVirtualCardProviderRequest $request, VirtualCardProvider $provider)
    {
        $validated = $request->validated();

        $validated['status']               = $request->boolean('status') ? 1 : 0;
        $validated['supported_currencies'] = $this->normalizeCsvList($validated['supported_currencies'] ?? null, uppercase: true);
        $validated['supported_countries']  = $this->normalizeCountryList($validated['supported_countries'] ?? null);
        $validated['capabilities']         = $this->normalizeCapabilities($request->input('capabilities', []));

        $provider->update($validated);
        notifyEvs('success', __('Provider Updated Successfully'));

        return redirect()->route('admin.virtual-card.provider.index');
    }

    /**
     * Parse comma-separated ISO-2 country list into a clean array, or
     * NULL when the field is blank ("no restriction").
     */
    private function normalizeCountryList(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $codes = array_filter(array_map(
            fn ($c) => strtoupper(trim($c)),
            explode(',', $raw)
        ), fn ($c) => preg_match('/^[A-Z]{2,3}$/', $c) === 1);

        return $codes ? array_values(array_unique($codes)) : null;
    }

    private function normalizeCsvList(?string $raw, bool $uppercase = false): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $values = array_filter(array_map(static function (string $value) use ($uppercase): string {
            $value = trim($value);

            return $uppercase ? strtoupper($value) : strtolower($value);
        }, explode(',', $raw)));

        return $values ? array_values(array_unique($values)) : null;
    }

    private function normalizeArrayList(array $values, bool $lowercase = false): ?array
    {
        $normalized = array_filter(array_map(static function (mixed $value) use ($lowercase): string {
            $value = trim((string) $value);

            return $lowercase ? strtolower($value) : $value;
        }, $values));

        return $normalized ? array_values(array_unique($normalized)) : null;
    }

    private function normalizeCapabilities(array $selected): array
    {
        $selected = array_flip(array_map('strval', $selected));

        return collect($this->providerCapabilityLabels())
            ->mapWithKeys(fn ($label, $key) => [$key => array_key_exists($key, $selected)])
            ->all();
    }

    private function providerCapabilityLabels(): array
    {
        return [
            'issue'        => __('Issue Card'),
            'card_details' => __('Reveal PAN/CVV'),
            'topup'        => __('Top-up'),
            'withdraw'     => __('Withdraw'),
            'freeze'       => __('Freeze / Unfreeze'),
            'limits'       => __('Spend Limits'),
            'controls'     => __('Card Controls'),
        ];
    }

    /**
     * Activate / inactivate (freeze) an issued card from the admin panel.
     *
     * Calls the provider's freeze/unfreeze API so the change actually
     * takes effect at the gateway. Falls back to a soft DB-only flip
     * when the provider lacks a status API (the provider class itself
     * decides this and signals via `soft => true`).
     */
    public function statusUpdate(Request $request, VirtualCardProviderFactory $factory)
    {
        $data = $request->validate([
            'card_id' => ['required', 'integer', 'exists:virtual_cards,id'],
            'status'  => ['required', 'in:active,inactive'],
        ]);

        $card = VirtualCard::with('provider')->findOrFail($data['card_id']);

        try {
            $provider = $card->provider ? $factory->getProvider($card->provider->code) : null;

            if ($provider) {
                if ($data['status'] === 'inactive') {
                    $provider->freezeCard($card);
                } else {
                    $provider->unfreezeCard($card);
                }
            }

            // An administrator's freeze is marked as theirs and has no timer:
            // the cardholder cannot lift it, and neither can a timed freeze
            // they set before (virtual-card:unfreeze-due).
            $meta = is_array($card->meta) ? $card->meta : [];
            unset($meta['freeze']);

            if ($data['status'] === 'inactive') {
                $meta['freeze'] = ['by' => VirtualCardControls::FROZEN_BY_ADMIN, 'frozen_at' => now()->toIso8601String()];
            }

            $card->update([
                'status' => $data['status'] === 'active'
                    ? VirtualCardStatus::Active->value
                    : VirtualCardStatus::Inactive->value,
                'meta'         => $meta,
                'frozen_until' => null,
            ]);

            notifyEvs('success', __('Card status updated to :status.', ['status' => $data['status']]));
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Admin card status update failed', [
                'card_id' => $card->id,
                'target'  => $data['status'],
                'error'   => $e->getMessage(),
            ]);
            notifyEvs('error', __('Failed to update card status: :msg', ['msg' => $e->getMessage()]));
        }

        return back();
    }

    /**
     * Review and process a virtual card request (approve/reject).
     *
     * One review of a request at a time. The request stays pending for the
     * whole provider call, so the pending check alone let a double click or
     * two administrators both reach the provider: two cards issued and
     * funded for one request, and the second attempt's failure refunding the
     * first one's fee. The lock outlives the slowest provider path (create,
     * fund and read back) with room to spare.
     */
    public function review(Request $request, string $uuid, WalletService $walletService)
    {
        $cardRequest = VirtualCardRequest::where('uuid', $uuid)
            ->with(['wallet.currency', 'user', 'cardholder.business'])
            ->firstOrFail();

        $lock = Cache::lock('virtual-card-request-review:'.$cardRequest->id, 300);

        if (! $lock->get()) {
            notifyEvs('error', __('This request is already being processed. Refresh the page in a moment.'));

            return back();
        }

        try {
            return $this->processReview($request, $cardRequest, $walletService);
        } finally {
            $lock->release();
        }
    }

    private function processReview(Request $request, VirtualCardRequest $cardRequest, WalletService $walletService)
    {
        $validated = $request->validate([
            'admin_note'  => ['nullable', 'string', 'max:255'],
            'action'      => ['required', 'in:approve,reject'],
            'provider_id' => ['nullable', 'integer', 'exists:virtual_card_providers,id'],
        ]);

        if ($validated['action'] === 'approve') {
            if (empty($validated['provider_id'])) {
                notifyEvs('error', __('Please select a compatible provider before approving.'));

                return back();
            }

            $manager  = app(VirtualCardManager::class);
            $provider = VirtualCardProvider::findOrFail($validated['provider_id']);
            $user     = $cardRequest->user;

            try {
                $this->ensureProviderCanIssue($provider, $cardRequest);
                $walletService->assertOperational($cardRequest->wallet);

                // Total issue fee = fixed + % of the initial load.
                $issueFee = $provider->issueTotalFee((float) ($cardRequest->initial_load_amount ?? 0.0));

                // The fee leaves in its own short transaction under a lock on
                // the request, so a double submit cannot approve twice, a fee
                // already taken by an earlier attempt is not taken again, and
                // no wallet row lock is held while the provider is on the line.
                $this->chargeIssueFee($cardRequest, $provider, $user, $issueFee, (string) $request->admin_note, $walletService);

                $cardRequest->refresh()->load(['wallet.currency', 'user', 'cardholder.business']);

                // Bitnob requires the cardholder to be pre-indexed in the
                // Visa BIN pool before card creation will succeed. The
                // first call is harmless on already-indexed users (Bitnob
                // returns "already registered") and triggers indexing on
                // fresh users. Done here so admins don't have to click
                // "Verify with Bitnob" first when approving a request.
                if ($provider->code === 'bitnob') {
                    try {
                        $cardholder = $cardRequest->cardholder;
                        if ($cardholder) {
                            app(BitnobCardProvider::class)
                                ->verifyCardholder($cardholder);
                        }
                    } catch (\Throwable $verifyEx) {
                        // Not fatal — issueCard will re-attempt registration
                        // and surface a clearer error if it really blocks.
                        Log::warning('Bitnob pre-issuance verify failed; continuing to issueCard', [
                            'error' => $verifyEx->getMessage(),
                        ]);
                    }
                }

                $card = $manager->issueProviderCard($cardRequest, $provider->code);
            } catch (ProviderOutcomeUnknownException $e) {
                // The provider may have issued the card. The fee and the load
                // stay debited and are reused on the next approval.
                logger()->error('Card issue outcome unknown: '.$e->getMessage(), $this->issueLogContext($cardRequest, $provider));
                notifyEvs('error', $e->getMessage());

                return back();
            } catch (\Throwable $e) {
                // The provider answered, or was never asked: nothing was
                // issued, the manager has returned the load, and the fee
                // goes back with it.
                $this->refundIssueFee($cardRequest, $e->getMessage(), $walletService);
                logger()->error('Card issue failed: '.$e->getMessage(), $this->issueLogContext($cardRequest, $provider) + ['exception' => $e]);
                notifyEvs('error', 'Card issuing failed: '.$e->getMessage());

                return back();
            }

            // The manager has already set the request status (Failed
            // when the provider returned status=`failed`, Issued
            // otherwise). Don't overwrite it here — that would erase
            // a failed-issuance signal that the dashboard relies on.
            $cardStatusValue = $card->status instanceof VirtualCardStatus
                ? $card->status->value
                : (string) $card->status;
            $cardIssueFailed = $cardStatusValue === VirtualCardStatus::Failed->value;
            $cardRequest->update(array_filter([
                'admin_note'  => $request->admin_note,
                'provider_id' => $provider->id,
                'status'      => $cardIssueFailed
                    ? VirtualCardRequestStatus::Failed
                    : VirtualCardRequestStatus::Issued,
            ], fn ($v) => $v !== null));

            // Skip the success notification when issuance actually
            // failed — sending "card approved" for a failed card
            // confuses users and floods the inbox. A notification
            // failure must never undo an issued card.
            if (! $cardIssueFailed) {
                try {
                    $wallet      = $cardRequest->wallet;
                    $walletName  = $wallet->name ?? $wallet->currency->code;
                    $cardNetwork = $card->brand  ?? $cardRequest->network;
                    $last4       = isset($card->number) ? substr($card->number, -4) : ($cardRequest->last4 ?? '');

                    $cardRequest->user->notify(new TemplateNotification(
                        identifier: 'virtual_card_user_approved',
                        data: [
                            'card_network' => $cardNetwork,
                            'last4'        => $last4,
                            'wallet'       => $walletName,
                            'fee'          => $issueFee,
                        ],
                        action: route('user.virtual-card.index')
                    ));
                } catch (\Throwable $notifyFailure) {
                    report($notifyFailure);
                }
            }

            if ($cardIssueFailed) {
                $reason = is_array($card->meta)
                    ? ($card->meta['failure_reason'] ?? __('Provider declined the card creation.'))
                    : __('Provider declined the card creation.');
                notifyEvs('warning', __('Card saved as failed. :reason', ['reason' => $reason]));
            } else {
                notifyEvs('success', 'Virtual card has been issued successfully!');
            }
        }

        if ($validated['action'] === 'reject') {
            $cardRequest->update([
                'status'     => VirtualCardRequestStatus::Rejected,
                'admin_note' => $request->admin_note,
            ]);
            notifyEvs('warning', 'Request rejected successfully.');
        }

        return redirect()->route('admin.virtual-card.requests.awaiting');
    }

    /**
     * Charge the issuing fee once, under a lock on the request.
     *
     * A request that is no longer pending is refused, so two admins (or one
     * double click) cannot both approve it. A fee an earlier, unfinished
     * attempt already took is found by its ledger row and not taken again.
     *
     * @throws NotifyErrorException
     */
    private function chargeIssueFee(
        VirtualCardRequest $cardRequest,
        VirtualCardProvider $provider,
        $user,
        float $issueFee,
        string $adminNote,
        WalletService $walletService,
    ): void {
        DB::transaction(function () use ($cardRequest, $provider, $user, $issueFee, $adminNote, $walletService): void {
            $locked = VirtualCardRequest::query()->lockForUpdate()->findOrFail($cardRequest->id);

            if ($locked->status !== VirtualCardRequestStatus::Pending) {
                throw new NotifyErrorException(__('This request has already been processed.'));
            }

            if ($issueFee > 0 && ! $this->issueFeeTransaction($locked)) {
                $defaultWallet = $user->default_wallet
                    ? WalletModel::query()->lockForUpdate()->find($user->default_wallet->id)
                    : null;

                if (! $defaultWallet || (float) $defaultWallet->balance < $issueFee) {
                    throw new NotifyErrorException(__('Insufficient balance in the default wallet for the card issuing fee.'));
                }

                $walletService->assertOperational($defaultWallet);

                Transaction::create(new TransactionData(
                    user_id: $user->id,
                    trx_type: TrxType::SUBTRACT_BALANCE,
                    amount: $issueFee,
                    amount_flow: AmountFlow::MINUS,
                    currency: $defaultWallet->currency->code,
                    net_amount: $issueFee,
                    payable_amount: $issueFee,
                    payable_currency: $defaultWallet->currency->code,
                    wallet_reference: $defaultWallet->uuid,
                    trx_data: ['kind' => 'issue_fee', 'virtual_card_request_id' => $locked->id],
                    description: __('Virtual Card Issuing Fee'),
                    status: TrxStatus::COMPLETED,
                    exchange_rate: Wallet::fxRate($defaultWallet),
                ));
                Wallet::subtractMoney($defaultWallet, $issueFee);
            }

            $locked->update(array_filter([
                'provider_id' => $provider->id,
                'admin_note'  => $adminNote !== '' ? $adminNote : null,
            ], fn ($v) => $v !== null));
        }, 3);
    }

    private function issueFeeTransaction(VirtualCardRequest $request): ?TransactionModel
    {
        return TransactionModel::query()
            ->where('trx_type', TrxType::SUBTRACT_BALANCE->value)
            ->where('trx_data->kind', 'issue_fee')
            ->where('trx_data->virtual_card_request_id', $request->id)
            ->where('status', TrxStatus::COMPLETED->value)
            ->orderBy('id')
            ->first();
    }

    /**
     * Return the issuing fee when nothing was issued. Idempotent through the
     * ledger row's own status, and never allowed to mask the original error.
     */
    private function refundIssueFee(VirtualCardRequest $cardRequest, string $reason, WalletService $walletService): void
    {
        try {
            DB::transaction(function () use ($cardRequest, $reason, $walletService): void {
                // A request that was issued or failed on an earlier attempt has
                // spent its fee; only an attempt that never got that far owes it.
                if (VirtualCardRequest::query()->whereKey($cardRequest->id)->first()?->status !== VirtualCardRequestStatus::Pending) {
                    return;
                }

                $fee = $this->issueFeeTransaction($cardRequest);

                if (! $fee) {
                    return;
                }

                $locked = TransactionModel::query()->lockForUpdate()->find($fee->id);

                if (! $locked || $locked->status !== TrxStatus::COMPLETED) {
                    return;
                }

                $walletService->creditSettlementByWalletUuid($locked->wallet_reference, (string) $locked->amount);

                $locked->update([
                    'status'  => TrxStatus::FAILED,
                    'remarks' => __('Issuing fee returned: :reason', ['reason' => Str::limit($reason, 200)]),
                ]);
            }, 3);
        } catch (\Throwable $refundFailure) {
            report($refundFailure);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function issueLogContext(VirtualCardRequest $cardRequest, ?VirtualCardProvider $provider): array
    {
        return [
            'virtual_card_request_id' => $cardRequest->id,
            'cardholder_id'           => $cardRequest->cardholder_id,
            'user_id'                 => $cardRequest->user_id,
            'wallet_id'               => $cardRequest->wallet_id,
            'provider_id'             => $provider->id                 ?? $cardRequest->provider_id,
            'provider_code'           => $provider->code               ?? null,
            'network'                 => $cardRequest->network?->value ?? $cardRequest->network,
            'initial_load_amount'     => $cardRequest->initial_load_amount,
        ];
    }

    private function ensureProviderCanIssue(VirtualCardProvider $provider, VirtualCardRequest $cardRequest): void
    {
        if (! $provider->status) {
            throw new NotifyErrorException(__('Selected provider is inactive.'));
        }

        if (! $provider->supports('issue')) {
            throw new NotifyErrorException(__('Selected provider does not support card issuance.'));
        }

        $network = strtolower((string) ($cardRequest->network?->value ?? $cardRequest->network ?? ''));
        if ($provider->code === 'bitnob' && $network !== VirtualCardNetwork::Visa->value) {
            throw new NotifyErrorException(__('Bitnob currently supports only Visa virtual cards. Select another provider for :network requests.', [
                'network' => strtoupper($network ?: __('unknown')),
            ]));
        }

        if (! $provider->supportsNetwork($network)) {
            throw new NotifyErrorException(__('Selected provider does not support the requested card network.'));
        }

        $currency = strtoupper((string) ($cardRequest->wallet?->currency?->code ?? ''));
        if (! $provider->supportsCurrency($currency)) {
            throw new NotifyErrorException(__('Selected provider does not support the request wallet currency.'));
        }

        $country = $this->cardholderCountry($cardRequest);
        if (! $provider->supportsCountry($country)) {
            throw new NotifyErrorException(__('Selected provider does not support this cardholder country.'));
        }
    }

    private function cardholderCountry(VirtualCardRequest $cardRequest): ?string
    {
        $cardholder = $cardRequest->cardholder;

        return $cardholder?->card_type?->isBusiness() && $cardholder?->business?->country
            ? $cardholder->business->country
            : ($cardholder?->country ?? null);
    }
}
