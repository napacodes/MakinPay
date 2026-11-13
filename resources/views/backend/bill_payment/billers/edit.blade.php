@extends('backend.bill_payment.layout')

@section('title', __('Edit biller'))

@section('sub_header')
    <x-page-header
        :title="$biller->name"
        :subtitle="__('Changes apply to new payments. Payments already made keep what they were made with.')"
        icon="receipt"
        :back="route('admin.bill-payment.billers.index')"
        :status="$biller->status
            ? ['tone' => 'success', 'label' => __('Active')]
            : ['tone' => 'neutral', 'label' => __('Disabled')]"
        :meta="array_values(array_filter([
            $biller->category ? __($biller->category->name) : null,
            getCountryDisplayLabel($biller->country, false) ?? $biller->country,
            strtoupper((string) $biller->currency),
            $biller->provider?->name,
        ]))"
    />
@endsection

@section('sub_content')
    @include('backend.bill_payment.billers._form', [
        'action'      => route('admin.bill-payment.billers.update', $biller),
        'method'      => 'PUT',
        'submitLabel' => __('Save changes'),
    ])
@endsection
