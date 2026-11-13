@php
	use App\Enums\AgentStatus;use App\Services\WalletService;$walletService = app(WalletService::class);
	$walletCardUser = auth()->user();
	$walletCardCounterCashOutRoute = route('user.agent.index', ['tab' => 'counter-cashout']);

	if ($walletCardUser?->isAgent()) {
		$walletCardCounterCashOutAgent = $walletCardUser->agent()
			->where('status', AgentStatus::APPROVED)
			->where('qr_enabled', true)
			->whereNotNull('qr_token')
			->latest()
			->first();

		$walletCardCounterCashOutRoute = $walletCardCounterCashOutAgent?->qrCashOutUrl() ?? $walletCardCounterCashOutRoute;
	}

	$walletCardProfile = match (true) {
		$walletCardUser?->isMerchant() => [
			'role' => 'merchant',
			'label' => __('Merchant Wallet'),
			'primary_label' => __('Add Funds'),
			'primary_icon' => 'deposit',
			'primary_route' => route('user.deposit.create'),
			'secondary_label' => __('Shops'),
			'secondary_icon' => 'sidebar-store',
			'secondary_route' => route('user.merchant.index'),
		],
		$walletCardUser?->isAgent() => [
			'role' => 'agent',
			'label' => __('Agent Wallet'),
			'primary_label' => __('Cash-In'),
			'primary_icon' => 'deposit',
			'primary_route' => route('user.agent.index', ['tab' => 'cash-in']),
			'secondary_label' => __('Counter Cash-Out'),
			'secondary_icon' => 'pay-money',
			'secondary_route' => $walletCardCounterCashOutRoute,
		],
		default => [
			'role' => 'user',
			'label' => __('Personal Wallet'),
			'primary_label' => __('Deposit'),
			'primary_icon' => 'deposit',
			'primary_route' => route('user.deposit.create'),
			'secondary_label' => __('My Wallets'),
			'secondary_icon' => 'wallet',
			'secondary_route' => route('user.wallet.index'),
		],
	};
@endphp

<div class="single-card-box single-card-box-slider sidebar-wallet-panel mb-30 @if(!request()->routeIs('user.dashboard'))  d-lg-block d-none @endif">
	<div class="walet-slider owl-carousel sidebar-wallet-slider mb-0"
	     data-sidebar-wallet-slider
	     data-sidebar-wallet-role="{{ $walletCardProfile['role'] }}">
		@foreach($walletCardUser->activeWallets() as $wallet)
			@php
				$maskedWalletId = $walletService->formatMaskedWalletId($wallet->uuid);
			@endphp
			<div class="walet-inner sidebar-wallet-card sidebar-wallet-card--{{ $walletCardProfile['role'] }}">
				<div class="sidebar-wallet-card__top">
                    <span class="sidebar-wallet-card__badge">
                        <span class="sidebar-wallet-card__symbol" aria-hidden="true">
							<x-icon name="wallet-2" width="12" height="12"/>
						</span>
                        <span>{{ $wallet->currency->code }} {{ $walletCardProfile['label'] }}</span>
                    </span>
				</div>
				
				<div class="sidebar-wallet-card__body">
					<div class="sidebar-wallet-card__id">
						<span class="sidebar-wallet-card__id-icon" aria-hidden="true">
							<x-icon name="sidebar-identity" width="12" height="12"/>
						</span>
						<span class="sidebar-wallet-card__id-label">{{ __('Wallet ID') }}</span>
						<span class="sidebar-wallet-card__id-text">
                            {{ $maskedWalletId }}
                        </span>
						<button type="button"
						        class="sidebar-wallet-card__copy copy-icon copyNow"
						        data-clipboard-text="{{ $wallet->uuid }}"
						        title="{{ __('Copy Wallet ID') }}"
						        data-bs-toggle="tooltip"
						        data-bs-placement="top"
						        aria-label="{{ __('Copy Wallet ID') }}">
							<x-icon name="clipboard" width="11" height="11"/>
						</button>
					</div>
					
					<div class="sidebar-wallet-card__balance">
						<span class="sidebar-wallet-card__balance-label">{{ __('Available Balance') }}</span>
						<div class="sidebar-wallet-card__amount-row">
							<div class="sidebar-wallet-card__amount" data-balance-mask data-hidden="0">
								{{ money($wallet->balance, $wallet->currency->code) }}
							</div>
							<button type="button"
							        class="sidebar-wallet-card__eye dk-balance-eye"
							        aria-label="{{ __('Toggle balance') }}"
							        aria-pressed="false">
								<span data-balance-visible-icon aria-hidden="true">
									<x-icon name="eye" width="12" height="12"/>
								</span>
								<span data-balance-hidden-icon aria-hidden="true" hidden>
									<x-icon name="eye-off" width="12" height="12"/>
								</span>
							</button>
							<span class="sidebar-wallet-card__chip" aria-hidden="true"></span>
						</div>
					</div>
				</div>
				
				<div class="sidebar-wallet-card__actions">
					<a class="sidebar-wallet-card__action sidebar-wallet-card__action--primary"
					   href="{{ $walletCardProfile['primary_route'] }}">
						<span class="sidebar-wallet-card__action-icon" aria-hidden="true">
							<x-icon name="{{ $walletCardProfile['primary_icon'] }}" width="13" height="13"/>
						</span>
						<span>{{ $walletCardProfile['primary_label'] }}</span>
					</a>
					<a class="sidebar-wallet-card__action sidebar-wallet-card__action--secondary"
					   href="{{ $walletCardProfile['secondary_route'] }}">
						<span class="sidebar-wallet-card__action-icon" aria-hidden="true">
							<x-icon name="{{ $walletCardProfile['secondary_icon'] }}" width="13" height="13"/>
						</span>
						<span>{{ $walletCardProfile['secondary_label'] }}</span>
					</a>
				</div>
			</div>
		@endforeach
	</div>
</div>
