@extends('backend.bill_payment.layout')

@section('title', __('New provider'))

@section('sub_header')
    <x-page-header
        :title="__('New provider')"
        :subtitle="__('Choose the driver carefully: it cannot change once billers depend on it.')"
        icon="receipt"
        :back="route('admin.bill-payment.providers.index')"
    />
@endsection

@section('sub_content')
    @include('backend.bill_payment.providers._form', [
        'action'      => route('admin.bill-payment.providers.store'),
        'method'      => 'POST',
        'submitLabel' => __('Save provider'),
    ])
@endsection
