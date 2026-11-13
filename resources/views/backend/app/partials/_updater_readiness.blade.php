@php
    /*
     * Six checks, run on this request rather than cached, because the answer
     * an operator needs is "can this server install right now" — not what it
     * could do the last time somebody looked.
     */
    $readinessTone = $checksReady ? 'success' : 'danger';

    $readinessLabel = $checksReady
        ? __('All checks passed')
        : trans_choice('{1} :count check failed|[2,*] :count checks failed', $checksFailed, ['count' => $checksFailed]);
@endphp

<section class="pu-card" data-show="{{ $liveStates }}">
    <header class="pu-card__head">
        <div class="pu-card__head-main">
            <div class="pu-card__head-text">
                <h2 class="pu-card__title">{{ __('Environment readiness') }}</h2>
                <p class="pu-card__subtitle">{{ __('Checked automatically before every install.') }}</p>
            </div>
        </div>
        <span class="pu-pill pu-pill--{{ $readinessTone }}">
            <span class="pu-pill__dot"></span>{{ $readinessLabel }}
        </span>
    </header>

    <div class="pu-checks">
        @foreach($checks as $check)
            <div class="pu-check-row">
                {{--
                    A passing check wears the icon of the thing it checked —
                    six identical tick marks told the eye nothing about which
                    row was which. A failure is always the cross, so it stays
                    unmistakable at a glance.
                --}}
                <div class="pu-check-row__icon pu-check-row__icon--{{ $check['status'] ? 'ok' : 'fail' }}">
                    @if($check['status'] && ! empty($check['icon']))
                        <x-icon :name="$check['icon']" width="13" height="13"/>
                    @else
                        <i class="fas {{ $check['status'] ? 'fa-check' : 'fa-xmark' }}" aria-hidden="true"></i>
                    @endif
                </div>
                <div class="pu-check-row__copy">
                    <span class="pu-check-row__label">{{ $check['label'] }}</span>
                    <span class="pu-check-row__hint">{{ $check['help'] ?? '' }}</span>
                </div>
                <div class="pu-check-row__value pu-check-row__value--{{ $check['status'] ? 'ok' : 'fail' }}" dir="ltr">
                    {{ $check['value'] ?? ($check['status'] ? __('ok') : __('check')) }}
                </div>
            </div>
        @endforeach
    </div>
</section>
