@extends('backend.bill_payment.layout')

@section('title', __('New biller'))

@section('sub_header')
    <x-page-header
        :title="__('New biller')"
        :subtitle="__('New billers start switched off, so you can check them before customers see them.')"
        icon="receipt"
        :back="route('admin.bill-payment.billers.index')"
    />
@endsection

@section('sub_content')
    @include('backend.bill_payment.billers._form', [
        'action'      => route('admin.bill-payment.billers.store'),
        'method'      => 'POST',
        'submitLabel' => __('Save biller'),
    ])
@endsection
