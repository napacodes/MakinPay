@props(['variant' => 'public'])

@php
    if (! (bool) config('app.demo', false)) {
        return;
    }

    /*
     * The demo notice.
     *
     * One slim bar that says "demo" at a glance, an About panel that says
     * the rest only when asked, and a Hide that lasts the browser session.
     * Hiding folds the bar into a small "Demo" chip in a corner, so the
     * site is never mistaken for a live service - not even in a screenshot
     * taken after someone closed the bar.
     *
     * The hooks other stylesheets lean on are unchanged: the
     * `demo-banner-dismissed` class on <html>, `--demo-banner-total`, and
     * `data-demo-banner` / `data-demo-banner-close`.
     */
    $isAdmin       = $variant === 'admin';
    $productName   = (string) config('app.demo_product_name', 'DigiKash');
    $vendorName    = (string) config('app.demo_vendor_name', 'Coevs');
    $salesUrl      = (string) config('app.demo_sales_url', '');
    $disclosureUrl = url('/demo-disclosure');
@endphp

<script>
    (function () {
        'use strict';

        try {
            window.localStorage && window.localStorage.removeItem('dk_demo_banner_dismissed');
            if (window.sessionStorage && window.sessionStorage.getItem('dk_demo_notice_hidden') === '1') {
                document.documentElement.classList.add('demo-banner-dismissed');
            }
        } catch (e) {
            /* Storage unavailable - the notice simply stays visible. */
        }
    })();
</script>

<div @class(['demo-banner', 'demo-banner--admin' => $isAdmin]) role="region" aria-label="{{ __('Demo site notice') }}" data-demo-banner>
    <div class="demo-banner__inner">
        <span class="demo-banner__badge">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 3h6"></path>
                <path d="M10 3v6.5L4.6 18.2A2 2 0 0 0 6.3 21h11.4a2 2 0 0 0 1.7-2.8L14 9.5V3"></path>
            </svg>
            {{ __('Demo') }}
        </span>

        <p class="demo-banner__text">
            @if($isAdmin)
                <strong class="demo-banner__title">{{ __('Demo installation.') }}</strong>
            @else
                <strong class="demo-banner__title">{{ __('You are exploring a live demo of :product.', ['product' => $productName]) }}</strong>
            @endif
            <span class="demo-banner__detail">{{ __('All data is fictitious — no real money, deposits or withdrawals.') }}</span>
            <span class="demo-banner__short">{{ __('Fictitious data · no real money') }}</span>
        </p>

        <button type="button"
                class="demo-banner__about"
                data-demo-banner-about
                aria-expanded="false"
                aria-controls="demo-banner-panel"
                aria-label="{{ __('About this demo') }}">
            <svg class="demo-banner__about-info" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M12 11v5"></path>
                <path d="M12 8h.01"></path>
            </svg>
            <span class="demo-banner__about-label">{{ __('About this demo') }}</span>
            <svg class="demo-banner__about-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m6 9 6 6 6-6"></path>
            </svg>
        </button>

        @if($salesUrl !== '')
            <a class="demo-banner__cta" href="{{ $salesUrl }}" target="_blank" rel="noopener noreferrer">
                {{ __('Get :product', ['product' => $productName]) }}
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M7 17 17 7"></path>
                    <path d="M8 7h9v9"></path>
                </svg>
            </a>
        @endif

        <span class="demo-banner__divider" aria-hidden="true"></span>

        <button type="button"
                class="demo-banner__close"
                data-demo-banner-close
                aria-label="{{ __('Hide demo notice for this session') }}">
            <span class="demo-banner__close-label">{{ __('Hide') }}</span>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 6 6 18"></path>
                <path d="m6 6 12 12"></path>
            </svg>
        </button>
    </div>
</div>

<div class="demo-banner__scrim" data-demo-banner-scrim hidden></div>

