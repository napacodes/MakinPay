{{--
    The ledger's own transaction modal, for the two user tabs that list
    transactions.

    `backend/transaction/partials/_table.blade.php` states the contract: a row
    carrying `data-trx-url` opens this modal anywhere it appears, provided the
    page also ships the dialogue and its script. These tabs draw their own rows
    rather than including that table — they are a different shape, narrower and
    without the selection column — so they opt in here instead.

    Gated on `transaction-list` because that is what `TransactionController`
    requires of `show`. Without the gate a row would look clickable to an
    administrator who cannot open it, and answer with a 403.
--}}
@can('transaction-list')
    @include('backend.transaction.partials._detail_modal')

    @push('scripts')
        @php($trxDetailJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/transaction-detail.js')))
        <script src="{{ asset('backend/js/transaction-detail.js?v='.$trxDetailJsVersion) }}"></script>
    @endpush
@endcan
