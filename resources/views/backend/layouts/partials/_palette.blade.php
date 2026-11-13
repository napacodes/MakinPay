@php
    /*
     * The command palette.
     *
     * One box that searches pages, users and transactions through the
     * global search endpoint, drawn under the header's search trigger. This
     * is the shell only: rows arrive from admin-palette.js, which builds them
     * with textContent so nothing the server sends is ever parsed as markup.
     *
     * The scope chips are the ones this admin may use. A scope they have no
     * permission for is not drawn greyed out; it is not drawn.
     */
    $scopes = $scopes ?? [['key' => 'all', 'label' => __('All')]];

    $scopeNouns = [
        'all'          => __('everything'),
        'pages'        => __('pages'),
        'users'        => __('users'),
        'transactions' => __('transactions'),
    ];
@endphp

<div class="dk-palette"
     id="admin-header-search-panel"
     role="dialog"
     aria-label="{{ __('Search') }}"
     hidden
     data-dk-palette-panel>
    <div class="dk-palette__bar">
        <span class="dk-palette__glyph" aria-hidden="true">
            <x-icon name="search" width="16" height="16"/>
            <span class="dk-palette__spinner" hidden data-dk-palette-spinner></span>
        </span>
        <input class="dk-palette__input"
               id="admin-header-search"
               type="text"
               autocomplete="off"
               autocapitalize="off"
               spellcheck="false"
               placeholder="{{ __('Type to search, or jump with ↑↓ and ↵') }}"
               aria-label="{{ __('Search') }}"
               role="combobox"
               aria-autocomplete="list"
               aria-expanded="true"
               aria-controls="admin-header-search-results"
               data-dk-palette-input>
        <div class="dk-palette__scopes" role="tablist" aria-label="{{ __('Search scope') }}">
            @foreach($scopes as $scope)
                <button type="button"
                        @class(['dk-palette__scope', 'is-active' => $loop->first])
                        role="tab"
                        aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                        data-dk-palette-scope="{{ $scope['key'] }}"
                        data-noun="{{ $scopeNouns[$scope['key']] ?? $scope['label'] }}">{{ $scope['label'] }}</button>
            @endforeach
        </div>
    </div>

    <div class="dk-palette__results"
         id="admin-header-search-results"
         role="listbox"
         aria-label="{{ __('Results') }}"
         data-scroll
         data-dk-palette-results></div>

    <div class="dk-palette__foot">
        {{--
            The keys read left-to-right whatever the panel's direction; the
            words beside them are the panel's own language.
        --}}
        <span class="dk-palette__keys" aria-hidden="true">
            <span><kbd dir="ltr">↑↓</kbd> {{ __('navigate') }}</span>
            <span><kbd dir="ltr">↵</kbd> {{ __('open') }}</span>
            <span><kbd dir="ltr">Tab</kbd> {{ __('scope') }}</span>
            <span><kbd dir="ltr">Esc</kbd> {{ __('close') }}</span>
        </span>
        <span class="dk-palette__scope-note">{{ __('Searching') }} <strong data-dk-palette-scope-name>{{ $scopeNouns[$scopes[0]['key']] ?? $scopes[0]['label'] }}</strong></span>
    </div>
</div>
