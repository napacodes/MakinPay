@extends('backend.virtual_card.index')
@section('title', __('Virtual Card Providers'))

@section('virtual_card_header')
	@php
		$providerItems = $providers->getCollection();
		$activeProviders = $providerItems->where('status', true)->count();
		$linkedGateways = $providerItems->filter(fn ($provider) => filled($provider->paymentGateway))->count();
		$totalNetworks = $providerItems->flatMap(fn ($provider) => $provider->supported_networks ?? [])->filter()->unique()->count();
	@endphp

	<x-page-header
		icon="fa-server"
		:title="__('Virtual Card Providers')"
		:subtitle="__('Manage provider availability, pricing, coverage, and API access.')"
		:stats="[
			['label' => __('Active'), 'value' => $activeProviders],
			['label' => __('Gateways'), 'value' => $linkedGateways],
			['label' => __('Networks'), 'value' => $totalNetworks],
		]"
	>
		<x-slot:actions>
			<a href="{{ route('admin.payment.gateway.index') }}" class="btn btn-outline-secondary" title="{{ __('Manage Payment Gateways') }}">
				<i class="fa-solid fa-building-columns" aria-hidden="true"></i>
				{{ __('Payment Gateways') }}
			</a>
		</x-slot:actions>
	</x-page-header>
@endsection

@section('virtual_card_content')
	<div class="card-body vpo-provider-board">
		<div class="vpo-provider-grid">
			@forelse($providers as $provider)
				@php
					$caps = $provider->resolved_capabilities ?? [];
					$capLabels = [
						'issue' => __('Issue'),
						'card_details' => __('Reveal'),
						'topup' => __('Top-up'),
						'withdraw' => __('Withdraw'),
						'freeze' => __('Freeze'),
						'limits' => __('Limits'),
						'controls' => __('Controls'),
					];
					$gateway = $provider->paymentGateway;
					$enabledCapabilities = collect($capLabels)->filter(fn ($label, $key) => ! empty($caps[$key]));
				@endphp

				<article class="vpo-provider-card" aria-labelledby="provider-{{ $provider->id }}-name">
					<header class="vpo-provider-card__header">
						<div class="vpo-provider-card__identity">
							<div class="vpo-provider-card__logo">
								<img src="{{ $provider->logo_url }}" alt="{{ $provider->name }}" loading="lazy">
							</div>
							<div class="vpo-provider-card__copy">
								<h2 id="provider-{{ $provider->id }}-name">{{ $provider->name }}</h2>
								<span>{{ Str::upper($provider->code) }}</span>
							</div>
						</div>

						<span class="vpo-status {{ $provider->status ? 'is-active' : 'is-inactive' }}">
							<i class="fa-solid fa-circle" aria-hidden="true"></i>
							{{ $provider->status ? __('Active') : __('Inactive') }}
						</span>
					</header>

					<dl class="vpo-provider-card__metrics">
						<div>
							<dt>{{ __('Issue cost') }}</dt>
							<strong>{{ $provider->fee_formatted }}</strong>
							@if((float) ($provider->issue_fee_pct ?? 0) > 0)
								<dd>{{ __('+ :fee%', ['fee' => feeRate($provider->issue_fee_pct)]) }}</dd>
							@endif
						</div>
						<div>
							<dt>{{ __('Cards') }}</dt>
							<strong>{{ number_format($provider->stat_total ?? 0) }}</strong>
							<dd>{{ __(':count active', ['count' => number_format($provider->stat_active ?? 0)]) }}</dd>
						</div>
						<div>
							<dt>{{ __('Features') }}</dt>
							<strong>{{ $enabledCapabilities->count() }}</strong>
							<dd>{{ __('available') }}</dd>
						</div>
					</dl>

					<div class="vpo-provider-card__details">
						<div class="vpo-provider-card__detail">
							<span>{{ __('Coverage') }}</span>
							<div class="vpo-chip-list">
								@forelse($provider->supported_networks ?? [] as $network)
									<span class="vpo-chip vpo-chip--brand">{{ Str::upper($network) }}</span>
								@empty
								@endforelse
								@forelse($provider->supported_currencies ?? [] as $currency)
									<span class="vpo-chip">{{ Str::upper($currency) }}</span>
								@empty
									@if(empty($provider->supported_networks))
										<span class="vpo-chip">{{ __('Global') }}</span>
									@endif
								@endforelse
							</div>
						</div>

						@if($enabledCapabilities->isNotEmpty())
							<div class="vpo-provider-card__detail">
								<span>{{ __('Available') }}</span>
								<div class="vpo-chip-list">
									@foreach($enabledCapabilities as $label)
										<span class="vpo-chip vpo-chip--success">{{ $label }}</span>
									@endforeach
								</div>
							</div>
						@endif
					</div>

					<footer class="vpo-provider-card__actions" role="group" aria-label="{{ __('Provider actions') }}">
						@if($gateway)
							<button type="button" class="vpo-action vcp-gateway-edit" data-edit-url="{{ route('admin.payment.gateway.edit', ['gateway' => $gateway->id, 'context' => 'virtual-card']) }}" aria-label="{{ __('Manage API access for :provider', ['provider' => $provider->name]) }}">
								<i class="fa-solid fa-key" aria-hidden="true"></i>
								<span>{{ __('API access') }}</span>
							</button>
						@else
							<span class="vpo-action is-disabled">
								<i class="fa-solid fa-link-slash" aria-hidden="true"></i>
								{{ __('No gateway') }}
							</span>
						@endif

						<a href="{{ route('admin.virtual-card.provider.show', $provider->id) }}" class="vpo-action" aria-label="{{ __('View activity for :provider', ['provider' => $provider->name]) }}">
							<i class="fa-solid fa-chart-line" aria-hidden="true"></i>
							<span>{{ __('Activity') }}</span>
						</a>

						<button type="button" class="vpo-action vpo-action--primary edit-modal" data-edit-url="{{ route('admin.virtual-card.provider.manage', $provider->id) }}" aria-label="{{ __('Open settings for :provider', ['provider' => $provider->name]) }}">
							<i class="fa-solid fa-sliders" aria-hidden="true"></i>
							<span>{{ __('Settings') }}</span>
						</button>
					</footer>
				</article>
			@empty
				<x-admin-not-found
					:title="__('No providers found')"
					:message="__('Seed or add a virtual card provider to start issuing cards.')"
					icon="fa-inbox"
					class="vpo-provider-empty"
				/>
			@endforelse
		</div>

		@if($providers->hasPages())
			<div class="d-flex justify-content-end mt-3">
				{{ $providers->withQueryString()->links() }}
			</div>
		@endif
	</div>

	@include('backend.virtual_card.partials._manage_modal')
	@include('backend.payment_gateway.partial._edit_payment_gateway_modal')
@endsection