<div class="demo-banner__panel"
     id="demo-banner-panel"
     role="dialog"
     aria-labelledby="demo-banner-panel-title"
     tabindex="-1"
     data-demo-banner-panel
     hidden>
    <div class="demo-banner__panel-head">
        <span class="demo-banner__panel-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 3h6"></path>
                <path d="M10 3v6.5L4.6 18.2A2 2 0 0 0 6.3 21h11.4a2 2 0 0 0 1.7-2.8L14 9.5V3"></path>
            </svg>
        </span>
        <span class="demo-banner__panel-heading">
            <strong id="demo-banner-panel-title">{{ __('About this demo') }}</strong>
            <span>{{ __('A public evaluation copy of :product', ['product' => $productName]) }}</span>
        </span>
    </div>

    <ul class="demo-banner__points">
        <li>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
            <span><strong>{{ __('No real money moves.') }}</strong> {{ __('Payments, deposits and withdrawals are simulated.') }}</span>
        </li>
        <li>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
            <span><strong>{{ __('All data is fictitious') }}</strong> {{ __('and may be reset at any time.') }}</span>
        </li>
        <li>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
            <span><strong>{{ __('Operated by :vendor', ['vendor' => $vendorName]) }}</strong>{{ __(', the software vendor — not a financial service.') }}</span>
        </li>
    </ul>

    <div class="demo-banner__panel-foot">
        <a class="demo-banner__btn demo-banner__btn--ghost" href="{{ $disclosureUrl }}">{{ __('Full disclosure') }}</a>
        <button type="button" class="demo-banner__btn demo-banner__btn--primary" data-demo-banner-panel-close>{{ __('Got it') }}</button>
    </div>
</div>

<button type="button"
        @class(['demo-banner__chip', 'demo-banner__chip--admin' => $isAdmin])
        data-demo-banner-chip
        aria-label="{{ __('Show demo notice') }}">
    <span class="demo-banner__chip-dot" aria-hidden="true"></span>
    {{ __('Demo') }}
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="m18 15-6-6-6 6"></path>
    </svg>
</button>

<div class="demo-banner__toast" role="status" data-demo-banner-toast hidden>
    <span>{{ __('Demo notice hidden for this session.') }}</span>
    <button type="button" data-demo-banner-undo>{{ __('Undo') }}</button>
</div>

