<header class="wz-top">
    <div class="wz-crumb">
        <span class="wz-crumb__title">
            <span data-wz-crumb-icon>@include('installer.wizard.icon', ['name' => 'home', 'size' => 14, 'stroke' => 2.2])</span>
            <span data-wz-crumb-title>{{ __('Welcome') }}</span>
        </span>
        <span class="wz-crumb__sep" aria-hidden="true">/</span>
        <span data-wz-crumb-step>{{ __('Step :current of :total', ['current' => 1, 'total' => count($wizardSteps)]) }}</span>
        <span class="wz-crumb__sep" aria-hidden="true">·</span>
        <span class="wz-crumb__eta">
            @include('installer.wizard.icon', ['name' => 'clock', 'size' => 13])
            <span data-wz-crumb-eta>{{ __('≈ :minutes min left', ['minutes' => 8]) }}</span>
        </span>
    </div>
    <div class="wz-top__actions">
        <a class="wz-btn wz-btn--sm wz-btn--icon" href="{{ $links['docs'] ?? '#' }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('Installation help') }}" title="{{ __('Installation help') }}">
            @include('installer.wizard.icon', ['name' => 'help', 'size' => 15])
        </a>
    </div>
</header>
