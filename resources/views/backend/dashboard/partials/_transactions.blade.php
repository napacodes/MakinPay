@php
    use App\Enums\TrxStatus;

    $dkRows = collect($dashboard['transactions']);

    $dkTabs = [
        'all'     => ['label' => __('All'), 'count' => $dkRows->count()],
        'pending' => ['label' => __('Pending'), 'count' => $dkRows->where('status', TrxStatus::PENDING)->count()],
        'failed'  => ['label' => __('Failed'), 'count' => $dkRows->where('status', TrxStatus::FAILED)->count()],
    ];
@endphp

{{--
    Latest transactions.

    The table here is the ledger's, included rather than rebuilt. This panel
    used to carry a second design for the same thing — its own row markup, its
    own type/tone map, its own channel formatter and its own block of CSS —
    which meant every change to how a transaction reads had to be made twice,
    and more than once was made only once.

    What it does not take: the checkbox column, because there is nothing here
    to select, and the mobile day headers, because ten rows would be mostly
    headers and filtering them would strand the header of an emptied day.

    The card, its All/Pending/Failed switch and the footer are this panel's own
    and stay.
--}}
<section class="dk-card dk-activity" aria-label="{{ __('Latest transactions') }}">
    <div class="dk-card__head">
        <div class="dk-card__titles">
            <h2 class="dk-card__title">
                <span class="dk-card__tile dk-card__tile--brand" aria-hidden="true"><i class="fa-solid fa-receipt"></i></span>{{ __('Latest transactions') }}
            </h2>
            <span class="dk-card__sub">{{ __('Newest first · all types · live') }}</span>
        </div>

        <div class="dk-seg" role="group" aria-label="{{ __('Filter transactions') }}" data-dk-trx-tabs>
            @foreach($dkTabs as $dkTabKey => $dkTab)
                <button type="button"
                        class="dk-seg__btn @if($dkTabKey === 'all') is-active @endif"
                        data-tab="{{ $dkTabKey }}"
                        aria-pressed="{{ $dkTabKey === 'all' ? 'true' : 'false' }}">{{ $dkTab['label'] }}<span class="dk-seg__count" dir="ltr">{{ $dkTab['count'] }}</span></button>
            @endforeach
        </div>
    </div>

    @if($dkRows->isEmpty())
        {{-- The panel keeps its own empty state: "nothing has ever happened
             here" and the ledger's "nothing matches these filters" are
             different things to say. --}}
        <div class="dk-empty">
            <span class="dk-empty__icon" aria-hidden="true"><i class="fa-solid fa-receipt"></i></span>
            <div class="dk-empty__title">{{ __('No transactions yet') }}</div>
            <div class="dk-empty__body">{{ __('The first deposit, payment or transfer shows up here in real time.') }}</div>
        </div>
    @else
        <div data-dk-trx-rows>
            {{-- `compact` because this panel is a column of a dashboard, not a
                 page: it drops the description and the chevron, the two things
                 in a row that cost width without carrying a figure. --}}
            @include('backend.transaction.partials._table', [
                'transactions' => $dkRows,
                'selectable'   => false,
                'grouped'      => false,
                'compact'      => true,
            ])
        </div>
    @endif

    <div class="dk-card__foot">
        <span class="dk-card__status">
            <span class="dk-pulse" aria-hidden="true"></span>{{ trans_choice('{0}Nothing to show yet|{1}Showing the most recent transaction · full list has filters & export|[2,*]Showing the :count most recent · full list has filters & export', $dkRows->count(), ['count' => $dkRows->count()]) }}
        </span>
        <span class="dk-card__actions">
            <a href="{{ route('admin.transaction') }}" class="dk-btn dk-btn--outline">{{ __('All transactions') }}<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        </span>
    </div>
</section>
