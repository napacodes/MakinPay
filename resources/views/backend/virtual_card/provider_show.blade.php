@extends('backend.virtual_card.index')
@section('title', __('Provider: :name', ['name' => $provider->name]))

@section('virtual_card_header')
	@php
		$caps = $provider->resolved_capabilities ?? [];
		$capLabels = [
			'issue' => __('Issue Card'),
			'card_details' => __('Reveal PAN/CVV'),
			'topup' => __('Top-up'),
			'withdraw' => __('Withdraw'),
			'freeze' => __('Freeze / Unfreeze'),
			'limits' => __('Spend Limits'),
			'controls' => __('Card Controls'),
		];
		$gateway = $provider->paymentGateway;
		$totalCards = (int) $statBuckets->sum();
		$enabledCapabilities = collect($capLabels)->filter(fn ($label, $key) => ! empty($caps[$key]));
	@endphp

	<x-page-header
		icon="fa-chart-line"
		:title="$provider->name"
		:subtitle="__('Monitor provider configuration and recent card activity.')"
		:stats="[
			['label' => __('Cards'), 'value' => number_format($totalCards)],
			['label' => __('Active'), 'value' => number_format((int) ($statBuckets['active'] ?? 0))],
			['label' => __('Failed'), 'value' => number_format((int) ($statBuckets['failed'] ?? 0))],
		]"
	>
		<x-slot:actions>
			<a href="{{ route('admin.virtual-card.provider.index') }}" class="btn btn-outline-secondary">
				<i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
				{{ __('All Providers') }}
			</a>
		</x-slot:actions>
	</x-page-header>
@endsection

