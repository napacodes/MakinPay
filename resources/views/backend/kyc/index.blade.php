@php
    use App\Enums\KycStatus;
    use App\Support\KycQueueRow;
@endphp
@extends('backend.layouts.app')
@section('title', __('KYC verification records'))

@push('styles')
    <link rel="stylesheet"
          href="{{ asset('backend/css/kyc-queue.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/kyc-queue.css'))) }}">
@endpush

@section('content')
    @php
        $outcomeChips = [
            ['key' => 'all',      'label' => __('All'),      'value' => $stats['total'],    'tone' => null],
            ['key' => 'approved', 'label' => __('Approved'), 'value' => $stats['approved'], 'tone' => 'success'],
            ['key' => 'rejected', 'label' => __('Rejected'), 'value' => $stats['rejected'], 'tone' => 'danger'],
            ['key' => 'pending',  'label' => __('Pending'),  'value' => $stats['pending'],  'tone' => 'warning'],
        ];

        $approvedShare = $stats['total'] > 0 ? round($stats['approved'] / $stats['total'] * 100, 1) : 0;
        $providerShare = $stats['total'] > 0 ? round($stats['provider_decided'] / $stats['total'] * 100) : null;

        /*
         * The same status-aware sentence the queue prints. The finder matches
         * on the document fingerprint whatever state the other submission is
         * in, and an approved twin is a different finding from a queued one.
         */
        $duplicateLine = function (array $duplicate): string {
            $name = '<a href="'.e(route('admin.kyc.review', ['submission' => $duplicate['submission_id']])).'">'
                .e('@'.$duplicate['username']).'</a>';
            $when = $duplicate['decided_at']?->format('j M');

            return match (true) {
                $duplicate['status'] === KycStatus::APPROVED && $when !== null => __('This document hash matches an approved submission from :username — approved :when.', ['username' => $name, 'when' => $when]),
                $duplicate['status'] === KycStatus::APPROVED                   => __('This document hash matches an approved submission from :username.', ['username' => $name]),
                $duplicate['status'] === KycStatus::REJECTED && $when !== null => __('This document hash matches a rejected submission from :username — rejected :when.', ['username' => $name, 'when' => $when]),
                $duplicate['status'] === KycStatus::REJECTED                   => __('This document hash matches a rejected submission from :username.', ['username' => $name]),
                default                                                        => __('This document hash matches a submission from :username that is still waiting for a decision.', ['username' => $name]),
            };
        };

        $decidedByOptions = [
            'all'      => __('Anyone'),
            'provider' => __('A provider'),
            'admin'    => __('An administrator'),
        ];
    @endphp

    <div class="admin-users-page admin-kyc-page">
        <x-page-header
            :title="__('Verification records')"
            :subtitle="__('Every decision ever made, who made it and why. Read-only — a record is amended by a new submission, never edited in place.')"
            icon="fa-file-lines"
            tile="teal"
            {{-- Whole-ledger figures from the controller. The old strip counted the ten
                 rows on the page, which is why every label ended in "on page". --}}
            :stats="[
                [
                    'label' => __('All submissions'),
                    'value' => number_format($stats['total']),
                    'note'  => match (true) {
                        $stats['total'] === 0                             => __('nothing submitted yet'),
                        request()->hasAny(['search', 'daterange', 'outcome', 'decided_by']) => __('matching this filter'),
                        default                                           => __('since launch'),
                    },
                    'icon'  => 'layer',
                ],
                [
                    'label' => __('Approved'),
                    'value' => number_format($stats['approved']),
                    'note'  => __(':percent% of all', ['percent' => $approvedShare]),
                    'tone'  => 'success',
                    'icon'  => 'check',
                ],
                [
                    'label' => __('Rejected'),
                    'value' => number_format($stats['rejected']),
                    {{-- Only when a reason code was actually stored: codes
                         became mandatory late, so an older ledger has
                         nothing to count and says nothing rather than
                         guessing from free text. --}}
                    'note'  => $stats['top_rejection_reason']
                        ? __('top reason: :reason', ['reason' => strtolower($stats['top_rejection_reason']->label())])
                        : null,
                    'tone'  => $stats['rejected'] > 0 ? 'danger' : 'neutral',
                    'icon'  => 'shield-ban',
                ],
                [
                    'label' => __('Decided by provider'),
                    'value' => $providerShare === null ? '—' : $providerShare.'%',
                    'note'  => $providerShare === null ? __('nothing decided yet') : __('rest went to a human'),
                    'icon'  => 'sync',
                ],
            ]"
            class="mb-3"
        >
            <x-slot:actions>
                <a href="{{ route('admin.kyc.export', request()->query()) }}" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-file-csv" aria-hidden="true"></i>{{ __('Export CSV') }}
                </a>
            </x-slot:actions>
        </x-page-header>

        {{-- Outcome chips carry the ledger totals, so filtering and reading the
             split are the same action. --}}
        <nav class="kq-triage" aria-label="{{ __('Outcome filter') }}">
            <div class="kq-chips">
                @foreach($outcomeChips as $chip)
                    @php
                        $isActive = $outcome === $chip['key'];
                    @endphp
                    <a href="{{ request()->fullUrlWithQuery(['outcome' => $chip['key'], 'page' => null]) }}"
                       class="kq-chip {{ $chip['tone'] ? 'kq-chip--'.$chip['tone'] : '' }} {{ $isActive ? 'is-active' : '' }}"
                       @if($isActive) aria-current="true" @endif>
                        {{ $chip['label'] }}
                        <span class="kq-chip__count">{{ number_format($chip['value']) }}</span>
                    </a>
                @endforeach
            </div>

            {{-- Same one-row bar as the queue: outcome chips, a "decided by"
                 select, search, date. --}}
            <form action="{{ route('admin.kyc.index') }}" method="GET" class="kq-tools">
                <input type="hidden" name="outcome" value="{{ $outcome }}">

                <label class="kq-search">
                    <span class="visually-hidden">{{ __('Search records') }}</span>
                    <i class="fa-solid fa-magnifying-glass kq-search__icon" aria-hidden="true"></i>
                    <input type="text"
                           name="search"
                           value="{{ request('search') }}"
                           class="kq-search__input"
                           placeholder="{{ __('Search name, email, reference or reviewer') }}">
                </label>

                {{-- The label is on the control, not repeated inside each of
                     its three options. The submitted values are unchanged. --}}
                <label class="kq-select">
                    {{ __('Decided by') }}
                    <select name="decided_by" class="kq-select__input">
                        @foreach($decidedByOptions as $value => $label)
                            <option value="{{ $value }}" @selected($decidedBy === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <input type="hidden" name="daterange" value="{{ request('daterange') }}">
                <div id="reportrange" class="kq-daterange" role="button" tabindex="0"
                     aria-label="{{ __('Choose a date range') }}">
                    <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                    <span></span>
                    <i class="fa-solid fa-angle-down kq-daterange__caret" aria-hidden="true"></i>
                </div>

                @if(request()->hasAny(['search', 'daterange', 'decided_by']))
                    <a href="{{ route('admin.kyc.index', ['outcome' => $outcome]) }}" class="kq-reset">
                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                        <span>{{ __('Reset') }}</span>
                    </a>
                @endif
            </form>
        </nav>

        <section class="admin-users-panel">
            <div class="admin-users-table-wrap">
                @if($kycRequests->isNotEmpty())
                    @php
                        /*
                         * Four columns, and no action column. This is a ledger,
                         * and every other ledger in the admin — transactions,
                         * activity history, deposit history, IP access, release
                         * history — carries none either: one destination per row
                         * means the row is the link, so a column repeating "View"
                         * down the page is a column that says nothing.
                         *
                         * Nothing went with it. Both entries that column's menu
                         * held already exist on the destination: the workspace
                         * header opens the profile, its banner opens the duplicate.
                         */
                        $span = 4;
                    @endphp
                    <table class="table admin-users-table kq-table--records mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Applicant') }}</th>
                                <th>{{ __('Template') }}</th>
                                <th>{{ __('Outcome') }}</th>
                                <th>{{ __('Decided by') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($kycRequests as $submission)
                                @php
                                    $user        = $submission->user;
                                    $avatarData  = getUserAvatarDetails($user->first_name, $user->last_name);
                                    $statusEnum  = $submission->status;
                                    $statusTone  = $statusEnum?->color() ?? 'secondary';
                                    $statusLabel = $statusEnum?->label() ?? __('Unknown');
                                    $kycType     = $submission->kycTemplate?->title ?? __('Unknown template');
                                    $row         = KycQueueRow::for($submission);
                                    $decision    = $row->decision();
                                    $signal      = $row->providerSignal();
                                    $detail      = $row->detailHtml();
                                    $duplicate   = $duplicates[$submission->id] ?? null;
                                @endphp
                                <tr class="admin-users-row align-middle kq-row {{ $duplicate ? 'is-flagged' : '' }}">
                                    {{-- Applicant --}}
                                    <td>
                                        <div class="admin-users-person">
                                            <div class="admin-users-avatar-wrap">
                                                @if($user->avatar)
                                                    <img class="admin-users-avatar" src="{{ asset($user->avatar) }}"
                                                         alt="{{ $user->name }}" loading="lazy">
                                                @else
                                                    <div class="admin-users-avatar {{ $avatarData['class'] }} text-white">
                                                        {{ $avatarData['initials'] }}
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="admin-users-person__copy">
                                                {{-- The name opens the record, because the record
                                                     is what this page lists. The handle keeps the
                                                     way to the person, which is the one route the
                                                     removed action column really owned. --}}
                                                <a href="{{ route('admin.kyc.review', array_merge(request()->query(), ['submission' => $submission->id])) }}"
                                                   class="admin-users-name">
                                                    {{ title($user->name) }}
                                                </a>
                                                <div class="admin-users-username">
                                                    <span><a href="{{ route('admin.user.manage', ['username' => $user->username, 'param' => 'statistics']) }}" class="kq-handle">{{ '@'.$user->username }}</a>@if(filled($user->country)) · {{ $user->country }}@endif</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    {{-- Template. The submission's own date is not here:
                                         the date that matters on a ledger row is the day
                                         it was decided, and that is in the next cell. --}}
                                    <td>
                                        <div class="admin-users-doc">
                                            <span class="kq-template">{{ $kycType }}</span>
                                        </div>
                                    </td>

                                    {{-- Outcome --}}
                                    <td>
                                        <span class="admin-users-pill admin-users-pill--{{ $statusTone }}">
                                            @if($statusEnum === KycStatus::APPROVED)
                                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                            @elseif($statusEnum === KycStatus::PENDING)
                                                <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                                            @elseif($statusEnum === KycStatus::REJECTED)
                                                <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i>
                                            @else
                                                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                                            @endif
                                            {{ $statusLabel }}
                                        </span>
                                        @if(filled($decision['reason'] ?? null))
                                            <span class="kq-signal__detail kq-signal__detail--reason">{{ __('Reason: :reason', ['reason' => $decision['reason']]) }}</span>
                                        @endif
                                        @if($duplicate)
                                            <span class="kq-signal__detail kq-signal__detail--flag">{{ __('Duplicate document flagged') }}</span>
                                        @endif
                                    </td>

                                    {{-- Decided by: the review log knows whether a person
                                         or the pipeline settled this; the submission alone
                                         does not. --}}
                                    <td>
                                        <div class="kq-signal">
                                            @if($decision)
                                                <span class="kq-signal__engine">
                                                    {{ $decision['automated']
                                                        ? __(':provider — cleared without a human', ['provider' => $decision['by']])
                                                        : __(':name · administrator', ['name' => $decision['by']]) }}
                                                </span>
                                                <span class="kq-signal__detail">
                                                    {{ $submission->risk_score === null
                                                        ? $decision['at']
                                                        : __(':at · risk score :score of 100', ['at' => $decision['at'], 'score' => $submission->risk_score]) }}
                                                </span>
                                            @else
                                                <span class="kq-signal__engine kq-signal__engine--muted">{{ __('No decision recorded yet') }}</span>
                                                {{-- No human has settled it, but the engine
                                                     may already have an opinion, and that is
                                                     what tells the reader whether this row is
                                                     waiting on them or on a webhook. --}}
                                                @if($signal['name'])
                                                    <span class="kq-signal__detail">{{ __(':provider returned “:verdict”', ['provider' => $signal['name'], 'verdict' => $signal['label']]) }}</span>
                                                @elseif($detail)
                                                    <span class="kq-signal__detail">{!! $detail !!}</span>
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                </tr>

                                @if($duplicate)
                                    <tr class="kq-flag-row">
                                        <td colspan="{{ $span }}">
                                            <div class="kq-flag" role="status">
                                                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                                                <span class="kq-flag__copy">
                                                    <strong>{{ __('Same :document already on file.', ['document' => strtolower($duplicate['field_label'])]) }}</strong>
                                                    {!! $duplicateLine($duplicate) !!}
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>

                    <div class="admin-users-pagination kq-pagination">
                        <span class="kq-pagination__summary">
                            {{ __('Showing :from–:to of :total records', [
                                'from'  => number_format($kycRequests->firstItem() ?? 0),
                                'to'    => number_format($kycRequests->lastItem() ?? 0),
                                'total' => number_format($kycRequests->total()),
                            ]) }}
                        </span>
                        {{ $kycRequests->links() }}
                    </div>
                @else
                    <div class="admin-users-empty">
                        <x-admin-not-found
                            :title="__('No KYC requests found')"
                            :message="__('KYC submissions matching the current filters will appear here.')"
                            icon="fa-id-card"
                        />
                    </div>
                @endif
            </div>
        </section>
    </div>
@endsection
