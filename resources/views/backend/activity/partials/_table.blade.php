@php
    use App\Enums\LoginRisk;
@endphp

<div class="ah-scroll" data-ah-scroll>
    <div class="ah-grid ah-grid--head" role="row">
        <span>{{ __('Account') }}</span>
        <span>{{ __('Network') }}</span>
        <span>{{ __('Device') }}</span>
        <span>{{ __('Signed in') }}</span>
        <span>{{ __('Risk') }}</span>
        <span aria-hidden="true"></span>
    </div>

    @forelse($groups as $group)
        <div class="ah-group">
            {{ $group['label'] }}
            <span class="ah-group__count">· {{ trans_choice('{1} :count sign-in|[2,*] :count sign-ins', $group['count'], ['count' => $group['count']]) }}</span>
        </div>

        @foreach($group['rows'] as $row)
            @php
                $risk    = $row->risk ?? LoginRisk::NORMAL;
                $account = $row->user;
                $name    = $account ? trim($account->first_name.' '.$account->last_name) ?: $account->username : __('Deleted account');
                $avatar  = $account?->avatar ? asset($account->avatar) : null;
                $place   = implode(', ', array_filter([$row->city, $row->country]));
            @endphp

            <button type="button"
                    class="ah-grid ah-row ah-row--{{ $risk->value }}"
                    data-ah-row
                    data-ah-id="{{ $row->id }}"
                    aria-label="{{ __('Open sign-in details for :name', ['name' => $name]) }}">
                <span class="ah-account">
                    <span class="ah-avatar ah-avatar--{{ $row->id % 4 }}">
                        @if($avatar)
                            <img src="{{ $avatar }}" alt="" loading="lazy" onerror="this.remove()">
                        @else
                            {{ \Illuminate\Support\Str::of($name)->explode(' ')->take(2)->map(fn ($part) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($part, 0, 1)))->implode('') }}
                        @endif
                    </span>
                    <span class="ah-account__copy">
                        <span class="ah-account__line">
                            <span class="ah-account__name">{{ $name }}</span>
                            @if($account?->role)
                                <span class="ah-pill ah-pill--{{ $account->role->value }}">{{ $account->role->title() }}</span>
                            @endif
                        </span>
                        <span class="ah-account__handle">&#64;{{ $account->username ?? '—' }}</span>
                    </span>
                </span>

                <span class="ah-stack">
                    {{-- Masked while the demo is on: a full address on a public
                         demo is something a visitor can copy and reuse. --}}
                    <span class="ah-mono" title="{{ maskSensitive($row->ip_address, 'ip') }}">{{ maskSensitive($row->ip_address, 'ip') }}</span>
                    <span class="ah-sub">{{ $place ?: __('Unknown location') }}@if($row->isp) · {{ $row->isp }}@endif</span>
                </span>

                <span class="ah-stack">
                    <span class="ah-strong">{{ $row->browser ?: __('Unknown') }}</span>
                    <span class="ah-sub">{{ $row->platform ?: __('Unknown') }}@if($row->device_type) · {{ $row->device_type }}@endif</span>
                </span>

                <span class="ah-stack">
                    <span class="ah-strong ah-tabular">{{ $row->login_at?->format('H:i') }}</span>
                    <span class="ah-sub">{{ $row->login_at?->diffForHumans() }} · {{ $row->method?->label() ?? __('Password') }}</span>
                </span>

                <span>
                    <span class="ah-risk ah-risk--{{ $risk->tone() }}">
                        <span class="ah-dot"></span>{{ $risk->label() }}
                    </span>
                </span>

                <span class="ah-caret" aria-hidden="true"></span>
            </button>
        @endforeach
    @empty
        <div class="ah-empty">
            <span class="ah-empty__tile"><x-icon name="login" width="20" height="20"/></span>
            <span class="ah-empty__title">{{ __('No sign-ins match') }}</span>
            <span class="ah-empty__hint">{{ __('Try a wider date range or clear the filters.') }}</span>
        </div>
    @endforelse
</div>
