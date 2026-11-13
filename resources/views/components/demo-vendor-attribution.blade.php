@php
    if (! (bool) config('app.demo', false)) {
        return;
    }

    $vendorName  = (string) config('app.demo_vendor_name', 'Coevs');
    $vendorUrl   = (string) config('app.demo_vendor_url', '');
    $productName = (string) config('app.demo_product_name', 'DigiKash');
    $salesUrl    = (string) config('app.demo_sales_url', '');
    $disclosureUrl = url('/demo-disclosure');

    /** @var array{label:string,url:string|null}|null $vendorLink */
    $vendorLink = $vendorUrl !== '' ? ['label' => $vendorName, 'url' => $vendorUrl] : null;
    /** @var array{label:string,url:string|null}|null $salesLink */
    $salesLink  = $salesUrl !== '' ? ['label' => __('View product'), 'url' => $salesUrl] : null;

    $variant = $variant ?? 'public';
@endphp

<div @class([
    'demo-vendor-attribution',
    'demo-vendor-attribution--public' => $variant === 'public',
    'demo-vendor-attribution--admin'  => $variant === 'admin',
]) data-demo-vendor-attribution role="contentinfo" aria-label="{{ __('Software vendor demo attribution') }}">
    <div class="demo-vendor-attribution__inner">
        <span class="demo-vendor-attribution__badge" aria-hidden="true">{{ __('DEMO INSTALLATION') }}</span>

        <p class="demo-vendor-attribution__text">
            {!! __('This site is a public demo of the :product software product, operated by the software vendor :vendor for evaluation purposes only. No real financial services, deposits, withdrawals, or cryptocurrency investments are offered here. All data displayed is fictitious.', [
                'product' => '<strong>'.e($productName).'</strong>',
                'vendor'  => $vendorLink
                    ? '<a href="'.e($vendorLink['url']).'" target="_blank" rel="noopener noreferrer">'.e($vendorLink['label']).'</a>'
                    : '<strong>'.e($vendorName).'</strong>',
            ]) !!}
        </p>

        <ul class="demo-vendor-attribution__links">
            <li>
                <a href="{{ $disclosureUrl }}">{{ __('Full demo disclosure') }}</a>
            </li>
            @if($salesLink)
                <li>
                    <a href="{{ $salesLink['url'] }}" target="_blank" rel="noopener noreferrer">{{ $salesLink['label'] }}</a>
                </li>
            @endif
        </ul>

        <button type="button"
                class="demo-vendor-attribution__close"
                data-demo-vendor-attribution-close
                aria-label="{{ __('Hide this notice on this device') }}"
                title="{{ __('Hide on this device') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>
</div>

