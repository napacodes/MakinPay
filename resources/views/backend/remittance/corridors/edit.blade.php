@extends('backend.layouts.app')
@section('title', __('Edit Corridor'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/remittance.css?v=' . filemtime(public_path('backend/css/remittance.css'))) }}">
@endpush

@section('content')
    <div class="remittance-admin remittance-admin--form">

        {{-- The corridor's source pair is the ID chip; the decorative map is gone. --}}
        <x-page-header
            :title="$corridor->name"
            icon="exchange"
            :back="route('admin.remittance.corridors.index')"
            :badge="$corridor->source_country.'/'.$corridor->source_currency"
            :status="['tone' => $corridor->status ? 'success' : 'neutral', 'label' => $corridor->status ? __('Active') : __('Disabled')]"
        />

        @include('backend.remittance.corridors._form', [
            'action'        => route('admin.remittance.corridors.update', $corridor),
            'method'        => 'PUT',
            'corridor'      => $corridor,
            'payoutMethods' => $payoutMethods,
            'countries'     => $countries,
            'currencies'    => $currencies,
            'gateways'      => $gateways,
        ])
    </div>

@endsection
