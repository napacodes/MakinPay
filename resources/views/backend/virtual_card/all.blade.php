@extends('backend.virtual_card.index')
@section('title', __('All Virtual Card Requests'))

@section('virtual_card_header')
	<x-page-header
		icon="fa-credit-card"
		:title="__('All Virtual Card Requests')"
		:subtitle="__('Track every card request, approval state, card metadata, and review history.')"
		:stats="[['label' => __('Total'), 'value' => $requests->total()]]"
	>
		<x-slot:actions>
			<a href="{{ route('admin.virtual-card.requests.awaiting') }}" class="btn btn-outline-secondary">
				<i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
				{{ __('View Pending') }}
			</a>
		</x-slot:actions>
	</x-page-header>
@endsection

@section('virtual_card_content')
	<div class="card-body vc-admin-board">
		<div class="vc-admin-toolbar">
			<form action="{{ route('admin.virtual-card.requests.all') }}" method="GET" class="vc-admin-filter-form">
				<div class="vc-admin-filter-form__status">
					<x-form.select
						name="status"
						:options="$statuses"
						:selected="request('status')"
						:includeBlank="true"
						aria-label="{{ __('Filter by status') }}"
					/>
				</div>
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
					<label for="virtual-card-request-search" class="visually-hidden">{{ __('Search virtual card requests') }}</label>
					<div class="input-group">
						<input id="virtual-card-request-search" type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by user, email, card...') }}">
						<button type="submit" class="btn btn-primary" title="{{ __('Search requests') }}" aria-label="{{ __('Search requests') }}">
							<i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
						</button>
					</div>
				</div>
			</form>
		</div>

		<div class="table-responsive vc-admin-table vc-admin-table--requests">
			<table class="table align-middle mb-0 vc-admin-table__table">
				<thead>
				<tr>
					<th class="vc-admin-table__cell--user">{{ __('User') }}</th>
					<th class="vc-admin-table__cell--card">{{ __('Card Details') }}</th>
					<th class="vc-admin-table__cell--status">{{ __('Status') }}</th>
					<th class="vc-admin-table__cell--requested">{{ __('Requested') }}</th>
					<th class="text-end admin-table-actions-heading">{{ __('Actions') }}</th>
				</tr>
				</thead>
				<tbody>
				@forelse($requests as $request)
					<tr>
						<td class="vc-admin-table__cell--user">
							<div class="vc-admin-user">
								<img src="{{ $request->user->avatar_alt }}" alt="{{ $request->user->name }}" loading="lazy">
								<div>
									<span class="fw-semibold">{{ $request->user->name }}</span>
									<span>#{{ $request->uuid }}</span>
								</div>
							</div>
						</td>
						<td class="vc-admin-table__cell--card">
							<div class="vc-admin-card-summary">
								<div class="vc-admin-card-summary__chips">
									<span class="vc-admin-chip">{{ $request->wallet->currency->code }}</span>
									@if($request->card)
										<span class="vc-admin-chip vc-admin-chip--success">**** {{ $request->card->last4 }}</span>
									@endif
								</div>
								@if($request->card)
									<small class="vc-admin-card-state">
										<i class="fa-regular fa-credit-card" aria-hidden="true"></i>
										{{ $request->card->brand ?? __('Virtual card') }}
										<span aria-hidden="true">·</span>
										{{ $request->card->expiry_month }}/{{ substr($request->card->expiry_year, -2) }}
									</small>
								@else
									<small class="vc-admin-card-state vc-admin-card-state--pending">
										<i class="fa-regular fa-credit-card" aria-hidden="true"></i>
										{{ __('Not issued yet') }}
									</small>
								@endif
							</div>
						</td>
						<td class="vc-admin-table__cell--status">
							<span class="badge bg-{{ $request->status->badgeColor() }}">{{ $request->status->label() }}</span>
							@if($request->admin_note)
								<div class="small text-muted mt-1" title="{{ $request->admin_note }}">
									<i class="fa-solid fa-note-sticky"></i>
									{{ Str::limit($request->admin_note, 30) }}
								</div>
							@endif
						</td>
						<td class="vc-admin-table__cell--requested">
							<div class="fw-semibold">{{ $request->created_at->format('M d, Y') }}</div>
							<small class="text-muted">{{ $request->created_at->diffForHumans() }}</small>
						</td>
						<td class="text-end vc-admin-table__cell--actions admin-table-actions-cell">
							<button
								type="button"
								class="btn btn-outline-primary admin-table-action"
								data-coreui-toggle="modal"
								data-coreui-target="#review-request-{{ $request->uuid }}"
								title="{{ __('View request details') }}"
								aria-label="{{ __('View request details for :reference', ['reference' => $request->uuid]) }}"
							>
								<i class="fa-regular fa-eye" aria-hidden="true"></i>
								{{ __('Details') }}
							</button>
							@include('backend.virtual_card.partials._review_modal', ['request' => $request])
						</td>
					</tr>
				@empty
					<tr>
						<td colspan="5">
							<x-admin-not-found
								:title="__('No requests found')"
								:message="__('Virtual card requests matching the current filters will appear here.')"
								icon="fa-inbox"
							/>
						</td>
					</tr>
				@endforelse
				</tbody>
			</table>
		</div>

        @if($requests->hasPages())
			<div class="d-flex justify-content-end mt-3">
				{{ $requests->withQueryString()->links() }}
			</div>
		@endif
	</div>
@endsection
