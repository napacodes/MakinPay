"use strict";

/*
 * DigiKash setup wizard. One form, eleven screens: 0-8 are the steps on the
 * rail, 9 is Installing and 10 is Success. Screens register hooks in
 * `screenHooks`; everything else (rail, crumb, footer, gating) lives here.
 */
(function () {
    const config = window.DK_INSTALLER || {};
    const root = document.querySelector('[data-wz]');

    if (!root) {
        return;
    }

    const SCREEN = {WELCOME: 0, SERVER: 1, APPLICATION: 2, LICENSE: 3, DATABASE: 4, ADMIN: 5, EMAIL: 6, OPTIONS: 7, REVIEW: 8, INSTALLING: 9, SUCCESS: 10};
    const STEP_COUNT = (config.steps || []).length;
    const text = config.text || {};
    const notes = text.notes || {};

    const form = root.querySelector('[data-wz-form]');
    const screens = Array.from(root.querySelectorAll('[data-wz-screen]'));
    const railItems = Array.from(root.querySelectorAll('[data-wz-rail]'));
    const els = {
        progress: root.querySelector('[data-wz-progress]'),
        progressFill: root.querySelector('[data-wz-progress-fill]'),
        progressValue: root.querySelector('[data-wz-progress-value]'),
        crumbIcon: root.querySelector('[data-wz-crumb-icon]'),
        crumbTitle: root.querySelector('[data-wz-crumb-title]'),
        crumbStep: root.querySelector('[data-wz-crumb-step]'),
        crumbEta: root.querySelector('[data-wz-crumb-eta]'),
        foot: root.querySelector('[data-wz-foot]'),
        note: root.querySelector('[data-wz-note]'),
        noteIcon: root.querySelector('[data-wz-note-icon]'),
        back: root.querySelector('[data-wz-back]'),
        next: root.querySelector('[data-wz-next]'),
        nextLabel: root.querySelector('[data-wz-next-label]'),
        nextIcon: root.querySelector('[data-wz-next-icon]'),
    };
    const csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    const state = {
        screen: SCREEN.WELCOME,
        unlocked: SCREEN.WELCOME,
        termsAccepted: false,
        serverOk: config.canInstall === true,
        licenseOk: false,
        databaseOk: false,
        smtpOk: false,
        smtpSkipped: false,
        installing: false,
    };

    /** Per-screen hooks: enter(), note() -> string|null, validate() -> bool. */
    const screenHooks = {};

    // ── Helpers ─────────────────────────────────────────────────────

    function icon(name, size, stroke) {
        const source = document.querySelector('[data-wz-icons]');
        const holder = source ? source.content.querySelector('[data-wz-icon="' + name + '"] svg') : null;

        if (!holder) {
            return document.createTextNode('');
        }

        const svg = holder.cloneNode(true);
        svg.setAttribute('width', String(size || 14));
        svg.setAttribute('height', String(size || 14));

        if (stroke) {
            svg.setAttribute('stroke-width', String(stroke));
        }

        return svg;
    }

    function format(template, values) {
        return Object.keys(values).reduce(function (result, key) {
            return result.split(':' + key).join(String(values[key]));
        }, String(template || ''));
    }

    async function postForm(url) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest'},
            body: new FormData(form),
        });

        let body = {};

        try {
            body = await response.json();
        } catch (error) {
            body = {ok: false, message: response.statusText};
        }

        return {status: response.status, body: body};
    }

    async function getJson(url) {
        const response = await fetch(url, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});

        return {status: response.status, body: await response.json()};
    }

    function validationMessage(body) {
        if (body && body.errors && typeof body.errors === 'object') {
            const first = Object.values(body.errors)[0];

            return Array.isArray(first) ? first[0] : String(first);
        }

        return (body && body.message) || '';
    }

    async function copyText(value, button) {
        try {
            await navigator.clipboard.writeText(value);
        } catch (error) {
            const area = document.createElement('textarea');
            area.value = value;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }

        if (button) {
            const label = button.querySelector('[data-wz-copy-label]');

            if (label) {
                const original = label.textContent;
                label.textContent = text.copied || 'Copied';
                window.setTimeout(function () {
                    label.textContent = original;
                }, 1600);
            }
        }
    }

    function setBusy(button, busy, busyLabel) {
        if (!button) {
            return;
        }

        if (busy) {
            button.dataset.wzIdle = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="wz-spinner" aria-hidden="true"></span><span></span>';
            button.lastElementChild.textContent = busyLabel || '';
        } else if (button.dataset.wzIdle) {
            button.innerHTML = button.dataset.wzIdle;
            button.disabled = false;
            delete button.dataset.wzIdle;
        }
    }

    function fieldsOf(screen) {
        return Array.from(screens[screen].querySelectorAll('input, select, textarea')).filter(function (field) {
            return !field.disabled && field.type !== 'hidden';
        });
    }

    function validateScreen(screen) {
        for (const field of fieldsOf(screen)) {
            if (!field.checkValidity()) {
                field.reportValidity();
                field.focus();

                return false;
            }
        }

        const hooks = screenHooks[screen];

        return !(hooks && hooks.validate && hooks.validate() === false);
    }

    // ── Gating ──────────────────────────────────────────────────────

    function isBlocked(screen) {
        switch (screen) {
            case SCREEN.WELCOME:
                return !state.termsAccepted;
            case SCREEN.SERVER:
                return !state.serverOk;
            case SCREEN.LICENSE:
                return !state.licenseOk;
            case SCREEN.DATABASE:
                return !state.databaseOk;
            case SCREEN.EMAIL:
                return !(state.smtpOk || state.smtpSkipped);
            default:
                return false;
        }
    }

    function footerNote(screen) {
        const hooks = screenHooks[screen];
        const custom = hooks && hooks.note ? hooks.note() : null;

        if (custom) {
            return custom;
        }

        switch (screen) {
            case SCREEN.WELCOME:
                return state.termsAccepted ? notes.welcome : notes.welcomeTerms;
            case SCREEN.SERVER:
                return state.serverOk ? notes.serverOk : notes.serverBad;
            case SCREEN.LICENSE:
                return state.licenseOk ? notes.licenseOk : notes.licenseBad;
            case SCREEN.DATABASE:
                return state.databaseOk ? notes.databaseOk : notes.databaseBad;
            case SCREEN.EMAIL:
                if (state.smtpSkipped) {
                    return notes.emailSkipped;
                }

                return state.smtpOk ? notes.emailOk : notes.emailBad;
            case SCREEN.APPLICATION:
                return notes.application;
            case SCREEN.ADMIN:
                return notes.admin;
            case SCREEN.OPTIONS:
                return notes.options;
            case SCREEN.REVIEW:
                return notes.review;
            default:
                return notes.default;
        }
    }

    function nextLabel(screen) {
        if (screen === SCREEN.WELCOME) {
            return text.start;
        }

        if (screen === SCREEN.OPTIONS) {
            return text.review;
        }

        if (screen === SCREEN.REVIEW) {
            return text.install;
        }

        if (screen === SCREEN.INSTALLING) {
            return text.installing;
        }

        return text.next;
    }

    // ── Rendering ───────────────────────────────────────────────────

    function render() {
        const screen = state.screen;
        const finished = screen >= SCREEN.INSTALLING;
        const activeStep = Math.min(screen, STEP_COUNT - 1);
        const blocked = isBlocked(screen);

        screens.forEach(function (section, index) {
            section.classList.toggle('is-active', index === screen);
        });

        railItems.forEach(function (item, index) {
            const done = finished || index < activeStep;
            const current = !finished && index === activeStep;

            item.classList.toggle('is-done', done);
            item.classList.toggle('is-current', current);
            item.classList.toggle('is-upcoming', !done && !current);

            const button = item.querySelector('[data-wz-goto]');
            button.disabled = finished || index > state.unlocked;

            if (current) {
                button.setAttribute('aria-current', 'step');
            } else {
                button.removeAttribute('aria-current');
            }
        });

        const percent = finished ? 100 : Math.round(activeStep / Math.max(1, STEP_COUNT - 1) * 100);
        els.progressFill.style.width = percent + '%';
        els.progressValue.textContent = percent + '%';
        els.progress.setAttribute('aria-valuenow', String(percent));

        const step = (config.steps || [])[activeStep] || {};
        els.crumbTitle.textContent = screen === SCREEN.SUCCESS ? text.complete : (screen === SCREEN.INSTALLING ? text.installing : step.title);
        els.crumbIcon.replaceChildren(icon(finished ? 'checkCircle' : step.icon, 14, 2.2));
        els.crumbStep.textContent = format(text.stepOf, {current: activeStep + 1, total: STEP_COUNT});
        const minutesLeft = Math.max(0, STEP_COUNT - 1 - activeStep);
        els.crumbEta.textContent = minutesLeft > 0 ? format(text.minLeft, {minutes: minutesLeft}) : text.almostDone;
        els.crumbEta.parentElement.hidden = finished;
        els.crumbEta.parentElement.previousElementSibling.hidden = finished;

        els.foot.hidden = screen === SCREEN.SUCCESS;
        els.foot.classList.toggle('is-blocked', blocked);
        els.note.textContent = footerNote(screen);
        els.noteIcon.replaceChildren(icon(blocked ? 'alert' : 'check', 12, 2.5));
        els.back.hidden = screen === SCREEN.WELCOME || finished;
        els.next.hidden = screen === SCREEN.SUCCESS || (screen === SCREEN.INSTALLING && !state.installing);
        els.next.disabled = state.installing;
        els.next.classList.toggle('is-blocked', blocked);
        els.next.setAttribute('aria-disabled', blocked ? 'true' : 'false');
        els.nextLabel.textContent = nextLabel(screen);
        els.nextIcon.replaceChildren(icon(screen === SCREEN.REVIEW ? 'play' : 'arrowRight', 14, 2.5));
    }

    // ── Navigation ──────────────────────────────────────────────────

    function goTo(screen, options) {
        const force = options && options.force;

        if (!force && (screen > state.unlocked || screen < 0 || screen >= SCREEN.INSTALLING)) {
            return;
        }

        state.screen = screen;

        const hooks = screenHooks[screen];

        if (hooks && hooks.enter) {
            hooks.enter();
        }

        render();
        window.scrollTo({top: 0, behavior: 'smooth'});

        const heading = screens[screen].querySelector('h1, h2');

        if (heading) {
            heading.setAttribute('tabindex', '-1');
            heading.focus({preventScroll: true});
        }
    }

    function next() {
        const screen = state.screen;

        if (state.installing || screen >= SCREEN.INSTALLING) {
            return;
        }

        if (isBlocked(screen)) {
            const hooks = screenHooks[screen];

            if (hooks && hooks.blocked) {
                hooks.blocked();
            }

            els.foot.classList.remove('is-nudged');
            void els.foot.offsetWidth;
            els.foot.classList.add('is-nudged');

            return;
        }

        if (!validateScreen(screen)) {
            return;
        }

        if (screen === SCREEN.REVIEW) {
            const hooks = screenHooks[SCREEN.INSTALLING];

            if (hooks && hooks.start) {
                hooks.start();
            }

            return;
        }

        state.unlocked = Math.max(state.unlocked, screen + 1);
        goTo(screen + 1);
    }

    function back() {
        if (state.screen > SCREEN.WELCOME && state.screen < SCREEN.INSTALLING) {
            goTo(state.screen - 1);
        }
    }

    // ── Wiring ──────────────────────────────────────────────────────

    els.next.addEventListener('click', next);
    els.back.addEventListener('click', back);

    railItems.forEach(function (item) {
        item.querySelector('[data-wz-goto]').addEventListener('click', function () {
            goTo(Number(item.dataset.wzRail));
        });
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        next();
    });

    root.addEventListener('click', function (event) {
        const button = event.target.closest('[data-wz-copy]');

        if (button) {
            const target = document.getElementById(button.dataset.wzCopy);
            copyText(target ? (target.value !== undefined ? target.value : target.textContent) : button.dataset.wzCopy, button);
        }
    });

    // ── 0 · Welcome ─────────────────────────────────────────────────

    (function () {
        const terms = root.querySelector('[data-wz-terms]');

        if (!terms) {
            return;
        }

        terms.addEventListener('change', function () {
            state.termsAccepted = terms.checked;
            render();
        });

        screenHooks[SCREEN.WELCOME] = {
            blocked: function () {
                terms.focus();
            },
        };
    })();

    // ── 1 · Server check ────────────────────────────────────────────

    (function () {
        const section = root.querySelector('[data-wz-server]');

        if (!section) {
            return;
        }

        const extCard = section.querySelector('[data-wz-ext-card]');
        const extRows = section.querySelector('[data-wz-ext-rows]');
        const writableRows = section.querySelector('[data-wz-writable-rows]');
        const rightColumn = section.querySelector('[data-wz-server-right]');
        const rerun = section.querySelector('[data-wz-rerun]');
        const banner = section.querySelector('[data-wz-server-banner]');
        const extSummary = section.querySelector('[data-wz-ext-summary]');
        const writableSummary = section.querySelector('[data-wz-writable-summary]');
        const labels = text.server || {};
        let totalChecks = Number(section.dataset.wzServerTotal || 0);

        function checkRow(row, withHelp) {
            const item = document.createElement('div');
            item.className = 'wz-check' + (row.ok ? '' : ' is-bad');

            const badge = document.createElement('span');
            badge.className = 'wz-badge' + (row.ok ? '' : ' is-bad');
            badge.appendChild(icon(row.ok ? 'check' : 'cross', 12, 3));

            const copy = document.createElement('span');
            copy.className = 'wz-check__text';

            const label = document.createElement('span');
            label.className = 'wz-check__label';
            label.textContent = row.label;
            copy.appendChild(label);

            if (withHelp) {
                const help = document.createElement('span');
                help.className = 'wz-check__help';
                help.textContent = row.help;
                copy.appendChild(help);
            }

            const chip = document.createElement('code');
            chip.className = 'wz-chip';
            chip.textContent = row.key;

            item.append(badge, copy, chip);

            return item;
        }

        function paintSummaries() {
            const extTotal = extRows.querySelectorAll('.wz-check').length;
            const extFailed = extRows.querySelectorAll('.wz-check.is-bad').length;
            const writableFailed = writableRows.querySelectorAll('.wz-check.is-bad').length;

            extSummary.textContent = extFailed
                ? format(labels.extMissing, {count: extFailed})
                : format(labels.extLoaded, {loaded: extTotal, total: extTotal});
            extSummary.classList.toggle('is-bad', extFailed > 0);
            writableSummary.textContent = writableFailed ? format(labels.pathsBad, {count: writableFailed}) : labels.pathsOk;
            writableSummary.classList.toggle('is-bad', writableFailed > 0);
        }

        function paint(report) {
            state.serverOk = report.can_install === true;
            totalChecks = report.total_count;

            report.metrics.forEach(function (metric) {
                const card = section.querySelector('[data-wz-metric="' + metric.key + '"]');
                const advisory = metric.key === 'memory' || metric.key === 'upload';

                if (!card) {
                    return;
                }

                card.classList.toggle('is-bad', !metric.ok && !advisory);
                card.classList.toggle('is-warn', !metric.ok && advisory);
                card.querySelector('[data-wz-metric-value]').textContent = metric.value;
                card.querySelector('[data-wz-metric-hint]').textContent = metric.hint;
            });

            extRows.replaceChildren.apply(extRows, report.extensions.map(function (row) {
                return checkRow(row, true);
            }));
            writableRows.replaceChildren.apply(writableRows, report.writable.map(function (row) {
                return checkRow(row, !row.ok);
            }));

            banner.hidden = state.serverOk;
            section.querySelector('[data-wz-server-banner-title]').textContent = format(report.failed_count === 1 ? labels.blockedOne : labels.blocked, {count: report.failed_count});
            section.querySelector('[data-wz-diagnostics]').value = report.diagnostics;
            section.querySelector('[data-wz-fix-commands]').textContent = report.fix_commands;

            paintSummaries();
            lockHeight();
            render();
        }

        function lockHeight() {
            const twoColumns = window.matchMedia('(min-width: 721px)').matches;
            extCard.style.maxHeight = twoColumns && rightColumn.offsetHeight ? rightColumn.offsetHeight + 'px' : '';
        }

        if (window.ResizeObserver) {
            new ResizeObserver(lockHeight).observe(rightColumn);
        }

        window.addEventListener('resize', lockHeight);

        rerun.addEventListener('click', async function () {
            setBusy(rerun, true, labels.checking);

            try {
                const result = await getJson(config.routes.requirements);

                if (result.body && result.body.data) {
                    paint(result.body.data);
                }
            } catch (error) {
                // Network failure: keep the last report on screen.
            } finally {
                setBusy(rerun, false);
            }
        });

        paintSummaries();

        screenHooks[SCREEN.SERVER] = {
            enter: function () {
                window.requestAnimationFrame(lockHeight);
            },
            note: function () {
                return state.serverOk ? format(notes.serverOk, {count: totalChecks}) : notes.serverBad;
            },
            blocked: function () {
                banner.scrollIntoView({behavior: 'smooth', block: 'center'});
            },
        };
    })();

    // ── 2 · Application ─────────────────────────────────────────────

    (function () {
        const section = root.querySelector('[data-wz-application]');

        if (!section) {
            return;
        }

        const prefix = section.querySelector('[data-wz-prefix]');
        const preview = section.querySelector('[data-wz-login-url]');
        const previewFull = section.querySelector('[data-wz-login-url-full]');
        const appUrl = section.querySelector('[data-wz-app-url]');
        const timezone = section.querySelector('[data-wz-timezone]');
        const lockedUrl = appUrl.value;

        function loginUrl() {
            const value = prefix.value.trim().replace(/^[/]+|[/]+$/g, '') || config.adminPrefix;
            const base = lockedUrl.replace(/[/]+$/, '');

            return {full: base + '/' + value + '/login', short: base.replace(/^https?:[/][/]/, '') + '/' + value + '/login'};
        }

        function paintPreview() {
            const url = loginUrl();
            preview.textContent = url.short;
            previewFull.value = url.full;
        }

        function randomPrefix() {
            const alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
            const bytes = new Uint8Array(6);
            window.crypto.getRandomValues(bytes);

            return 'panel-' + Array.from(bytes, function (byte) {
                return alphabet[byte % alphabet.length];
            }).join('');
        }

        prefix.addEventListener('input', function () {
            prefix.value = prefix.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
            paintPreview();
        });

        section.querySelector('[data-wz-randomize]').addEventListener('click', function () {
            prefix.value = randomPrefix();
            paintPreview();
            prefix.focus();
        });

        appUrl.addEventListener('input', function () {
            appUrl.value = lockedUrl;
        });

        (function preselectDetectedTimezone() {
            let detected = '';

            try {
                detected = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            } catch (error) {
                return;
            }

            const option = Array.from(timezone.options).find(function (candidate) {
                return candidate.value === detected;
            });

            if (!option) {
                return;
            }

            option.textContent = option.textContent + ' — ' + timezone.dataset.wzDetectedLabel;

            if (timezone.value === 'UTC' || timezone.value === '') {
                timezone.value = detected;
            }
        })();

        paintPreview();
    })();

    // ── Shared: verify/test status panels ───────────────────────────

    function paintStatus(panel, tone, message) {
        panel.classList.toggle('is-ok', tone === 'ok');
        panel.classList.toggle('is-error', tone === 'error');
        panel.classList.toggle('is-warn', tone === 'warn');
        panel.querySelector('.wz-status__text').textContent = message;
    }

    function paintGuidance(list, items) {
        const lines = Array.isArray(items) ? items : [];
        list.replaceChildren.apply(list, lines.map(function (line) {
            const item = document.createElement('li');
            item.textContent = line;

            return item;
        }));
        list.hidden = lines.length === 0;
    }

    // ── 3 · License ────────────────────────────────────────────────

    (function () {
        const section = root.querySelector('[data-wz-license]');

        if (!section) {
            return;
        }

        const code = section.querySelector('[data-wz-license-code]');
        const affix = section.querySelector('[data-wz-license-affix]');
        const status = section.querySelector('[data-wz-license-status]');
        const verify = section.querySelector('[data-wz-license-verify]');
        const details = section.querySelector('[data-wz-license-details]');
        const guidance = section.querySelector('[data-wz-license-guidance]');
        const labels = text.license || {};
        const verifyIdleHtml = verify.innerHTML;

        function reset() {
            verify.innerHTML = verifyIdleHtml;
            verify.disabled = false;
            verify.classList.remove('wz-btn--success');
            state.licenseOk = false;
            state.licenseDetails = [];
            affix.classList.remove('wz-affix--verified');
            paintStatus(status, 'idle', labels.idle);
            details.hidden = true;
            paintGuidance(guidance, []);
            render();
        }

        function verifiedSummary(rows) {
            const value = function (key) {
                const row = rows.find(function (candidate) {
                    return candidate.key === key;
                });

                return row ? row.value : '';
            };
            const domain = value('domain') || config.domain;
            const type = value('license_type');

            return type
                ? format(labels.verifiedTyped, {type: type, domain: domain})
                : format(labels.verifiedBound, {domain: domain});
        }

        function paintDetails(rows) {
            details.replaceChildren();

            rows.forEach(function (row) {
                const term = document.createElement('dt');
                term.textContent = row.label;

                const value = document.createElement('dd');
                value.textContent = row.value;

                if (row.key === 'supported_until') {
                    value.classList.add('is-good');
                }

                if (row.key === 'domain') {
                    value.classList.add('is-mono');
                }

                details.append(term, value);
            });

            details.hidden = rows.length === 0;
        }

        verify.addEventListener('click', async function () {
            if (!code.checkValidity()) {
                code.reportValidity();
                code.focus();

                return;
            }

            setBusy(verify, true, labels.verifying);
            paintStatus(status, 'idle', labels.contacting);
            paintGuidance(guidance, []);

            try {
                const result = await postForm(config.routes.license);
                const body = result.body || {};

                if (body.ok === true) {
                    state.licenseOk = true;
                    state.licenseDetails = Array.isArray(body.details) ? body.details : [];
                    affix.classList.add('wz-affix--verified');
                    paintStatus(status, 'ok', verifiedSummary(state.licenseDetails));
                    paintDetails(state.licenseDetails);
                } else {
                    state.licenseOk = false;
                    paintStatus(status, 'error', validationMessage(body) || labels.failed);
                    paintGuidance(guidance, body.guidance);
                }
            } catch (error) {
                state.licenseOk = false;
                paintStatus(status, 'error', labels.network);
            } finally {
                setBusy(verify, false);

                if (state.licenseOk) {
                    verify.replaceChildren(icon('check', 14, 3), document.createTextNode(labels.verifiedButton));
                    verify.classList.add('wz-btn--success');
                    verify.disabled = true;
                }

                render();
            }
        });

        code.addEventListener('input', function () {
            if (state.licenseOk || status.classList.contains('is-error')) {
                reset();
            }
        });

        section.querySelector('[data-wz-paste]').addEventListener('click', async function () {
            try {
                const pasted = (await navigator.clipboard.readText()).trim();

                if (pasted !== '') {
                    code.value = pasted;
                    reset();
                }
            } catch (error) {
                // Clipboard read needs a secure context and permission.
            }

            code.focus();
        });

        screenHooks[SCREEN.LICENSE] = {
            blocked: function () {
                if (code.value.trim() === '') {
                    code.focus();
                } else {
                    verify.focus();
                }
            },
        };
    })();

    // ── 4 · Database ───────────────────────────────────────────────

    (function () {
        const section = root.querySelector('[data-wz-database]');

        if (!section) {
            return;
        }

        const drivers = Array.from(section.querySelectorAll('[data-wz-driver]'));
        const serverFields = Array.from(section.querySelectorAll('[data-wz-server-field]'));
        const nameInput = section.querySelector('[data-wz-db-name]');
        const nameLabel = section.querySelector('[data-wz-db-name-label]');
        const nameHelp = section.querySelector('[data-wz-db-name-help]');
        const status = section.querySelector('[data-wz-db-status]');
        const test = section.querySelector('[data-wz-db-test]');
        const guidance = section.querySelector('[data-wz-db-guidance]');
        const report = section.querySelector('[data-wz-db-report]');
        const reportTitle = section.querySelector('[data-wz-db-report-title]');
        const reportRows = section.querySelector('[data-wz-db-report-rows]');
        const labels = text.database || {};
        const testIdleHtml = test.innerHTML;
        const sqlitePattern = /[.]sqlite$/;
        const serverDefaultName = nameInput.value && !sqlitePattern.test(nameInput.value) ? nameInput.value : 'digikash';
        const sqliteDefaultName = 'database/database.sqlite';

        function driver() {
            const checked = drivers.find(function (radio) {
                return radio.checked;
            });

            return checked ? checked.value : 'mysql';
        }

        function reset() {
            state.databaseOk = false;
            state.databaseReport = null;
            test.innerHTML = testIdleHtml;
            test.disabled = false;
            test.classList.remove('wz-btn--success');
            paintStatus(status, 'idle', labels.idle);
            paintGuidance(guidance, []);
            report.hidden = true;
            render();
        }

        function syncDriver() {
            const sqlite = driver() === 'sqlite';

            serverFields.forEach(function (field) {
                field.hidden = sqlite;
                field.querySelectorAll('input').forEach(function (input) {
                    input.disabled = sqlite;
                });
            });

            if (sqlite && !sqlitePattern.test(nameInput.value)) {
                nameInput.value = sqliteDefaultName;
            } else if (!sqlite && sqlitePattern.test(nameInput.value)) {
                nameInput.value = serverDefaultName;
            }

            nameLabel.textContent = sqlite ? nameLabel.dataset.wzLabelSqlite : nameLabel.dataset.wzLabelServer;
            nameHelp.textContent = sqlite ? nameHelp.dataset.wzHelpSqlite : nameHelp.dataset.wzHelpServer;
        }

        function reportRow(row) {
            const tone = row.status === 'success' ? '' : (row.status === 'warning' ? ' is-warn' : ' is-bad');
            const item = document.createElement('div');
            item.className = 'wz-check' + (row.status === 'error' ? ' is-bad' : '');

            const badge = document.createElement('span');
            badge.className = 'wz-badge' + tone;
            badge.appendChild(icon(row.status === 'success' ? 'check' : (row.status === 'warning' ? 'alert' : 'cross'), 12, 3));

            const label = document.createElement('span');
            label.className = 'wz-check__label';
            label.textContent = row.label;

            const detail = document.createElement('code');
            detail.className = 'wz-chip';
            detail.textContent = row.detail;

            item.append(badge, label, detail);

            return item;
        }

        function engineVersion(version) {
            const names = {mysql: 'MySQL', mariadb: 'MariaDB', sqlite: 'SQLite'};
            const name = names[driver()] || '';

            return /[a-z]/i.test(version) ? version : (name + ' ' + version).trim();
        }

        function paintReport(body) {
            const rows = Array.isArray(body.checks) ? body.checks : [];

            report.hidden = rows.length === 0;

            if (rows.length === 0) {
                return;
            }

            reportTitle.textContent = body.server_version
                ? engineVersion(body.server_version) + (body.latency_ms ? ' · ' + body.latency_ms + ' ms' : '')
                : labels.report;
            reportRows.replaceChildren.apply(reportRows, rows.map(reportRow));
        }

        test.addEventListener('click', async function () {
            for (const field of section.querySelectorAll('[data-wz-db-field]')) {
                if (!field.disabled && !field.checkValidity()) {
                    field.reportValidity();
                    field.focus();

                    return;
                }
            }

            setBusy(test, true, labels.testing);
            paintStatus(status, 'idle', driver() === 'sqlite' ? labels.preparing : labels.connecting);
            paintGuidance(guidance, []);

            try {
                const result = await postForm(config.routes.database);
                const body = result.body || {};

                state.databaseOk = body.ok === true;
                state.databaseReport = body;
                paintReport(body);

                if (state.databaseOk) {
                    paintStatus(status, 'ok', labels.connected);
                } else {
                    paintStatus(status, body.status === 'warning' ? 'warn' : 'error', validationMessage(body) || labels.failed);
                    paintGuidance(guidance, body.guidance);
                }
            } catch (error) {
                state.databaseOk = false;
                paintStatus(status, 'error', labels.network);
            } finally {
                setBusy(test, false);

                if (state.databaseOk) {
                    test.replaceChildren(icon('check', 14, 3), document.createTextNode(labels.connectedButton));
                    test.classList.add('wz-btn--success');
                    test.disabled = true;
                }

                render();
            }
        });

        drivers.forEach(function (radio) {
            radio.addEventListener('change', function () {
                syncDriver();
                reset();
            });
        });

        section.querySelectorAll('[data-wz-db-field]').forEach(function (field) {
            field.addEventListener('input', function () {
                if (state.databaseOk || !report.hidden || !guidance.hidden || status.classList.contains('is-error')) {
                    reset();
                }
            });
        });

        syncDriver();

        screenHooks[SCREEN.DATABASE] = {
            blocked: function () {
                test.focus();
            },
        };
    })();

    // ── 5 · Super admin ────────────────────────────────────────────

    (function () {
        const section = root.querySelector('[data-wz-admin]');

        if (!section) {
            return;
        }

        const password = section.querySelector('[data-wz-password]');
        const confirmation = section.querySelector('[data-wz-password-confirm]');
        const reveal = section.querySelector('[data-wz-reveal]');
        const strength = section.querySelector('[data-wz-strength]');
        const strengthLabel = section.querySelector('[data-wz-strength-label]');
        const error = section.querySelector('[data-wz-password-error]');
        const labels = text.admin || {};

        function rules(value, repeat) {
            return {
                length: value.length >= 8,
                case: /[a-z]/.test(value) && /[A-Z]/.test(value),
                number: /[0-9]/.test(value),
                match: value.length > 0 && value === repeat,
            };
        }

        function level(value) {
            if (value === '') {
                return 0;
            }

            const met = rules(value, value);
            const required = met.length && met.case && met.number;

            if (!required) {
                return 1;
            }

            return 2 + (/[^A-Za-z0-9]/.test(value) ? 1 : 0) + (value.length >= 12 ? 1 : 0);
        }

        function paint() {
            const met = rules(password.value, confirmation.value);
            const score = level(password.value);

            Object.keys(met).forEach(function (key) {
                section.querySelector('[data-wz-rule="' + key + '"]').classList.toggle('is-met', met[key]);
            });

            strength.dataset.level = String(score);
            strengthLabel.textContent = password.value === '' ? labels.empty : (labels.levels || [])[score];
            error.hidden = true;
            confirmation.removeAttribute('aria-invalid');
        }

        function setVisible(visible) {
            password.type = visible ? 'text' : 'password';
            confirmation.type = visible ? 'text' : 'password';
            reveal.setAttribute('aria-pressed', visible ? 'true' : 'false');
            section.querySelector('[data-wz-reveal-label]').textContent = visible ? labels.hide : labels.show;
            section.querySelector('[data-wz-reveal-icon]').replaceChildren(icon(visible ? 'eyeOff' : 'eye', 13));
        }

        function generate() {
            const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*-_=+?'];
            const all = sets.join('');
            const bytes = new Uint32Array(16);
            window.crypto.getRandomValues(bytes);

            const chars = Array.from(bytes, function (value, index) {
                const pool = index < sets.length ? sets[index] : all;

                return pool[value % pool.length];
            });

            const order = new Uint32Array(chars.length);
            window.crypto.getRandomValues(order);

            for (let i = chars.length - 1; i > 0; i--) {
                const j = order[i] % (i + 1);
                const swap = chars[i];
                chars[i] = chars[j];
                chars[j] = swap;
            }

            return chars.join('');
        }

        password.addEventListener('input', paint);
        confirmation.addEventListener('input', paint);

        reveal.addEventListener('click', function () {
            setVisible(password.type === 'password');
        });

        section.querySelector('[data-wz-generate]').addEventListener('click', function () {
            const value = generate();
            password.value = value;
            confirmation.value = value;
            setVisible(true);
            paint();
            password.focus();
        });

        paint();

        screenHooks[SCREEN.ADMIN] = {
            validate: function () {
                const met = rules(password.value, confirmation.value);

                if (!(met.length && met.case && met.number)) {
                    error.textContent = labels.weak;
                    error.hidden = false;
                    password.focus();

                    return false;
                }

                if (!met.match) {
                    error.textContent = labels.mismatch;
                    error.hidden = false;
                    confirmation.setAttribute('aria-invalid', 'true');
                    confirmation.focus();

                    return false;
                }

                return true;
            },
        };
    })();

    // ── 6 · Email ──────────────────────────────────────────────────

    (function () {
        const section = root.querySelector('[data-wz-email]');

        if (!section) {
            return;
        }

        const providers = Array.from(section.querySelectorAll('[data-wz-provider]'));
        const fields = Array.from(section.querySelectorAll('[data-wz-mail-field]'));
        const host = section.querySelector('[data-wz-mail-host]');
        const port = section.querySelector('[data-wz-mail-port]');
        const secure = section.querySelector('[data-wz-mail-secure]');
        const fromName = section.querySelector('[data-wz-mail-from-name]');
        const recipient = section.querySelector('[data-wz-mail-recipient]');
        const hint = section.querySelector('[data-wz-provider-hint]');
        const skipButton = section.querySelector('[data-wz-mail-skip]');
        const skipInput = section.querySelector('[data-wz-mail-skip-input]');
        const skippedBanner = section.querySelector('[data-wz-mail-skipped]');
        const test = section.querySelector('[data-wz-mail-test]');
        const status = section.querySelector('[data-wz-mail-status]');
        const guidance = section.querySelector('[data-wz-mail-guidance]');
        const presets = config.mailProviders || {};
        const labels = text.email || {};
        const testIdleHtml = test.innerHTML;
        const portFor = {tls: '587', ssl: '465'};

        state.mailProvider = 'custom';

        function reset() {
            state.smtpOk = false;
            test.innerHTML = testIdleHtml;
            test.disabled = state.smtpSkipped;
            test.classList.remove('wz-btn--success');
            status.hidden = true;
            paintGuidance(guidance, []);
            render();
        }

        function selectProvider(id, fill) {
            const preset = presets[id];

            if (!preset) {
                return;
            }

            state.mailProvider = id;

            providers.forEach(function (button) {
                const active = button.dataset.wzProvider === id;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });

            if (fill) {
                host.value = preset.host || ('mail.' + config.domain);
                port.value = String(preset.port);
                secure.value = preset.encryption;
            }

            hint.hidden = !preset.hint;
            section.querySelector('[data-wz-provider-hint-text]').textContent = preset.hint || '';
        }

        function setSkipped(skipped) {
            state.smtpSkipped = skipped;
            skipInput.value = skipped ? '1' : '0';
            section.classList.toggle('is-mail-skipped', skipped);
            skippedBanner.hidden = !skipped;
            skipButton.setAttribute('aria-pressed', skipped ? 'true' : 'false');
            section.querySelector('[data-wz-mail-skip-label]').textContent = skipped ? labels.configure : labels.skip;
            section.querySelector('[data-wz-mail-skip-icon]').replaceChildren(icon(skipped ? 'settings' : 'skip', 14));

            fields.forEach(function (field) {
                field.disabled = skipped;
            });

            reset();
        }

        providers.forEach(function (button) {
            button.addEventListener('click', function () {
                selectProvider(button.dataset.wzProvider, true);
                reset();
                host.focus();
            });
        });

        secure.addEventListener('change', function () {
            if (port.value === '' || Object.values(portFor).includes(port.value)) {
                port.value = portFor[secure.value] || port.value;
            }
        });

        fields.forEach(function (field) {
            field.addEventListener('input', function () {
                if (state.smtpOk || !status.hidden) {
                    reset();
                }
            });
        });

        skipButton.addEventListener('click', function () {
            setSkipped(!state.smtpSkipped);
        });

        test.addEventListener('click', async function () {
            for (const field of fields) {
                if (!field.checkValidity()) {
                    field.reportValidity();
                    field.focus();

                    return;
                }
            }

            setBusy(test, true, labels.sending);
            status.hidden = false;
            paintStatus(status, 'idle', labels.signingIn);
            paintGuidance(guidance, []);

            try {
                const result = await postForm(config.routes.smtp);
                const body = result.body || {};

                state.smtpOk = body.ok === true;

                if (state.smtpOk) {
                    paintStatus(status, 'ok', format(labels.sent, {email: recipient.value}));
                } else {
                    paintStatus(status, 'error', validationMessage(body) || labels.failed);
                    paintGuidance(guidance, body.guidance);
                }
            } catch (error) {
                state.smtpOk = false;
                paintStatus(status, 'error', labels.network);
            } finally {
                setBusy(test, false);

                if (state.smtpOk) {
                    test.replaceChildren(icon('check', 14, 3), document.createTextNode(labels.sentButton));
                    test.classList.add('wz-btn--success');
                    test.disabled = true;
                }

                render();
            }
        });

        if (host.value === '') {
            selectProvider('custom', true);
        } else {
            selectProvider('custom', false);
        }

        screenHooks[SCREEN.EMAIL] = {
            enter: function () {
                const appName = document.getElementById('app_name');
                const adminEmail = document.getElementById('admin_email');

                if (fromName.value.trim() === '' && appName) {
                    fromName.value = appName.value.trim().slice(0, 50);
                }

                if (recipient.value.trim() === '' && adminEmail) {
                    recipient.value = adminEmail.value.trim();
                }
            },
            blocked: function () {
                test.focus();
            },
        };
    })();

    // ── 8 · Review ─────────────────────────────────────────────────

    (function () {
        const section = root.querySelector('[data-wz-review]');

        if (!section) {
            return;
        }

        const labels = text.review || {};

        function value(id) {
            const field = document.getElementById(id);

            return field ? field.value.trim() : '';
        }

        function checked(name) {
            const box = root.querySelector('[data-wz-option="' + name + '"]');

            return !!(box && box.checked);
        }

        function rows(card, entries) {
            const list = section.querySelector('[data-wz-summary="' + card + '"] [data-wz-summary-rows]');

            list.replaceChildren.apply(list, entries.filter(Boolean).map(function (entry) {
                const row = document.createElement('div');
                row.className = 'wz-summary__row';

                const term = document.createElement('dt');
                term.textContent = entry[0];

                const detail = document.createElement('dd');
                detail.textContent = entry[1] || '—';

                if (entry[2]) {
                    detail.classList.add('is-mono');
                }

                row.append(term, detail);

                return row;
            }));
        }

        function ready(key, ok, text) {
            const tile = section.querySelector('[data-wz-ready="' + key + '"]');
            tile.classList.toggle('is-pending', !ok);
            tile.querySelector('[data-wz-ready-value]').textContent = text;
        }

        function licenseType() {
            const row = (state.licenseDetails || []).find(function (detail) {
                return detail.key === 'license_type';
            });

            return row ? row.value : '';
        }

        function paint() {
            const driver = (root.querySelector('[data-wz-driver]:checked') || {}).value || 'mysql';
            const sqlite = driver === 'sqlite';
            const loginUrl = value('wz-login-url-full');
            const provider = (config.mailProviders || {})[state.mailProvider] || {};
            const fromName = value('email_from_name');
            const fromAddress = value('email_from_address');
            const version = state.databaseReport && state.databaseReport.server_version;

            ready('server', state.serverOk, state.serverOk ? labels.serverOk : labels.serverBad);
            ready('license', state.licenseOk, state.licenseOk ? [labels.verified, licenseType()].filter(Boolean).join(' · ') : labels.notVerified);
            ready('database', state.databaseOk, state.databaseOk ? (version ? labels.connected + ' · ' + version : labels.connected) : labels.notTested);
            ready('email', state.smtpOk, state.smtpSkipped ? labels.skipped : (state.smtpOk ? labels.testSent : labels.notTested));

            rows('application', [
                [labels.name, value('app_name')],
                [labels.url, value('app_url'), true],
                [labels.adminLogin, loginUrl.replace(/^https?:[/][/][^/]+/, ''), true],
                [labels.locale, [value('default_currency_code'), value('site_timezone')].filter(Boolean).join(' · ')],
            ]);

            rows('database', [
                [labels.driver, selectedDriverLabel(driver)],
                sqlite ? null : [labels.host, value('db_host') + ':' + value('db_port'), true],
                [sqlite ? labels.file : labels.database, value('db_database'), true],
                sqlite ? null : [labels.user, value('db_username'), true],
            ]);

            rows('admin', [
                [labels.name, value('admin_name')],
                [labels.email, value('admin_email')],
            ]);

            rows('email', state.smtpSkipped
                ? [[labels.status, labels.skippedLong]]
                : [
                    [labels.provider, provider.label || ''],
                    [labels.host, value('mail_host') + ':' + value('mail_port'), true],
                    [labels.from, fromName ? fromName + ' <' + fromAddress + '>' : fromAddress],
                ]);

            rows('options', [
                [labels.import, sqlite ? labels.importSqlite : labels.importDump],
                [labels.demo, checked('seed_demo_data') ? labels.yes : labels.no],
                [labels.runtime, [
                    checked('queue_connection') ? labels.queueDatabase : labels.queueSync,
                    checked('optimize_caches') ? labels.cacheOn : labels.cacheOff,
                    checked('pwa_enabled') ? labels.pwaOn : labels.pwaOff,
                ].join(' · ')],
            ]);
        }

        function selectedDriverLabel(driver) {
            const option = root.querySelector('[data-wz-driver][value="' + driver + '"]');

            return option ? option.nextElementSibling.textContent.trim() : driver;
        }

        section.querySelectorAll('[data-wz-edit]').forEach(function (button) {
            button.addEventListener('click', function () {
                goTo(Number(button.dataset.wzEdit));
            });
        });

        screenHooks[SCREEN.REVIEW] = {
            enter: paint,
        };
    })();

    // ── 9 · Installing ─────────────────────────────────────────────

    (function () {
        const section = root.querySelector('[data-wz-installing]');

        if (!section) {
            return;
        }

        const groups = config.stageGroups || [];
        const labels = text.install || {};
        const head = section.querySelector('.wz-installing-head');
        const title = section.querySelector('[data-wz-install-title]');
        const lead = section.querySelector('[data-wz-install-lead]');
        const stageLabel = section.querySelector('[data-wz-install-stage]');
        const percentLabel = section.querySelector('[data-wz-install-percent]');
        const bar = section.querySelector('[data-wz-install-bar]');
        const fill = section.querySelector('[data-wz-install-fill]');
        const logBox = section.querySelector('.wz-log');
        const logBody = section.querySelector('[data-wz-log]');
        const logLines = section.querySelector('[data-wz-log-lines]');
        const errorPanel = section.querySelector('[data-wz-install-error]');
        const fixButton = section.querySelector('[data-wz-install-fix]');
        let timer = null;
        let percent = 0;
        let simulated = 0;
        let lines = [];

        function stamp() {
            const now = new Date();
            const pad = function (value) {
                return String(value).padStart(2, '0');
            };

            return '[' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds()) + ']';
        }

        function log(line) {
            lines.push(line);

            const row = document.createElement('span');
            row.className = 'wz-log__line';

            if (line.indexOf('… failed') !== -1) {
                row.classList.add('is-error');
            } else if (line.indexOf('… ok') !== -1) {
                row.classList.add('is-ok');
            }

            row.textContent = line + '\n';
            logLines.appendChild(row);
            logBody.scrollTop = logBody.scrollHeight;
        }

        function setPercent(value) {
            percent = Math.max(0, Math.min(100, Math.round(value)));
            fill.style.width = percent + '%';
            percentLabel.textContent = percent + '%';
            bar.setAttribute('aria-valuenow', String(percent));
        }

        function paintStages(statuses) {
            groups.forEach(function (group) {
                const tile = section.querySelector('[data-wz-stage="' + group + '"]');

                if (!tile) {
                    return;
                }

                const status = statuses[group] || 'pending';
                tile.classList.toggle('is-done', status === 'done');
                tile.classList.toggle('is-current', status === 'current');
                tile.classList.toggle('is-failed', status === 'failed');
            });
        }

        function stageTitle(group) {
            const tile = section.querySelector('[data-wz-stage="' + group + '"] strong');

            return tile ? tile.textContent : group;
        }

        function simulatedStatuses() {
            const statuses = {};

            groups.forEach(function (group, index) {
                statuses[group] = index < simulated ? 'done' : (index === simulated ? 'current' : 'pending');
            });

            return statuses;
        }

        function tick() {
            setPercent(Math.min(94, percent + (percent < 60 ? 4 : 2)));

            if (percent >= (simulated + 1) * 15 && simulated < groups.length - 2) {
                simulated += 1;
            }

            paintStages(simulatedStatuses());
            stageLabel.textContent = stageTitle(groups[simulated]);
        }

        function reportStatuses(stages) {
            const statuses = {};

            groups.forEach(function (group) {
                const own = stages.filter(function (stage) {
                    return stage.group === group;
                });

                if (own.some(function (stage) {
                    return stage.status === 'failed';
                })) {
                    statuses[group] = 'failed';
                } else if (own.length > 0) {
                    statuses[group] = 'done';
                }
            });

            return statuses;
        }

        function resetScreen() {
            lines = [];
            logLines.replaceChildren();
            logBox.classList.remove('is-finished');
            errorPanel.hidden = true;
            fixButton.hidden = true;
            head.classList.remove('is-failed', 'is-done');
            title.textContent = labels.title;
            lead.textContent = labels.lead;
            simulated = 0;
            setPercent(4);
            paintStages(simulatedStatuses());
            stageLabel.textContent = stageTitle(groups[0]);
        }

        function download(filename, body) {
            const link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([body], {type: 'text/plain'}));
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(function () {
                URL.revokeObjectURL(link.href);
            }, 1000);
        }

        function fieldScreen(name) {
            const field = form.querySelector('[name="' + name + '"]');
            const screen = field ? field.closest('[data-wz-screen]') : null;

            return screen ? Number(screen.dataset.wzScreen) : null;
        }

        function fail(body) {
            const data = body.data || {};
            const stages = Array.isArray(data.stages) ? data.stages : [];
            const statuses = stages.length ? reportStatuses(stages) : simulatedStatuses();

            if (!stages.length) {
                statuses[groups[simulated]] = 'failed';
            }

            (data.log || []).forEach(log);
            log(stamp() + ' ' + labels.stopped);

            paintStages(statuses);
            logBox.classList.add('is-finished');
            head.classList.add('is-failed');
            title.textContent = labels.failedTitle;
            lead.textContent = labels.failedLead;
            stageLabel.textContent = labels.stopped;

            section.querySelector('[data-wz-install-error-text]').textContent = validationMessage(body) || labels.failed;
            paintGuidance(section.querySelector('[data-wz-install-guidance]'), body.guidance);

            const firstField = body.errors ? Object.keys(body.errors)[0] : null;
            const target = firstField ? fieldScreen(firstField) : null;

            fixButton.hidden = target === null;
            fixButton.dataset.wzTarget = target === null ? '' : String(target);
            errorPanel.hidden = false;

            state.installing = false;
            render();
            errorPanel.scrollIntoView({behavior: 'smooth', block: 'center'});
        }

        function finish(data) {
            paintStages(Object.fromEntries(groups.map(function (group) {
                return [group, 'done'];
            })));
            setPercent(100);
            stageLabel.textContent = labels.done;
            (data.log || []).forEach(log);
            log(stamp() + ' ' + labels.locked);
            logBox.classList.add('is-finished');
            head.classList.add('is-done');
            title.textContent = labels.doneTitle;

            state.installing = false;
            state.installResult = data;
            paintSuccess(data, lines.slice());

            window.setTimeout(function () {
                goTo(SCREEN.SUCCESS, {force: true});
            }, 1200);
        }

        async function start() {
            if (state.installing) {
                return;
            }

            state.installing = true;
            goTo(SCREEN.INSTALLING, {force: true});
            resetScreen();
            log(stamp() + ' ' + labels.sending);
            timer = window.setInterval(tick, 900);

            let result;

            try {
                result = await postForm(config.routes.install);
            } catch (error) {
                result = {status: 0, body: {ok: false, message: labels.network}};
            } finally {
                window.clearInterval(timer);
            }

            if (result.body && result.body.ok === true && result.body.data) {
                finish(result.body.data);
            } else {
                fail(result.body || {});
            }
        }

        fixButton.addEventListener('click', function () {
            goTo(Number(fixButton.dataset.wzTarget));
        });

        section.querySelector('[data-wz-install-review]').addEventListener('click', function () {
            goTo(SCREEN.REVIEW);
        });

        section.querySelector('[data-wz-install-retry]').addEventListener('click', start);

        section.querySelector('[data-wz-log-download]').addEventListener('click', function () {
            download('digikash-install-log.txt', lines.join('\n') + '\n');
        });

        screenHooks[SCREEN.INSTALLING] = {start: start};

        // ── 10 · Success ───────────────────────────────────────────

        function paintSuccess(data, logSnapshot) {
            const screen = root.querySelector('[data-wz-success]');
            const summary = data.summary || {};
            const stats = [format(labels.statsTime, {seconds: summary.duration_s || 0})];

            if (summary.tables_count) {
                stats.push(format(labels.statsTables, {count: summary.tables_count}));
            }

            if (summary.seeders_count) {
                stats.push(format(labels.statsSeeders, {count: summary.seeders_count}));
            }

            screen.querySelector('[data-wz-success-stats]').textContent = stats.join(' · ') + '. ';
            screen.querySelector('[data-wz-success-admin]').href = data.admin_url;
            screen.querySelector('[data-wz-success-login]').textContent = String(data.admin_url || '').replace(/^https?:[/][/]/, '');
            screen.querySelector('[data-wz-success-email]').textContent = data.admin_email || '';

            const security = screen.querySelector('[data-wz-security]');
            const problems = [];

            if (summary.env_exposed === true) {
                problems.push(labels.envExposed);
            }

            if (summary.sql_dump_present) {
                problems.push(labels.dumpPresent);
            }

            security.classList.toggle('is-danger', problems.length > 0);
            security.classList.toggle('is-warn', problems.length === 0 && summary.env_exposed === null);
            screen.querySelector('[data-wz-security-text]').textContent = problems.length
                ? labels.securityPrefix + ' ' + problems.join(' ')
                : (summary.env_exposed === null ? labels.envUnknown : labels.securityOk);

            screen.querySelector('[data-wz-report-download]').onclick = function () {
                download('digikash-install-report.txt', [
                    'DigiKash installation report',
                    'Installed: ' + new Date().toISOString(),
                    'Site: ' + config.appUrl,
                    'Admin login: ' + data.admin_url,
                    'Admin email: ' + data.admin_email,
                    'Duration: ' + (summary.duration_s || 0) + ' s',
                    'Tables: ' + (summary.tables_count || 0),
                    'SQL dump still on the server: ' + (summary.sql_dump_present ? 'yes - delete DB/digikash.sql' : 'no'),
                    '.env readable over HTTP: ' + (summary.env_exposed === null ? 'could not check' : (summary.env_exposed ? 'YES - block it now' : 'no')),
                    '',
                    'Log:',
                ].concat(logSnapshot).join('\n') + '\n');
            };
        }
    })();

    // ── Keyboard and leave guard ────────────────────────────────────

    form.addEventListener('keydown', function (event) {
        const target = event.target;

        if (event.key !== 'Enter' || event.isComposing || !(target instanceof HTMLInputElement)) {
            return;
        }

        if (['button', 'submit', 'checkbox', 'radio'].includes(target.type)) {
            return;
        }

        event.preventDefault();

        const scope = target.closest('[data-wz-enter]');
        const action = scope ? root.querySelector(scope.dataset.wzEnter) : null;

        if (action && !action.disabled) {
            action.click();

            return;
        }

        next();
    });

    window.addEventListener('beforeunload', function (event) {
        if (state.installing) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    /* @wz-screens */

    render();
})();
