"use strict";

/*
 * System information: copy the report, copy one value, filter the extension
 * chips, and keep the jump bar pointing at the card on screen.
 *
 * notifyEvs() is the panel's toast (general/js/helpers.js).
 */
(function () {
    var toast = function (type, text) {
        if (typeof window.notifyEvs === 'function' && text) {
            window.notifyEvs(type, text);
        }
    };

    /*
     * The Clipboard API needs a secure context; a buyer's staging site on
     * plain http is exactly where someone opens this page to file a ticket,
     * so the textarea fallback is not optional.
     */
    var copyText = function (text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');

            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();

            try {
                if (document.execCommand('copy')) {
                    resolve();
                } else {
                    reject(new Error('copy refused'));
                }
            } catch (error) {
                reject(error);
            } finally {
                document.body.removeChild(area);
            }
        });
    };

    /* Copy report: the text route is the one source, so page and paste agree. */
    var reportButton = document.querySelector('[data-sys-copy-report]');

    if (reportButton) {
        reportButton.addEventListener('click', function () {
            if (reportButton.getAttribute('aria-busy') === 'true') {
                return;
            }

            reportButton.setAttribute('aria-busy', 'true');

            fetch(reportButton.dataset.url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/plain' }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    return response.text();
                })
                .then(copyText)
                .then(function () {
                    toast('success', reportButton.dataset.done);
                })
                .catch(function () {
                    toast('error', reportButton.dataset.fail);
                })
                .finally(function () {
                    reportButton.removeAttribute('aria-busy');
                });
        });
    }

    /* One value: the button turns into a tick for a moment, and that is all. */
    document.querySelectorAll('[data-sys-copy-value]').forEach(function (button) {
        button.addEventListener('click', function () {
            copyText(button.dataset.sysCopyValue).then(function () {
                button.classList.add('is-done');

                window.setTimeout(function () {
                    button.classList.remove('is-done');
                }, 1200);
            });
        });
    });

    /* Extensions: all of them, or only the ones this server lacks. */
    var filter = document.querySelector('[data-sys-ext-filter]');
    var chipList = document.querySelector('[data-sys-ext-list]');
    var noneMissing = document.querySelector('[data-sys-ext-none]');

    if (filter && chipList) {
        var filterButtons = filter.querySelectorAll('[data-sys-ext-show]');

        filterButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var onlyMissing = button.dataset.sysExtShow === 'missing';
                var shown = 0;

                filterButtons.forEach(function (other) {
                    var isChosen = other === button;

                    other.classList.toggle('is-on', isChosen);
                    other.setAttribute('aria-pressed', isChosen ? 'true' : 'false');
                });

                chipList.querySelectorAll('.sys-chip').forEach(function (chip) {
                    var hide = onlyMissing && chip.dataset.ok === '1';

                    chip.hidden = hide;
                    shown += hide ? 0 : 1;
                });

                if (noneMissing) {
                    noneMissing.hidden = shown > 0;
                }
            });
        });
    }

    /*
     * Jump bar.
     *
     * The current card is the last one whose top has passed the bar. A page
     * that cannot scroll far enough to bring the last cards up there would
     * never light them — the old tabbed page shipped that bug once — so at
     * the foot of the page the last card wins outright.
     */
    var nav = document.querySelector('[data-sys-nav]');

    if (!nav) {
        return;
    }

    var links = Array.prototype.slice.call(nav.querySelectorAll('[data-sys-nav-link]'));
    var sections = links
        .map(function (link) {
            return document.getElementById('sys-' + link.dataset.sysNavLink);
        })
        .filter(Boolean);

    var markActive = function (key) {
        links.forEach(function (link) {
            var isActive = link.dataset.sysNavLink === key;

            link.classList.toggle('is-active', isActive);

            if (isActive) {
                link.setAttribute('aria-current', 'location');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    var current = function () {
        var line = nav.getBoundingClientRect().bottom + 24;
        var atFoot = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4;

        if (atFoot) {
            return sections[sections.length - 1];
        }

        var found = sections[0];

        sections.forEach(function (section) {
            if (section.getBoundingClientRect().top <= line) {
                found = section;
            }
        });

        return found;
    };

    var queued = false;

    var update = function () {
        queued = false;

        var section = current();

        if (section) {
            markActive(section.id.slice(4));
        }
    };

    window.addEventListener('scroll', function () {
        if (!queued) {
            queued = true;
            window.requestAnimationFrame(update);
        }
    }, { passive: true });

    update();

    /*
     * Links written for the tabbed page, ?section=php, land on the card that
     * holds the same facts now. Application and Database became the stack;
     * Server became Host; Storage became Drivers & locale.
     */
    var legacy = {
        application: 'stack',
        database: 'stack',
        server: 'host',
        storage: 'drivers',
        security: 'security',
        php: 'php',
        overview: 'overview'
    };

    try {
        var requested = new URL(window.location.href).searchParams.get('section');
        var target = requested && legacy[requested] ? document.getElementById('sys-' + legacy[requested]) : null;

        if (target && !window.location.hash) {
            target.scrollIntoView({ block: 'start' });
        }
    } catch (error) {
        /* An address that cannot be parsed still shows the whole page. */
    }
})();