<style>
    /*
     * Same visual language as the demo notice bar (demo-banner): ink
     * surface, one solid amber "demo" marker, neutral outline actions.
     * Amber marks the word "demo" only - never links or buttons.
     */
    .demo-vendor-attribution {
        margin-top: 12px;
        padding: 14px 16px;
        border-radius: 12px;
        background: #0e1220;
        border: 1px solid rgba(255, 255, 255, 0.08);
        color: #a6acbf;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, "Helvetica Neue", sans-serif;
        font-size: 12.5px;
        line-height: 1.55;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    .demo-vendor-attribution--admin {
        margin: 8px 16px 12px;
        padding: 10px 12px 10px 14px;
        font-size: 12px;
        background: #ffffff;
        border: 1px solid #e6e8ef;
        color: #5b6175;
    }

    [data-coreui-theme="dark"] .demo-vendor-attribution--admin {
        background: #161a2b;
        border-color: rgba(255, 255, 255, 0.07);
        color: #a6acbf;
    }

    .demo-vendor-attribution__inner {
        display: flex;
        align-items: center;
        gap: 10px 16px;
    }

    .demo-vendor-attribution__badge {
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        height: 22px;
        padding-inline: 9px;
        border-radius: 6px;
        background: #f59e0b;
        color: #1a1204;
        font-weight: 700;
        font-size: 10.5px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        line-height: 1;
    }

    .demo-vendor-attribution__text {
        flex: 1;
        min-width: 0;
        margin: 0;
        color: inherit;
    }

    .demo-vendor-attribution__text strong {
        color: #f2f3f7;
        font-weight: 600;
    }

    .demo-vendor-attribution__text a {
        color: #f2f3f7;
        font-weight: 600;
        text-decoration: underline;
        text-decoration-color: rgba(255, 255, 255, 0.35);
        text-underline-offset: 2px;
    }

    .demo-vendor-attribution__text a:hover {
        text-decoration-color: currentColor;
    }

    .demo-vendor-attribution--admin .demo-vendor-attribution__text strong,
    .demo-vendor-attribution--admin .demo-vendor-attribution__text a {
        color: #151827;
    }

    .demo-vendor-attribution--admin .demo-vendor-attribution__text a {
        text-decoration-color: rgba(21, 24, 39, 0.3);
    }

    [data-coreui-theme="dark"] .demo-vendor-attribution--admin .demo-vendor-attribution__text strong,
    [data-coreui-theme="dark"] .demo-vendor-attribution--admin .demo-vendor-attribution__text a {
        color: #f2f3f7;
    }

    .demo-vendor-attribution__links {
        flex-shrink: 0;
        list-style: none;
        margin: 0;
        padding: 0;
        display: inline-flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .demo-vendor-attribution__links li {
        list-style: none;
    }

    .demo-vendor-attribution__links a {
        display: inline-flex;
        align-items: center;
        height: 30px;
        padding-inline: 12px;
        border: 1px solid rgba(255, 255, 255, 0.22);
        border-radius: 7px;
        color: #f2f3f7;
        font-size: 12.5px;
        font-weight: 600;
        line-height: 1;
        text-decoration: none;
        white-space: nowrap;
        transition: background-color 0.15s ease, border-color 0.15s ease;
    }

    .demo-vendor-attribution__links a:hover {
        background: rgba(255, 255, 255, 0.08);
        border-color: rgba(255, 255, 255, 0.36);
        color: #ffffff;
    }

    .demo-vendor-attribution--admin .demo-vendor-attribution__links a {
        border-color: #dadde7;
        color: #151827;
    }

    .demo-vendor-attribution--admin .demo-vendor-attribution__links a:hover {
        background: #f5f6fa;
        color: #151827;
    }

    [data-coreui-theme="dark"] .demo-vendor-attribution--admin .demo-vendor-attribution__links a {
        border-color: rgba(255, 255, 255, 0.16);
        color: #f2f3f7;
    }

    [data-coreui-theme="dark"] .demo-vendor-attribution--admin .demo-vendor-attribution__links a:hover {
        background: rgba(255, 255, 255, 0.06);
    }

    .demo-vendor-attribution__close {
        flex-shrink: 0;
        display: inline-grid;
        place-items: center;
        width: 30px;
        height: 30px;
        padding: 0;
        border: 0;
        border-radius: 6px;
        background: transparent;
        color: #a6acbf;
        cursor: pointer;
        transition: color 0.15s ease, background-color 0.15s ease;
    }

    .demo-vendor-attribution__close:hover {
        background: rgba(255, 255, 255, 0.08);
        color: #ffffff;
    }

    .demo-vendor-attribution--admin .demo-vendor-attribution__close {
        color: #5b6175;
    }

    .demo-vendor-attribution--admin .demo-vendor-attribution__close:hover {
        background: #f0f1f6;
        color: #151827;
    }

    [data-coreui-theme="dark"] .demo-vendor-attribution--admin .demo-vendor-attribution__close:hover {
        background: rgba(255, 255, 255, 0.06);
        color: #f2f3f7;
    }

    .demo-vendor-attribution__links a:focus-visible,
    .demo-vendor-attribution__text a:focus-visible,
    .demo-vendor-attribution__close:focus-visible {
        outline: 2px solid #f59e0b;
        outline-offset: 2px;
    }

    .demo-vendor-attribution__close svg {
        width: 15px;
        height: 15px;
    }

    @media (max-width: 991px) {
        .demo-vendor-attribution__inner {
            flex-wrap: wrap;
        }

        .demo-vendor-attribution__text {
            flex-basis: calc(100% - 140px);
        }

        .demo-vendor-attribution__close {
            margin-inline-start: auto;
        }
    }

    @media (max-width: 575px) {
        .demo-vendor-attribution {
            padding: 14px;
        }

        .demo-vendor-attribution__text {
            flex-basis: 100%;
            order: 3;
        }

        .demo-vendor-attribution__links {
            order: 4;
        }
    }

    @media print {
        .demo-vendor-attribution {
            display: none !important;
        }
    }
</style>

<script>
    'use strict';
    (function () {
        /**
         * Device-only dismissal — localStorage *only*, no server call.
         * The user explicitly wants this banner to:
         *   • disappear on the device where it was closed
         *   • come back when signing in from any other device / browser
         * so we deliberately do NOT persist to the database.
         *
         * Each variant (public / admin) gets its own storage key so an
         * admin who hides it in the dashboard still sees it on the
         * public site, and vice-versa.
         */
        var nodes = document.querySelectorAll('[data-demo-vendor-attribution]');
        if (! nodes.length) {
            return;
        }

        nodes.forEach(function (node) {
            var variant   = node.classList.contains('demo-vendor-attribution--admin') ? 'admin' : 'public';
            var storageKey = 'digikash:demo-vendor-attribution-dismissed:' + variant;

            try {
                if (window.localStorage && window.localStorage.getItem(storageKey) === '1') {
                    if (node.parentNode) {
                        node.parentNode.removeChild(node);
                    }
                    return;
                }
            } catch (_) {
                /* localStorage disabled — leave banner visible. */
            }

            var closeBtn = node.querySelector('[data-demo-vendor-attribution-close]');
            if (! closeBtn) {
                return;
            }

            closeBtn.addEventListener('click', function () {
                try {
                    if (window.localStorage) {
                        window.localStorage.setItem(storageKey, '1');
                    }
                } catch (_) {
                    /* ignore — best-effort dismissal only. */
                }
                if (node.parentNode) {
                    node.parentNode.removeChild(node);
                }
            });
        });
    })();
</script>
