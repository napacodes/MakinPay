<div class="dk-card__head">
    <div class="dk-card__titles">
        <div>
            <h2 class="dk-card__title">{{ __('Finish setting up DigiKash') }}</h2>
            <div class="dk-card__sub">{{ __('Your dashboard fills in as each step goes live.') }}</div>
        </div>
    </div>
    <div class="dk-setup__progress">
        <span class="dk-setup__count">{{ __(':done of :total done', ['done' => $dashboard['setup']['done'], 'total' => $dashboard['setup']['total']]) }}</span>
        <span class="dk-setup__track" aria-hidden="true"><span class="dk-setup__bar" style="width: {{ $dashboard['setup']['percent'] }}%"></span></span>
    </div>
</div>

<div class="dk-setup">
    @foreach($dashboard['setup']['steps'] as $step)
        <div class="dk-setup__step @if($step['done']) is-done @endif">
            <span class="dk-setup__dot" aria-hidden="true"><i class="fa-solid {{ $step['done'] ? 'fa-check' : 'fa-circle' }}"></i></span>
            <span class="dk-setup__copy">
                <span class="dk-setup__title">{{ $step['title'] }}</span>
                <span class="dk-setup__why">{{ $step['why'] }}</span>
            </span>
            <a href="{{ $step['link'] }}" class="dk-btn @if($step['done']) dk-btn--ghost @else dk-btn--primary @endif">{{ $step['action'] }}</a>
        </div>
    @endforeach
</div>

<div class="dk-card__foot">{{ __('Pending reviews will appear here once users start transacting.') }}</div>
