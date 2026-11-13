@php use App\Enums\MethodType; @endphp
@php use App\Support\Money; @endphp
@extends('backend.deposit.index')
@section('title', $type->label().' '. __('Methods'))
@section('deposit_header')
    <x-page-header
        :title="__(':type Deposit Methods', ['type' => $type->label()])"
        :eyebrow="__('Finance')"
        :subtitle="__('Easily manage, create, or update all :type deposit methods for your platform from this page.', ['type' => $type->label()])"
        icon="deposit"
    >
        @can('deposit-method-manage')
            <x-slot:actions>
                @if($type->isAutomatic())
                    <a href="{{ route('admin.payment.gateway.index') }}" class="btn btn-outline-secondary">
                        <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
                        <span>{{ __('Payment Gateways') }}</span>
                    </a>
                @endif
                <a href="#new_payment_method_modal" data-coreui-toggle="modal" class="btn btn-primary">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    <span>{{ __('Create').' '. $type->label().' ' . __('Method') }}</span>
                </a>
            </x-slot:actions>
        @endcan
    </x-page-header>
@endSection

@section('deposit_content')
    <x-method-filter index-route="admin.deposit.method.index" :type="$type" :gateways="$paymentGateways" />

    <div class="card border-0 mb-4">
        <div class="card-body">
            <div class="table-responsive rounded">
                <table class="table mb-0 caption-top">
                    <thead>
                    <tr>
                        <th>{{ __('Logo') }}</th>
                        <th>{{ __('Name | Currency') }}</th>
                        <th>{{ __('Min | Max Deposit') }}</th>
                        <th>{{ __('Rate Type|Rate') }}</th>
                        <th>{{ __('Charges') }}</th>
                        <th>{{ __('Status') }}</th>
                        @can('deposit-method-manage')
                            <th>{{ __('Action') }}</th>
                        @endcan
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($paymentMethods as $paymentMethod)
                        <tr class="align-middle">
                            <td>
                                <div class="position-relative me-3">
                                    <img src="{{ asset($paymentMethod->logo_alt) }}" height="20" alt="" loading="lazy">
                                </div>
                            </td>
                            <td>
                                <div class="text-nowrap"><strong
                                        class="text-muted">{{ title($paymentMethod->name) }}</strong></div>
                                <div class="small text-body-secondary text-nowrap">
                                    <span class="badge text-bg-secondary">
                                        {{ $paymentMethod->currency }}
                                    </span>
                                </div>
                            </td>
                            <td>
                                {{-- Limits are denominated in the base currency, which is what
                                     the edit form collects and what PaymentService validates
                                     against. Labelling them with the method's own symbol is
                                     what led operators to type converted amounts here. --}}
                                <div class="text-nowrap"><strong
                                        class="text-muted"> {{ Money::trimFee($paymentMethod->min_deposit) }} {{ siteCurrency() }} </strong>
                                </div>
                                <div class="small text-body-secondary text-nowrap"><strong
                                        class="text-muted"> {{ Money::trimFee($paymentMethod->max_deposit) }} {{ siteCurrency() }} </strong>
                                </div>
                            </td>
                            <td>
                                <div class="text-nowrap">
                                    @if($paymentMethod->conversion_rate_live)
                                        <strong class="text-danger">{{ __('LIVE') }}</strong>
                                    @else
                                        <strong class="text-primary">{{ __('LOCAL') }}</strong>
                                    @endif
                                </div>
                                <div class="small text-body-secondary text-nowrap ">
                                    {{-- A rate is stored as decimal(36,18), so printing the column
                                         directly renders "1.000000000000000000". formatRate() keeps
                                         every significant digit and drops the padding. --}}
                                    <strong>1 {{ siteCurrency() }}
                                        = {{ \App\Support\Money::formatRate($paymentMethod->getRawOriginal('conversion_rate')) }} {{ $paymentMethod->currency}}</strong>
                                </div>
                            </td>
                            <td>
                                <x-charge-breakdown :method="$paymentMethod"/>
                            </td>
                            <td class="text-nowrap text-uppercase">
                                <span class="badge {{ $paymentMethod->status ? 'bg-success' : 'bg-danger' }}">
                                    {{ $paymentMethod->status ? __('Active') : __('Inactive') }}
                                </span>
                            </td>
                            @can('deposit-method-manage')
                                <td>
                                    @if( (isset($paymentMethod->paymentGateway) && $paymentMethod->paymentGateway->status) || $paymentMethod->type == MethodType::MANUAL )
                                        <button type="button"
                                                data-edit-url="{{ route('admin.deposit.method.edit', $paymentMethod->id) }}"
                                                class="btn btn-primary  edit-modal">
                                            <x-icon name="manage" height="20"/>
                                            {{ __('Manage') }}
                                        </button>
                                    @else
                                        <button disabled class="btn  btn-warning">
                                            <i class="fa-solid fa-lock me-1"></i> {{ __('Disabled by Gateway') }}
                                        </button>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-end mt-3">
                {{ $paymentMethods->links() }}
            </div>
        </div>
    </div>
    @can('deposit-method-manage')
        @include('backend.deposit.method.partials._new_payment_method_modal')
        @include('backend.deposit.method.partials._edit_payment_method_modal')
    @endcan
@endsection

@push('scripts')
    @can('deposit-method-manage')
        @include('backend.deposit.method.partials._scripts')
    @endcan
@endpush
