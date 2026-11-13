@extends('backend.layouts.app')
@section('title', $title)

@section('content')
    @php
        /*
         * The strip reads $summary, which the controller computes across the
         * whole installation. It used to count the ten rows the paginator
         * happened to return, so every figure moved when you turned a page
         * and "Active on page" meant nothing an operator could act on.
         */
        $activeDelta = $summary['activeYesterday'] > 0
            ? round(($summary['activeToday'] - $summary['activeYesterday']) / $summary['activeYesterday'] * 100, 1)
            : null;
    @endphp
    
    <div class="admin-users-page">
        <x-page-header
            :title="$title"
            :subtitle="__('Customer records, balances, activity logs and login access.')"
            icon="users-1"
            :eyebrow="__('People')"
            :status="['label' => __(':count accounts', ['count' => number_format($summary['total'])])]"
            {{--
                Board 2a's four cells. The two with a history behind them —
                the headcount and today's sign-ins — carry a sparkline; the
                two that are counts carry a tonal tile, because the handoff
                forbids drawing a trend on a figure that has none.
            --}}
            :stats="[
                {{--
                    Both of these pass a spark *and* an icon. The component
                    keeps the sparkline whenever there is one and falls back
                    to the tile when the run is empty — which is what a fresh
                    installation has, and a cell with nothing on its inline
                    end beside three that have something reads as broken.
                --}}
                [
                    'label' => __('Total users'),
                    'value' => number_format($summary['total']),
                    'delta' => $summary['newThisWeek'] > 0 ? '+'.number_format($summary['newThisWeek']) : null,
                    'tone'  => 'success',
                    'note'  => __('this week'),
                    'spark' => $summary['totalSpark'],
                    'icon'  => 'users-1',
                ],
                [
                    'label' => __('Active today'),
                    'value' => number_format($summary['activeToday']),
                    'delta' => $activeDelta === null ? null : ($activeDelta >= 0 ? '+' : '−').abs($activeDelta).'%',
                    'tone'  => $activeDelta !== null && $activeDelta < 0 ? 'warning' : 'success',
                    'note'  => __('vs. yesterday'),
                    'spark' => $summary['activeSpark'],
                    'icon'  => 'kpi-users',
                ],
                [
                    'label' => __('KYC pending'),
                    'value' => number_format($summary['kycPending']),
                    'tone'  => $summary['kycPending'] > 0 ? 'warning' : 'success',
                    'note'  => __('awaiting review'),
                    'icon'  => 'kyc',
                ],
                [
                    'label' => __('Suspended'),
                    'value' => number_format($summary['suspended']),
                    'tone'  => $summary['suspended'] > 0 ? 'warning' : 'neutral',
                    'note'  => __('needs review'),
                    'icon'  => 'shield-ban',
                ],
            ]"
            class="mb-3"
        >
            <x-slot:actions>
                @can('user-create')
                    <a href="#new_user_modal" data-coreui-toggle="modal" class="ph-btn ph-btn--primary">
                        <x-icon name="plus" width="14" height="14"/>
                        {{ __('Add user') }}
                    </a>
                @endcan
            </x-slot:actions>
        </x-page-header>
        
        <section class="admin-users-panel">
            @include('backend.user.partials._filters')
            
            <div class="admin-users-table-wrap">
                @if($users->isNotEmpty())
                    <table class="table admin-users-table mb-0">
                        <thead>
                        <tr>
                            <th>{{ __('Member') }}</th>
                            <th>{{ __('Contact') }}</th>
                            <th>{{ __('Verification') }}</th>
                            <th>{{ __('Joined') }}</th>
                            <th>{{ __('Last Login') }}</th>
                            @can('user-manage')
                                <th class="text-end">{{ __('Action') }}</th>
                            @endcan
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($users as $user)
                            @php
                                $avatarData = getUserAvatarDetails($user->first_name, $user->last_name);

								$kycSubmission = $user->kycSubmission;
								$kycStatus = $kycSubmission?->status ?? null;

								$badgeColor = $kycStatus?->color() ?? 'danger';
								$statusText = $kycStatus?->label() ?? __('Not Submitted');
								$kycTitle = $kycSubmission?->kycTemplate->title ?? __('No Submission');
                            @endphp
                            <tr class="admin-users-row align-middle">
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
                                            <span class="admin-users-status-dot bg-{{ $user->status->color() }}"
                                                  title="{{ $user->status->label() }}"></span>
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
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="admin-users-contact">
                                        <div class="admin-users-contact__line">
                                            <i class="fa-regular fa-envelope" aria-hidden="true"></i>
                                            <span>{{ maskSensitive($user->email) }}</span>
                                        </div>
                                        <span class="admin-users-pill admin-users-pill--{{ $user->email_verified_at ? 'success' : 'danger' }}">
                                            <i class="fa-solid {{ $user->email_verified_at ? 'fa-circle-check' : 'fa-circle-xmark' }}" aria-hidden="true"></i>
                                            {{ $user->email_verified_at ? __('Verified') : __('Unverified') }}
                                        </span>
                                        {{-- PH10: the registered country, and which side of the home country it is on, as tax reads it. --}}
                                        @php $accountScope = $user->accountScope(); @endphp
                                        <div class="admin-users-contact__line" data-account-scope="{{ $accountScope ?? '' }}">
                                            <i class="fa-regular fa-globe" aria-hidden="true"></i>
                                            <span dir="ltr">{{ strtoupper((string) $user->country) ?: '—' }}</span>
                                            @if($accountScope !== null)
                                                <span class="admin-users-pill admin-users-pill--{{ $accountScope === 'local' ? 'primary' : 'document' }}">
                                                    {{ $accountScope === 'local' ? __('Local') : __('International') }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="admin-users-doc">
                                        <span class="admin-users-doc__label">
                                            {{ $kycSubmission ? $kycTitle : __('No KYC document submitted') }}
                                        </span>
                                        <div class="admin-users-doc__badges">
                                            <span class="admin-users-pill admin-users-pill--{{ $badgeColor }}">
                                                {{ $statusText }}
                                            </span>
                                            <span class="admin-users-pill admin-users-pill--document">
                                            {{ $kycSubmission ? __('Document') : __('KYC') }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="admin-users-date">
                                        <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                                        <div>
                                            <span>{{ $user->created_at->format('Y-m-d H:i') }}</span>
                                            <small>{{ $user->created_at->diffForHumans() }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="admin-users-date {{ optional($user->latestLoginActivity)->login_at ? '' : 'is-empty' }}">
                                        <i class="fa-regular fa-clock" aria-hidden="true"></i>
                                        <div>
                                            <span>{{ optional($user->latestLoginActivity)->login_at?->format('Y-m-d H:i') ?? '--' }}</span>
                                            <small>{{ optional($user->latestLoginActivity)->login_at?->diffForHumans() ?? '--' }}</small>
                                        </div>
                                    </div>
                                </td>
                                
                                @can('user-manage')
                                    <td class="text-end">
                                        <a href="{{ route('admin.user.manage', ['username' => $user->username, 'param' => 'statistics']) }}" class="admin-table-action">
                                            <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                                            <span>{{ __('Manage') }}</span>
                                        </a>
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    
                    <div class="admin-users-pagination">
                        {{ $users->links() }}
                    </div>
                @else
                    <div class="admin-users-empty">
                        <x-admin-not-found
                            :title="__('No users found')"
                            :message="__('No user accounts match the current search or filter criteria.')"
                            icon="fa-users"
                        />
                    </div>
                @endif
            </div>
        </section>
    </div>
    
    {{-- Create New User Modal --}}
    @can('user-create')
        @include('backend.user.partials._new_user')
    @endcan

@endsection