@section('virtual_card_content')
	<div class="card-body vpo-insights">
		<section class="vpo-insights__overview" aria-label="{{ __('Provider overview') }}">
			<dl class="vpo-insights__facts">
				<div>
					<dt>{{ __('Status') }}</dt>
					<dd>
						<span class="vpo-status {{ $provider->status ? 'is-active' : 'is-inactive' }}">
							<i class="fa-solid fa-circle" aria-hidden="true"></i>
							{{ $provider->status ? __('Active') : __('Inactive') }}
						</span>
					</dd>
				</div>
				<div>
					<dt>{{ __('Gateway') }}</dt>
					<dd>{{ $gateway?->name ?? __('Not linked') }}</dd>
				</div>
				<div>
					<dt>{{ __('Issue cost') }}</dt>
					<dd>{{ $provider->fee_formatted }}</dd>
				</div>
			</dl>

			<div class="vpo-insights__metadata">
				<div>
					<span>{{ __('Coverage') }}</span>
					<div class="vpo-chip-list">
						@foreach($provider->supported_networks ?? [] as $network)
							<span class="vpo-chip vpo-chip--brand">{{ Str::upper($network) }}</span>
						@endforeach
						@foreach($provider->supported_currencies ?? [] as $currency)
							<span class="vpo-chip">{{ Str::upper($currency) }}</span>
						@endforeach
						@foreach($provider->supported_countries ?? [] as $country)
							<span class="vpo-chip">{{ Str::upper($country) }}</span>
						@endforeach
						@if(empty($provider->supported_networks) && empty($provider->supported_currencies) && empty($provider->supported_countries))
							<span class="vpo-chip">{{ __('Global') }}</span>
						@endif
					</div>
				</div>
				<div>
					<span>{{ __('Available features') }}</span>
					<div class="vpo-chip-list">
						@forelse($enabledCapabilities as $label)
							<span class="vpo-chip vpo-chip--success">{{ $label }}</span>
						@empty
							<span class="vpo-chip">{{ __('None enabled') }}</span>
						@endforelse
					</div>
				</div>
			</div>

			<div class="vpo-insights__actions" role="group" aria-label="{{ __('Provider controls') }}">
				<button type="button" class="vpo-action vcp-test-connection" data-test-url="{{ route('admin.virtual-card.provider.test-connection', $provider->id) }}" data-result-target="#vcp-test-result-detail">
					<i class="fa-solid fa-plug-circle-check" aria-hidden="true"></i>
					{{ __('Test connection') }}
				</button>
				@if($gateway)
					<button type="button" class="vpo-action vcp-gateway-edit" data-edit-url="{{ route('admin.payment.gateway.edit', ['gateway' => $gateway->id, 'context' => 'virtual-card']) }}">
						<i class="fa-solid fa-key" aria-hidden="true"></i>
						{{ __('API access') }}
					</button>
				@endif
				<button type="button" class="vpo-action vpo-action--primary edit-modal" data-edit-url="{{ route('admin.virtual-card.provider.manage', $provider->id) }}">
					<i class="fa-solid fa-sliders" aria-hidden="true"></i>
					{{ __('Provider settings') }}
				</button>
			</div>

			<div id="vcp-test-result-detail" class="vcp-test-result d-none"></div>
		</section>

		<div class="vpo-activity-grid">
			<section class="vpo-activity-panel">
				<header class="vpo-activity-panel__header">
					<h2>{{ __('Recent cards') }}</h2>
					<span>{{ $recentCards->count() }}</span>
				</header>
				<div class="table-responsive vc-admin-table">
					<table class="table table-sm mb-0 align-middle">
							<thead>
								<tr>
									<th>{{ __('Card') }}</th>
									<th>{{ __('User') }}</th>
									<th>{{ __('Wallet') }}</th>
									<th>{{ __('Status') }}</th>
									<th class="text-end admin-table-actions-heading">{{ __('Action') }}</th>
								</tr>
							</thead>
							<tbody>
								@forelse($recentCards as $card)
									@php
										$statusValue = $card->status?->value ?? (string) $card->status;
										$badgeClass = match ($statusValue) {
											'active' => 'success',
											'pending' => 'info',
											'inactive' => 'warning',
											'blocked', 'failed' => 'danger',
											'expired' => 'secondary',
											default => 'secondary',
										};
									@endphp
									<tr>
										<td>
											<div class="fw-semibold">**** {{ $card->last4 ?? '----' }}</div>
											<small class="text-muted">{{ $card->expiry_month ? $card->expiry_month.'/'.$card->expiry_year : __('No expiry') }}</small>
										</td>
										<td>
											<div class="small">{{ $card->user?->email ?? __('Unknown') }}</div>
											<small class="text-muted">{{ optional($card->request?->cardholder)->full_name }}</small>
										</td>
										<td><span class="vcp-chip">{{ $card->wallet?->currency?->code ?? '-' }}</span></td>
										<td><span class="badge bg-{{ $badgeClass }} text-white">{{ ucfirst($statusValue ?: '-') }}</span></td>
										<td class="text-end admin-table-actions-cell">
											<form action="{{ route('admin.virtual-card.cards.refresh', $card->id) }}" method="POST" class="admin-table-actions__form">
												@csrf
												<button type="submit" class="btn btn-sm btn-outline-primary admin-table-action" title="{{ __('Refresh from gateway') }}" aria-label="{{ __('Refresh card from gateway') }}">
													<i class="fa-solid fa-rotate" aria-hidden="true"></i>
												</button>
											</form>
										</td>
									</tr>
								@empty
									<tr>
										<td colspan="5">
											<x-admin-not-found
												:title="__('No cards issued through this provider')"
												:message="__('Issued cards for this provider will appear here.')"
												icon="fa-credit-card"
											/>
										</td>
									</tr>
								@endforelse
							</tbody>
					</table>
				</div>
			</section>

			<section class="vpo-activity-panel">
				<header class="vpo-activity-panel__header">
					<h2>{{ __('Recent requests') }}</h2>
					<span>{{ $recentRequests->count() }}</span>
				</header>
				<div class="table-responsive vc-admin-table">
					<table class="table table-sm mb-0 align-middle">
							<thead>
								<tr>
									<th>{{ __('Request') }}</th>
									<th>{{ __('User') }}</th>
									<th>{{ __('Cardholder') }}</th>
									<th>{{ __('Status') }}</th>
								</tr>
							</thead>
							<tbody>
								@forelse($recentRequests as $req)
									<tr>
										<td>
											<div class="fw-semibold small">#{{ $req->uuid }}</div>
											<small class="text-muted">{{ $req->created_at?->diffForHumans() }}</small>
										</td>
										<td><div class="small">{{ $req->user?->email ?? __('Unknown') }}</div></td>
										<td>
											<div class="small">{{ optional($req->cardholder)->full_name }}</div>
											<small class="text-muted">{{ optional($req->cardholder)->country }}</small>
										</td>
										<td><span class="badge bg-{{ $req->status?->badgeColor() }}">{{ $req->status?->label() }}</span></td>
									</tr>
								@empty
									<tr>
										<td colspan="4">
											<x-admin-not-found
												:title="__('No recent requests')"
												:message="__('Recent provider requests will appear here.')"
												icon="fa-inbox"
											/>
										</td>
									</tr>
								@endforelse
							</tbody>
					</table>
				</div>
			</section>
		</div>
	</div>

	@include('backend.virtual_card.partials._manage_modal')
	@include('backend.payment_gateway.partial._edit_payment_gateway_modal')
@endsection
