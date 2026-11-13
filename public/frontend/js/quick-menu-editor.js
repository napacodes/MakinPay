"use strict";

/**
 * Quick-menu editor.
 *
 * Every catalog shortcut is already in the grid; the unpinned ones arrive with
 * the `hidden` attribute. Editing therefore never needs to build a tile - it
 * reorders the nodes that are already there and flips `hidden`. That keeps the
 * icon markup server-rendered and the panel usable the moment it opens.
 */
(function () {
    var SELECTED_ATTR = 'data-quick-menu-key';

    function panelOf(node) {
        return node ? node.closest('[data-quick-menu-panel]') : null;
    }

    function rowsOf(panel) {
        var list = panel.querySelector('[data-quick-menu-editor-list]');

        return list ? Array.prototype.slice.call(list.querySelectorAll('[data-quick-menu-row]')) : [];
    }

    function chosenKeys(panel) {
        return rowsOf(panel)
            .filter(function (row) {
                var toggle = row.querySelector('[data-quick-menu-toggle]');

                return toggle && toggle.checked;
            })
            .map(function (row) {
                return row.getAttribute(SELECTED_ATTR);
            })
            .filter(Boolean);
    }

    function snapshot(panel) {
        return {
            order: rowsOf(panel).map(function (row) {
                return row.getAttribute(SELECTED_ATTR);
            }),
            keys: chosenKeys(panel)
        };
    }

    function applyState(panel, order, keys) {
        var list = panel.querySelector('[data-quick-menu-editor-list]');

        if (list) {
            order.forEach(function (key) {
                var row = list.querySelector('[' + SELECTED_ATTR + '="' + key + '"]');

                if (row) {
                    list.appendChild(row);
                }
            });
        }

        rowsOf(panel).forEach(function (row) {
            var toggle = row.querySelector('[data-quick-menu-toggle]');

            if (toggle) {
                toggle.checked = keys.indexOf(row.getAttribute(SELECTED_ATTR)) !== -1;
            }
        });
    }

    /** Mirror the editor's order and selection onto the visible grid. */
    function paintGrid(panel, keys) {
        var grid = panel.querySelector('[data-quick-menu-grid]');

        if (!grid) {
            return;
        }

        rowsOf(panel).forEach(function (row) {
            var key = row.getAttribute(SELECTED_ATTR);
            var tile = grid.querySelector('.quick-function-menu__action[' + SELECTED_ATTR + '="' + key + '"]');

            if (!tile) {
                return;
            }

            grid.appendChild(tile);
            tile.hidden = keys.indexOf(key) === -1;
        });
    }

    function setEditing(panel, isEditing) {
        var editor = panel.querySelector('[data-quick-menu-editor]');
        var grid = panel.querySelector('[data-quick-menu-grid]');
        var trigger = panel.querySelector('[data-quick-menu-edit]');
        var label = panel.querySelector('[data-quick-menu-edit-label]');

        panel.classList.toggle('is-editing', isEditing);

        if (editor) {
            editor.hidden = !isEditing;
        }

        if (grid) {
            grid.hidden = isEditing;
        }

        if (trigger) {
            trigger.setAttribute('aria-expanded', isEditing ? 'true' : 'false');
        }

        if (label) {
            label.textContent = isEditing
                ? (label.getAttribute('data-label-close') || label.textContent)
                : (label.getAttribute('data-label-edit') || label.textContent);
        }
    }

    function busy(panel, isBusy) {
        panel.querySelectorAll('[data-quick-menu-save], [data-quick-menu-reset], [data-quick-menu-cancel]').forEach(function (button) {
            button.disabled = isBusy;
        });
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    function announce(type, message) {
        if (typeof window.notifyEvs === 'function') {
            window.notifyEvs(type, message);
        }
    }

    function firstError(payload) {
        if (payload && payload.errors) {
            for (var field in payload.errors) {
                if (Object.prototype.hasOwnProperty.call(payload.errors, field) && payload.errors[field].length) {
                    return payload.errors[field][0];
                }
            }
        }

        return payload && payload.message ? payload.message : null;
    }

    function send(panel, url, method, body) {
        busy(panel, true);

        return fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken()
            },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : null
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (payload) {
                if (!response.ok) {
                    throw new Error(firstError(payload) || panel.getAttribute('data-quick-menu-failed-message') || 'The quick menu could not be saved.');
                }

                return payload;
            });
        }).finally(function () {
            busy(panel, false);
        });
    }

    /**
     * Apply a saved selection to every panel on the page.
     *
     * The desktop dropdown and the mobile sheet are separate copies of the same
     * menu, so a save in one has to land in the other; otherwise closing and
     * reopening the other one shows the selection the user just replaced.
     */
    function syncAllPanels(keys) {
        document.querySelectorAll('[data-quick-menu-panel]').forEach(function (panel) {
            applyState(panel, keys, keys);
            paintGrid(panel, keys);
            setEditing(panel, false);
            panel.quickMenuSnapshot = snapshot(panel);
        });
    }

    /*
     * Closing from the header discards, exactly as Cancel does. Nothing here
     * writes to the server, so letting it read as "Done" would promise a save
     * the user never made.
     */
    function onEditToggle(trigger) {
        var panel = panelOf(trigger);

        if (!panel) {
            return;
        }

        if (panel.classList.contains('is-editing')) {
            onCancel(trigger);

            return;
        }

        panel.quickMenuSnapshot = snapshot(panel);
        setEditing(panel, true);
    }

    function onNudge(button) {
        var row = button.closest('[data-quick-menu-row]');
        var parent = row ? row.parentNode : null;

        if (!parent) {
            return;
        }

        if (button.getAttribute('data-quick-menu-move') === 'up') {
            if (row.previousElementSibling) {
                parent.insertBefore(row, row.previousElementSibling);
            }
        } else if (row.nextElementSibling) {
            parent.insertBefore(row.nextElementSibling, row);
        }

        button.focus();
    }

    function onCancel(button) {
        var panel = panelOf(button);

        if (!panel) {
            return;
        }

        var state = panel.quickMenuSnapshot;

        if (state) {
            applyState(panel, state.order, state.keys);
        }

        setEditing(panel, false);
    }

    function onSave(button) {
        var panel = panelOf(button);

        if (!panel) {
            return;
        }

        var keys = chosenKeys(panel);

        if (!keys.length) {
            announce('error', panel.getAttribute('data-quick-menu-empty-message') || 'Keep at least one shortcut.');

            return;
        }

        send(panel, panel.getAttribute('data-quick-menu-update-url'), 'PUT', { items: keys })
            .then(function (payload) {
                syncAllPanels(payload.items && payload.items.length ? payload.items : keys);
                announce('success', payload.message || '');
            })
            .catch(function (error) {
                announce('error', error.message);
            });
    }

    function onReset(button) {
        var panel = panelOf(button);

        if (!panel) {
            return;
        }

        send(panel, panel.getAttribute('data-quick-menu-reset-url'), 'DELETE', null)
            .then(function () {
                window.location.reload();
            })
            .catch(function (error) {
                announce('error', error.message);
            });
    }

    var handlers = [
        ['[data-quick-menu-edit]', onEditToggle],
        ['[data-quick-menu-move]', onNudge],
        ['[data-quick-menu-cancel]', onCancel],
        ['[data-quick-menu-save]', onSave],
        ['[data-quick-menu-reset]', onReset]
    ];

    /*
     * Capture phase, deliberately. Both panels stop a click from bubbling out
     * of themselves so that clicking inside the menu does not close it, which
     * means a bubble-phase listener on `document` never sees these buttons at
     * all. Capture runs before the target, so it is the only place a delegated
     * handler outside the panel can hear them.
     */
    document.addEventListener('click', function (event) {
        for (var i = 0; i < handlers.length; i++) {
            var match = event.target.closest(handlers[i][0]);

            if (match) {
                event.preventDefault();
                event.stopPropagation();
                handlers[i][1](match);

                return;
            }
        }
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-quick-menu-panel]').forEach(function (panel) {
            panel.quickMenuSnapshot = snapshot(panel);
        });
    });
})();
