"use strict";

/*
 * P2P → Marketplace Settings.
 *
 * Progressive: every control posts as a plain form field, so the page saves
 * fine without this file. It adds the section tabs, the live fee and
 * settlement previews, the country chips, the unsaved-changes state shared
 * by the header and the save bar, and the asset-override dialog's create /
 * edit switching. Every string it shows comes from a data attribute the
 * view rendered through the translator, so this file carries no copy of its own.
 */
(function () {
    var root = document.querySelector('[data-p2ps]');
    var form = document.getElementById('p2p-settings-form');

    if (!root || !form) {
        return;
    }

    // ── Section tabs ───────────────────────────────────────────────
    var tabs  = Array.prototype.slice.call(root.querySelectorAll('[data-p2ps-tab]'));
    var panes = Array.prototype.slice.call(root.querySelectorAll('[data-p2ps-pane]'));

    function paneFor(key) {
        return panes.filter(function (pane) { return pane.getAttribute('data-p2ps-pane') === key; })[0] || null;
    }

    function activateTab(key, focus) {
        if (!paneFor(key)) {
            key = tabs.length ? tabs[0].getAttribute('data-p2ps-tab') : null;
        }

        tabs.forEach(function (tab) {
            var on = tab.getAttribute('data-p2ps-tab') === key;
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.setAttribute('tabindex', on ? '0' : '-1');
            if (on && focus) {
                tab.focus();
            }
        });

        panes.forEach(function (pane) {
            var on = pane.getAttribute('data-p2ps-pane') === key;
            pane.classList.toggle('is-active', on);
            if (on) {
                pane.removeAttribute('hidden');
            } else {
                pane.setAttribute('hidden', '');
            }
        });

        // The save bar belongs to the form; the overrides tab sits outside
        // it and each override saves itself, so there is nothing to commit.
        var savebarEl = form.querySelector('[data-settings-savebar]');
        var pane = paneFor(key);

        if (savebarEl) {
            /*
             * Whether the bar applies here is this page's business;
             * whether anything is unsaved is admin-savebar.js's. Say
             * which pane we are on and let it decide the rest, or the
             * two disagree and a clean form shows a bar.
             */
            if (pane && form.contains(pane)) {
                savebarEl.removeAttribute('data-ss-inapplicable');
            } else {
                savebarEl.setAttribute('data-ss-inapplicable', '');
            }

            form.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (key && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', '#' + key);
        }
    }

    // The tab a page opens on: the one holding a validation error, else the
    // one named in the hash, else the first.
    function initialTab() {
        var withError = panes.filter(function (pane) {
            return pane.querySelector('.is-invalid, .p2ps-error');
        })[0];

        if (withError) {
            return withError.getAttribute('data-p2ps-pane');
        }

        var hash = (window.location.hash || '').replace(/^#/, '');

        return paneFor(hash) ? hash : (tabs.length ? tabs[0].getAttribute('data-p2ps-tab') : null);
    }

    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () {
            activateTab(tab.getAttribute('data-p2ps-tab'), false);
        });

        tab.addEventListener('keydown', function (event) {
            var next = null;

            if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
                next = tabs[(index + 1) % tabs.length];
            } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
                next = tabs[(index - 1 + tabs.length) % tabs.length];
            } else if (event.key === 'Home') {
                next = tabs[0];
            } else if (event.key === 'End') {
                next = tabs[tabs.length - 1];
            }

            if (next) {
                event.preventDefault();
                activateTab(next.getAttribute('data-p2ps-tab'), true);
            }
        });
    });

    activateTab(initialTab(), false);

    // ── Number formatting ──────────────────────────────────────────
    /**
     * Trim storage padding without dropping real precision.
     *
     * The shared formatter already does exactly this — it is the browser twin
     * of Money::trimFee(), and the admin layout loads it before this file. The
     * copy that used to live here pinned eight places of its own, so it would
     * have kept the old scale on this one screen after every other preview in
     * the admin had moved.
     */
    function trim(value) {
        return DigiKashMoney.trim(value);
    }

    function num(el) {
        if (!el) {
            return null;
        }

        var raw = String(el.value).trim();

        if (raw === '') {
            return null;
        }

        var parsed = parseFloat(raw);

        return isFinite(parsed) ? parsed : null;
    }

    // ── Fee preview ────────────────────────────────────────────────
    // Total fee = fixed + amount × pct, then clamped to the floor and
    // ceiling — the same rule the settlement code applies.
    var calc      = form.querySelector('[data-fee-calc]');
    var baseInput = calc && calc.querySelector('[data-fee-calc-base]');
    var makerOut  = calc && calc.querySelector('[data-fee-calc-maker]');
    var takerOut  = calc && calc.querySelector('[data-fee-calc-taker]');
    var totalOut  = calc && calc.querySelector('[data-fee-calc-total]');

    function feeFor(side, base) {
        var pct   = num(form.querySelector('[data-settings-fee="' + side + '"]')) || 0;
        var fixed = num(form.querySelector('[data-settings-fee-fixed="' + side + '"]')) || 0;
        var min   = num(form.querySelector('[data-settings-fee-min="' + side + '"]'));
        var max   = num(form.querySelector('[data-settings-fee-max="' + side + '"]'));
        var fee   = fixed + base * (pct / 100);

        if (min !== null && fee < min) {
            fee = min;
        }

        if (max !== null && fee > max) {
            fee = max;
        }

        return fee;
    }

    function recalcFees() {
        if (!calc) {
            return;
        }

        var base  = num(baseInput) || 0;
        var maker = feeFor('maker', base);
        var taker = feeFor('taker', base);

        makerOut.textContent = trim(base - maker);
        takerOut.textContent = trim(base + taker);
        totalOut.textContent = trim(maker + taker);
    }

    if (calc) {
        form.addEventListener('input', function (event) {
            var target = event.target;

            if (target === baseInput || target.hasAttribute('data-settings-fee')
                || target.hasAttribute('data-settings-fee-fixed')
                || target.hasAttribute('data-settings-fee-min')
                || target.hasAttribute('data-settings-fee-max')) {
                recalcFees();
            }
        });
        recalcFees();
    }

    // ── Settlement timeline ────────────────────────────────────────
    var expiryInput  = form.querySelector('[data-settings-expiry]');
    var disputeInput = form.querySelector('[data-settings-dispute]');
    var segPay       = form.querySelector('[data-timing-seg-pay]');
    var segDispute   = form.querySelector('[data-timing-seg-dispute]');
    var totalEl      = form.querySelector('[data-timing-total]');
    var payEl        = form.querySelector('[data-timing-pay]');
    var disputeEl    = form.querySelector('[data-timing-dispute]');

    function recalcTiming() {
        if (!expiryInput || !disputeInput) {
            return;
        }

        var pay     = Math.max(0, parseInt(expiryInput.value, 10) || 0);
        var dispute = Math.max(0, parseInt(disputeInput.value, 10) || 0);
        var total   = pay + dispute;

        if (totalEl)   { totalEl.textContent = total; }
        if (payEl)     { payEl.textContent = pay; }
        if (disputeEl) { disputeEl.textContent = dispute; }

        if (segPay && segDispute) {
            var payPct = total > 0 ? (pay / total) * 100 : 0;
            segPay.style.width     = payPct + '%';
            segDispute.style.width = (100 - payPct) + '%';
        }
    }

    if (expiryInput && disputeInput) {
        expiryInput.addEventListener('input', recalcTiming);
        disputeInput.addEventListener('input', recalcTiming);
        recalcTiming();
    }

    // ── Country pickers (chips + search + click-to-toggle list) ────
    ['allowed', 'blocked'].forEach(function (key) {
        var select  = form.querySelector('[data-country-select="' + key + '"]');
        var chips   = form.querySelector('[data-country-chips="' + key + '"]');
        var search  = form.querySelector('[data-country-search="' + key + '"]');
        var count   = form.querySelector('[data-country-count="' + key + '"]');
        var optList = form.querySelector('[data-country-options="' + key + '"]');
        var emptyEl = form.querySelector('[data-country-empty="' + key + '"]');

        if (!select || !optList) {
            return;
        }

        var optionByValue = {};
        Array.prototype.forEach.call(select.options, function (opt) {
            optionByValue[opt.value] = opt;
        });

        function nameFor(value) {
            var li = optList.querySelector('[data-country-value="' + value + '"]');

            return li ? (li.getAttribute('data-country-name') || value) : value;
        }

        function refreshChips() {
            if (!chips) {
                return;
            }

            chips.innerHTML = '';

            var selected = Array.prototype.slice.call(select.selectedOptions);

            if (!selected.length) {
                var empty = document.createElement('div');
                empty.className = 'p2ps-picker__empty';
                var title = document.createElement('b');
                title.textContent = chips.getAttribute('data-empty-title') || '';
                var text = document.createElement('span');
                text.textContent = chips.getAttribute('data-empty-text') || '';
                empty.appendChild(title);
                empty.appendChild(text);
                chips.appendChild(empty);
            }

            selected.forEach(function (opt) {
                var chip = document.createElement('span');
                chip.className = 'p2ps-chip';

                var code = document.createElement('span');
                code.className = 'p2ps-chip__code';
                code.setAttribute('dir', 'ltr');
                code.textContent = opt.value;

                var name = document.createElement('span');
                name.textContent = nameFor(opt.value);

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'p2ps-chip__remove';
                btn.setAttribute('aria-label', (chips.getAttribute('data-remove-label') || '') + ' ' + nameFor(opt.value));
                btn.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';
                btn.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    setSelected(opt.value, false);
                });

                chip.appendChild(code);
                chip.appendChild(name);
                chip.appendChild(btn);
                chips.appendChild(chip);
            });

            if (count) {
                var template = count.getAttribute('data-count-template') || ':count';
                count.textContent = template.replace(':count', String(selected.length));
            }
        }

        function setSelected(value, isSelected) {
            var opt = optionByValue[value];

            if (!opt) {
                return;
            }

            opt.selected = isSelected;

            var li = optList.querySelector('[data-country-value="' + value + '"]');

            if (li) {
                li.classList.toggle('is-selected', isSelected);
                li.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            }

            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function toggleValue(value) {
            var opt = optionByValue[value];

            if (opt) {
                setSelected(value, !opt.selected);
            }
        }

        optList.addEventListener('click', function (event) {
            var li = event.target.closest('[data-country-value]');

            if (li) {
                toggleValue(li.getAttribute('data-country-value'));
            }
        });

        optList.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            var li = event.target.closest('[data-country-value]');

            if (li) {
                event.preventDefault();
                toggleValue(li.getAttribute('data-country-value'));
            }
        });

        if (search) {
            search.addEventListener('input', function () {
                var q = this.value.trim().toLowerCase();
                var visible = 0;

                Array.prototype.forEach.call(optList.querySelectorAll('[data-country-value]'), function (li) {
                    var match = q === '' || (li.getAttribute('data-country-search-text') || '').indexOf(q) !== -1;
                    li.classList.toggle('is-hidden', !match);
                    if (match) {
                        visible++;
                    }
                });

                if (emptyEl) {
                    emptyEl.classList.toggle('is-hidden', visible > 0);
                }
            });
        }

        select.addEventListener('change', refreshChips);
        refreshChips();
    });

    // ── Unsaved-changes state ──────────────────────────────────────
    // One snapshot per control on load; a pane is dirty when any control
    // inside it differs, the form when any pane is. The header pill, the
    // rail dots, the save bar and its Discard all read from that, and the
    // form carries the answer so none of them can disagree.
    var stateEl   = document.querySelector('[data-p2ps-state]');
    var stateText = stateEl && stateEl.querySelector('[data-p2ps-state-text]');
    var resetBtns = Array.prototype.slice.call(document.querySelectorAll('[data-p2ps-discard]'));
    var initial   = {};

    function controlsOf(scope) {
        return Array.prototype.slice.call(scope.querySelectorAll('input[name], select[name], textarea[name]'))
            .filter(function (el) { return el.type !== 'hidden'; });
    }

    function valueOf(el) {
        if (el.tagName === 'SELECT' && el.multiple) {
            return Array.prototype.map.call(el.selectedOptions, function (opt) { return opt.value; }).sort().join(',');
        }

        if (el.type === 'checkbox' || el.type === 'radio') {
            return el.checked ? '1' : '0';
        }

        return String(el.value);
    }

    function keyOf(el) {
        return el.name + '#' + controlsOf(form).indexOf(el);
    }

    function snapshot() {
        initial = {};
        controlsOf(form).forEach(function (el) {
            initial[keyOf(el)] = valueOf(el);
        });
    }

    function isDirty(scope) {
        return controlsOf(scope).some(function (el) {
            return initial[keyOf(el)] !== valueOf(el);
        });
    }

    function updatePristine() {
        var dirty = false;

        panes.forEach(function (pane) {
            if (!form.contains(pane)) {
                return;
            }

            var paneDirty = isDirty(pane);
            var tab = tabs.filter(function (t) { return t.getAttribute('data-p2ps-tab') === pane.getAttribute('data-p2ps-pane'); })[0];

            if (tab) {
                tab.setAttribute('data-dirty', paneDirty ? 'true' : 'false');
            }

            dirty = dirty || paneDirty;
        });

        form.setAttribute('data-pristine', dirty ? 'false' : 'true');

        if (stateEl) {
            stateEl.classList.toggle('ph-state--warning', dirty);
            stateEl.classList.toggle('ph-state--success', !dirty);
            if (stateText) {
                stateText.textContent = stateEl.getAttribute(dirty ? 'data-dirty-text' : 'data-saved-text') || '';
            }
        }

        resetBtns.forEach(function (btn) {
            btn.disabled = !dirty;
        });
    }

    snapshot();
    updatePristine();

    form.addEventListener('input', updatePristine);
    form.addEventListener('change', updatePristine);

    resetBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            form.reset();
            ['allowed', 'blocked'].forEach(function (key) {
                var select = form.querySelector('[data-country-select="' + key + '"]');

                if (!select) {
                    return;
                }

                Array.prototype.forEach.call(select.options, function (opt) {
                    var li = form.querySelector('[data-country-options="' + key + '"] [data-country-value="' + opt.value + '"]');

                    if (li) {
                        li.classList.toggle('is-selected', opt.selected);
                        li.setAttribute('aria-selected', opt.selected ? 'true' : 'false');
                    }
                });

                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
            recalcFees();
            recalcTiming();
            updatePristine();
        });
    });

    window.addEventListener('beforeunload', function (event) {
        if (form.getAttribute('data-pristine') === 'false') {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    form.addEventListener('submit', function () {
        snapshot();
        updatePristine();
    });

    // ── Reset-override confirmation ────────────────────────────────
    Array.prototype.forEach.call(root.querySelectorAll('form[data-confirm]'), function (confirmForm) {
        confirmForm.addEventListener('submit', function (event) {
            if (!window.confirm(confirmForm.getAttribute('data-confirm') || '')) {
                event.preventDefault();
            }
        });
    });
})();

/*
 * Asset fee override dialog. One modal serves create and edit; the trigger
 * decides which by setting the form action and method.
 */
(function ($) {
    if (!$) {
        return;
    }

    var $modal = $('#p2pAssetFeeModal');

    if (!$modal.length) {
        return;
    }

    var $form       = $modal.find('[data-asset-fee-form]');
    var storeAction = $form.attr('action');
    var labels      = {
        add:        $form.data('label-add'),
        save:       $form.data('label-save'),
        titleAdd:   $form.data('title-add'),
        titleEdit:  $form.data('title-edit'),
        unit:       $form.data('unit-default'),
        errorTitle: $form.data('error-title'),
        errorText:  $form.data('error-text')
    };

    function setUnit(code) {
        // The flat fee and the clamp are read in the asset itself, so the
        // badge follows the selection rather than naming a currency that has
        // nothing to do with the trade.
        $modal.find('[data-asset-fee-unit]').text(code || labels.unit);
    }

    function reset() {
        $form[0].reset();
        $form.attr('action', storeAction);
        $form.find('[name="currency_id"][type="hidden"]').remove();
        $modal.find('[data-asset-fee-method]').val('POST');
        $modal.find('[data-asset-fee-currency]').prop('disabled', false).val('');
        $modal.find('[data-asset-fee-currency-locked]').prop('hidden', true);
        $modal.find('[data-asset-fee-submit]').text(labels.add);
        $modal.find('[data-asset-fee-title]').text(labels.titleAdd);
        $modal.find('#asset_fee_is_active').prop('checked', true);
        setUnit('');
    }

    $(document).on('click', '[data-asset-fee-create]', reset);

    $(document).on('click', '[data-asset-fee-edit]', function () {
        var $trigger = $(this);

        reset();
        $form.attr('action', $trigger.data('asset-fee-action'));
        $modal.find('[data-asset-fee-method]').val('PUT');
        $modal.find('[data-asset-fee-submit]').text(labels.save);
        $modal.find('[data-asset-fee-title]').text(labels.titleEdit);
        setUnit($trigger.data('asset-fee-code'));

        $.ajax({
            url: $trigger.data('asset-fee-edit'),
            method: 'GET',
            headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')},
            success: function (data) {
                // The asset a row belongs to is fixed — the unique index would
                // reject a move anyway, so the select is shown filled but
                // locked, with the reason stated.
                var $currency = $modal.find('[data-asset-fee-currency]');

                if (!$currency.find('option[value="' + data.currency_id + '"]').length) {
                    $currency.append($('<option>', {value: data.currency_id, text: $trigger.data('asset-fee-code')}));
                }

                $currency.val(data.currency_id).prop('disabled', true);
                $modal.find('[data-asset-fee-currency-locked]').prop('hidden', false);

                // Disabled controls are not submitted, so the id rides along
                // in a hidden field the validator still sees.
                $form.find('[name="currency_id"][type="hidden"]').remove();
                $form.append($('<input>', {type: 'hidden', name: 'currency_id', value: data.currency_id}));

                ['maker', 'taker'].forEach(function (side) {
                    $form.find('[name="' + side + '_fee_fixed"]').val(data[side + '_fee_fixed']);
                    $form.find('[name="' + side + '_fee_pct"]').val(data[side + '_fee_pct']);
                    $form.find('[name="' + side + '_fee_min"]').val(data[side + '_fee_min']);
                    $form.find('[name="' + side + '_fee_max"]').val(data[side + '_fee_max']);
                });

                $modal.find('#asset_fee_is_active').prop('checked', !!data.is_active);
            },
            error: function () {
                if (typeof Notify === 'function') {
                    new Notify({
                        status: 'error',
                        title: labels.errorTitle,
                        text: labels.errorText,
                        autoclose: true
                    });
                }
            }
        });
    });

    $modal.find('[data-asset-fee-currency]').on('change', function () {
        setUnit($(this).find('option:selected').text().split(' — ')[0]);
    });
})(window.jQuery);
