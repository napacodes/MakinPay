@php
    $languages = $languages ?? collect();
    $currentLanguage     = \App\Support\LanguageSwitcher::current($languages);
    $currentLanguageCode = $currentLanguage?->code ?? app()->getLocale();
    $currentLanguageName = $currentLanguage?->displayName() ?: strtoupper($currentLanguageCode);
@endphp

{{-- Desktop Language Selector --}}
<div class="lang">
    <div class="dropdown">
        <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            @if($currentLanguage?->flag)
                <img class="mx-2" src="{{ asset($currentLanguage->flag) }}" height="24" width="24" alt="{{ $currentLanguageName }}" loading="lazy">
            @else
                <span class="mx-2">{{ \App\Support\LanguageSwitcher::flagFallback($currentLanguage, $currentLanguageCode) }}</span>
            @endif
            <span>
                {{ $currentLanguageName }}
            </span>
        </a>
        <ul class="dropdown-menu">
            @foreach($languages as $language)
            <li>
                <a href="{{ route('locale-set', $language->code) }}"
                   class="dropdown-item d-flex align-items-center {{ $language->code == app()->getLocale() ? 'active' : '' }}">
                    @if($language->flag)
                        <img class="icon me-2" src="{{ asset($language->flag) }}" alt="{{ $language->name }}" loading="lazy">
                    @else
                        <span class="icon me-2">{{ \App\Support\LanguageSwitcher::flagFallback($language) }}</span>
                    @endif
                    {{ $language->displayName() }}
                </a>
            </li>
            @endforeach
        </ul>
    </div>
</div>
