{{--
    The detail drawer. Rendered empty and filled from the show endpoint, so
    one panel serves every row and the table does not carry a hidden copy of
    each sign-in's facts.
--}}
<div class="ah-overlay" data-ah-overlay hidden></div>

<aside class="ah-drawer"
       data-ah-drawer
       hidden
       role="dialog"
       aria-modal="true"
       aria-labelledby="ahDrawerName">

    <header class="ah-drawer__head">
        <span class="ah-avatar ah-avatar--lg" data-ah-avatar></span>

        <span class="ah-drawer__identity">
            <span class="ah-drawer__line">
                <span class="ah-drawer__name" id="ahDrawerName" data-ah-name></span>
                <span class="ah-pill" data-ah-role></span>
            </span>
            <span class="ah-drawer__handle" data-ah-handle></span>
        </span>

        <button type="button" class="ah-drawer__close" data-ah-close aria-label="{{ __('Close') }}">
            <span aria-hidden="true">&times;</span>
        </button>
    </header>

    <div class="ah-drawer__body" data-ah-scroll>
        <div class="ah-assess" data-ah-assess>
            <span class="ah-assess__copy">
                <span class="ah-assess__label">{{ __('Risk assessment') }}</span>
                <span class="ah-assess__value" data-ah-risk-label></span>
            </span>
            <span class="ah-assess__why" data-ah-risk-reason></span>
        </div>

        <div class="ah-facts">
            <span class="ah-section-label">{{ __('Session') }}</span>
            <dl class="ah-facts__list" data-ah-facts></dl>
        </div>

        <div class="ah-history">
            <span class="ah-section-label">{{ __('Recent sign-ins for this account') }}</span>
            <ul class="ah-history__list" data-ah-history></ul>
        </div>
    </div>

    <footer class="ah-drawer__foot">
        <a class="ah-btn" data-ah-account href="#">{{ __('Open account') }}</a>
        <button type="button" class="ah-btn ah-btn--cta" data-ah-cta></button>
    </footer>
</aside>
