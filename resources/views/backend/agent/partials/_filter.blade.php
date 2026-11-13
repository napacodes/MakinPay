@php
    /*
     * The status select is redundant on a scoped screen — the tabs above
     * already say which slice you are in — so it is drawn only on the
     * unscoped list. The test is $scope, a status value, never a translated
     * label: the same check written against __('All') silently stopped
     * matching the moment the panel was switched to Arabic.
     */
    $routeName      = Route::currentRouteName();
    $route          = route($routeName);
    $showStatus     = ($scope ?? 'all') === 'all';
    $showCurrencies = ! empty($currencies);
@endphp

<div class="admin-table-filterbar d-flex mb-3">
    <form action="{{ $route }}" method="GET" class="admin-table-filters row g-2 g-md-3">

        <div class="col-auto">
            <div class="input-group">
                <input type="hidden" name="daterange" value="{{ request('daterange') }}">
                <div id="reportrange" class="form-control d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-calendar-days"></i>
                        <span class="text-nowrap flex-grow-1"></span>
                    </div>
                    <x-icon name="angle-down" class="text-muted flex-shrink-0"/>
                </div>
            </div>
        </div>

        {{--
            The filter bar carries no visible labels — the sibling merchant bar
            does not either, and two bars the same operator uses in one sitting
            should not disagree. The blank first option names the control on
            screen; aria-label names it for a reader, which the placeholder-style
            option alone never did.
        --}}
        @if($showStatus)
            <div class="col-auto">
                <x-form.select
                    name="status"
                    :label="__('Agent Status')"
                    class="form-select pe-5"
                    :options="App\Enums\AgentStatus::options()"
                    :selected="request('status')"
                    :aria-label="__('Agent Status')"
                />
            </div>
        @endif

        @if($showCurrencies)
            <div class="col-auto">
                <x-form.select
                    name="currency"
                    :label="__('Any Currency')"
                    class="form-select pe-5"
                    :options="$currencies"
                    :selected="request('currency')"
                    :aria-label="__('Currency')"
                />
            </div>
        @endif

        <div class="col-12 col-md-6 col-xl-auto">
            <div class="input-group">
                <input type="text" name="search" value="{{ request('search') }}"
                       class="form-control"
                       placeholder="{{ __('Search') }}..." aria-label="{{ __('Search...') }}">
                <button type="submit" class="btn btn-primary" aria-label="{{ __('Search') }}">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </form>
</div>
