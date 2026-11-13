"use strict";

/*
 * Add new user dialog (Admin → Users).
 *
 * Progressive: every control posts as a plain form field, so the dialog
 * submits fine without this file. It only adds the touches the fields
 * cannot do alone — the dial code following the country, the avatar frame
 * knowing it holds a picture (helpers.js paints the preview itself), the
 * password toggles, generator and strength read-out, the status pill, and
 * reopening the dialog after a failed post so the errors are seen.
 */
(function () {
    var modal = document.getElementById('new_user_modal');

    if (!modal) {
        return;
    }

    var country = modal.querySelector('[data-nu-country]');
    var dialCode = modal.querySelector('[data-nu-dial-code]');
    var avatarInput = modal.querySelector('[data-nu-avatar]');
    var avatarPreview = modal.querySelector('[data-nu-avatar-preview]');
    var password = modal.querySelector('[data-nu-password]');
    var confirm = modal.querySelector('[data-nu-confirm]');
    var meter = modal.querySelector('[data-nu-meter]');
    var strength = modal.querySelector('[data-nu-strength]');
    var match = modal.querySelector('[data-nu-match]');
    var status = modal.querySelector('[data-nu-status]');
    var statusPill = modal.querySelector('[data-nu-status-pill]');
    var generate = modal.querySelector('[data-nu-generate]');

    var label = function (element, name) {
        return element.getAttribute('data-label-' + name) || '';
    };

    /* ── Country → dial code ─────────────────────────────────────────── */

    var syncDialCode = function () {
        if (!country || !dialCode) {
            return;
        }

        var option = country.options[country.selectedIndex];

        dialCode.textContent = option ? String(option.getAttribute('data-dial-code') || '') : '';
    };

    /* ── Avatar frame ─────────────────────────────────────────────────── */

    var syncAvatar = function () {
        if (!avatarInput || !avatarPreview) {
            return;
        }

        avatarPreview.classList.toggle('is-filled', !!(avatarInput.files && avatarInput.files.length > 0));
    };

    /* ── Password strength and confirmation ──────────────────────────── */

    /*
     * Four cheap checks, one bar each. This is a nudge for the admin
     * typing on someone's behalf, not the server's rule: the store method
     * only requires the two fields to match.
     */
    var score = function (value) {
        if (value.length === 0) {
            return 0;
        }

        var points = 0;

        if (value.length >= 8) {
            points += 1;
        }

        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) {
            points += 1;
        }

        if (/[0-9]/.test(value)) {
            points += 1;
        }

        if (/[^A-Za-z0-9]/.test(value)) {
            points += 1;
        }

        return Math.max(1, points);
    };

    var syncStrength = function () {
        if (!password || !meter || !strength) {
            return;
        }

        var value = password.value;
        var points = score(value);
        var names = ['', 'weak', 'fair', 'good', 'strong'];

        meter.setAttribute('data-score', String(points));
        strength.setAttribute('data-score', String(points));

        if (points === 0) {
            strength.textContent = label(strength, 'empty');

            return;
        }

        strength.textContent = (label(strength, 'summary') || ':strength')
            .replace(':strength', label(strength, names[points]))
            .replace(':count', String(value.length));
    };

    var syncMatch = function () {
        if (!password || !confirm || !match) {
            return;
        }

        var value = confirm.value;

        match.classList.remove('is-ok', 'is-bad');

        if (value.length === 0) {
            match.textContent = label(match, 'empty');

            return;
        }

        var same = value === password.value;

        match.classList.add(same ? 'is-ok' : 'is-bad');
        match.textContent = label(match, same ? 'match' : 'mismatch');
    };

    var setVisible = function (button, visible) {
        var target = document.getElementById(button.getAttribute('data-nu-toggle') || '');

        if (!target) {
            return;
        }

        target.type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', visible ? 'true' : 'false');
        button.setAttribute('aria-label', label(button, visible ? 'hide' : 'show'));
    };

    var toggleVisible = function (button) {
        var target = document.getElementById(button.getAttribute('data-nu-toggle') || '');

        setVisible(button, !!target && target.type === 'password');
    };

    /*
     * No look-alikes (0/O, 1/l/I) because the admin will read this one out
     * or paste it into a message. Loops until every bar lights, which the
     * 61-character set reaches almost always on the first draw.
     */
    var generatePassword = function () {
        var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%&*';
        var out = '';
        var attempts = 0;

        do {
            var draw = new Uint32Array(14);

            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(draw);
            } else {
                for (var j = 0; j < draw.length; j++) {
                    draw[j] = Math.floor(Math.random() * 4294967296);
                }
            }

            out = '';

            for (var i = 0; i < draw.length; i++) {
                out += chars.charAt(draw[i] % chars.length);
            }

            attempts += 1;
        } while (score(out) < 4 && attempts < 10);

        return out;
    };

    /* ── Status pill ──────────────────────────────────────────────────── */

    var syncStatus = function () {
        if (!status || !statusPill) {
            return;
        }

        statusPill.classList.toggle('is-on', status.checked);
        statusPill.textContent = label(statusPill, status.checked ? 'on' : 'off');
    };

    /* ── Wire up ──────────────────────────────────────────────────────── */

    if (country) {
        country.addEventListener('change', syncDialCode);
        syncDialCode();
    }

    if (avatarInput) {
        avatarInput.addEventListener('change', syncAvatar);
        syncAvatar();
    }

    if (password) {
        password.addEventListener('input', function () {
            syncStrength();
            syncMatch();
        });
        syncStrength();
    }

    if (confirm) {
        confirm.addEventListener('input', syncMatch);
        syncMatch();
    }

    Array.prototype.forEach.call(modal.querySelectorAll('[data-nu-toggle]'), function (button) {
        button.addEventListener('click', function () {
            toggleVisible(button);
        });
    });

    if (generate && password && confirm) {
        generate.addEventListener('click', function () {
            var value = generatePassword();

            password.value = value;
            confirm.value = value;

            Array.prototype.forEach.call(modal.querySelectorAll('[data-nu-toggle]'), function (button) {
                setVisible(button, true);
            });

            syncStrength();
            syncMatch();
            password.focus();
        });
    }

    if (status) {
        status.addEventListener('change', syncStatus);
        syncStatus();
    }

    /*
     * A failed post reloads the list with the dialog closed and the errors
     * rendered inside it. The blade marks the modal when the failed form
     * was this one; reopen it so the admin sees what to fix.
     */
    if (modal.getAttribute('data-nu-reopen') === '1' && window.coreui && window.coreui.Modal) {
        window.coreui.Modal.getOrCreateInstance(modal).show();
    }
})();
