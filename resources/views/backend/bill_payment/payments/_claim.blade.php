{{--
    Who is on a payment. A claim is advisory and lapses after
    bill_payments.claim_ttl_minutes; a lapsed one is shown as such.

    `$compact` puts it on one line under the status in the queue; the detail
    page gives it a row of its own.
--}}
@php
    $bpaCompact = $compact ?? false;
@endphp
@if($bpaCompact)
    @if($row['claim'] !== null)
        <span class="bpa-claim-line">
            <x-icon name="user-security" width="12" height="12"/>
            @switch($row['claim'])
                @case('mine')
                    <span class="bpa-claim bpa-claim--mine">{{ __('You') }}</span>
                    @break
                @case('other')
                    <span class="bpa-claim">{{ $row['claimHolder'] }}</span>
                    @break
                @default
                    <span>{{ __(':name, claim lapsed', ['name' => $row['claimHolder']]) }}</span>
            @endswitch
            @if($row['claim'] !== 'expired')
                <span class="text-nowrap">· {{ $row['claimAgoShort'] }}</span>
            @endif
        </span>
    @endif
@else
    @switch($row['claim'])
        @case('mine')
            <span class="bpa-claim bpa-claim--mine">{{ __('You') }}</span>
            <span class="bpa-muted d-block">{{ $row['claimAgo'] }}</span>
            @break
        @case('other')
            <span class="bpa-claim">{{ $row['claimHolder'] }}</span>
            <span class="bpa-muted d-block">{{ $row['claimAgo'] }}</span>
            @break
        @case('expired')
            <span class="bpa-muted">{{ $row['claimHolder'] }}</span>
            <span class="bpa-muted d-block">{{ __('Claim lapsed') }}</span>
            @break
        @default
            <span class="bpa-muted">—</span>
    @endswitch
@endif
