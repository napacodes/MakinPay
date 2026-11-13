@php
    /*
     * The detail header: identity from the user row, the one status pill
     * from the suspension window / status / verification, contact details
     * as the meta row, Notify and Manage funds as the two actions, and
     * everything that changes the account's standing in the ⋯ menu.
     */
    $fullName    = trim($user->first_name.' '.$user->last_name) ?: $user->username;
    $isSuspended = $user->isSuspended();
    $firstName   = $user->first_name ?: $user->username;

    $accountType = match (true) {
        $user->isMerchant() => __('Merchant account'),
        $user->isAgent()    => __('Agent account'),
        default             => __('Personal account'),
    };

    $status = match (true) {
        $isSuspended                                    => ['tone' => 'warning', 'label' => __('Suspended')],
        $user->status !== \App\Enums\UserStatus::ACTIVE => ['tone' => 'danger', 'label' => $user->status?->label() ?? __('Inactive')],
        $user->email_verified_at === null               => ['tone' => 'neutral', 'label' => __('Pending')],
        default                                         => ['tone' => 'success', 'label' => __('Active')],
    };

    $location  = implode(', ', array_filter([$user->city, $user->country]));
    $lastLogin = $user->latestLoginActivity?->login_at;

    $meta = array_values(array_filter([
        $user->email ? maskSensitive($user->email) : null,
        $user->phone ? maskSensitive($user->phone, 'phone') : null,
        $location ?: null,
        $user->created_at ? __('Joined :date', ['date' => $user->created_at->format('d M Y')]) : null,
        $lastLogin ? __('Last login :time', ['time' => $lastLogin->diffForHumans()]) : __('Never signed in'),
    ]));

    $admin      = auth('admin')->user();
    $canManage  = (bool) $admin?->can('user-manage');
    $canConvert = $canManage && $user->isUser();
    $canDelete  = (bool) $admin?->can('user-delete');
    $canLogin   = (bool) $admin?->can('user-login-as');
@endphp

<x-page-header
    :title="$fullName"
    :avatar="$user"
    :eyebrow="__('User').' · '.$accountType"
    :badge="'@'.$user->username"
    :back="route('admin.user.index')"
    :back-label="__('Back to Users')"
    :breadcrumbs="[['label' => __('Platform')], ['label' => __('Users'), 'url' => route('admin.user.index')], ['label' => $fullName]]"
    :status="$status"
    :meta="$meta"
    :stats="$headerStats"
>
    <x-slot:actions>
        @can('custom-notify-users')
            <button type="button" class="ph-btn ph-btn--secondary" data-coreui-toggle="modal" data-coreui-target="#umNotifyModal">
                <x-icon name="bell" width="16" height="16"/>{{ __('Notify') }}
            </button>
        @endcan

        @can('user-balance-manage')
            <button type="button" class="ph-btn ph-btn--primary" data-coreui-toggle="modal" data-coreui-target="#umFundsModal">
                <x-icon name="add-balance" width="16" height="16"/>{{ __('Manage funds') }}
            </button>
        @endcan
    </x-slot:actions>

    @if($canLogin || $canManage || $canDelete)
        <x-slot:menu>
            @if($canLogin)
                {{-- target="_blank" only outside demo mode: DemoMode answers this route with a session toast that must land in the tab the admin is looking at. --}}
                <a href="{{ route('admin.user.login', $user->id) }}" @unless((bool) config('app.demo', false)) target="_blank" @endunless class="dropdown-item">
                    <span class="ph-menu__tile"><x-icon name="login"/></span>
                    <span class="ph-menu__copy">
                        <span class="ph-menu__label">{{ __('Login as user') }}</span>
                        <span class="ph-menu__hint">{{ __('Open the user panel as :name', ['name' => $firstName]) }}</span>
                    </span>
                </a>
            @endif

            @if($canConvert)
                <button type="button" class="dropdown-item" data-coreui-toggle="modal" data-coreui-target="#umConvertModal">
                    <span class="ph-menu__tile"><x-icon name="merchant"/></span>
                    <span class="ph-menu__copy">
                        <span class="ph-menu__label">{{ __('Convert to merchant') }}</span>
                        <span class="ph-menu__hint">{{ __('Switch account type') }}</span>
                    </span>
                </button>
            @endif

            @if(($canLogin || $canConvert) && ($canManage || $canDelete))
                <div class="dropdown-divider"></div>
            @endif

            @if($canManage && $isSuspended)
                <button type="submit" form="umLiftSuspensionForm" class="dropdown-item is-warning">
                    <span class="ph-menu__tile"><x-icon name="shield-ban"/></span>
                    <span class="ph-menu__copy">
                        <span class="ph-menu__label">{{ __('Lift suspension') }}</span>
                        <span class="ph-menu__hint">{{ $user->suspended_until ? __('Auto-lifts :time', ['time' => $user->suspended_until->diffForHumans()]) : __('Suspended until lifted') }}</span>
                    </span>
                </button>
            @elseif($canManage)
                <button type="button" class="dropdown-item is-warning" data-coreui-toggle="modal" data-coreui-target="#umSuspendModal">
                    <span class="ph-menu__tile"><x-icon name="shield-ban"/></span>
                    <span class="ph-menu__copy">
                        <span class="ph-menu__label">{{ __('Suspend account') }}</span>
                        <span class="ph-menu__hint">{{ __('Blocks sign-in and transactions') }}</span>
                    </span>
                </button>
            @endif

            @if($canDelete)
                <button type="button" class="dropdown-item is-danger" data-coreui-toggle="modal" data-coreui-target="#umDeleteModal">
                    <span class="ph-menu__tile"><x-icon name="trash"/></span>
                    <span class="ph-menu__copy">
                        <span class="ph-menu__label">{{ __('Delete account…') }}</span>
                        <span class="ph-menu__hint">{{ __('Permanent, requires confirmation') }}</span>
                    </span>
                </button>
            @endif
        </x-slot:menu>
    @endif
</x-page-header>

@if($canManage && $isSuspended)
    <form id="umLiftSuspensionForm" method="POST" action="{{ route('admin.user.lift-suspension', $user->id) }}" class="um-hidden">
        @csrf
    </form>
@endif
