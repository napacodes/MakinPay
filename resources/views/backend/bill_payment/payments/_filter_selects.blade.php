{{-- The biller, category and country selects both payment lists share. --}}
<div class="col-6 col-md-3 col-xl-2">
    <label class="visually-hidden" for="{{ $idPrefix }}-biller">{{ __('Biller') }}</label>
    <select id="{{ $idPrefix }}-biller" name="biller" class="form-select">
        <option value="">{{ __('All billers') }}</option>
        @foreach($billers as $biller)
            <option value="{{ $biller->id }}" @selected($filters['biller'] === (string) $biller->id)>{{ $biller->name }} ({{ $biller->country }})</option>
        @endforeach
    </select>
</div>
<div class="col-6 col-md-3 col-xl-2">
    <label class="visually-hidden" for="{{ $idPrefix }}-category">{{ __('Category') }}</label>
    <select id="{{ $idPrefix }}-category" name="category" class="form-select">
        <option value="">{{ __('All categories') }}</option>
        @foreach($categories as $category)
            <option value="{{ $category->code }}" @selected($filters['category'] === $category->code)>{{ __($category->name) }}</option>
        @endforeach
    </select>
</div>
<div class="col-6 col-md-3 col-xl-auto">
    <label class="visually-hidden" for="{{ $idPrefix }}-country">{{ __('Country') }}</label>
    <select id="{{ $idPrefix }}-country" name="country" class="form-select">
        <option value="">{{ __('All countries') }}</option>
        @foreach($countries as $country)
            <option value="{{ $country }}" @selected($filters['country'] === $country)>{{ getCountryDisplayLabel($country, false) ?? $country }}</option>
        @endforeach
    </select>
</div>
