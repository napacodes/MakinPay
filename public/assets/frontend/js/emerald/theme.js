"use strict";

/* ==========================================================
   DigiKash — Emerald landing theme
   Sticky nav, mobile drawer, scroll reveal, count-up, marquee,
   pricing toggle and FAQ disclosure. Vanilla JS. Every effect is
   skipped under prefers-reduced-motion, and reveal classes are
   added here only so a no-JS page renders fully visible.
   ========================================================== */
(function () {
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---- Sticky header ---- */
    function stickyHeader() {
        var header = document.querySelector('[data-dk-header]');

        if (!header) {
            return;
        }

        var onScroll = function () {
            header.classList.toggle('is-stuck', window.scrollY > 80);
        };

        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    /* ---- Mobile drawer: focus trapped, Escape closes, focus returns ---- */
    function drawer() {
        var panel = document.querySelector('[data-dk-drawer]');
        var opener = document.querySelector('[data-dk-drawer-open]');
        var closer = document.querySelector('[data-dk-drawer-close]');

        if (!panel || !opener) {
            return;
        }

        var focusables = function () {
            return panel.querySelectorAll('a[href], button:not([disabled])');
        };

        var open = function () {
            panel.hidden = false;
            window.requestAnimationFrame(function () {
                panel.classList.add('is-open');
            });
            opener.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';

            var first = focusables()[0];

            if (first) {
                first.focus();
            }
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

            if (!items.length) {
                return;
            }

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

    /* ---- Scroll reveal ---- */
    function reveal() {
        var targets = document.querySelectorAll('[data-dk-reveal]');

        if (!targets.length || reduced || !('IntersectionObserver' in window)) {
            return;
        }

        targets.forEach(function (node) {
            node.classList.add('dk-reveal');
        });

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }

                entry.target.classList.add('is-in');
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.2 });

        targets.forEach(function (node) {
            observer.observe(node);
        });
    }

    /* ---- Count-up ---- */
    function counters() {
        var nodes = document.querySelectorAll('[data-dk-count]');

        if (!nodes.length) {
            return;
        }

        var render = function (node, value) {
            var decimals = parseInt(node.getAttribute('data-dk-decimals') || '0', 10);

            node.textContent = (node.getAttribute('data-dk-prefix') || '')
                + value.toLocaleString(undefined, {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals
                })
                + (node.getAttribute('data-dk-suffix') || '');
        };

        var run = function (node) {
            var target = parseFloat(node.getAttribute('data-dk-count'));

            if (isNaN(target)) {
                return;
            }

            if (reduced) {
                render(node, target);

                return;
            }

            var started = null;

            var step = function (now) {
                if (started === null) {
                    started = now;
                }

                var progress = Math.min((now - started) / 1400, 1);

                render(node, target * (1 - Math.pow(1 - progress, 3)));

                if (progress < 1) {
                    window.requestAnimationFrame(step);
                }
            };

            window.requestAnimationFrame(step);
        };

        if (!('IntersectionObserver' in window)) {
            nodes.forEach(run);

            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }

                run(entry.target);
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.4 });

        nodes.forEach(function (node) {
            observer.observe(node);
        });
    }

    /* ---- Marquee: duplicate the track, pace it by width ---- */
    function marquee() {
        document.querySelectorAll('[data-dk-marquee]').forEach(function (track) {
            if (reduced) {
                return;
            }

            var clone = track.cloneNode(true);

            clone.setAttribute('aria-hidden', 'true');
            clone.removeAttribute('data-dk-marquee');
            track.parentNode.appendChild(clone);

            var seconds = Math.max(18, track.scrollWidth / 40);

            track.parentNode.style.setProperty('--dk-marquee-duration', seconds + 's');
            track.parentNode.classList.add('is-running');
        });
    }

    /* ---- Pricing toggle: a real radiogroup, arrow keys plus Home/End ---- */
    function pricing() {
        var group = document.querySelector('[data-dk-billing]');

        if (!group) {
            return;
        }

        var options = Array.prototype.slice.call(group.querySelectorAll('[role=radio]'));

        var select = function (option) {
            options.forEach(function (item) {
                var on = item === option;

                item.setAttribute('aria-checked', on ? 'true' : 'false');
                item.setAttribute('tabindex', on ? '0' : '-1');
                item.classList.toggle('is-selected', on);
            });

            var cycle = option.getAttribute('data-dk-billing-option');

            document.querySelectorAll('[data-dk-price]').forEach(function (price) {
                var next = price.getAttribute('data-dk-price-' + cycle);

                if (next !== null) {
                    price.textContent = next;
                }
            });

            group.setAttribute('data-dk-billing-active', cycle);
        };

        options.forEach(function (option) {
            option.addEventListener('click', function () {
                select(option);
            });

            option.addEventListener('keydown', function (event) {
                var steps = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
                var index = options.indexOf(option);
                var next = null;

                if (steps[event.key]) {
                    next = options[(index + steps[event.key] + options.length) % options.length];
                } else if (event.key === 'Home') {
                    next = options[0];
                } else if (event.key === 'End') {
                    next = options[options.length - 1];
                }

                if (!next) {
                    return;
                }

                event.preventDefault();
                select(next);
                next.focus();
            });
        });
    }

    /* ---- FAQ disclosure ---- */
    function faq() {
        document.querySelectorAll('[data-dk-faq-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var expanded = button.getAttribute('aria-expanded') === 'true';
                var panel = document.getElementById(button.getAttribute('aria-controls'));

                button.setAttribute('aria-expanded', expanded ? 'false' : 'true');

                if (panel) {
                    panel.classList.toggle('is-open', !expanded);
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        stickyHeader();
        drawer();
        reveal();
        counters();
        marquee();
        pricing();
        faq();
    });
})();