<style>
    :root {
        --demo-banner-height: 44px;
        --demo-banner-safe-top: env(safe-area-inset-top, 0px);
        --demo-banner-total: calc(var(--demo-banner-height) + var(--demo-banner-safe-top));

        --demo-ink: #0e1220;
        --demo-ink-text: #f2f3f7;
        --demo-ink-muted: #a6acbf;
        --demo-ink-line: rgba(255, 255, 255, 0.10);
        --demo-marker: #f59e0b;
        --demo-marker-text: #1a1204;
        --demo-action: #4f46e5;
        --demo-action-hover: #4338ca;
        --demo-surface: #ffffff;
        --demo-surface-text: #151827;
        --demo-surface-muted: #5b6175;
        --demo-surface-line: #e6e8ef;
        --demo-font: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, "Helvetica Neue", sans-serif;
    }

    :root:has(.demo-banner--admin) {
        --demo-banner-height: 40px;
    }

    @media (max-width: 575px) {
        :root,
        :root:has(.demo-banner--admin) {
            --demo-banner-height: 44px;
        }
    }

    html {
        scroll-padding-top: var(--demo-banner-total);
    }

    body {
        padding-top: var(--demo-banner-total) !important;
    }

    /*
     * Above the admin column (1035) and every page's own header, below the
     * drawers, sheets and modals (1040 and up), which cover the whole
     * window and must cover the bar with it - a max z-index left a drawer's
     * own header and close button underneath the notice.
     */
    .demo-banner {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1038;
        width: 100%;
        height: var(--demo-banner-total);
        padding-top: var(--demo-banner-safe-top);
        box-sizing: border-box;
        background: var(--demo-ink);
        color: var(--demo-ink-text);
        border-bottom: 1px solid var(--demo-ink-line);
        font-family: var(--demo-font);
        font-size: 13.5px;
        line-height: 1.4;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    .demo-banner *,
    .demo-banner__panel *,
    .demo-banner__chip,
    .demo-banner__toast * {
        box-sizing: border-box;
    }

    .demo-banner__inner {
        max-width: 1240px;
        height: 100%;
        margin: 0 auto;
        padding-inline: 24px;
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .demo-banner--admin .demo-banner__inner {
        max-width: none;
        padding-inline: 20px;
        gap: 12px;
    }

    .demo-banner__badge {
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        height: 22px;
        padding-inline: 9px;
        border-radius: 6px;
        background: var(--demo-marker);
        color: var(--demo-marker-text);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        line-height: 1;
    }

    .demo-banner--admin .demo-banner__badge {
        height: 20px;
        font-size: 10.5px;
    }

    .demo-banner__text {
        flex: 1;
        min-width: 0;
        margin: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: inherit;
    }

    .demo-banner__title {
        font-weight: 600;
        color: var(--demo-ink-text);
    }

    .demo-banner__detail {
        margin-inline-start: 4px;
        color: var(--demo-ink-muted);
    }

    .demo-banner__short {
        display: none;
        color: #c9cddb;
    }

    .demo-banner__about,
    .demo-banner__close {
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        height: 30px;
        margin: 0;
        padding-inline: 10px;
        border: 0;
        border-radius: 6px;
        background: transparent;
        font-family: inherit;
        font-size: 13px;
        line-height: 1;
        cursor: pointer;
        transition: background-color 0.15s ease, color 0.15s ease;
    }

    .demo-banner__about {
        color: var(--demo-ink-text);
        font-weight: 600;
    }

    .demo-banner__about-info {
        display: none;
    }

    .demo-banner__about[aria-expanded="true"] .demo-banner__about-chevron {
        transform: rotate(180deg);
    }

    .demo-banner__close {
        padding-inline: 10px 8px;
        color: var(--demo-ink-muted);
        font-size: 12.5px;
        font-weight: 500;
    }

    .demo-banner__about:hover,
    .demo-banner__close:hover,
    .demo-banner__about[aria-expanded="true"] {
        background: rgba(255, 255, 255, 0.08);
        color: #ffffff;
    }

    .demo-banner__cta {
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        height: 30px;
        padding-inline: 12px;
        border: 1px solid rgba(255, 255, 255, 0.22);
        border-radius: 7px;
        color: var(--demo-ink-text);
        font-size: 13px;
        font-weight: 600;
        line-height: 1;
        text-decoration: none;
        transition: background-color 0.15s ease, border-color 0.15s ease;
    }

    .demo-banner__cta:hover {
        background: rgba(255, 255, 255, 0.08);
        border-color: rgba(255, 255, 255, 0.36);
        color: #ffffff;
    }

    .demo-banner__divider {
        flex-shrink: 0;
        width: 1px;
        height: 20px;
        background: var(--demo-ink-line);
    }

    .demo-banner button:focus-visible,
    .demo-banner a:focus-visible,
    .demo-banner__panel a:focus-visible,
    .demo-banner__panel button:focus-visible,
    .demo-banner__chip:focus-visible,
    .demo-banner__toast button:focus-visible {
        outline: 2px solid var(--demo-marker);
        outline-offset: 2px;
    }

    /* === About panel: a popover under the bar, a bottom sheet on phones === */
    .demo-banner__panel {
        position: fixed;
        top: calc(var(--demo-banner-total) + 8px);
        inset-inline-end: max(16px, calc((100vw - 1240px) / 2 + 24px));
        z-index: 2147483646;
        width: 380px;
        max-width: calc(100vw - 32px);
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 16px;
        background: var(--demo-surface);
        color: var(--demo-surface-text);
        border: 1px solid #e3e5ee;
        border-radius: 14px;
        box-shadow: 0 18px 48px rgba(16, 20, 40, 0.16), 0 2px 6px rgba(16, 20, 40, 0.06);
        font-family: var(--demo-font);
        font-size: 13px;
        line-height: 1.45;
        text-align: start;
    }

    .demo-banner__panel[hidden],
    .demo-banner__scrim[hidden],
    .demo-banner__toast[hidden] {
        display: none !important;
    }

    .demo-banner__panel:focus {
        outline: none;
    }

    .demo-banner--admin ~ .demo-banner__panel {
        inset-inline-end: 16px;
    }

    .demo-banner__panel-head {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .demo-banner__panel-icon {
        flex-shrink: 0;
        display: inline-grid;
        place-items: center;
        width: 34px;
        height: 34px;
        border-radius: 9px;
        background: #fef3c7;
        color: #92400e;
    }

    .demo-banner__panel-heading {
        display: flex;
        flex-direction: column;
        gap: 1px;
    }

    .demo-banner__panel-heading strong {
        font-size: 15px;
        font-weight: 700;
    }

    .demo-banner__panel-heading span {
        font-size: 12.5px;
        color: var(--demo-surface-muted);
    }

    .demo-banner__points {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 12px;
        color: #2b3044;
    }

    .demo-banner__points li {
        display: flex;
        gap: 10px;
        margin: 0;
    }

    .demo-banner__points svg {
        flex-shrink: 0;
        margin-top: 1px;
        color: var(--demo-action);
    }

    .demo-banner__points strong {
        font-weight: 600;
        color: var(--demo-surface-text);
    }

    .demo-banner__panel-foot {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        padding-top: 14px;
        border-top: 1px solid #eef0f5;
    }

    .demo-banner__btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 36px;
        margin: 0;
        padding-inline: 16px;
        border-radius: 8px;
        font-family: inherit;
        font-size: 13px;
        font-weight: 600;
        line-height: 1;
        text-decoration: none;
        cursor: pointer;
        transition: background-color 0.15s ease, border-color 0.15s ease;
    }

    .demo-banner__btn--ghost {
        border: 1px solid #dadde7;
        background: transparent;
        color: var(--demo-surface-text);
    }

    .demo-banner__btn--ghost:hover {
        background: #f5f6fa;
        color: var(--demo-surface-text);
    }

    .demo-banner__btn--primary {
        border: 0;
        background: var(--demo-action);
        color: #ffffff;
    }

    .demo-banner__btn--primary:hover {
        background: var(--demo-action-hover);
    }

    .demo-banner__scrim {
        position: fixed;
        inset: 0;
        z-index: 2147483645;
        background: rgba(10, 13, 24, 0.45);
    }

    [data-coreui-theme="dark"] .demo-banner__panel {
        --demo-surface: #161a2b;
        --demo-surface-text: #f2f3f7;
        --demo-surface-muted: #a6acbf;
        border-color: rgba(255, 255, 255, 0.08);
        box-shadow: 0 18px 48px rgba(0, 0, 0, 0.45);
    }

    [data-coreui-theme="dark"] .demo-banner__points {
        color: #c9cddb;
    }

    [data-coreui-theme="dark"] .demo-banner__points svg {
        color: #a5b4fc;
    }

    [data-coreui-theme="dark"] .demo-banner__panel-foot {
        border-top-color: rgba(255, 255, 255, 0.08);
    }

    [data-coreui-theme="dark"] .demo-banner__btn--ghost {
        border-color: rgba(255, 255, 255, 0.16);
    }

    [data-coreui-theme="dark"] .demo-banner__btn--ghost:hover {
        background: rgba(255, 255, 255, 0.06);
    }

    /* === The chip: what stays once the bar is hidden === */
    .demo-banner__chip {
        position: fixed;
        inset-inline-start: 24px;
        bottom: calc(24px + env(safe-area-inset-bottom, 0px));
        z-index: 1045;
        display: none;
        align-items: center;
        gap: 8px;
        height: 32px;
        margin: 0;
        padding-inline: 10px 12px;
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 999px;
        background: var(--demo-ink);
        color: var(--demo-ink-text);
        font-family: var(--demo-font);
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.02em;
        line-height: 1;
        box-shadow: 0 8px 24px rgba(14, 18, 32, 0.22);
        cursor: pointer;
        transition: background-color 0.15s ease;
    }

    .demo-banner__chip:hover {
        background: #1a1f33;
    }

    .demo-banner__chip--admin {
        inset-inline-start: auto;
        inset-inline-end: 20px;
        bottom: calc(20px + env(safe-area-inset-bottom, 0px));
    }

    .demo-banner__chip-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--demo-marker);
    }

    .demo-banner__toast {
        position: fixed;
        left: 50%;
        bottom: calc(24px + env(safe-area-inset-bottom, 0px));
        z-index: 2147483646;
        transform: translateX(-50%);
        display: flex;
        align-items: center;
        gap: 14px;
        max-width: calc(100vw - 32px);
        height: 44px;
        padding-inline: 16px 8px;
        border-radius: 10px;
        background: var(--demo-ink);
        color: var(--demo-ink-text);
        font-family: var(--demo-font);
        font-size: 13px;
        white-space: nowrap;
        box-shadow: 0 12px 32px rgba(14, 18, 32, 0.25);
    }

    .demo-banner__toast button {
        height: 30px;
        margin: 0;
        padding-inline: 10px;
        border: 0;
        border-radius: 6px;
        background: rgba(255, 255, 255, 0.08);
        color: #ffffff;
        font-family: inherit;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
    }

    .demo-banner__toast button:hover {
        background: rgba(255, 255, 255, 0.14);
    }

    /* === Dismissed state — banner hidden, chip shown, all overrides reset === */
    .demo-banner-dismissed {
        --demo-banner-height: 0px !important;
        --demo-banner-safe-top: 0px !important;
        --demo-banner-total: 0px !important;
    }

    .demo-banner-dismissed body {
        padding-top: 0 !important;
    }

    .demo-banner-dismissed .demo-banner,
    .demo-banner-dismissed .demo-banner__panel,
    .demo-banner-dismissed .demo-banner__scrim {
        display: none !important;
    }

    .demo-banner-dismissed .demo-banner__chip {
        display: inline-flex;
    }

    /* === Conflict resolution: push existing top-anchored elements below banner === */

    .header-top-section {
        top: var(--demo-banner-total) !important;
    }

    .header-1 {
        top: calc(60px + var(--demo-banner-total)) !important;
    }

    @media (max-width: 991px) {
        .header-1 {
            top: var(--demo-banner-total) !important;
        }
    }

    .sticky.header-1,
    .sticky {
        top: var(--demo-banner-total) !important;
    }

    .premium-header {
        top: var(--demo-banner-total) !important;
    }

    /*
     * Only nudge the sidebar TOP — never force a vh-based height.
     *
     * Mobile browsers report `100vh` as the size of the layout viewport
     * including the area under collapsible chrome (address bar, etc.),
     * so `height: calc(100vh - banner)` made the offcanvas sidebar
     * taller than the visible viewport. The sidebar footer (quick-links
     * panel) then sat below the visible area and only re-appeared once
     * the user scrolled enough to collapse the chrome — which produced
     * the "sometimes shows, sometimes doesn't" bug on mobile.
     *
     * CoreUI already pins `.sidebar` with `top: 0; bottom: 0` (and
     * fixed positioning) on both desktop (.sidebar-fixed) and mobile
     * (its built-in @media (max-width: 991.98px) rule). Shifting only
     * `top` here keeps `bottom: 0` intact, so the sidebar always sizes
     * to the real visible viewport.
     */
    .sidebar.sidebar-fixed,
    .sidebar-fixed {
        top: var(--demo-banner-total) !important;
    }

    .header.header-sticky,
    .app-header {
        top: var(--demo-banner-total) !important;
    }

    /* Mobile dashboard fixed header (.dk-mobile-header — z-index: 1030) */
    .dk-mobile-header {
        top: var(--demo-banner-total) !important;
    }

    /*
     * The admin shell: the column is fixed top-to-bottom and the header is
     * sticky at the top, so both start below the bar. Moving `top` alone
     * keeps the column's `bottom: 0`, and the dismissed state needs no rule
     * of its own because --demo-banner-total falls to 0 there.
     *
     * The user dashboard's navbar is fixed at the top on phones the same way.
     */
    .dk-side,
    .dk-top,
    .dashboard-user-layout .navbar-area {
        top: var(--demo-banner-total) !important;
    }

    /* === Dismissed state restores original positions === */
    .demo-banner-dismissed .header-top-section,
    .demo-banner-dismissed .sticky,
    .demo-banner-dismissed .sticky.header-1,
    .demo-banner-dismissed .premium-header,
    .demo-banner-dismissed .header.header-sticky,
    .demo-banner-dismissed .app-header,
    .demo-banner-dismissed .dk-mobile-header {
        top: 0 !important;
    }

    .demo-banner-dismissed .header-1 {
        top: 60px !important;
    }

    @media (max-width: 991px) {
        .demo-banner-dismissed .header-1 {
            top: 0 !important;
        }
    }

    /*
     * Mirror of the active-banner override above: shift only `top`, never
     * pin a vh-based height. CoreUI's mobile rule keeps `bottom: 0`, so the
     * sidebar auto-sizes to the real visible viewport on every browser
     * (iOS Safari, Chrome Android, FF Mobile) — using `100vh` here would
     * re-introduce the same off-screen footer bug the active-banner block
     * documents.
     */
    .demo-banner-dismissed .sidebar.sidebar-fixed,
    .demo-banner-dismissed .sidebar-fixed {
        top: 0 !important;
    }

    /* === Tablet: the sentence shortens, the actions stay === */
    @media (max-width: 991px) {
        .demo-banner__detail,
        .demo-banner__cta,
        .demo-banner__divider {
            display: none;
        }

        .demo-banner__inner {
            padding-inline: 16px 8px;
            gap: 10px;
        }
    }

    /* === Phone: badge, a short line, and two 44px icon buttons === */
    @media (max-width: 575px) {
        .demo-banner__inner {
            padding-inline: 14px 0;
            gap: 6px;
        }

        .demo-banner__badge {
            height: 20px;
            padding-inline: 7px;
            font-size: 10.5px;
        }

        .demo-banner__badge svg,
        .demo-banner__title,
        .demo-banner__about-label,
        .demo-banner__about-chevron,
        .demo-banner__close-label {
            display: none;
        }

        .demo-banner__short {
            display: inline;
            font-size: 12.5px;
        }

        .demo-banner__about-info {
            display: block;
        }

        .demo-banner__about,
        .demo-banner__close {
            justify-content: center;
            width: 44px;
            height: 44px;
            padding: 0;
            border-radius: 0;
        }

        .demo-banner__close svg {
            width: 17px;
            height: 17px;
        }

        .demo-banner__panel,
        .demo-banner--admin ~ .demo-banner__panel {
            top: auto;
            bottom: 0;
            inset-inline: 0;
            width: auto;
            max-width: none;
            padding: 22px 20px calc(28px + env(safe-area-inset-bottom, 0px));
            border-radius: 20px 20px 0 0;
            border-bottom: 0;
            font-size: 14px;
        }

        .demo-banner__panel-foot {
            flex-direction: column-reverse;
        }

        .demo-banner__btn {
            height: 48px;
            border-radius: 12px;
            font-size: 15px;
        }

        .demo-banner__chip,
        .demo-banner__chip--admin {
            inset-inline-start: 16px;
            inset-inline-end: auto;
            bottom: calc(16px + env(safe-area-inset-bottom, 0px));
        }

        .demo-banner__toast {
            bottom: calc(64px + env(safe-area-inset-bottom, 0px));
        }
    }

    @media print {
        body {
            padding-top: 0 !important;
        }

        .demo-banner,
        .demo-banner__panel,
        .demo-banner__scrim,
        .demo-banner__chip,
        .demo-banner__toast {
            display: none !important;
        }
    }
