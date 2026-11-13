@extends('backend.bill_payment.layout')

@section('title', __('Bill payment providers'))

@section('sub_header')
    <x-page-header
        :title="__('Bill payment providers')"
        :subtitle="__('Who pays the biller, and the fee rule billers follow unless they override it.')"
        icon="receipt"
        :stats="[
            ['label' => __('Providers'), 'value' => (int) $stats['total'], 'tone' => 'brand', 'icon' => 'provider'],
            ['label' => __('Active'), 'value' => (int) $stats['active'], 'tone' => 'success', 'icon' => 'check'],
            ['label' => __('Billers served'), 'value' => (int) $stats['billers'], 'tone' => 'neutral', 'icon' => 'list-2'],
        ]"
    >
        @can('bill-payment-manage')
            <x-slot:actions>
                <a href="{{ route('admin.bill-payment.providers.create') }}" class="ph-btn ph-btn--primary">
                    <x-icon name="plus" width="14" height="14"/>
                    {{ __('New provider') }}
                </a>
            </x-slot:actions>
        @endcan
    </x-page-header>
@endsection

@section('sub_content')
    @php
        $canManage = auth('admin')->user()?->can('bill-payment-manage') ?? false;
    @endphp

    @if($providers->isEmpty())
        <div class="card border-0 bpa-card">
            <div class="card-body">
                <x-admin-not-found
                    :title="__('No providers')"
                    :message="__('Add a provider before adding billers.')"
                    icon="fa-receipt"
                />
            </div>
        </div>
    @else
        <div class="bpa-provider-grid">
            @foreach($providers as $provider)
                @php
                    $driverLabel = $driverLabels[$provider->driver] ?? __('Unregistered driver (:driver)', ['driver' => $provider->driver]);
                    $feeLabel    = $provider->effectiveFeeDescription();
                @endphp
                <article class="bpa-provider {{ $provider->status ? '' : 'is-disabled' }}">
                    <header class="bpa-provider__head">
                        <span class="bpa-provider__logo" aria-hidden="true">
                            @if($provider->logo)
                                <img src="{{ asset($provider->logo) }}" alt="" width="40" height="40" loading="lazy">
                            @else
                                <x-icon name="provider" width="20" height="20"/>
                            @endif
                        </span>
                        <div class="bpa-provider__identity">
                            <h2 class="bpa-provider__name">{{ $provider->name }}</h2>
                            <span class="bpa-code" dir="ltr">{{ $provider->code }}</span>
                        </div>
                        @if($canManage)
                            <form action="{{ route('admin.bill-payment.providers.toggle-status', $provider) }}" method="POST" class="bpa-toggle-form">
                                @csrf
                                <label class="form-check form-switch feature-mgmt-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           data-bpa-autosubmit
                                           aria-label="{{ $provider->status ? __('Disable :name', ['name' => $provider->name]) : __('Enable :name', ['name' => $provider->name]) }}"
                                           @checked($provider->status)>
                                </label>
                            </form>
                        @else
                            <span class="bpa-pill bpa-pill--{{ $provider->status ? 'success' : 'secondary' }}">
                                {{ $provider->status ? __('Active') : __('Disabled') }}
                            </span>
                        @endif
                    </header>

                    @if($provider->is_system)
                        <span class="bpa-pill bpa-pill--info bpa-provider__badge">{{ __('Ships with Bill payments') }}</span>
                    @endif

                    <dl class="bpa-provider__facts">
                        <div>
                            <dt>{{ __('Driver') }}</dt>
                            <dd>{{ __($driverLabel) }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Fee') }}</dt>
                            <dd dir="ltr">{{ $feeLabel }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Billers') }}</dt>
                            <dd>{{ number_format((int) $provider->billers_count) }}</dd>
                        </div>
                    </dl>

                    @if($provider->fee_currency && \App\Support\DecimalMoney::compare((string) $provider->fee_fixed, '0') > 0)
                        <p class="bpa-muted bpa-provider__note">{{ __('The fixed fee applies only to billers priced in :currency.', ['currency' => $provider->fee_currency]) }}</p>
                    @endif

                    @if($provider->processing_note)
                        <p class="bpa-provider__note">{{ $provider->processing_note }}</p>
                    @endif

                    @if($canManage)
                        <div class="admin-table-actions bpa-provider__actions">
                            <a href="{{ route('admin.bill-payment.providers.edit', $provider) }}"
                               class="btn btn-outline-primary btn-sm admin-table-action"
                               title="{{ __('Edit :name', ['name' => $provider->name]) }}">
                                <x-icon name="edit" width="14" height="14"/>
                                <span>{{ __('Edit') }}</span>
                            </a>
                            @if(! $provider->is_system && (int) $provider->billers_count === 0)
                                <form action="{{ route('admin.bill-payment.providers.destroy', $provider) }}"
                                      method="POST"
                                      class="admin-table-actions__form"
                                      data-bpa-confirm="{{ __('Delete :name? This cannot be undone.', ['name' => $provider->name]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="btn btn-outline-danger btn-sm admin-table-action admin-table-action--danger"
                                            title="{{ __('Delete :name', ['name' => $provider->name]) }}">
                                        <x-icon name="trash" width="14" height="14"/>
                                        <span>{{ __('Delete') }}</span>
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
@endsection
