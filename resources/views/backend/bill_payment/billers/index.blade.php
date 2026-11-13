@extends('backend.bill_payment.layout')

@section('title', __('Billers'))

@section('sub_header')
    <x-page-header
        :title="__('Billers')"
        :subtitle="__('The companies customers can pay, what each one accepts and what it charges.')"
        icon="receipt"
        :stats="[
            ['label' => __('Active billers'), 'value' => (int) $stats['active'], 'tone' => 'success', 'icon' => 'check'],
            ['label' => __('Countries'), 'value' => (int) $stats['countries'], 'tone' => 'brand', 'icon' => 'sidebar-globe'],
            ['label' => __('Active categories'), 'value' => (int) $stats['categories'], 'tone' => 'neutral', 'icon' => 'tags'],
            ['label' => __('Disabled'), 'value' => (int) $stats['disabled'], 'tone' => $stats['disabled'] > 0 ? 'warning' : 'neutral', 'icon' => 'pause'],
        ]"
    >
        @can('bill-payment-manage')
            <x-slot:actions>
                <a href="{{ route('admin.bill-payment.billers.import') }}" class="ph-btn ph-btn--secondary" data-bpa-import-link>
                    <x-icon name="upload" width="14" height="14"/>
                    {{ __('Import CSV') }}
                </a>
                <a href="{{ route('admin.bill-payment.billers.create') }}" class="ph-btn ph-btn--primary">
                    <x-icon name="plus" width="14" height="14"/>
                    {{ __('New biller') }}
                </a>
            </x-slot:actions>
        @endcan
    </x-page-header>
@endsection

