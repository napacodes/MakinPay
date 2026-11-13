@php
    /*
     * The status pill counts today's flagged sign-ins, so the screen says
     * whether there is anything to do before the operator reads a single row.
     */
    $status = $flaggedToday > 0
        ? ['tone' => 'warning', 'label' => trans_choice('{1} :count flagged today|[2,*] :count flagged today', $flaggedToday, ['count' => $flaggedToday])]
        : ['tone' => 'success', 'label' => __('No flags today')];

    $settingsUrl = route('admin.settings.site.index');
    $admin       = auth('admin')->user();
    $canManage   = (bool) $admin?->can('user-manage');
@endphp

<x-page-header
    :title="__('Activity history')"
    icon="login"
    tile="soft"
    :eyebrow="__('Security')"
    :subtitle="__('Every sign-in across users, merchants and agents — with device, network and risk context.')"
    :breadcrumbs="[['label' => __('Platform')], ['label' => __('Security')], ['label' => __('Activity history')]]"
    :status="$status"
    :stats="$headerStats"
>
    <x-slot:actions>
        <a href="{{ route('admin.activity-log.export', request()->query()) }}" class="ph-btn ph-btn--secondary">
            <x-icon name="file" width="16" height="16"/>{{ __('Export CSV') }}
        </a>

        <a href="{{ route('admin.activity-log', array_merge(request()->except('page'), ['risk' => 'flagged'])) }}" class="ph-btn ph-btn--primary">
            <x-icon name="shield-ban" width="16" height="16"/>{{ __('Review flagged') }}
        </a>
    </x-slot:actions>

    <x-slot:menu>
        <a href="{{ $settingsUrl }}" class="dropdown-item">
            <span class="ph-menu__tile"><x-icon name="shield"/></span>
            <span class="ph-menu__copy">
                <span class="ph-menu__label">{{ __('Risk rules') }}</span>
                <span class="ph-menu__hint">{{ __('Security → Sign-in Risk Rules') }}</span>
            </span>
        </a>

        <a href="{{ route('admin.ip-access.index') }}" class="dropdown-item">
            <span class="ph-menu__tile"><x-icon name="lock"/></span>
            <span class="ph-menu__copy">
                <span class="ph-menu__label">{{ __('IP deny list') }}</span>
                <span class="ph-menu__hint">{{ __('Manage blocked addresses') }}</span>
            </span>
        </a>

        <a href="{{ $settingsUrl }}" class="dropdown-item">
            <span class="ph-menu__tile"><x-icon name="blog"/></span>
            <span class="ph-menu__copy">
                <span class="ph-menu__label">{{ __('Retention policy') }}</span>
                <span class="ph-menu__hint">{{ trans_choice('{1} Sign-in logs are kept :count day|[2,*] Sign-in logs are kept :count days', $retentionDays, ['count' => $retentionDays]) }}</span>
            </span>
        </a>

        @if($canManage)
            <div class="dropdown-divider"></div>

            <button type="submit" form="ahSignOutAllForm" class="dropdown-item is-warning">
                <span class="ph-menu__tile"><x-icon name="login"/></span>
                <span class="ph-menu__copy">
                    <span class="ph-menu__label">{{ __('Sign out all sessions') }}</span>
                    <span class="ph-menu__hint">{{ __('Every user must sign in again') }}</span>
                </span>
            </button>
        @endif
    </x-slot:menu>
</x-page-header>

@if($canManage)
    <form id="ahSignOutAllForm" method="POST" action="{{ route('admin.activity-log.sign-out-all') }}" class="ah-hidden"
          data-ah-confirm="{{ __('Sign every customer out of every device? They will all have to sign in again.') }}">
        @csrf
    </form>
@endif
