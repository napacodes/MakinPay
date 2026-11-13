@php
    $tabUrl = fn (string $param): string => route('admin.user.manage', ['username' => $user->username, 'param' => $param]);
    $tabs = [
        ['param' => 'statistics', 'label' => __('Overview')],
        ['param' => 'info', 'label' => __('Information')],
        ['param' => 'transactions', 'label' => __('Transactions')],
    ];

    /* Shown while the module is on, or while this customer still has vaults. */
    if ($vaultsVisible ?? false) {
        $tabs[] = ['param' => 'vaults', 'label' => __('Vaults')];
    }

    if ($user->isMerchant()) {
        $tabs[] = ['param' => 'merchants', 'label' => __('Merchants')];
    }

    /* No count pills: the header stats strip is the one place counts live. */
    $tabs[] = ['param' => 'referrals', 'label' => __('Referrals')];

    if (auth('admin')->user()?->can('support-list')) {
        $tabs[] = ['param' => 'tickets', 'label' => __('Tickets')];
    }

    $tabs[] = ['param' => 'activities', 'label' => __('Activity')];
@endphp

<nav class="ph-tabs" aria-label="{{ __('User sections') }}">
    @foreach($tabs as $tab)
        <a href="{{ $tabUrl($tab['param']) }}"
           class="ph-tab {{ $activeTab === $tab['param'] ? 'is-active' : '' }}"
           @if($activeTab === $tab['param']) aria-current="page" @endif>
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
