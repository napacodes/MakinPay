"use strict";

/* ==========================================================
   DigiKash — Aurora landing theme
   Mobile drawer, dark/light mode toggle, portal tabs, feature
   catalog filter, card preview, remittance calculator and
   scroll reveal. Vanilla JS, client-side only — nothing here
   makes a request. Motion is skipped under
   prefers-reduced-motion, and reveal classes are added here
   only, so a no-JS page renders fully visible.
   ========================================================== */
(function () {
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var root = document.documentElement;

    function each(selector, scope, callback) {
        Array.prototype.forEach.call((scope || document).querySelectorAll(selector), callback);
    }

    /* ---- Dark / light mode ---- */
    function modeToggle() {
        var meta = document.querySelector('meta[data-au-theme-color]');

        var paint = function () {
            var light = root.getAttribute('data-mode') === 'light';

            each('[data-au-mode-toggle]', document, function (button) {
                var label = button.getAttribute(light ? 'data-label-to-dark' : 'data-label-to-light');

                button.setAttribute('aria-label', label);
                button.setAttribute('title', label);
            });

            if (meta) {
                meta.setAttribute('content', light ? '#F7F8FC' : '#070B1A');
            }
        };

        paint();

        each('[data-au-mode-toggle]', document, function (button) {
            button.addEventListener('click', function () {
                var next = root.getAttribute('data-mode') === 'light' ? 'dark' : 'light';

                root.setAttribute('data-mode', next);

                try {
                    window.localStorage.setItem('au-mode', next);
                } catch (error) {
                    /* Private mode or blocked storage: the toggle still works for this page. */
                }

                paint();
            });
        });
    }

    /* ---- Mobile drawer: focus trapped, Escape closes, focus returns ---- */
    function drawer() {
        var panel = document.querySelector('[data-au-drawer]');
        var opener = document.querySelector('[data-au-drawer-open]');
        var closer = document.querySelector('[data-au-drawer-close]');

        if (!panel || !opener) {
            return;
        }

        var focusables = function () {
            return panel.querySelectorAll('a[href], button:not([disabled])');
        };

        var open = function () {
            panel.hidden = false;
            opener.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';

            // Focus only once the panel is visible: an element under
            // visibility:hidden refuses focus and the call is silently lost.
            window.requestAnimationFrame(function () {
                panel.classList.add('is-open');

                var first = focusables()[0];

                if (first) {
                    first.focus();
                }
            });
        };

        var close = function () {
            panel.classList.remove('is-open');
            opener.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
            window.setTimeout(function () {
                panel.hidden = true;
            }, reduced ? 0 : 250);
            opener.focus();
        };

        opener.addEventListener('click', open);

        if (closer) {
            closer.addEventListener('click', close);
        }

        panel.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();

                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            var items = focusables();
            var first = items[0];
            var last = items[items.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        panel.addEventListener('click', function (event) {
            if (event.target.closest('a')) {
                close();
            }
        });
    }

    /* ---- Portal tabs (WAI-ARIA tabs, arrow keys move between them) ---- */
    function portalTabs() {
        each('[data-au-tabs]', document, function (list) {
            var tabs = list.querySelectorAll('[role="tab"]');

            var select = function (tab, focus) {
                Array.prototype.forEach.call(tabs, function (other) {
                    var on = other === tab;
                    var panel = document.getElementById(other.getAttribute('aria-controls'));

                    other.setAttribute('aria-selected', on ? 'true' : 'false');
                    other.setAttribute('tabindex', on ? '0' : '-1');

                    if (panel) {
                        panel.hidden = !on;
                    }
                });

                if (focus) {
                    tab.focus();
                }
            };

            Array.prototype.forEach.call(tabs, function (tab, index) {
                tab.addEventListener('click', function () {
                    select(tab, false);
                });

                tab.addEventListener('keydown', function (event) {
                    var step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];

                    if (root.getAttribute('dir') === 'rtl' && (event.key === 'ArrowRight' || event.key === 'ArrowLeft')) {
                        step = -step;
                    }

                    if (event.key === 'Home') {
                        event.preventDefault();
                        select(tabs[0], true);
                    } else if (event.key === 'End') {
                        event.preventDefault();
                        select(tabs[tabs.length - 1], true);
                    } else if (step) {
                        event.preventDefault();
                        select(tabs[(index + step + tabs.length) % tabs.length], true);
                    }
                });
            });
        });
    }

    /* ---- Feature catalog category filter ---- */
    function catalogFilter() {
        each('[data-au-catalog]', document, function (catalog) {
            var buttons = catalog.querySelectorAll('[data-au-filter]');

            Array.prototype.forEach.call(buttons, function (button) {
                button.addEventListener('click', function () {
                    var category = button.getAttribute('data-au-filter');

                    Array.prototype.forEach.call(buttons, function (other) {
                        other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
                    });

                    each('[data-au-feature]', catalog, function (card) {
                        card.hidden = category !== 'all' && card.getAttribute('data-au-feature') !== category;
                    });
                });
            });
        });
    }

    /* ---- Virtual card preview: theme swatches + freeze ---- */
    function cardPreview() {
        each('[data-au-cardstage]', document, function (stage) {
            var target = stage.querySelector('[data-au-card-theme]');
            var name = stage.querySelector('[data-au-card-theme-name]');
            var frozen = stage.querySelector('[data-au-frozen]');
            var freeze = stage.querySelector('[data-au-freeze]');

            each('[data-au-swatch]', stage, function (swatch) {
                swatch.addEventListener('click', function () {
                    if (target) {
                        target.setAttribute('data-au-card-theme', swatch.getAttribute('data-au-swatch'));
                    }

                    if (name) {
                        name.textContent = swatch.getAttribute('data-au-swatch-name');
                    }

                    each('[data-au-swatch]', stage, function (other) {
                        other.setAttribute('aria-pressed', other === swatch ? 'true' : 'false');
                    });
                });
            });

            if (freeze && frozen) {
                freeze.addEventListener('click', function () {
                    var isFrozen = frozen.hidden;
                    var label = freeze.querySelector('[data-au-freeze-label]');

                    frozen.hidden = !isFrozen;
                    freeze.setAttribute('aria-pressed', isFrozen ? 'true' : 'false');

                    if (label) {
                        label.textContent = freeze.getAttribute(isFrozen ? 'data-label-unfreeze' : 'data-label-freeze');
                    }
                });
            }
        });
    }

    /* ---- Remittance calculator (an illustration, never a quote) ----
       Mirrors the remittance module's own arithmetic: the fee is charged on
       top of the amount sent, the recipient gets amount x rate, and each leg
       is shown at its own currency's precision. The figures come from the
       server in data-au-calc; nothing is fetched here. */
    function remittanceCalculator() {
        each('[data-au-calc]', document, function (calc) {
            var config;

            try {
                config = JSON.parse(calc.getAttribute('data-au-calc'));
            } catch (error) {
                return;
            }

            if (!config || !config.corridors || !config.corridors.length) {
                return;
            }

            var input = calc.querySelector('[data-au-calc-amount]');
            var active = config.corridors[0];

            var format = function (value, decimals) {
                return new Intl.NumberFormat('en-US', {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals
                }).format(value);
            };

            var set = function (selector, text) {
                each(selector, calc, function (node) {
                    node.textContent = text;
                });
            };

            var render = function () {
                var amount = parseFloat(String(input ? input.value : '0').replace(/,/g, '')) || 0;
                var fee = active.fixed_fee + (amount * active.percent_fee / 100);
                var receive = Math.max(0, amount) * active.rate;
                var precision = active.decimals === 0
                    ? calc.getAttribute('data-label-no-decimals')
                    : calc.getAttribute('data-label-decimals').replace(':count', String(active.decimals));

                set('[data-au-calc-fee]', '+ ' + format(fee, config.source.decimals) + ' ' + config.source.code);
                set('[data-au-calc-rate]', '1 ' + config.source.code + ' = ' + format(active.rate, active.rate < 1 ? 4 : 2) + ' ' + active.code);
                set('[data-au-calc-receive]', format(receive, active.decimals));
                set('[data-au-calc-code]', active.code);
                set('[data-au-calc-name]', active.name + ' · ' + precision);
            };

            if (input) {
                input.addEventListener('input', function () {
                    var cleaned = input.value.replace(/[^0-9.,]/g, '');

                    if (cleaned !== input.value) {
                        input.value = cleaned;
                    }

                    render();
                });
            }

            each('[data-au-dest]', calc, function (button) {
                button.addEventListener('click', function () {
                    var code = button.getAttribute('data-au-dest');

                    config.corridors.forEach(function (corridor) {
                        if (corridor.code === code) {
                            active = corridor;
                        }
                    });

                    each('[data-au-dest]', calc, function (other) {
                        other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
                    });

                    render();
                });
            });

            render();
        });
    }

    /* ---- Scroll reveal ---- */
    function reveal() {
        var targets = document.querySelectorAll('[data-au-reveal]');

        if (!targets.length || reduced || !('IntersectionObserver' in window)) {
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }

                entry.target.classList.add('is-in');
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

        Array.prototype.forEach.call(targets, function (node) {
            node.classList.add('au-reveal');
            observer.observe(node);
        });
    }

    function boot() {
        modeToggle();
        drawer();
        portalTabs();
        catalogFilter();
        cardPreview();
        remittanceCalculator();
        reveal();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
