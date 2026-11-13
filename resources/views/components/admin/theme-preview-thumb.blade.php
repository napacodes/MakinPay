@props(['theme', 'mode', 'lightMode' => false])

{{--
    Miniature rendering of a theme's landing page, drawn entirely in CSS so it
    stays crisp at any density and can follow an edited palette later. The
    geometry is shared; every colour, radius and the gold nav pill come from
    `[data-tp-theme]` rules in backend/css/theme-manager-admin.css, which is
    what makes one component serve every theme.

    A theme with a light mode draws the scene a second time, re-coloured by
    `[data-tp-scene=light]` and clipped to the right-hand diagonal, so the
    card shows both modes at once.
--}}
<div class="tp" data-tp-theme="{{ $theme }}">
    @foreach ($lightMode ? ['dark', 'light'] : ['base'] as $scene)
    <div class="tp__scene" data-tp-scene="{{ $scene }}" aria-hidden="true">
        <div class="tp__nav">
            <span class="tp__nav-dot"></span>
            <span class="tp__nav-brand"></span>
            <span class="tp__nav-spacer"></span>
            <span class="tp__nav-links">
                <span></span><span></span><span></span>
            </span>
            <span class="tp__nav-ghost"></span>
            <span class="tp__nav-fill"></span>
        </div>

        <div class="tp__hero">
            <span class="tp__eyebrow"></span>
            <span class="tp__headline"></span>
            <span class="tp__headline tp__headline--short"></span>
            <span class="tp__body"></span>
            <span class="tp__body tp__body--short"></span>
            <span class="tp__ctas">
                <span class="tp__cta"></span>
                <span class="tp__cta tp__cta--ghost"></span>
            </span>
        </div>

        <div class="tp__card">
            <span class="tp__mark">DK</span>
            <span class="tp__card-foot">
                <span class="tp__card-chip"></span>
                <span class="tp__card-digits">
                    <span></span><span></span><span></span><span class="is-strong"></span>
                </span>
            </span>
        </div>

        <div class="tp__dots">
            <span class="tp__dot"><span class="tp__dot-mark"></span><span class="tp__dot-bar"></span></span>
            <span class="tp__dot"><span class="tp__dot-mark"></span><span class="tp__dot-bar"></span></span>
            <span class="tp__dot"><span class="tp__dot-mark"></span><span class="tp__dot-bar"></span></span>
        </div>
    </div>
    @endforeach

    <span class="tp__mode">{{ $mode }}</span>
</div>
