@extends('backend.virtual_card.index')
@section('title', __('Add Fee Setting'))
@section('virtual_card_header')
    <x-page-header
        icon="fa-tag"
        :title="__('Add Fee Setting')"
        :subtitle="__('Create a pricing rule for virtual card operations and supported currencies.')"
    >
        <x-slot:actions>
            <a href="{{ route('admin.virtual-card.fee-settings.index') }}" class="btn btn-outline-secondary">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                {{ __('Back to List') }}
            </a>
        </x-slot:actions>
    </x-page-header>
@endsection
@section('virtual_card_content')
    <div class="card-body vc-admin-board vc-admin-form-board">
        @include('backend.virtual_card.fee_settings.partials._form', ['feeSetting' => null])
    </div>
@endsection
