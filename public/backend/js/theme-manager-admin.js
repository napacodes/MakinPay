"use strict";

/* ==========================================================
   Theme Manager
   Gallery activation, Landing Colours tabs, colour editing with
   hex normalisation, and the save state machine
   (idle -> saving -> saved | error -> idle). jQuery is already
   loaded on the admin panel.
   ========================================================== */
(function ($) {
    var HEX = /^#[0-9A-Fa-f]{6}$/;
    var PREVIEW_FALLBACK = '#C8C8CE';
    var SAVED_HOLD_MS = 1700;
    var ERROR_HOLD_MS = 2600;

    /* Trim, prepend #, drop non-hex characters, clamp to 6, uppercase. */
    function normalise(raw) {
        var value = String(raw === null || raw === undefined ? '' : raw).trim();

        if (value.charAt(0) === '#') {
            value = value.slice(1);
        }

        value = value.replace(/[^0-9a-fA-F]/g, '').slice(0, 6);

        return '#' + value.toUpperCase();
    }

    /* A half-typed hex must never reach the preview as an invalid colour. */
    function previewSafe(value) {
        return HEX.test(value) ? value : PREVIEW_FALLBACK;
    }

    function csrfToken() {
        return $('meta[name=csrf-token]').attr('content') || '';
    }

    function announce(message) {
        $('[data-tm-status]').filter(':visible').first().text(message);
    }

    function toast(type, message) {
        if (!message) {
            return;
        }

        if (typeof window.Notify === 'function') {
            new window.Notify({ status: type, text: message, autotimeout: 4000 });

            return;
        }

        announce(message);
    }

    /* ------------------------------------------------------
       Colour fields
       ------------------------------------------------------ */
    function applyFieldValue($field, value, syncHexInput) {
        var $swatch = $field.find('[data-tm-swatch]');
        var $hex = $field.find('[data-tm-hex]');

        $field.get(0).style.setProperty('--tm-field-value', previewSafe(value));

        if (HEX.test(value)) {
            $field.find('[data-tm-color]').val(value);
        }

        if (syncHexInput) {
            $hex.val(value);
        }

        $hex.attr('aria-invalid', HEX.test(value) ? 'false' : 'true');

        $swatch.find('[data-tm-swatch-value]').text(value);
    }

    /* ------------------------------------------------------
       Save state machine
       ------------------------------------------------------ */
    function setSavePhase($button, phase, message) {
        if (!$button.length) {
            return;
        }

        var labels = {
            idle: $button.attr('data-label-idle'),
            saving: $button.attr('data-label-saving'),
            saved: $button.attr('data-label-saved'),
            error: $button.attr('data-label-error')
        };

        window.clearTimeout($button.data('tm-timer'));

        $button
            .removeClass('is-saving is-saved is-error')
            .attr('aria-busy', phase === 'saving' ? 'true' : 'false');

        if (phase !== 'idle') {
            $button.addClass('is-' + phase);
        }

        $button.find('[data-tm-save-label]').text(labels[phase] || labels.idle);

        var $icon = $button.find('.tm-btn__icon');
        $icon.attr('class', 'tm-btn__icon');

        if (phase === 'saved') {
            $icon.addClass('fa-solid fa-check');
        } else if (phase === 'error') {
            $icon.addClass('fa-solid fa-triangle-exclamation');
        }

        if (message) {
            announce(message);
        }

        if (phase === 'saved' || phase === 'error') {
            $button.data('tm-timer', window.setTimeout(function () {
                setSavePhase($button, 'idle');
            }, phase === 'saved' ? SAVED_HOLD_MS : ERROR_HOLD_MS));
        }
    }

    /* Any edit invalidates a pending confirmation. */
    function resetSavePhase($panel) {
        setSavePhase($panel.find('[data-tm-save]'), 'idle');
    }

    function postForm($form, onDone, onFail) {
        return $.ajax({
            url: $form.attr('action'),
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            data: $form.serialize()
        }).done(onDone).fail(onFail);
    }

    function messageFrom(xhr) {
        var payload = xhr.responseJSON || {};

        if (payload.message) {
            return payload.message;
        }

        if (payload.errors) {
            return $.map(payload.errors, function (list) {
                return list[0];
            }).join(' ');
        }

        return '';
    }

    /* ------------------------------------------------------
       Gallery activation and tabs
       ------------------------------------------------------ */
    function paintActiveCard(slug) {
        $('[data-tm-card]').each(function () {
            var $card = $(this);
            var isActive = $card.attr('data-tm-card') === slug;
            var $button = $card.find('.tm-btn--primary');

            $card.toggleClass('is-active', isActive);
            $button.attr('aria-checked', isActive ? 'true' : 'false');

            if (isActive) {
                $button.attr('aria-disabled', 'true');
                $button.find('[data-tm-activate-label]').text($button.attr('data-label-inuse'));
            } else {
                $button.removeAttr('aria-disabled');
                $button.find('[data-tm-activate-label]').text($button.attr('data-label-activate'));
            }
        });
    }

    function selectTab(slug) {
        var $colours = $('[data-tm-colors]');

        if (!$colours.length || !slug) {
            return;
        }

        $colours.find('[data-tm-tab]').each(function () {
            var $tab = $(this);
            var selected = $tab.attr('data-tm-tab') === slug;

            $tab.toggleClass('is-selected', selected)
                .attr('aria-selected', selected ? 'true' : 'false')
                .attr('tabindex', selected ? '0' : '-1');
        });

        $colours.find('[data-tm-panel]').each(function () {
            var $panel = $(this);
            var shown = $panel.attr('data-tm-panel') === slug;

            $panel.toggleClass('is-shown', shown).prop('hidden', !shown);

            if (shown) {
                resetSavePhase($panel);
            }
        });
    }

    $(function () {
        var $admin = $('.tm-admin');

        if (!$admin.length) {
            return;
        }

        /* ---- Activate ---- */
        $admin.on('submit', '[data-tm-activate]', function (event) {
            event.preventDefault();

            var $form = $(this);
            var $button = $form.find('.tm-btn--primary');

            if ($button.attr('aria-disabled') === 'true') {
                return;
            }

            var slug = $form.attr('data-tm-activate');
            var previous = $('[data-tm-card].is-active').attr('data-tm-card');

            paintActiveCard(slug);
            selectTab(slug);

            postForm($form, function (response) {
                toast('success', response && response.message);
            }, function (xhr) {
                paintActiveCard(previous);
                selectTab(previous);
                toast('error', messageFrom(xhr));
            });
        });

        /* ---- Details panel: only one card open at a time ---- */
        $admin.on('click', '[data-tm-details-toggle]', function () {
            var $toggle = $(this);
            var expanded = $toggle.attr('aria-expanded') === 'true';

            $admin.find('[data-tm-details-toggle]').attr('aria-expanded', 'false');
            $admin.find('.tm-card__details').prop('hidden', true);

            if (!expanded) {
                $toggle.attr('aria-expanded', 'true');
                $admin.find('#' + $toggle.attr('aria-controls')).prop('hidden', false);
            }
        });

        /* ---- Tabs ---- */
        $admin.on('click', '[data-tm-tab]', function () {
            selectTab($(this).attr('data-tm-tab'));
        });

        $admin.on('keydown', '[data-tm-tab]', function (event) {
            var steps = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
            var step = steps[event.key];

            if (!step) {
                return;
            }

            event.preventDefault();

            var $tabs = $admin.find('[data-tm-tab]');
            var $next = $tabs.eq(($tabs.index(this) + step + $tabs.length) % $tabs.length);

            selectTab($next.attr('data-tm-tab'));
            $next.trigger('focus');
        });

        /* ---- Colour picker -> hex ---- */
        $admin.on('input change', '[data-tm-color]', function () {
            var $field = $(this).closest('[data-tm-field]');

            applyFieldValue($field, normalise(this.value), true);
            resetSavePhase($field.closest('[data-tm-panel]'));
        });

        /* ---- Hex -> colour picker ---- */
        $admin.on('input', '[data-tm-hex]', function () {
            var $field = $(this).closest('[data-tm-field]');
            var value = normalise(this.value);

            this.value = value;
            applyFieldValue($field, value, false);
            resetSavePhase($field.closest('[data-tm-panel]'));
        });

        $admin.on('blur', '[data-tm-hex]', function () {
            if (HEX.test(this.value)) {
                return;
            }

            var label = $(this).closest('[data-tm-field]').find('.tm-field__label').text();

            announce(label + ': ' + this.value + ' is not a complete six-digit colour.');
        });

        /* ---- Per-field reset ---- */
        $admin.on('click', '[data-tm-field-reset]', function () {
            var $field = $(this).closest('[data-tm-field]');

            applyFieldValue($field, normalise($field.attr('data-tm-default')), true);
            resetSavePhase($field.closest('[data-tm-panel]'));
        });

        $admin.on('change', '[data-tm-mode]', function () {
            resetSavePhase($(this).closest('[data-tm-panel]'));
        });

        /* ---- Save ---- */
        $admin.on('submit', '[data-tm-save-form]', function (event) {
            event.preventDefault();

            var $form = $(this);
            var $button = $form.find('[data-tm-save]');

            if ($button.hasClass('is-saving')) {
                return;
            }

            setSavePhase($button, 'saving');

            postForm($form, function (response) {
                setSavePhase($button, 'saved', response && response.message);
            }, function (xhr) {
                setSavePhase($button, 'error', messageFrom(xhr));
            });
        });

        /* ---- Reset every field of the current tab ---- */
        $admin.on('submit', '[data-tm-reset-form]', function (event) {
            event.preventDefault();

            var $form = $(this);
            var slug = $form.attr('data-tm-reset-form');
            var $panel = $admin.find('[data-tm-panel=' + slug + ']');
            var $button = $panel.find('[data-tm-save]');

            postForm($form, function (response) {
                $panel.find('[data-tm-field]').each(function () {
                    var $field = $(this);

                    applyFieldValue($field, normalise($field.attr('data-tm-default')), true);
                });

                $panel.find('[data-tm-mode]').each(function () {
                    $(this).val($(this).attr('data-tm-mode-default'));
                });

                setSavePhase($button, 'idle');
                toast('success', response && response.message);
            }, function (xhr) {
                setSavePhase($button, 'error', messageFrom(xhr));
            });
        });
    });
})(jQuery);
