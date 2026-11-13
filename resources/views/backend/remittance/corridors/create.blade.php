@extends('backend.layouts.app')
@section('title', __('Add Corridor'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/remittance.css?v=' . filemtime(public_path('backend/css/remittance.css'))) }}">
@endpush

@section('content')
    <div class="remittance-admin remittance-admin--form">

        {{-- The hero's meta strip said "~2 min / 6 steps"; the form below says it better. --}}
        <x-page-header
            :title="__('Add Corridor')"
            :subtitle="__('Define a new country pair, its FX margin, fees, and payout driver.')"
            icon="exchange"
            :back="route('admin.remittance.corridors.index')"
        />

        @include('backend.remittance.corridors._form', [
            'action'        => route('admin.remittance.corridors.store'),
            'method'        => 'POST',
            'corridor'      => $corridor,
            'payoutMethods' => $payoutMethods,
            'countries'     => $countries,
            'currencies'    => $currencies,
            'gateways'      => $gateways,
        ])
    </div>

@endsection
