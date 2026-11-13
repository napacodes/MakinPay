"use strict";

/*
 * The admin save bar — what it says, and when it says it.
 *
 * admin-savebar.css draws the one bar every settings screen carries. This is
 * the other half: it stays out of the way until something is actually
 * unsaved, then names how many changes there are and which section holds
 * them, the way Site Settings has always behaved.
 *
 * Site Settings keeps its own copy of this because it also reasons about
 * invalid fields, a search index and per-section forms. Everything here is
 * the part a plainer settings screen needs.
 *
 * The page supplies the wording, so the bar reads in the admin's language:
 *
 *   [data-ss-savebar]            the bar
 *     data-ss-unsaved-one        ":count unsaved change"
 *     data-ss-unsaved-many       ":count unsaved changes"
 *     data-ss-in                 "in"
 *     data-ss-nothing            "Nothing is applied until you save."
 *     data-ss-form-id            id of the form it commits, when not an ancestor
 *   [data-ss-savebar-title]      the headline
 *   [data-ss-savebar-detail]     the line under it
 *   [data-ss-section="Label"]    on a pane, so the detail can name it
 *
 * Counting is by control, not by section: an operator who changed two fields
 * wants to read "2 unsaved changes", and a bar that says "1 section" hides
 * the thing they are about to commit.
 */
(function () {
    var bar = document.querySelector('[data-ss-savebar]');
    if (!bar) {
        return;
    }

    var formId = bar.getAttribute('data-ss-form-id');
    var form = formId ? document.getElementById(formId) : bar.closest('form');
    if (!form) {
        return;
    }

    var title = document.querySelector('[data-ss-savebar-title]');
    var detail = document.querySelector('[data-ss-savebar-detail]');
    var saveButton = bar.querySelector('[type="submit"]');

    function phrase(name, count, fallback) {
        var frame = bar.getAttribute(name) || fallback;
        return frame.replace(':count', String(count));
    }

    /*
     * Every named control the form carries, including those in panes that are
     * closed: they are still submitted, so a change in one of them is still
     * unsaved work and still has to be counted.
     */
    var fields = Array.prototype.slice.call(
        form.querySelectorAll('input[name], select[name], textarea[name]')
    ).filter(function (field) {
        return field.type !== 'hidden' && field.type !== 'submit' && field.name !== '_token';
    });

    function valueOf(field) {
        if (field.type === 'checkbox' || field.type === 'radio') {
            return field.checked ? '1' : '';
        }
        return field.value;
    }

    /*
     * The value the server rendered, not the value when this file happened to
     * run. A browser restoring a form on back-navigation would otherwise make
     * a restored page look clean while holding edits.
     */
    function initialOf(field) {
        if (field.type === 'checkbox' || field.type === 'radio') {
            return field.defaultChecked ? '1' : '';
        }
        if (field.tagName === 'SELECT') {
            var picked = Array.prototype.slice.call(field.options).filter(function (option) {
                return option.defaultSelected;
            })[0];
            return picked ? picked.value : field.value;
        }
        return field.defaultValue;
    }

    var baseline = fields.map(initialOf);

    /*
     * A radio group is one decision, not one control per button. Switching
     * the payout release mode flips two inputs — the one turned on and the
     * one turned off — so counting inputs tells an operator who made a
     * single choice that there are two unsaved changes. Measured in Chrome
     * before this existed: choosing a release mode and a split provider,
     * two decisions, read as "4 unsaved changes".
     *
     * Everything else is still counted per control, because there each
     * control really is its own change.
     */
    var units = [];
    var groupAt = {};

    fields.forEach(function (field, index) {
        if (field.type !== 'radio') {
            units.push([index]);

            return;
        }

        if (groupAt[field.name] === undefined) {
            groupAt[field.name] = units.length;
            units.push([]);
        }

        units[groupAt[field.name]].push(index);
    });

    function sectionOf(field) {
        var pane = field.closest('[data-ss-section]');
        return pane ? pane.getAttribute('data-ss-section') : '';
    }

    function render() {
        var total = 0;
        var perSection = [];
        var seen = {};

        units.forEach(function (members) {
            var changed = -1;

            members.forEach(function (index) {
                if (changed === -1 && valueOf(fields[index]) !== baseline[index]) {
                    changed = index;
                }
            });

            if (changed === -1) {
                return;
            }

            total++;

            var section = sectionOf(fields[changed]);
            if (!section) {
                return;
            }
            if (seen[section] === undefined) {
                seen[section] = perSection.length;
                perSection.push({ label: section, count: 0 });
            }
            perSection[seen[section]].count++;
        });

        bar.setAttribute('data-pristine', total ? 'false' : 'true');

        /*
         * A page may say the bar does not belong on the pane in view — P2P's
         * per-asset overrides sit outside the form and each saves itself.
         * That is the page's call, and it outranks having edits.
         */
        if (bar.hasAttribute('data-ss-inapplicable')) {
            bar.hidden = true;

            return;
        }

        if (!total) {
            bar.hidden = true;

            return;
        }

        bar.hidden = false;

        if (title) {
            title.textContent = total === 1
                ? phrase('data-ss-unsaved-one', 1, ':count unsaved change')
                : phrase('data-ss-unsaved-many', total, ':count unsaved changes');
        }

        if (detail) {
            var where = bar.getAttribute('data-ss-in') || 'in';
            var nothing = bar.getAttribute('data-ss-nothing') || '';
            var parts = perSection.map(function (entry) {
                return entry.count + ' ' + where + ' ' + entry.label;
            });

            detail.textContent = parts.length
                ? parts.join(', ') + ' — ' + nothing
                : nothing;
        }
    }

    form.addEventListener('input', render);
    form.addEventListener('change', render);

    /*
     * Discard puts every control back to what the server rendered, which is
     * what reset() does — and then the bar has to be told, because a reset
     * fires no input event of its own.
     */
    var discard = bar.querySelector('[data-ss-discard], [data-p2ps-discard], [data-mps-reset]');
    if (discard) {
        discard.addEventListener('click', function () {
            form.reset();
            render();
        });
    }

    /*
     * Ctrl S is the shortcut the bar advertises, so it has to work. The
     * browser's own Save-page dialogue is not useful on a settings screen.
     */
    document.addEventListener('keydown', function (event) {
        if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 's') {
            return;
        }
        if (bar.hidden) {
            return;
        }

        event.preventDefault();

        if (saveButton) {
            saveButton.click();
        }
    });

    window.addEventListener('beforeunload', function (event) {
        if (bar.getAttribute('data-pristine') === 'false') {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    render();
})();
