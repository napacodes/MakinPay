@extends('backend.layouts.app')
@section('title', __('Transaction :id', ['id' => strtoupper($transaction->trx_id)]))

@section('content')
    {{--
        The deep-link page.

        It is the same modal the list opens, rendered on its own rather than a
        second layout of the same data — a support agent following a link from a
        ticket and an operator clicking a row must be looking at one screen, or
        the two of them end up describing different transactions to each other.

        `$detail` is rendered server-side into a `<noscript>` summary so the page
        still answers the basic question without JavaScript, which is the other
        half of what a deep link is for.
    --}}
    <div class="admin-users-page admin-trx-show">
        {{--
            The header states which transaction this is; the modal below
            states everything about it. Without one the page opened on a
            bare modal with no title of its own.
        --}}
        <x-page-header
            :title="$detail['type_label']"
            icon="transaction"
            :badge="$detail['id']"
            :back="route('admin.transaction')"
            :back-label="__('All transactions')"
            :meta="[$detail['status_label'], $detail['created_human']]"
        />

        <noscript>
            <div class="admin-trx-show__fallback">
                <h1>{{ $detail['type_label'] }} · <code>{{ $detail['id'] }}</code></h1>
                <p>{{ $detail['sign'] }}{{ $detail['amount'] }} {{ $detail['currency'] }} · {{ $detail['status_label'] }}</p>
                <p>{{ $detail['description'] }}</p>
                <p>{{ $detail['created_human'] }}</p>
                <a class="btn btn-primary" href="{{ $detail['urls']['receipt'] }}">{{ __('Download receipt PDF') }}</a>
            </div>
        </noscript>

        @include('backend.transaction.partials._detail_modal', [
            'trxStandaloneUrl' => $detail['urls']['self'],
        ])
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('backend/js/transaction-detail.js').'?v='.config('app.version').'-'.filemtime(public_path('backend/js/transaction-detail.js')) }}"></script>
@endpush
