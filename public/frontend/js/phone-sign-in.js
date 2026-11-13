"use strict";

/*
 * Phone-first sign-in: the phone field's country picker, the Phone / Email
 * tabs on the sign-in form, the six code boxes and resend countdown on the
 * SMS-code page, and the "Where do you live?" choice on sign-up.
 *
 * Every piece reads its own markup and does nothing when that markup is not
 * on the page, so one file serves the classic and Golden themes alike. The
 * server renders a working form without this script: the inactive tab's
 * inputs are already disabled, the code box is an ordinary input, and the
 * country select is visible.
 */
(function () {
    /* ----- 1. Phone field: country picker ----- */
    function initPhoneField(field) {
        var toggle = field.querySelector('[data-psf-toggle]');
        var menu = field.querySelector('[data-psf-menu]');
        var search = field.querySelector('[data-psf-search]');
        var input = field.querySelector('.psf__input');
        var hidden = field.querySelector('[data-psf-country]');
        var codeEl = toggle ? toggle.querySelector('[data-psf-code]') : null;
        var dialEl = toggle ? toggle.querySelector('[data-psf-dial]') : null;
        var hintEl = field.querySelector('[data-psf-hint-text]');
        var pillLocal = field.querySelector('[data-psf-pill="local"]');
        var pillAway = field.querySelector('[data-psf-pill="away"]');
        var empty = field.querySelector('[data-psf-empty]');
        var options = Array.prototype.slice.call(field.querySelectorAll('[data-psf-option]'));
        var groups = Array.prototype.slice.call(field.querySelectorAll('[data-psf-group]'));
        var home = field.getAttribute('data-home') || '';

        if (!toggle || !menu || !hidden) {
            return;
        }

        function isOpen() {
            return !menu.hidden;
        }

        function visibleOptions() {
            return options.filter(function (option) {
                return !option.hidden;
            });
        }

        function open() {
            if (toggle.disabled) {
                return;
            }
            menu.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            if (search) {
                search.value = '';
                filter('');
                search.focus();
            }
            var selected = field.querySelector('.psf__option.is-selected');
            if (selected) {
                selected.scrollIntoView({ block: 'nearest' });
            }
        }

        function close(returnFocus) {
            menu.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            if (returnFocus) {
                toggle.focus();
            }
        }

        function filter(term) {
            var query = term.trim().toLowerCase().replace(/^\+/, '');
            var shown = 0;

            options.forEach(function (option) {
                var haystack = [
                    option.getAttribute('data-name') || '',
                    option.getAttribute('data-code') || '',
                    (option.getAttribute('data-dial') || '').replace('+', '')
                ].join(' ').toLowerCase();
                var match = query === '' || haystack.indexOf(query) !== -1;
                option.hidden = !match;
                shown += match ? 1 : 0;
            });

            groups.forEach(function (group) {
                group.hidden = query !== '';
            });

            if (empty) {
                empty.hidden = shown !== 0;
            }
        }

        function pick(option) {
            var code = option.getAttribute('data-code') || '';
            var dial = option.getAttribute('data-dial') || '';
            var name = option.getAttribute('data-name') || code;
            var local = home !== '' && code === home;

            hidden.value = code;
            if (codeEl) {
                codeEl.textContent = code;
            }
            if (dialEl) {
                dialEl.textContent = dial;
            }
            toggle.setAttribute('aria-label', toggle.getAttribute('aria-label').replace(/:\s.*\./, ': ' + name + '.'));

            options.forEach(function (other) {
                var on = other === option;
                other.classList.toggle('is-selected', on);
                other.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            if (pillLocal) {
                pillLocal.hidden = !local;
            }
            if (pillAway) {
                pillAway.hidden = local;
            }
            if (hintEl) {
                var template = field.getAttribute(local ? 'data-hint-home' : 'data-hint-away') || '';
                hintEl.textContent = template.replace('__DIAL__', dial);
            }

            close(false);
            if (input) {
                input.focus();
            }
        }

        toggle.addEventListener('click', function () {
            isOpen() ? close(true) : open();
        });

        options.forEach(function (option) {
            option.addEventListener('click', function () {
                pick(option);
            });
        });

        if (search) {
            search.addEventListener('input', function () {
                filter(search.value);
            });
        }

        menu.addEventListener('keydown', function (event) {
            var list = visibleOptions();
            var index = list.indexOf(document.activeElement);

            if (event.key === 'Escape') {
                event.preventDefault();
                close(true);
            } else if (event.key === 'ArrowDown') {
                event.preventDefault();
                (list[index + 1] || list[0] || search).focus();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (index <= 0) {
                    (search || list[0]).focus();
                } else {
                    list[index - 1].focus();
                }
            } else if (event.key === 'Enter' && document.activeElement === search && list.length > 0) {
                event.preventDefault();
                pick(list[0]);
            }
        });

        document.addEventListener('click', function (event) {
            if (isOpen() && !field.contains(event.target)) {
                close(false);
            }
        });
    }

    /* ----- 2. Sign-in form: Phone / Email tabs ----- */
    function initSignInTabs(tabs) {
        var form = tabs.closest('form') || document;
        var buttons = Array.prototype.slice.call(tabs.querySelectorAll('[data-signin-tab]'));
        var panels = Array.prototype.slice.call(form.querySelectorAll('[data-signin-panel]'));

        function show(name, focus) {
            buttons.forEach(function (button) {
                var on = button.getAttribute('data-signin-tab') === name;
                button.setAttribute('aria-selected', on ? 'true' : 'false');
                button.classList.toggle('is-active', on);
                button.tabIndex = on ? 0 : -1;
            });

            panels.forEach(function (panel) {
                var on = panel.getAttribute('data-signin-panel') === name;
                panel.hidden = !on;
                Array.prototype.forEach.call(panel.querySelectorAll('input, button, select'), function (control) {
                    control.disabled = !on;
                });
            });

            if (focus) {
                var first = form.querySelector('[data-signin-panel="' + name + '"] input:not([type="hidden"])');
                if (first) {
                    first.focus();
                }
            }
        }

        buttons.forEach(function (button, index) {
            button.addEventListener('click', function () {
                show(button.getAttribute('data-signin-tab'), true);
            });
            button.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
                    return;
                }
                event.preventDefault();
                var next = buttons[(index + (event.key === 'ArrowRight' ? 1 : buttons.length - 1)) % buttons.length];
                next.focus();
                next.click();
            });
        });
    }

    /* ----- 3. SMS code: six boxes over the one real input ----- */
    function initCodeBoxes(wrap) {
        var real = wrap.querySelector('input[name="code"]');
        var length = parseInt(wrap.getAttribute('data-otp-length') || '6', 10);

        if (!real || length < 1) {
            return;
        }

        var row = document.createElement('div');
        row.className = 'psf-code__boxes';
        var boxes = [];

        for (var i = 0; i < length; i++) {
            var box = document.createElement('input');
            box.type = 'text';
            box.inputMode = 'numeric';
            box.maxLength = 1;
            box.className = 'psf-code__box';
            box.autocomplete = i === 0 ? 'one-time-code' : 'off';
            box.setAttribute('aria-label', (wrap.getAttribute('data-otp-digit-label') || 'Digit :n').replace(':n', String(i + 1)));
            row.appendChild(box);
            boxes.push(box);
        }

        function sync() {
            real.value = boxes.map(function (box) {
                return box.value;
            }).join('');
        }

        function spread(digits, from) {
            digits.split('').forEach(function (digit, offset) {
                if (boxes[from + offset]) {
                    boxes[from + offset].value = digit;
                }
            });
            sync();
            var next = boxes[Math.min(from + digits.length, boxes.length - 1)];
            next.focus();
        }

        boxes.forEach(function (box, index) {
            box.addEventListener('input', function () {
                var digits = box.value.replace(/\D+/g, '');
                box.value = '';
                if (digits !== '') {
                    spread(digits, index);
                } else {
                    sync();
                }
            });
            box.addEventListener('keydown', function (event) {
                if (event.key === 'Backspace' && box.value === '' && index > 0) {
                    boxes[index - 1].value = '';
                    boxes[index - 1].focus();
                    sync();
                } else if (event.key === 'ArrowLeft' && index > 0) {
                    boxes[index - 1].focus();
                } else if (event.key === 'ArrowRight' && index < boxes.length - 1) {
                    boxes[index + 1].focus();
                }
            });
            box.addEventListener('paste', function (event) {
                var text = (event.clipboardData || window.clipboardData).getData('text') || '';
                var digits = text.replace(/\D+/g, '').slice(0, length);
                if (digits !== '') {
                    event.preventDefault();
                    spread(digits, 0);
                }
            });
        });

        real.type = 'hidden';
        real.removeAttribute('autofocus');
        wrap.classList.add('is-enhanced');
        wrap.appendChild(row);
        boxes[0].focus();
    }

    /* ----- 4. SMS code: resend countdown ----- */
    function initCountdown(clock) {
        var seconds = parseInt(clock.getAttribute('data-otp-countdown') || '0', 10);
        var time = clock.querySelector('[data-otp-time]');
        var waiting = clock.querySelector('[data-otp-waiting]');
        var ready = clock.querySelector('[data-otp-ready]');

        function render() {
            if (time) {
                var minutes = Math.floor(seconds / 60);
                var rest = seconds % 60;
                time.textContent = minutes + ':' + (rest < 10 ? '0' : '') + rest;
            }
            if (waiting) {
                waiting.hidden = seconds <= 0;
            }
            if (ready) {
                ready.hidden = seconds > 0;
            }
        }

        render();
        var timer = window.setInterval(function () {
            seconds -= 1;
            render();
            if (seconds <= 0) {
                window.clearInterval(timer);
            }
        }, 1000);
    }

    /* ----- 5. Sign-up: where do you live? ----- */
    function initResidence(group) {
        var form = group.closest('form') || document;
        var select = form.querySelector('[data-residence-select]');
        var picker = form.querySelector('[data-residence-picker]');
        var radios = Array.prototype.slice.call(group.querySelectorAll('input[type="radio"]'));
        var home = group.getAttribute('data-home') || '';

        if (!select || radios.length === 0) {
            return;
        }

        function apply(value) {
            var away = value === 'away';

            radios.forEach(function (radio) {
                var card = radio.closest('[data-residence-card]');
                if (card) {
                    card.classList.toggle('is-selected', radio.checked);
                }
            });

            if (picker) {
                picker.hidden = !away;
            }

            if (!away) {
                select.value = home;
            } else if (select.value === home) {
                select.value = '';
            }

            select.dispatchEvent(new Event('change', { bubbles: true }));
            form.dispatchEvent(new CustomEvent('residence:change', { detail: { away: away } }));
        }

        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (radio.checked) {
                    apply(radio.value);
                }
            });
        });

        var checked = radios.filter(function (radio) {
            return radio.checked;
        })[0];
        if (checked) {
            apply(checked.value);
        }
    }

    /* ----- 6. Sign-up: the residence note follows the choice ----- */
    function initResidenceNote(note) {
        var form = note.closest('form') || document;
        var local = note.querySelector('[data-residence-note="local"]');
        var away = note.querySelector('[data-residence-note="away"]');

        form.addEventListener('residence:change', function (event) {
            var isAway = Boolean(event.detail && event.detail.away);
            note.classList.toggle('is-away', isAway);
            if (local) {
                local.hidden = isAway;
            }
            if (away) {
                away.hidden = !isAway;
            }
        });
    }

    function each(selector, init) {
        Array.prototype.forEach.call(document.querySelectorAll(selector), init);
    }

    function boot() {
        each('[data-phone-field]', initPhoneField);
        each('[data-signin-tabs]', initSignInTabs);
        each('[data-otp-boxes]', initCodeBoxes);
        each('[data-otp-countdown]', initCountdown);
        each('[data-residence-note-wrap]', initResidenceNote);
        each('[data-residence]', initResidence);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
