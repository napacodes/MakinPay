"use strict";

(function () {
    const root = () => document.querySelector("[data-team]");

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute("content") : "";
    }

    function notify(type, message) {
        if (typeof window.notifyEvs === "function") {
            window.notifyEvs(type, message);
        }
    }

    function firstError(data) {
        if (data && data.errors) {
            const keys = Object.keys(data.errors);

            if (keys.length) {
                return data.errors[keys[0]][0];
            }
        }

        return (data && data.message) || "Request failed.";
    }

    /**
     * POST a form body and hand back {ok, data}.
     *
     * Every drawer action is the same shape — a token, a URL, a JSON answer —
     * so the three buttons share one path rather than three near-copies.
     */
    function post(url, fields) {
        const body = new FormData();

        body.set("_token", csrfToken());

        Object.keys(fields || {}).forEach((key) => body.set(key, fields[key]));

        return fetch(url, {
            method: "POST",
            body: body,
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
        }).then((response) => response.json().then((data) => ({ ok: response.ok, data: data })));
    }

    const TONES = ["g1", "g2", "g3", "g4", "g5", "g6"];
    const PRESENCE = ["online", "offline", "blocked"];

    function setText(scope, selector, value) {
        const el = scope.querySelector(selector);

        if (el) {
            el.textContent = value === null || value === undefined || value === "" ? "—" : value;
        }
    }

    function renderDrawer(drawer, staff) {
        const face = drawer.querySelector("[data-drawer-avatar]");

        if (face) {
            TONES.forEach((tone) => face.classList.remove("team-avatar__face--" + tone));
            face.classList.add("team-avatar__face--" + staff.tone);

            if (staff.avatar) {
                face.innerHTML = "";
                const img = document.createElement("img");
                img.src = staff.avatar;
                img.alt = "";
                face.appendChild(img);
            } else {
                face.textContent = staff.initials;
            }
        }

        const presence = drawer.querySelector("[data-drawer-presence]");

        if (presence) {
            PRESENCE.forEach((state) => presence.classList.remove("team-avatar__presence--" + state));
            presence.classList.add("team-avatar__presence--" + staff.presence);
        }

        setText(drawer, "[data-drawer-name]", staff.name);
        setText(drawer, "[data-drawer-email]", staff.email);
        setText(drawer, "[data-drawer-role]", staff.role_label || staff.role || "");
        setText(drawer, "[data-drawer-last-active]", staff.last_active);
        setText(drawer, "[data-drawer-joined]", staff.joined);
        setText(drawer, "[data-drawer-ip]", staff.last_ip);

        const tfa = drawer.querySelector("[data-drawer-tfa]");

        if (tfa) {
            tfa.classList.toggle("team-drawer__chip--tfa-on", !!staff.two_factor);
            tfa.classList.toggle("team-drawer__chip--tfa-off", !staff.two_factor);
        }

        const form = drawer.querySelector("[data-team-form]");

        if (form) {
            form.setAttribute("data-action", staff.update_url);

            const name = form.querySelector("[data-drawer-input-name]");
            const email = form.querySelector("[data-drawer-input-email]");
            const role = form.querySelector("[data-drawer-input-role]");

            if (name) {
                name.value = staff.name || "";
            }

            if (email) {
                email.value = staff.email || "";
            }

            if (role && staff.role) {
                role.value = staff.role;
            }
        }

        const toggle = drawer.querySelector("[data-team-status]");

        if (toggle) {
            toggle.setAttribute("aria-checked", staff.status ? "true" : "false");
            toggle.setAttribute("data-action", staff.status_url);
        }

        const reset = drawer.querySelector("[data-team-reset]");

        if (reset) {
            reset.setAttribute("data-action", staff.password_reset_url);
        }

        const revoke = drawer.querySelector("[data-team-revoke]");

        if (revoke) {
            revoke.setAttribute("data-action", staff.revoke_url);
        }

        openModal();
    }

    /*
     * Manage is a modal now, and it is rendered outside the table so its
     * backdrop covers the page. That puts it outside `[data-team]`, so it is
     * looked up on the document rather than within the container.
     */
    function drawerElement() {
        return document.querySelector("[data-team-drawer]");
    }

    function openModal() {
        const element = document.getElementById("team_manage_modal");

        if (!element || !window.coreui || !window.coreui.Modal) {
            return;
        }

        window.coreui.Modal.getOrCreateInstance(element).show();
    }

    function clearSelection() {
        const container = root();

        if (!container) {
            return;
        }

        container.querySelectorAll("[data-team-row]").forEach((node) => {
            node.classList.remove("is-selected");
            node.setAttribute("aria-pressed", "false");
        });
    }

    function selectRow(row) {
        const container = root();
        const drawer = drawerElement();
        const payload = row.getAttribute("data-staff");

        if (!container || !drawer || !payload) {
            return;
        }

        container.querySelectorAll("[data-team-row]").forEach((node) => {
            const selected = node === row;

            node.classList.toggle("is-selected", selected);
            node.setAttribute("aria-pressed", selected ? "true" : "false");
        });

        try {
            renderDrawer(drawer, JSON.parse(payload));
        } catch (error) {
            console.error("Team manage payload could not be read.", error);
        }
    }

    function currentRow() {
        const container = root();

        return container ? container.querySelector("[data-team-row].is-selected") : null;
    }

    /** Keep the row in step with what the drawer just changed. */
    function patchRow(changes) {
        const row = currentRow();

        if (!row) {
            return;
        }

        let staff = {};

        try {
            staff = JSON.parse(row.getAttribute("data-staff"));
        } catch (error) {
            return;
        }

        Object.keys(changes).forEach((key) => {
            if (changes[key] !== undefined) {
                staff[key] = changes[key];
            }
        });

        row.setAttribute("data-staff", JSON.stringify(staff));
    }

    function saveMember(button, drawer) {
        const form = drawer ? drawer.querySelector("[data-team-form]") : null;

        if (!form) {
            return;
        }

        const toggle = drawer.querySelector("[data-team-status]");
        const body = new FormData(form);

        body.set("_token", csrfToken());
        body.set("_method", "PUT");
        body.set("status", toggle && toggle.getAttribute("aria-checked") === "true" ? "1" : "0");

        button.disabled = true;

        fetch(form.getAttribute("data-action"), {
            method: "POST",
            body: body,
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data: data })))
            .then((result) => {
                if (!result.ok) {
                    notify("error", firstError(result.data));

                    return;
                }

                notify("success", result.data.message);

                const row = currentRow();
                const nameInput = form.querySelector("[data-drawer-input-name]");
                const emailInput = form.querySelector("[data-drawer-input-email]");

                if (row) {
                    const name = row.querySelector(".team-member__name");
                    const email = row.querySelector(".team-member__email");

                    if (name && nameInput && name.firstChild) {
                        name.firstChild.textContent = nameInput.value + " ";
                    }

                    if (email && emailInput) {
                        email.textContent = emailInput.value;
                    }
                }

                patchRow({
                    name: nameInput ? nameInput.value : undefined,
                    email: emailInput ? emailInput.value : undefined,
                });
            })
            .catch(() => notify("error", "Request failed."))
            .finally(() => {
                button.disabled = false;
            });
    }

    /**
     * The switch moves first and is put back if the server refuses.
     *
     * The server is the authority on "you cannot deactivate yourself" and "not
     * the last active super admin", so a refusal has to be able to undo the
     * flip rather than leave the screen claiming something untrue.
     */
    function toggleStatus(toggle) {
        const next = toggle.getAttribute("aria-checked") !== "true";

        toggle.setAttribute("aria-checked", next ? "true" : "false");
        toggle.disabled = true;

        post(toggle.getAttribute("data-action"), { status: next ? "1" : "0" })
            .then((result) => {
                if (!result.ok) {
                    toggle.setAttribute("aria-checked", next ? "false" : "true");
                    notify("error", firstError(result.data));

                    return;
                }

                notify("success", result.data.message);
                patchRow({ status: next });

                const row = currentRow();

                if (row) {
                    const pill = row.querySelector(".team-status");
                    const presence = row.querySelector(".team-avatar__presence");

                    if (pill) {
                        pill.classList.toggle("team-status--active", next);
                        pill.classList.toggle("team-status--inactive", !next);
                    }

                    if (presence && !next) {
                        PRESENCE.forEach((state) => presence.classList.remove("team-avatar__presence--" + state));
                        presence.classList.add("team-avatar__presence--blocked");
                    }
                }
            })
            .catch(() => {
                toggle.setAttribute("aria-checked", next ? "false" : "true");
                notify("error", "Request failed.");
            })
            .finally(() => {
                toggle.disabled = false;
            });
    }

    function simpleAction(button) {
        button.disabled = true;

        post(button.getAttribute("data-action"), {})
            .then((result) => {
                notify(result.ok ? "success" : "error", firstError(result.data));
            })
            .catch(() => notify("error", "Request failed."))
            .finally(() => {
                button.disabled = false;
            });
    }

    document.addEventListener("DOMContentLoaded", () => {
        const container = root();

        if (!container) {
            return;
        }

        /*
         * Bound to the document, not the container. The modal is rendered
         * outside `[data-team]` so its backdrop covers the page, which puts
         * Save, the status switch and the two buttons beyond the container's
         * reach — a listener there would have stopped hearing them the moment
         * the drawer became a dialog.
         */
        document.addEventListener("click", (event) => {
            const save = event.target.closest("[data-team-save]");

            if (save) {
                saveMember(save, drawerElement());

                return;
            }

            const toggle = event.target.closest("[data-team-status]");

            if (toggle) {
                toggleStatus(toggle);

                return;
            }

            const reset = event.target.closest("[data-team-reset]");

            if (reset) {
                simpleAction(reset);

                return;
            }

            const revoke = event.target.closest("[data-team-revoke]");

            if (revoke) {
                simpleAction(revoke);

                return;
            }

            const row = event.target.closest("[data-team-row]");

            if (row) {
                selectRow(row);
            }
        });

        /*
         * CoreUI owns closing — the × dismisses it, so does Escape and the
         * backdrop. The row highlight has to be dropped on all three, and
         * only the event knows about all three.
         */
        const modal = document.getElementById("team_manage_modal");

        if (modal) {
            modal.addEventListener("hidden.coreui.modal", clearSelection);
        }
    });
})();
