{{--
 | Quick-menu editor.
 |
 | Rendered inside the panel body, hidden until the user taps Customize.
 | Expects the variables the host partial already built:
 |   $quickFunctionLinks       - catalog entries, pinned first, each with `selected`
 |   $quickFunctionAccentMap   - icon => accent token
 |   $quickFunctionTitleMap    - icon => display title override
--}}
<div class="quick-function-menu__editor" data-quick-menu-editor hidden>
    <p class="quick-function-menu__editor-hint">
        {{ __('Switch off the shortcuts you never use, and move the ones you do to the top.') }}
    </p>

    <ul class="quick-function-menu__editor-list" data-quick-menu-editor-list>
        @foreach($quickFunctionLinks as $quickFunctionEditorLink)
            @php
                $quickFunctionEditorTitle = $quickFunctionTitleMap[$quickFunctionEditorLink['icon']] ?? $quickFunctionEditorLink['title'];

                if ($quickFunctionEditorLink['icon'] === 'history' && $quickFunctionEditorLink['title'] !== __('History')) {
                    $quickFunctionEditorTitle = $quickFunctionEditorLink['title'];
                }
            @endphp
            <li class="quick-function-menu__editor-row"
                data-quick-menu-row
                data-quick-menu-key="{{ $quickFunctionEditorLink['key'] }}"
                data-accent="{{ $quickFunctionAccentMap[$quickFunctionEditorLink['icon']] ?? 'blue' }}">
                <span class="quick-function-menu__editor-move">
                    <button type="button"
                            class="quick-function-menu__editor-nudge"
                            data-quick-menu-move="up"
                            aria-label="{{ __('Move :item up', ['item' => $quickFunctionEditorTitle]) }}">
                        <i class="fas fa-chevron-up" aria-hidden="true"></i>
                    </button>
                    <button type="button"
                            class="quick-function-menu__editor-nudge"
                            data-quick-menu-move="down"
                            aria-label="{{ __('Move :item down', ['item' => $quickFunctionEditorTitle]) }}">
                        <i class="fas fa-chevron-down" aria-hidden="true"></i>
                    </button>
                </span>

                <span class="quick-function-menu__editor-icon" aria-hidden="true">
                    <x-icon name="{{ $quickFunctionEditorLink['icon'] }}" height="18" width="18" />
                </span>

                <span class="quick-function-menu__editor-label">{{ $quickFunctionEditorTitle }}</span>

                <label class="quick-function-menu__editor-switch">
                    <input type="checkbox"
                           data-quick-menu-toggle
                           value="{{ $quickFunctionEditorLink['key'] }}"
                           @checked($quickFunctionEditorLink['selected'])>
                    <span class="quick-function-menu__editor-track" aria-hidden="true"></span>
                    <span class="visually-hidden">{{ __('Show :item in the quick menu', ['item' => $quickFunctionEditorTitle]) }}</span>
                </label>
            </li>
        @endforeach
    </ul>

    <div class="quick-function-menu__editor-actions">
        <button type="button" class="quick-function-menu__editor-btn" data-quick-menu-reset>
            <i class="fas fa-rotate-left" aria-hidden="true"></i>
            <span>{{ __('Reset') }}</span>
        </button>
        <button type="button" class="quick-function-menu__editor-btn" data-quick-menu-cancel>
            {{ __('Cancel') }}
        </button>
        <button type="button" class="quick-function-menu__editor-btn quick-function-menu__editor-btn--save" data-quick-menu-save>
            {{ __('Save') }}
        </button>
    </div>
</div>
