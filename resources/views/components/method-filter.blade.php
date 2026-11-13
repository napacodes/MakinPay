@props(['indexRoute', 'type', 'gateways'])

@php($filterActive = filled(request('search')) || filled(request('gateway')))

<form method="GET" action="{{ route($indexRoute) }}" class="pgw-filter pgw-filter--methods" role="search">
    <input type="hidden" name="type" value="{{ $type->value }}">

    <div class="pgw-filter__search">
        <label class="form-label" for="method-search">{{ __('Search') }}</label>
        <div class="pgw-filter__input">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search"
                   id="method-search"
                   name="search"
                   class="form-control"
                   value="{{ request('search') }}"
                   placeholder="{{ __('Name or currency') }}"
                   autocomplete="off">
        </div>
    </div>

    <div class="pgw-filter__field">
        <label class="form-label" for="method-gateway">{{ __('Gateway') }}</label>
        <select id="method-gateway" name="gateway" class="form-select">
            <option value="">{{ __('All Gateways') }}</option>
            @foreach($gateways as $gatewayOption)
                <option value="{{ $gatewayOption->id }}" @selected((string) request('gateway') === (string) $gatewayOption->id)>
                    {{ $gatewayOption->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="pgw-filter__actions">
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-filter" aria-hidden="true"></i>
            {{ __('Filter') }}
        </button>
        @if($filterActive)
            <a href="{{ route($indexRoute, ['type' => $type->value]) }}" class="btn btn-light">
                <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                {{ __('Reset') }}
            </a>
        @endif
    </div>
</form>
