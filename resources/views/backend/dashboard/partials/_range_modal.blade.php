{{--
    Custom date range for "Money in & out".

    Apply reloads the page with range=custom and the two dates, which is the
    same contract start_date/end_date has always had — a bookmark of the result
    still works, and so does an integration that only ever knew those keys.
--}}
<div class="dk-modal" data-dk-range-modal hidden>
    <div class="dk-modal__backdrop" data-dk-range-dismiss></div>

    <div class="dk-modal__dialog dk-modal__dialog--range" role="dialog" aria-modal="true" aria-labelledby="dk-range-title">
        <div class="dk-modal__head">
            <div>
                <h3 class="dk-modal__title" id="dk-range-title">{{ __('Custom date range') }}</h3>
                <p class="dk-modal__sub">{{ __('Money in & out will show completed transactions in this span.') }}</p>
            </div>
            <button type="button" class="dk-modal__close" data-dk-range-dismiss aria-label="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>

        <div class="dk-modal__body">
            <div class="dk-range-fields">
                <label for="dk-range-start">{{ __('Start') }}
                    <input id="dk-range-start" type="date" data-dk-range-input="start" value="{{ $dashboard['range']->start->toDateString() }}" dir="ltr">
                </label>
                <label for="dk-range-end">{{ __('End') }}
                    <input id="dk-range-end" type="date" data-dk-range-input="end" value="{{ $dashboard['range']->end->toDateString() }}" dir="ltr">
                </label>
            </div>

            <div class="dk-chips" role="group" aria-label="{{ __('Range presets') }}">
                @foreach([14 => __('Last 14 days'), 60 => __('Last 60 days'), 90 => __('Last 90 days'), 0 => __('This year')] as $dkDays => $dkChip)
                    <button type="button" class="dk-chip" data-dk-range-preset="{{ $dkDays }}">{{ $dkChip }}</button>
                @endforeach
            </div>
        </div>

        <div class="dk-modal__foot">
            <span class="dk-modal__note" data-dk-range-summary></span>
            <div class="dk-modal__actions">
                <button type="button" class="dk-btn dk-btn--ghost" data-dk-range-dismiss>{{ __('Cancel') }}</button>
                <button type="button" class="dk-btn dk-btn--primary" data-dk-range-apply>{{ __('Apply range') }}</button>
            </div>
        </div>
    </div>
</div>
