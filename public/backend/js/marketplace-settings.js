"use strict";

/*
 * Marketplace settings — admin panel
 *
 * The page renders every section; this file decides which one is visible and
 * keeps the navigation column honest while fields are edited.
 *
 *  1. Sections — clicking a row shows its panel, updates the panel heading
 *     and writes the section into the address as #payouts, so reload, back
 *     and a bookmark all land where the reader was.
 *  2. Filter — the box at the top of the column narrows the rows.
 *  3. Live values — the value on each row follows the field being edited,
 *     not the saved record, so an unsaved rate and its amber dot agree.
 *  4. Dirty tracking — per section, feeding the row dots, the save bar and
 *     the state pill in the page header.
 *
 * The server decides which section is open on first paint (the first one
 * holding a validation error), so a rejected save never hides its reason.
 * Fields of a closed section stay in the document and are still submitted:
 * one save writes every section. That is also why the form carries
 * novalidate — a browser will not report a constraint violation on a control
 * it cannot focus, and the save would fail in silence.
 */
(function () {
    var screen = document.querySelector('[data-mps-screen]');
    if (!screen) {
        return;
    }

    var form = screen.querySelector('[data-mps-form]');
    var links = Array.prototype.slice.call(screen.querySelectorAll('[data-mps-link]'));
    var panels = Array.prototype.slice.call(screen.querySelectorAll('[data-mps-panel]'));
    var savebar = screen.querySelector('[data-mps-savebar]');

    /*
     * The panel heading is one element, not one per section: the titles live
     * here so the markup does not repeat a heading five times only to hide
     * four of them.
     */
    var titleEl = screen.querySelector('[data-mps-panel-title]');
    var descEl = screen.querySelector('[data-mps-panel-desc]');
    var hintEl = screen.querySelector('[data-mps-panel-hint]');

    var headings = {};
    panels.forEach(function (panel) {
        headings[panel.getAttribute('data-mps-panel')] = null;
    });

    // Capture the server-rendered heading for the section that opened first.
    var initialSection = screen.getAttribute('data-mps-active') || 'general';
    if (titleEl) {
        headings[initialSection] = {
            title: titleEl.textContent,
            desc: descEl ? descEl.textContent : '',
            hint: hintEl ? hintEl.textContent : ''
        };
    }

    // Every other heading comes from the panel's own aria-label plus the
    // card title it already carries, so no copy is duplicated in script.
    panels.forEach(function (panel) {
        var key = panel.getAttribute('data-mps-panel');
        if (headings[key]) {
            return;
        }
        var link = screen.querySelector('[data-mps-link="' + key + '"] .mps-nav__label');
        var firstCard = panel.querySelector('.mps-card__title');
        var firstCardDesc = firstCard ? firstCard.querySelector('span') : null;
        headings[key] = {
            title: panel.getAttribute('aria-label') || (link ? link.textContent : key),
            desc: firstCardDesc ? firstCardDesc.textContent : '',
            hint: ''
        };
    });

    function readOnly(section) {
        return section === 'custody' || section === 'history';
    }

    function show(section, pushHash) {
        var known = false;
        panels.forEach(function (panel) {
            if (panel.getAttribute('data-mps-panel') === section) {
                known = true;
            }
        });

        if (!known) {
            return;
        }

        panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-mps-panel') !== section;
        });

        links.forEach(function (link) {
            link.setAttribute('aria-current', link.getAttribute('data-mps-link') === section ? 'true' : 'false');
        });

        var heading = headings[section];
        if (heading) {
            if (titleEl) { titleEl.textContent = heading.title; }
            if (descEl) { descEl.textContent = heading.desc; }
            if (hintEl) { hintEl.textContent = heading.hint; }
        }

        if (savebar) {
            /*
             * Whether the bar applies to this section is this page's
             * business; whether anything is unsaved is
             * admin-savebar.js's. Say which, and let it decide the
             * rest, or the two disagree and a clean form shows a bar.
             */
            if (readOnly(section)) {
                savebar.setAttribute('data-ss-inapplicable', '');
            } else {
                savebar.removeAttribute('data-ss-inapplicable');
            }

            if (form) {
                form.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        screen.setAttribute('data-mps-active', section);

        if (pushHash && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', '#' + section);
        }
    }

    links.forEach(function (link) {
        link.addEventListener('click', function () {
            show(link.getAttribute('data-mps-link'), true);
        });
    });

    /*
     * A fragment only wins when the server did not already pick a section
     * because of a validation error — the error is the more urgent fact.
     */
    var hash = (window.location.hash || '').replace('#', '');
    if (hash && screen.getAttribute('data-mps-active') === 'general' && hash !== 'general') {
        show(hash, false);
    }

    // ── Filter ───────────────────────────────────────────────────────────
    var filter = screen.querySelector('[data-mps-filter]');
    var groups = Array.prototype.slice.call(screen.querySelectorAll('[data-mps-group]'));
    var empty = screen.querySelector('[data-mps-empty]');

    if (filter) {
        filter.addEventListener('input', function () {
            var q = filter.value.trim().toLowerCase();
            var any = false;

            links.forEach(function (link) {
                var terms = (link.getAttribute('data-mps-terms') || '') + ' ' + link.textContent;
                var match = q === '' || terms.toLowerCase().indexOf(q) !== -1;
                link.hidden = !match;
                if (match) {
                    any = true;
                }
            });

            groups.forEach(function (group) {
                group.hidden = group.querySelectorAll('[data-mps-link]:not([hidden])').length === 0;
            });

            if (empty) {
                empty.hidden = any;
            }
        });
    }

    if (!form) {
        return;
    }

    // ── Live values and the split preview ────────────────────────────────
    var pctInput = form.querySelector('[data-mps-pct]');
    var fixedInput = form.querySelector('[data-mps-fixed]');
    var holdInput = form.querySelector('[data-mps-hold]');
    var enabledInput = form.querySelector('[data-mps-enabled]');
    var switchRow = form.querySelector('[data-mps-switch-row]');
    var modeInputs = Array.prototype.slice.call(form.querySelectorAll('[data-mps-mode]'));
    var railInputs = Array.prototype.slice.call(form.querySelectorAll('[data-mps-rail]'));

    var sampleInput = form.querySelector('[data-mps-sample]');
    var vendorOut = form.querySelector('[data-mps-vendor]');
    var platformOut = form.querySelector('[data-mps-platform]');
    var rateOut = form.querySelector('[data-mps-rate]');
    var vendorBar = form.querySelector('[data-mps-bar-vendor]');
    var platformBar = form.querySelector('[data-mps-bar-platform]');

    var holdField = form.querySelector('[data-mps-hold-field]');
    var modeHelp = form.querySelector('[data-mps-mode-help]');
    var resultVerb = form.querySelector('[data-mps-result-verb]');
    var resultNote = form.querySelector('[data-mps-result-note]');

    var valueEls = {};
    Array.prototype.slice.call(screen.querySelectorAll('[data-mps-value]')).forEach(function (el) {
        valueEls[el.getAttribute('data-mps-value')] = el;
    });

    function money(n) {
        if (!isFinite(n)) {
            return '0.00';
        }
        return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function isInstant() {
        for (var i = 0; i < modeInputs.length; i++) {
            if (modeInputs[i].checked) {
                return modeInputs[i].value === '1';
            }
        }
        return true;
    }

    function isWallet() {
        for (var i = 0; i < railInputs.length; i++) {
            if (railInputs[i].checked) {
                return railInputs[i].value !== 'stripe_connect';
            }
        }
        return true;
    }

    /*
     * The hold duration reads the way an operator says it. The sentence
     * frame comes from the markup the server rendered, so it stays
     * translated; only the number and its unit are assembled here.
     */
    function humanHold(minutes) {
        var frame = resultVerb ? resultVerb.getAttribute('data-hold') : null;
        var units = holdInput ? holdInput.getAttribute('data-units') : null;
        var names = units ? units.split('|') : ['days', 'hours', 'minutes'];
        var value;
        var unit;

        if (minutes >= 1440) {
            value = (minutes / 1440).toLocaleString(undefined, { maximumFractionDigits: 1 });
            unit = names[0];
        } else if (minutes >= 60) {
            value = (minutes / 60).toLocaleString(undefined, { maximumFractionDigits: 1 });
            unit = names[1];
        } else {
            value = String(minutes);
            unit = names[2];
        }

        var text = value + ' ' + unit;
        return frame ? frame.replace(':duration', text) : text;
    }

    function syncSplit() {
        if (!pctInput) {
            return;
        }
        var base = parseFloat(sampleInput ? sampleInput.value : '0') || 0;
        var pct = parseFloat(pctInput.value) || 0;
        var fixed = parseFloat(fixedInput ? fixedInput.value : '0') || 0;
        var cut = Math.min(base, base * (pct / 100) + fixed);
        var share = base > 0 ? (cut / base) * 100 : 0;

        if (platformOut) { platformOut.textContent = money(cut); }
        if (vendorOut) { vendorOut.textContent = money(base - cut); }
        if (rateOut) { rateOut.textContent = money(share) + '%'; }
        if (vendorBar) { vendorBar.style.inlineSize = (100 - share) + '%'; }
        if (platformBar) { platformBar.style.inlineSize = share + '%'; }

        if (valueEls.commission) {
            valueEls.commission.textContent = money(pct) + '%' + (fixed > 0 ? ' +' + money(fixed) : '');
        }
    }

    function syncAvailability() {
        if (!enabledInput) {
            return;
        }
        var on = enabledInput.checked;

        if (switchRow) {
            switchRow.classList.toggle('mps-switch-row--off', !on);
            var onCopy = switchRow.querySelector('[data-mps-avail="on"]');
            var offCopy = switchRow.querySelector('[data-mps-avail="off"]');
            if (onCopy) { onCopy.hidden = !on; }
            if (offCopy) { offCopy.hidden = on; }
        }

        var el = valueEls.general;
        if (el) {
            var next = el.getAttribute(on ? 'data-on' : 'data-off');
            if (next) {
                el.textContent = next;
            }
        }
    }

    function syncPayout() {
        var instant = isInstant();
        var minutes = parseInt(holdInput ? holdInput.value : '0', 10) || 0;

        if (holdField) {
            holdField.setAttribute('data-disabled', instant ? 'true' : 'false');
        }

        if (modeHelp) {
            var help = modeHelp.getAttribute(instant ? 'data-instant' : 'data-hold');
            if (help) {
                modeHelp.textContent = help;
            }
        }

        if (resultVerb) {
            resultVerb.textContent = instant
                ? (resultVerb.getAttribute('data-instant') || resultVerb.textContent)
                : humanHold(minutes);
        }

        if (resultNote) {
            var key = (isWallet() ? 'data-wallet-' : 'data-stripe-') + (instant ? 'instant' : 'hold');
            var note = resultNote.getAttribute(key);
            if (note) {
                resultNote.textContent = note;
            }
        }

        var el = valueEls.payouts;
        if (el) {
            el.textContent = instant
                ? (el.getAttribute('data-instant') || el.textContent)
                : (el.getAttribute('data-hold') || '') + humanHold(minutes);
        }
    }

    // ── Dirty tracking, per section ──────────────────────────────────────
    var tracked = {};
    panels.forEach(function (panel) {
        var key = panel.getAttribute('data-mps-panel');
        var fields = Array.prototype.slice.call(panel.querySelectorAll('input[name], select[name], textarea[name]'));
        if (fields.length) {
            tracked[key] = fields;
        }
    });

    function snapshot(fields) {
        return fields.map(function (field) {
            if (field.type === 'checkbox' || field.type === 'radio') {
                return field.name + '=' + (field.checked ? field.value : '');
            }
            return field.name + '=' + field.value;
        }).join('&');
    }

    var baseline = {};
    Object.keys(tracked).forEach(function (key) {
        baseline[key] = snapshot(tracked[key]);
    });

    var statePill = screen.querySelector('[data-mps-state]');

    function syncDirty() {
        var dirty = [];

        Object.keys(tracked).forEach(function (key) {
            var changed = snapshot(tracked[key]) !== baseline[key];
            var dot = screen.querySelector('[data-mps-dot="' + key + '"]');
            if (dot) {
                dot.hidden = !changed;
            }
            if (changed) {
                dirty.push(key);
            }
        });

        var any = dirty.length > 0;



        if (statePill) {
            statePill.classList.toggle('ph-state--warning', any);
            statePill.classList.toggle('ph-state--success', !any);
            var clean = statePill.querySelector('[data-mps-state-clean]');
            var dirtyLabel = statePill.querySelector('[data-mps-state-dirty]');
            if (clean) { clean.hidden = any; }
            if (dirtyLabel) { dirtyLabel.hidden = !any; }
        }
    }

    function syncAll() {
        syncSplit();
        syncAvailability();
        syncPayout();
        syncDirty();
    }

    form.addEventListener('input', syncAll);
    form.addEventListener('change', syncAll);

    Array.prototype.slice.call(form.querySelectorAll('[data-mps-preset]')).forEach(function (chip) {
        chip.addEventListener('click', function () {
            if (!holdInput) {
                return;
            }
            holdInput.value = chip.getAttribute('data-mps-preset');
            holdInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    if (sampleInput) {
        sampleInput.addEventListener('input', syncSplit);
    }

    var reset = screen.querySelector('[data-mps-reset]');
    if (reset) {
        reset.addEventListener('click', function () {
            form.reset();
            syncAll();
        });
    }


    form.addEventListener('submit', function () {
        Object.keys(tracked).forEach(function (key) {
            baseline[key] = snapshot(tracked[key]);
        });
        syncDirty();
    });

    syncAll();
})();
