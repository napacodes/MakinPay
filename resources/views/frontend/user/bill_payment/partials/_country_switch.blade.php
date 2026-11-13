{{--
    Which country's billers are listed. Expects $countries (list of
    code/name) and $country (?string). Names only: a flag emoji renders as
    two letters on Windows. A select only when there is a
    choice to make; a single country is shown as a plain label.
--}}
@if(count($countries) > 1)
    <form method="GET" class="bp-country" role="search">
        <label class="bp-country__label" for="bp-country-select">
            <i class="fa-solid fa-globe" aria-hidden="true"></i>
            {{ __('Billers in') }}
        </label>
        <select id="bp-country-select" name="country" class="form-select bp-country__select" data-bp-autosubmit>
            @foreach($countries as $option)
                <option value="{{ $option['code'] }}" @selected($option['code'] === $country)>
                    {{ $option['name'] }}
                </option>
            @endforeach
        </select>
        <noscript><button type="submit" class="btn btn-light btn-sm">{{ __('Show') }}</button></noscript>
    </form>
@elseif(count($countries) === 1)
    <span class="bp-country bp-country--single">
        <i class="fa-solid fa-globe" aria-hidden="true"></i>
        {{ __('Billers in') }}
        <strong>{{ $countries[0]['name'] }}</strong>
    </span>
@endif
