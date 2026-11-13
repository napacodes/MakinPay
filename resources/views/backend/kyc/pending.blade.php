@php
    use App\Enums\KycStatus;
    use App\Support\KycQueueRow;
@endphp
@extends('backend.layouts.app')
@section('title', __('KYC Pending Requests'))

@push('styles')
    <link rel="stylesheet"
          href="{{ asset('backend/css/kyc-queue.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/kyc-queue.css'))) }}">
@endpush

@section('content')
    @php
        $chips = [
            ['key' => 'all',       'label' => __('All'),                 'tone' => null,      'dot' => false],
            ['key' => 'breaching', 'label' => __('Breaching'),           'tone' => 'danger',  'dot' => true],
            ['key' => 'flagged',   'label' => __('Flagged'),             'tone' => 'danger',  'dot' => true],
            /* The clear verdicts are the good news on this page; a dot is
               the alarm marker and belongs to the two danger chips. */
            ['key' => 'approve',   'label' => __('Provider says clear'), 'tone' => 'success', 'dot' => false],
            ['key' => 'manual',    'label' => __('Manual only'),         'tone' => null,      'dot' => false],
        ];

        /*
         * What the duplicate strip says, given what became of the other
         * submission. The finder matches on the document fingerprint whatever
         * state the twin is in, and "already approved elsewhere" is a
         * different finding from "also waiting in this queue".
         */
        $duplicateLine = function (array $duplicate): string {
            $name = '<a href="'.e(route('admin.kyc.review', ['submission' => $duplicate['submission_id']])).'">'
                .e('@'.$duplicate['username']).'</a>';
            $when = $duplicate['decided_at']?->format('j M');

            return match (true) {
                $duplicate['status'] === KycStatus::APPROVED && $when !== null => __('This document hash matches an approved submission from :username — approved :when. Resolve before deciding.', ['username' => $name, 'when' => $when]),
                $duplicate['status'] === KycStatus::APPROVED                   => __('This document hash matches an approved submission from :username. Resolve before deciding.', ['username' => $name]),
                $duplicate['status'] === KycStatus::REJECTED && $when !== null => __('This document hash matches a rejected submission from :username — rejected :when. Resolve before deciding.', ['username' => $name, 'when' => $when]),
                $duplicate['status'] === KycStatus::REJECTED                   => __('This document hash matches a rejected submission from :username. Resolve before deciding.', ['username' => $name]),
                default                                                        => __('This document hash matches a submission from :username that is still waiting for a decision. Resolve before deciding.', ['username' => $name]),
            };
        };

        $firstInQueue = $kycPendingRequests->first();
    @endphp

    <div class="admin-users-page admin-kyc-page">
        <x-page-header
            :title="__('Pending reviews')"
            :subtitle="__('Oldest first, riskiest first. Provider verdicts and duplicate-document checks arrive with the submission, so a decision is one screen away.')"
            icon="fa-id-card"
            :status="$stats['breaching'] > 0
                ? ['tone' => 'warning', 'label' => trans_choice(':count breaching SLA|:count breaching SLA', $stats['breaching'], ['count' => $stats['breaching']])]
                : ['tone' => 'success', 'label' => __('All within SLA')]"
            {{-- Whole-queue figures, counted in the controller ahead of the paginator.
                 These used to be page counts, and the labels said so. --}}
            :stats="[
                [
                    'label' => __('In queue'),
                    'value' => number_format($stats['total']),
                    'note'  => __('across every page'),
                    'tone'  => 'primary',
                    'icon'  => 'inbox',
                ],
                [
                    'label' => __('Breaching SLA'),
                    'value' => number_format($stats['breaching']),
                    'note'  => __('waiting over :hours hours', ['hours' => $stats['sla_hours']]),
                    'tone'  => $stats['breaching'] > 0 ? 'danger' : 'success',
                    'icon'  => 'shield-ban',
                ],
                [
                    'label' => __('Submitted today'),
                    'value' => number_format($stats['today']),
                    'note'  => __('arrivals, last 14 days'),
                    {{-- The only cell whose figure and series are the same
                         measure, so the only one that earns a chart. --}}
                    'spark' => $stats['daily'],
                ],
                [
                    'label' => __('Oldest waiting'),
                    {{-- The same "3d 4h" form the Waiting column prints, out
                         of one helper, so the strip and the column agree. --}}
                    'value' => KycQueueRow::humanDuration($stats['oldest']),
                    'note'  => $stats['oldest'] ? $stats['oldest']->format('d M, H:i') : __('nothing waiting'),
                    'tone'  => $stats['breaching'] > 0 ? 'danger' : 'neutral',
                    'icon'  => 'clock',
                ],
            ]"
            class="mb-3"
        >
            <x-slot:actions>
                <a href="{{ request()->fullUrl() }}" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-rotate" aria-hidden="true"></i>{{ __('Refresh') }}
                </a>
                {{-- Opens the queue at its first row. Omitted rather than
                     disabled when there is no queue: a control that cannot
                     lead anywhere should not be on the page. --}}
                @if($firstInQueue)
                    <a href="{{ route('admin.kyc.review', array_merge(request()->query(), ['submission' => $firstInQueue->id])) }}"
                       class="btn btn-primary">
                        <i class="fa-solid fa-check" aria-hidden="true"></i>{{ __('Start review run') }}
                    </a>
                @endif
            </x-slot:actions>

            <x-slot:menu>
                <a href="{{ route('admin.kyc.export', request()->query()) }}" class="dropdown-item">
                    <span class="ph-menu__tile"><i class="fa-solid fa-file-csv" aria-hidden="true"></i></span>
                    <span class="ph-menu__copy">
                        <span class="ph-menu__label">{{ __('Export this queue') }}</span>
                        <span class="ph-menu__hint">{{ __('CSV of the current filter — decisions only, no documents') }}</span>
                    </span>
                </a>
                <a href="{{ route('admin.kyc.index') }}" class="dropdown-item">
                    <span class="ph-menu__tile"><i class="fa-solid fa-id-card" aria-hidden="true"></i></span>
                    <span class="ph-menu__copy">
                        <span class="ph-menu__label">{{ __('All submissions') }}</span>
                        <span class="ph-menu__hint">{{ __('Every decision ever made, settled and pending') }}</span>
                    </span>
                </a>
            </x-slot:menu>
        </x-page-header>

        {{-- Triage. Each chip carries its own count, so choosing a filter and
             reading the queue's shape are the same action. --}}
        <nav class="kq-triage" aria-label="{{ __('Triage filter') }}">
            <div class="kq-chips">
                @foreach($chips as $chip)
                    @php
                        $count = $triageCounts[$chip['key']] ?? 0;
                    @endphp
                    <a href="{{ request()->fullUrlWithQuery(['triage' => $chip['key'], 'page' => null]) }}"
                       class="kq-chip {{ $chip['tone'] ? 'kq-chip--'.$chip['tone'] : '' }} {{ $triage === $chip['key'] ? 'is-active' : '' }}"
                       @if($triage === $chip['key']) aria-current="true" @endif>
                        @if($chip['dot'] && $count > 0)
                            <span class="kq-chip__dot" aria-hidden="true"></span>
                        @endif
                        {{ $chip['label'] }}
                        <span class="kq-chip__count">{{ number_format($count) }}</span>
                    </a>
                @endforeach
            </div>

            {{-- One row: chips, search, date. The template picker that used to
                 sit here filtered nothing — the scope only understands a date
                 range and a search term — and the chips already slice the
                 queue by how it is being handled. --}}
            <form action="{{ route('admin.kyc.pending') }}" method="GET" class="kq-tools">
                <input type="hidden" name="triage" value="{{ $triage }}">

                <label class="kq-search">
                    <span class="visually-hidden">{{ __('Search submissions') }}</span>
                    <i class="fa-solid fa-magnifying-glass kq-search__icon" aria-hidden="true"></i>
                    <input type="text"
                           name="search"
                           value="{{ request('search') }}"
                           class="kq-search__input"
                           placeholder="{{ __('Search name, email or document number') }}">
                </label>

                <input type="hidden" name="daterange" value="{{ request('daterange') }}">
                <div id="reportrange" class="kq-daterange" role="button" tabindex="0"
                     aria-label="{{ __('Choose a date range') }}">
                    <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                    <span></span>
                    <i class="fa-solid fa-angle-down kq-daterange__caret" aria-hidden="true"></i>
                </div>

                @if(request()->hasAny(['search', 'daterange']))
                    <a href="{{ route('admin.kyc.pending', ['triage' => $triage]) }}" class="kq-reset">
                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                        <span>{{ __('Reset') }}</span>
                    </a>
                @endif
            </form>
        </nav>

        <section class="admin-users-panel">
            <div class="admin-users-table-wrap">
                @if($kycPendingRequests->isNotEmpty())
                    <table class="table admin-users-table kq-table--queue mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Applicant') }}</th>
                                <th>{{ __('Submission') }}</th>
                                <th>{{ __('Provider signal') }}</th>
                                <th>{{ __('Waiting') }}</th>
                                <th class="text-end">{{ __('Decision') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($kycPendingRequests as $submission)
                                @php
                                    $user       = $submission->user;
                                    $avatarData = getUserAvatarDetails($user->first_name, $user->last_name);
                                    $kycType    = $submission->kycTemplate?->title ?? __('Unknown template');
                                    $row        = KycQueueRow::for($submission);
                                    /* The same humanised labels the review
                                       workspace prints on its tabs, rather
                                       than the raw submission_data key. */
                                    $documents  = collect($row->documents())->pluck('label');
                                    $signal     = $row->providerSignal();
                                    $detail     = $row->detailHtml();
                                    $wait       = $row->waiting($slaHours);
                                    $duplicate  = $duplicates[$submission->id] ?? null;
                                    $span       = 5;
                                @endphp
                                <tr class="admin-users-row kq-row {{ $duplicate ? 'is-flagged' : '' }}">
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
                                                <a href="{{ route('admin.user.manage', ['username' => $user->username, 'param' => 'statistics']) }}"
                                                   class="admin-users-name">
                                                    {{ title($user->name) }}
                                                </a>
                                                <div class="admin-users-username">
                                                    <span>{{ '@'.$user->username }}</span>
                                                    <span class="admin-user-role-badge admin-user-role-badge--{{ $user->role->value }}">
                                                        {{ $user->role->title() }}
                                                    </span>
                                                    @if(filled($user->country))
                                                        <span>{{ $user->country }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    {{-- KYC Submission --}}
                                    <td>
                                        {{-- What was actually uploaded. The old cell said
                                             "Awaiting review" on every row of a page called
                                             Pending reviews, which is a label telling you where
                                             you already are. --}}
                                        <span class="kq-template">{{ $kycType }}</span>
                                        <span class="kq-chipset">
                                            @foreach($documents->take(3) as $document)
                                                <span class="kq-docchip">{{ $document }}</span>
                                            @endforeach
                                            @if($documents->count() > 3)
                                                <span class="kq-docchip">+{{ $documents->count() - 3 }}</span>
                                            @endif
                                            <span class="kq-docchip kq-docchip--{{ $user->email_verified_at ? 'ok' : 'bad' }}">
                                                {{ $user->email_verified_at ? __('Email verified') : __('Email unverified') }}
                                            </span>
                                        </span>
                                    </td>

                                    {{-- Provider signal: the verdict the engine already stored. --}}
                                    <td>
                                        <div class="kq-signal">
                                            <div class="kq-signal__head">
                                                <span class="kq-signal__tile kq-signal__tile--{{ $signal['accent'] }}" aria-hidden="true">{{ $signal['initials'] }}</span>
                                                @if($signal['name'])
                                                    <span class="kq-signal__engine">{{ $signal['name'] }}</span>
                                                @endif
                                                <span class="kq-pill kq-pill--{{ $signal['tone'] }}">
                                                    {{ $signal['label'] }}
                                                    @if($signal['confidence'])
                                                        <span class="kq-pill__score">{{ $signal['confidence'] }}</span>
                                                    @endif
                                                </span>
                                            </div>
                                            @if($detail)
                                                <span class="kq-signal__detail">{!! $detail !!}</span>
                                            @endif
                                        </div>
                                    </td>

                                    {{-- Waiting --}}
                                    <td>
                                        <div class="kq-wait">
                                            <span class="kq-wait__value is-{{ $wait['tone'] }}">{{ $wait['label'] }}</span>
                                            <span class="kq-wait__at">{{ $wait['at'] }}</span>
                                            <span class="kq-wait__bar" aria-hidden="true">
                                                <span class="kq-wait__fill is-{{ $wait['tone'] }}" style="inline-size: {{ $wait['percent'] }}%"></span>
                                            </span>
                                        </div>
                                    </td>

                                    <td class="text-end">
                                        <span class="kq-decide">
                                            <a href="{{ route('admin.kyc.review', array_merge(request()->query(), ['submission' => $submission->id])) }}"
                                               class="admin-table-action {{ $duplicate ? 'kq-review--urgent' : '' }}">
                                                <span>{{ __('Review') }}</span>
                                                <i class="fa-solid fa-chevron-right kq-review__caret" aria-hidden="true"></i>
                                            </a>

                                            {{--
                                                Everything here is a link or a copy. Approve and
                                                reject are deliberately absent: the workspace
                                                exists because a decision needs the documents on
                                                screen, and a one-click approve in a list menu is
                                                how a passport gets waved through unopened.
                                            --}}
                                            <span class="dropdown">
                                                <button type="button"
                                                        class="kq-rowmenu"
                                                        data-coreui-toggle="dropdown"
                                                        data-coreui-offset="0,6"
                                                        aria-expanded="false"
                                                        aria-label="{{ __('More actions for :name', ['name' => title($user->name)]) }}">
                                                    <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end kq-menu">
                                                    <li>
                                                        <a class="dropdown-item"
                                                           href="{{ route('admin.user.manage', ['username' => $user->username, 'param' => 'statistics']) }}">
                                                            <i class="fa-solid fa-user" aria-hidden="true"></i>{{ __('Open profile') }}
                                                        </a>
                                                    </li>
                                                    @if($duplicate)
                                                        {{-- Only where there is one to open. --}}
                                                        <li>
                                                            <a class="dropdown-item"
                                                               href="{{ route('admin.kyc.review', ['submission' => $duplicate['submission_id']]) }}">
                                                                <i class="fa-solid fa-fingerprint" aria-hidden="true"></i>{{ __('Open the duplicate') }}
                                                            </a>
                                                        </li>
                                                    @endif
                                                </ul>
                                            </span>
                                        </span>
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
                            {{ __('Showing :first–:last of :total pending submissions', [
                                'first' => number_format($kycPendingRequests->firstItem() ?? 0),
                                'last'  => number_format($kycPendingRequests->lastItem() ?? 0),
                                'total' => number_format($kycPendingRequests->total()),
                            ]) }}
                        </span>
                        {{ $kycPendingRequests->links() }}
                    </div>
                @else
                    <div class="admin-users-empty">
                        <x-admin-not-found
                            :title="$triage === 'all' ? __('No pending KYC requests') : __('Nothing in this filter')"
                            :message="$triage === 'all'
                                ? __('Pending submissions will appear here when users submit verification.')
                                : __('Clear the triage filter to see the rest of the queue.')"
                            icon="fa-id-card"
                        />
                    </div>
                @endif
            </div>
        </section>
    </div>

@endsection