</style>

<script>
    (function () {
        'use strict';

        var STORAGE_KEY = 'dk_demo_notice_hidden';
        var DISMISS_CLASS = 'demo-banner-dismissed';
        var TOAST_MS = 5000;
        var PHONE_QUERY = '(max-width: 575px)';

        function remember(hidden) {
            try {
                if (hidden) {
                    window.sessionStorage.setItem(STORAGE_KEY, '1');
                } else {
                    window.sessionStorage.removeItem(STORAGE_KEY);
                }
            } catch (e) {
                /* Storage unavailable — the choice lasts this page only. */
            }
        }

        function bind() {
            var root = document.documentElement;
            var bar = document.querySelector('[data-demo-banner]');
            if (! bar || bar.dataset.demoBannerBound === '1') {
                return;
            }
            bar.dataset.demoBannerBound = '1';

            var about = bar.querySelector('[data-demo-banner-about]');
            var close = bar.querySelector('[data-demo-banner-close]');
            var panel = document.querySelector('[data-demo-banner-panel]');
            var scrim = document.querySelector('[data-demo-banner-scrim]');
            var chip = document.querySelector('[data-demo-banner-chip]');
            var toast = document.querySelector('[data-demo-banner-toast]');
            var undo = document.querySelector('[data-demo-banner-undo]');
            var panelClose = panel ? panel.querySelector('[data-demo-banner-panel-close]') : null;
            var toastTimer = null;

            function isPanelOpen() {
                return panel && ! panel.hidden;
            }

            function openPanel() {
                if (! panel) {
                    return;
                }
                panel.hidden = false;
                if (scrim && window.matchMedia(PHONE_QUERY).matches) {
                    scrim.hidden = false;
                }
                about.setAttribute('aria-expanded', 'true');
                panel.focus();
            }

            function closePanel(returnFocus) {
                if (! isPanelOpen()) {
                    return;
                }
                panel.hidden = true;
                if (scrim) {
                    scrim.hidden = true;
                }
                about.setAttribute('aria-expanded', 'false');
                if (returnFocus) {
                    about.focus();
                }
            }

            function hideToast() {
                window.clearTimeout(toastTimer);
                if (toast) {
                    toast.hidden = true;
                }
            }

            function hideNotice() {
                closePanel(false);
                remember(true);
                root.classList.add(DISMISS_CLASS);
                if (toast) {
                    toast.hidden = false;
                    window.clearTimeout(toastTimer);
                    toastTimer = window.setTimeout(hideToast, TOAST_MS);
                }
                if (chip) {
                    chip.focus();
                }
            }

            function showNotice() {
                hideToast();
                remember(false);
                root.classList.remove(DISMISS_CLASS);
                if (close) {
                    close.focus();
                }
            }

            if (about) {
                about.addEventListener('click', function (event) {
                    event.preventDefault();
                    if (isPanelOpen()) {
                        closePanel(true);
                    } else {
                        openPanel();
                    }
                });
            }

            if (close) {
                close.addEventListener('click', function (event) {
                    event.preventDefault();
                    hideNotice();
                });
            }

            if (panelClose) {
                panelClose.addEventListener('click', function () {
                    closePanel(true);
                });
            }

            if (scrim) {
                scrim.addEventListener('click', function () {
                    closePanel(true);
                });
            }

            if (chip) {
                chip.addEventListener('click', showNotice);
            }

            if (undo) {
                undo.addEventListener('click', showNotice);
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && isPanelOpen()) {
                    closePanel(true);
                }
            });

            document.addEventListener('click', function (event) {
                if (! isPanelOpen()) {
                    return;
                }
                if (panel.contains(event.target) || (about && about.contains(event.target))) {
                    return;
                }
                closePanel(false);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bind);
        } else {
            bind();
        }
    })();
</script>
