@extends('backend.virtual_card.index')
@section('title', __('Awaiting Virtual Card Requests'))

@section('virtual_card_header')
	@php
		$pendingAmount = $requests->getCollection()->sum(fn ($item) => (float) $item->initial_load_amount);
		$oldestRequest = $requests->getCollection()->sortBy('created_at')->first();
	@endphp
	<x-page-header
		icon="fa-credit-card"
		:title="__('Awaiting Virtual Card Requests')"
		:subtitle="__('Review pending card requests with wallet context, card state, and admin notes.')"
		:stats="[
			['label' => __('Pending'), 'value' => $requests->total()],
			['label' => __('Page Value'), 'value' => money($pendingAmount, null, false)],
			['label' => __('Oldest'), 'value' => $oldestRequest?->created_at?->diffForHumans(short: true) ?? __('None')],
		]"
	/>
@endsection

@section('virtual_card_content')
	<div class="card-body vc-admin-board">
		<div class="vc-admin-toolbar">
			<form action="{{ route('admin.virtual-card.requests.awaiting') }}" method="GET" class="vc-admin-filter-form vc-admin-filter-form--two">
				<div class="vc-admin-filter-form__date">
					<div class="input-group">
						<input type="hidden" name="daterange" value="{{ request('daterange') }}">
						<div id="reportrange" class="form-control d-flex align-items-center justify-content-between" role="button" tabindex="0" aria-label="{{ __('Filter by request date') }}">
							<div class="d-flex align-items-center gap-2">
								<i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
								<span class="text-nowrap flex-grow-1"></span>
							</div>
							<x-icon name="angle-down" class="text-muted flex-shrink-0"/>
						</div>
					</div>
				</div>
				<div class="vc-admin-filter-form__search">
					<label for="awaiting-card-request-search" class="visually-hidden">{{ __('Search awaiting virtual card requests') }}</label>
					<div class="input-group">
						<input id="awaiting-card-request-search" type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by user, wallet, or status...') }}">
						<button type="submit" class="btn btn-primary" aria-label="{{ __('Search requests') }}">
							<i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
						</button>
					</div>
				</div>
			</form>
		</div>

		<div class="table-responsive vc-admin-table vc-request-table">
			<table class="table caption-top mb-0 vc-admin-table__table">
				<thead>
				<tr class="align-middle">
					<th class="vc-admin-table__cell--request">{{ __('Request') }}</th>
					<th class="vc-admin-table__cell--wallet">{{ __('Wallet') }}</th>
					<th class="vc-admin-table__cell--card">{{ __('Card') }}</th>
					<th class="vc-admin-table__cell--queue">{{ __('Queue') }}</th>
					<th class="text-end admin-table-actions-heading">{{ __('Action') }}</th>
				</tr>
				</thead>
				<tbody>
				@forelse($requests as $request)
					@php
						$user = $request->user;
						$wallet = $request->wallet;
						$currency = $wallet?->currency;
						$cardholder = $request->cardholder;
						$card = $request->card;
						$cardholderCountry = strtoupper((string) ($cardholder?->country ?? 'N/A'));
						$kycStatusRaw = $cardholder?->kyc_status;
						$kycStatusValue = $kycStatusRaw instanceof \BackedEnum ? (string) $kycStatusRaw->value : (string) ($kycStatusRaw ?? '');
						$kycReady = in_array(strtolower($kycStatusValue), ['1', 'approved', 'verified', 'success'], true);
						$requestAgeHours = $request->created_at?->diffInHours(now()) ?? 0;
						$priorityClass = $requestAgeHours >= 24 ? 'vc-request-priority--high' : ($requestAgeHours >= 6 ? 'vc-request-priority--medium' : 'vc-request-priority--normal');
						$network = strtoupper((string) ($request->network?->value ?? $request->network ?? 'VISA'));
					@endphp
					<tr class="align-middle">
						<td class="vc-admin-table__cell--request">
							<div class="vc-admin-user vc-request-user">
								<img src="{{ asset($user->avatar_alt) }}" alt="{{ $user->name }}" loading="lazy">
								<div class="vc-request-user__meta">
									<a href="{{ route('admin.user.manage', $user->username) }}">{{ $user->name }}</a>
									<div class="vc-request-code">
										<i class="fa-solid fa-hashtag"></i>
										{{ $request->uuid }}
									</div>
									<div class="vc-request-subline">
										<span>{{ $cardholder?->full_name ?? trim(($cardholder?->first_name ?? '').' '.($cardholder?->last_name ?? '')) ?: __('No cardholder') }}</span>
										<span class="vc-admin-chip">{{ $cardholderCountry }}</span>
									</div>
								</div>
							</div>
						</td>
						<td class="vc-admin-table__cell--wallet">
							<div class="vc-request-money">
								<strong>{{ money($request->initial_load_amount, $currency->code ?? null, false) }} {{ $currency->code ?? 'USD' }}</strong>
								<span>{{ __('Initial load') }}</span>
							</div>
							<div class="vc-request-wallet">
								<span class="vc-admin-chip">{{ $currency->code ?? 'N/A' }}</span>
								<span>{{ __('Balance') }}: {{ money($wallet->balance ?? 0, $wallet?->currency?->code, false) }}</span>
							</div>
						</td>
						<td class="vc-admin-table__cell--card">
							<div class="vc-request-stack">
								<div class="vc-request-cardline">
									<span class="vc-admin-chip vc-admin-chip--info">{{ $network }}</span>
									<span class="vc-admin-chip {{ $kycReady ? 'vc-admin-chip--success' : 'vc-admin-chip--warning' }}">
										{{ $kycReady ? __('KYC ready') : __('KYC check') }}
									</span>
								</div>
								@if($card)
									<span>{{ __('Existing card') }}: **** {{ $card->last4 ?: __('Pending') }}</span>
								@else
									<span>{{ __('New virtual card request') }}</span>
								@endif
							</div>
						</td>
						<td class="vc-admin-table__cell--queue">
							<div class="vc-request-queue">
								<span class="badge bg-{{ $request->status->badgeColor() }}">{{ $request->status->label() }}</span>
								<span class="vc-request-priority {{ $priorityClass }}">
									{{ $requestAgeHours >= 24 ? __('Aged') : ($requestAgeHours >= 6 ? __('Due') : __('Fresh')) }}
								</span>
							</div>
							<div class="fw-semibold mt-2">{{ $request->created_at->format('Y-m-d H:i') }}</div>
							<div class="small text-muted">{{ $request->created_at->diffForHumans() }}</div>
							@if($request->admin_note)
								<div class="vc-request-note mt-2">{{ $request->admin_note }}</div>
							@endif
						</td>
						<td class="text-end vc-admin-table__cell--actions admin-table-actions-cell">
							<div class="vc-request-actions admin-table-actions">
								<button type="button" class="btn btn-outline-primary admin-table-action" data-coreui-toggle="modal" data-coreui-target="#review-request-{{ $request->uuid }}">
									<i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>
									{{ __('Review') }}
								</button>
							</div>
							@include('backend.virtual_card.partials._review_modal', ['request' => $request])
						</td>
					</tr>
				@empty
					<tr>
						<td colspan="5">
							<x-admin-not-found
								:title="__('No virtual card requests found')"
								:message="__('Pending virtual card requests will appear here when users submit them.')"
								icon="fa-credit-card"
							/>
						</td>
					</tr>
				@endforelse
				</tbody>
			</table>
		</div>

		<div class="d-flex justify-content-end mt-3">
			{{ $requests->withQueryString()->links() }}
		</div>
	</div>
@endsection