@section('sub_content')
    @php
        $canManage = auth('admin')->user()?->can('bill-payment-manage') ?? false;
        $filtered  = collect($filters)->filter(fn ($value) => $value !== '')->isNotEmpty();
    @endphp

    <div class="card border-0 mb-4 bpa-card">
        <div class="card-body">
            <div class="admin-table-filterbar d-flex mb-3">
                <form action="{{ route('admin.bill-payment.billers.index') }}" method="GET" class="admin-table-filters row g-2 g-md-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="visually-hidden" for="bpa-filter-search">{{ __('Search billers') }}</label>
                        <input type="search" id="bpa-filter-search" name="search" value="{{ $filters['search'] }}"
                               class="form-control" placeholder="{{ __('Search by name or code') }}">
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="visually-hidden" for="bpa-filter-category">{{ __('Category') }}</label>
                        <select id="bpa-filter-category" name="category" class="form-select">
                            <option value="">{{ __('All categories') }}</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected($filters['category'] === (string) $category->id)>{{ __($category->name) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="visually-hidden" for="bpa-filter-country">{{ __('Country') }}</label>
                        <select id="bpa-filter-country" name="country" class="form-select">
                            <option value="">{{ __('All countries') }}</option>
                            @foreach($countries as $country)
                                <option value="{{ $country }}" @selected($filters['country'] === $country)>{{ getCountryDisplayLabel($country, false) ?? $country }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="visually-hidden" for="bpa-filter-provider">{{ __('Provider') }}</label>
                        <select id="bpa-filter-provider" name="provider" class="form-select">
                            <option value="">{{ __('All providers') }}</option>
                            @foreach($providers as $provider)
                                <option value="{{ $provider->id }}" @selected($filters['provider'] === (string) $provider->id)>{{ $provider->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-xl-auto">
                        <label class="visually-hidden" for="bpa-filter-status">{{ __('Status') }}</label>
                        <select id="bpa-filter-status" name="status" class="form-select">
                            <option value="">{{ __('Any status') }}</option>
                            <option value="active" @selected($filters['status'] === 'active')>{{ __('Active') }}</option>
                            <option value="disabled" @selected($filters['status'] === 'disabled')>{{ __('Disabled') }}</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <span>{{ __('Filter') }}</span>
                        </button>
                        @if($filtered)
                            <a href="{{ route('admin.bill-payment.billers.index') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden">{{ __('Billers') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Biller') }}</th>
                            <th scope="col">{{ __('Category') }}</th>
                            <th scope="col">{{ __('Country') }}</th>
                            <th scope="col">{{ __('Amount') }}</th>
                            <th scope="col">{{ __('Fee') }}</th>
                            <th scope="col">{{ __('Provider') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            @if($canManage)
                                <th scope="col" class="text-end">{{ __('Actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($billers as $biller)
                            @php
                                $currency = strtoupper((string) $biller->currency);
                                $isFixed  = $biller->amount_type === \App\Enums\BillPayment\BillerAmountType::Fixed;
                                $min      = $biller->min_amount !== null ? money($biller->min_amount, $currency, false) : null;
                                $max      = $biller->max_amount !== null ? money($biller->max_amount, $currency, false) : null;

                                $amountLabel = match (true) {
                                    $isFixed               => trans_choice('{0} No packages|{1} :count package|[2,*] :count packages', (int) $biller->active_items_count, ['count' => (int) $biller->active_items_count]),
                                    $min !== null && $max !== null => $min.' – '.$max.' '.$currency,
                                    $min !== null          => __('From :amount', ['amount' => $min.' '.$currency]),
                                    $max !== null          => __('Up to :amount', ['amount' => $max.' '.$currency]),
                                    default                => __('Any amount in :currency', ['currency' => $currency]),
                                };

                                $hiddenBy = match (true) {
                                    ! $biller->status                  => null,
                                    ! ($biller->category?->status)     => __('Hidden from customers: its category is off.'),
                                    ! ($biller->provider?->status)     => __('Hidden from customers: its provider is off.'),
                                    default                            => null,
                                };
                            @endphp
                            <tr>
                                <td>
                                    <div class="bpa-biller">
                                        @if($biller->logo)
                                            <img class="bpa-biller__logo" src="{{ asset($biller->logo) }}" alt="" width="36" height="36" loading="lazy">
                                        @else
                                            <span class="bpa-biller__logo bpa-biller__logo--icon" aria-hidden="true">
                                                <x-icon :name="$biller->category?->icon ?: 'bill-other'" width="18" height="18"/>
                                            </span>
                                        @endif
                                        <div class="bpa-biller__copy">
                                            <span class="bpa-biller__name">{{ $biller->name }}</span>
                                            <span class="bpa-muted">{{ $biller->customer_number_label }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $biller->category ? __($biller->category->name) : '—' }}</td>
                                <td>
                                    <span class="bpa-code" dir="ltr">{{ $biller->country }}</span>
                                    <span class="bpa-muted">{{ $currency }}</span>
                                </td>
                                <td class="text-nowrap">{{ $amountLabel }}</td>
                                <td class="text-nowrap">
                                    <span dir="ltr">{{ $fees[$biller->id] ?? '0' }}</span>
                                    @if($biller->fee_percent === null && $biller->fee_fixed === null)
                                        <span class="bpa-muted d-block">{{ __('From the provider') }}</span>
                                    @endif
                                </td>
                                <td>{{ $biller->provider?->name ?? '—' }}</td>
                                <td>
                                    @if($canManage)
                                        <form action="{{ route('admin.bill-payment.billers.toggle-status', $biller) }}" method="POST" class="bpa-toggle-form">
                                            @csrf
                                            <label class="form-check form-switch feature-mgmt-switch">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       data-bpa-autosubmit
                                                       aria-label="{{ $biller->status ? __('Disable :name', ['name' => $biller->name]) : __('Enable :name', ['name' => $biller->name]) }}"
                                                       @checked($biller->status)>
                                            </label>
                                        </form>
                                    @else
                                        <span class="bpa-pill bpa-pill--{{ $biller->status ? 'success' : 'secondary' }}">
                                            {{ $biller->status ? __('Active') : __('Disabled') }}
                                        </span>
                                    @endif
                                    @if($hiddenBy)
                                        <span class="bpa-hint">{{ $hiddenBy }}</span>
                                    @endif
                                </td>
                                @if($canManage)
                                    <td class="text-end">
                                        <div class="admin-table-actions">
                                            <a href="{{ route('admin.bill-payment.billers.edit', $biller) }}"
                                               class="btn btn-outline-primary btn-sm admin-table-action"
                                               title="{{ __('Edit :name', ['name' => $biller->name]) }}">
                                                <x-icon name="edit" width="14" height="14"/>
                                                <span>{{ __('Edit') }}</span>
                                            </a>
                                            <form action="{{ route('admin.bill-payment.billers.destroy', $biller) }}"
                                                  method="POST"
                                                  class="admin-table-actions__form"
                                                  data-bpa-confirm="{{ __('Delete :name? This cannot be undone.', ['name' => $biller->name]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="btn btn-outline-danger btn-sm admin-table-action admin-table-action--danger"
                                                        title="{{ __('Delete :name', ['name' => $biller->name]) }}">
                                                    <x-icon name="trash" width="14" height="14"/>
                                                    <span>{{ __('Delete') }}</span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canManage ? 8 : 7 }}">
                                    <x-admin-not-found
                                        :title="$filtered ? __('No billers match your filter') : __('No billers yet')"
                                        :message="$filtered ? __('Try a different search or clear the filter.') : __('Add the first biller customers will be able to pay.')"
                                        icon="fa-receipt"
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($billers->hasPages())
                <div class="mt-3">{{ $billers->links() }}</div>
            @endif
        </div>
    </div>
@endsection
