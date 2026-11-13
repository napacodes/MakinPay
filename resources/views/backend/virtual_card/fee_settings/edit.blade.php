@extends('backend.virtual_card.index')
@section('title', __('Edit Fee Setting'))
@section('virtual_card_header')
    <x-page-header
        icon="fa-pen-to-square"
        :title="__('Edit Fee Setting')"
        :subtitle="__('Update the selected virtual card pricing rule and operating limits.')"
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
        @include('backend.virtual_card.fee_settings.partials._form', ['feeSetting' => $feeSetting])
    </div>
@endsection
