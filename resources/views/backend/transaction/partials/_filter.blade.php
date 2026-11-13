@php
    $trxActiveStatus = (string) request('status', '');
    $trxHasFilters   = collect(['type', 'status', 'search', 'daterange'])
        ->contains(fn (string $field): bool => filled(request($field)));
@endphp

<div class="admin-table-filterbar admin-trx-filterbar">
	<form action="{{ route('admin.transaction') }}" method="GET" class="admin-table-filters admin-trx-filters">
		{{-- The picker itself lives in the topbar; this is the field it writes
		     to, and it has to stay inside the form that submits. --}}
		<input type="hidden" name="daterange" value="{{ request('daterange') }}">

		{{-- Search --}}
		<label class="admin-trx-search">
			<span class="visually-hidden">{{ __('Search') }}</span>
			<svg class="admin-trx-search__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>
			<input type="search" name="search" value="{{ request('search') }}" class="admin-trx-control"
			       placeholder="{{ __('Search name, TXN ID, provider…') }}">
		</label>

		{{-- Type Filter --}}
		<div class="admin-trx-type">
			<x-form.select name="type" label="{{ __('Transaction Type') }}" class="form-select-md admin-trx-control"
			               :options="\App\Enums\TrxType::options()"
			               :selected="request('type')"/>
			<svg class="admin-trx-type__caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
		</div>

		{{-- The status filter is the summary cards above: each one is the count
		     and the link that produces it, so a second control here said the
		     same thing twice. The radios were also what carried `status`
		     through this form, so it stays as a field — without it, changing
		     the type or the search would silently drop the selected status. --}}
		@if($trxActiveStatus !== '')
			<input type="hidden" name="status" value="{{ $trxActiveStatus }}">
		@endif

		{{-- One explicit action for the pair. The select used to apply itself
		     on change, which reloaded the page mid-thought and threw away a
		     half-typed search; now both are set first and applied together. --}}
		<button type="submit" class="admin-trx-apply">
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18l-7 8v6l-4-2v-4z"></path></svg>
			{{ __('Apply') }}
		</button>

		@if($trxHasFilters)
			<a href="{{ route('admin.transaction') }}" class="admin-trx-clear">
				<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg>
				{{ __('Clear') }}
			</a>
		@endif

		@isset($transactionsTotal)
			<span class="admin-trx-filterbar__meta">
				{{ __(':count transactions', ['count' => number_format($transactionsTotal)]) }}
			</span>
		@endisset
	</form>
</div>
