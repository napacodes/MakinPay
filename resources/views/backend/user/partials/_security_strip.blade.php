{{--
    Security, as the first card of the Overview (delta 3): the overall
    reading and the two account actions in the header row, then one tonal
    tile per check. The tiles are 4-up on a wide card, 2×2 in the middle and
    1-up on a phone — the flex-basis formula never leaves an orphan.
--}}
<section class="um-card um-security" data-um-security>
    <div class="um-security__head">
        <div class="um-security__title">
            <h2 class="um-card__title">{{ __('Security') }}</h2>
            <span class="um-badge um-badge--bold um-badge--{{ $securityRail['overall']['tone'] }}"><span class="um-badge__dot"></span>{{ $securityRail['overall']['label'] }}</span>
        </div>
        @can('user-manage')
            <div class="um-security__actions">
                <button type="button" class="um-btn um-btn--xs" data-coreui-toggle="modal" data-coreui-target="#umResetModal">
                    <x-icon name="lock"/>{{ __('Reset password') }}
                </button>
                <form method="POST" action="{{ route('admin.user.sign-out-all', $user->id) }}" data-um-confirm="{{ __('Sign this user out of every device and revoke their API tokens?') }}">
                    @csrf
                    <button type="submit" class="um-btn um-btn--xs">
                        <x-icon name="login"/>{{ __('Sign out all') }}
                    </button>
                </form>
            </div>
        @endcan
    </div>

    <div class="um-security__tiles">
        @foreach($securityRail['rows'] as $row)
            <div class="um-security__tile">
                <span class="um-tile um-tile--sec {{ $row['tone'] === 'neutral' ? '' : 'um-tile--'.$row['tone'] }}"><x-icon :name="$row['icon']"/></span>
                <span class="um-security__copy">
                    <span class="um-security__label">{{ $row['label'] }}</span>
                    <span class="um-security__status um-security__status--{{ $row['tone'] }}">
                        @if($row['tone'] !== 'neutral')
                            <span class="um-badge__dot"></span>
                        @endif
                        {{ $row['value'] }}
                    </span>
                    <span class="um-security__sub">{{ $row['sub'] }}</span>
                </span>
            </div>
        @endforeach
    </div>
</section>
