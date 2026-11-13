<footer class="wz-foot" data-wz-foot>
    <div class="wz-foot__note" role="status" aria-live="polite">
        <span class="wz-foot__note-icon" data-wz-note-icon>@include('installer.wizard.icon', ['name' => 'check', 'size' => 12, 'stroke' => 2.5])</span>
        <span data-wz-note>{{ __('Nothing is written until you confirm on the review screen') }}</span>
    </div>
    <div class="wz-foot__actions">
        <button class="wz-btn wz-btn--lg" type="button" data-wz-back hidden>
            @include('installer.wizard.icon', ['name' => 'arrowLeft', 'size' => 14, 'stroke' => 2.5])
            {{ __('Back') }}
        </button>
        <button class="wz-btn wz-btn--lg wz-btn--primary wz-btn--next" type="button" data-wz-next>
            <span data-wz-next-label>{{ __('Start setup') }}</span>
            <span data-wz-next-icon>@include('installer.wizard.icon', ['name' => 'arrowRight', 'size' => 14, 'stroke' => 2.5])</span>
        </button>
    </div>
</footer>
