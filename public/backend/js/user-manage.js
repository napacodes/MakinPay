"use strict";

/**
 * Admin → Users → Manage user.
 *
 * Every form on the page is complete without this file: the modals are
 * real forms posting to real routes, the segmented controls are links, and
 * the switches carry hidden inputs. This script only keeps the previews,
 * counters, computed labels and enable/disable states honest, and turns
 * the Account controls switches into immediate posts with a revert on
 * failure.
 */
(function () {
    const page = document.querySelector("[data-user-manage]");

    if (!page) {
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    const isDemo = page.dataset.demo === "1";
    const toast = (type, message) => {
        if (typeof window.notifyEvs === "function") {
            window.notifyEvs(type, message);
        }
    };
    const fill = (template, value) => String(template || "").replace("%s", value);
    const money = (amount, option) => {
        const decimals = Number(option.dataset.decimals || 2);
        const formatted = Number(amount).toLocaleString(document.documentElement.lang || "en", {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
        const symbol = option.dataset.symbol || "";

        return option.dataset.position === "suffix" ? formatted + symbol : symbol + formatted;
    };

    /* ── Switches: a button[role=switch] mirrored into a hidden input ─── */

    const setSwitch = (button, on) => {
        button.setAttribute("aria-checked", on ? "true" : "false");
        const key = button.dataset.umSwitch;
        const input = key ? button.closest("form")?.querySelector('[data-um-switch-value="' + key + '"]') : null;

        if (input) {
            input.value = on ? "1" : "0";
        }
    };

    page.querySelectorAll("[data-um-switch]").forEach((button) => {
        setSwitch(button, button.getAttribute("aria-checked") === "true");
        button.addEventListener("click", (event) => {
            event.preventDefault();
            setSwitch(button, button.getAttribute("aria-checked") !== "true");
            button.dispatchEvent(new CustomEvent("um:switch", { bubbles: true }));
        });
    });

    page.querySelectorAll("form[data-um-confirm]").forEach((form) => {
        form.addEventListener("submit", (event) => {
            if (!window.confirm(form.dataset.umConfirm)) {
                event.preventDefault();
            }
        });
    });

    /* ── Country: tax reads local or international from it, so a change asks first ─ */

    page.querySelectorAll("form[data-um-info]").forEach((form) => {
        const country = form.querySelector("[data-um-country]");

        if (!country) {
            return;
        }

        form.addEventListener("submit", (event) => {
            if (country.value !== country.dataset.umOriginal && !window.confirm(country.dataset.umConfirm)) {
                event.preventDefault();
            }
        });
    });

    /* ── Account controls: post each toggle immediately, revert on error ─ */

    const onCount = page.querySelector("[data-um-on-count]");
    const recount = () => {
        if (onCount) {
            onCount.textContent = String(page.querySelectorAll('[data-um-feature][aria-checked="true"]').length);
        }
    };

    page.querySelectorAll("[data-um-feature]").forEach((button) => {
        const row = button.closest("[data-um-control]");
        const paint = (on) => {
            button.setAttribute("aria-checked", on ? "true" : "false");
            row?.classList.toggle("is-on", on);
            recount();
        };

        const toggle = () => {
            if (isDemo) {
                toast("error", page.dataset.i18nDemo);
                return;
            }

            const was = button.getAttribute("aria-checked") === "true";
            const now = !was;
            paint(now);
            row?.classList.add("is-busy");

            const body = new URLSearchParams({
                _token: csrf,
                user_id: page.dataset.userId,
                feature: button.dataset.umFeature,
                status: now ? "1" : "0",
            });

            fetch(page.dataset.featureUrl, {
                method: "POST",
                headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" },
                body,
            })
                .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    if (!ok || data.type !== "success") {
                        paint(was);
                    }
                    toast(data.type || (ok ? "success" : "error"), data.message || page.dataset.i18nError);
                })
                .catch(() => {
                    paint(was);
                    toast("error", page.dataset.i18nError);
                })
                .finally(() => row?.classList.remove("is-busy"));
        };

        button.addEventListener("click", (event) => {
            event.stopPropagation();
            toggle();
        });
        row?.addEventListener("click", toggle);
    });

    /* ── Date range (transactions, activity) ────────────────────────────── */

    page.querySelectorAll("[data-um-daterange]").forEach((button) => {
        const form = button.closest("form");
        const input = form?.querySelector("[data-um-daterange-value]");

        if (!form || !input || !window.jQuery || !window.jQuery.fn.daterangepicker || !window.moment) {
            return;
        }

        const $button = window.jQuery(button);
        const current = (input.value || "").split(",");
        const options = {
            autoUpdateInput: false,
            opens: document.documentElement.dir === "rtl" ? "right" : "left",
            locale: { cancelLabel: button.dataset.i18nAll, format: "YYYY-MM-DD" },
        };

        if (current.length === 2) {
            options.startDate = window.moment(current[0]);
            options.endDate = window.moment(current[1]);
        }

        $button.daterangepicker(options);
        $button.on("apply.daterangepicker", (event, picker) => {
            input.value = picker.startDate.format("YYYY-MM-DD") + "," + picker.endDate.format("YYYY-MM-DD");
            form.submit();
        });
        $button.on("cancel.daterangepicker", () => {
            input.value = "";
            form.submit();
        });
    });

    /* ── Manage funds ───────────────────────────────────────────────────── */

    const funds = page.querySelector("[data-um-funds]");

    if (funds) {
        const walletInput = funds.querySelector("[data-um-fund-wallet]");
        const modeInput = funds.querySelector("[data-um-fund-mode]");
        const amountInput = funds.querySelector("[data-um-fund-amount]");
        const field = funds.querySelector("[data-um-fund-field]");
        const error = funds.querySelector("[data-um-fund-error]");
        const errorBalance = funds.querySelector("[data-um-fund-error-balance]");
        const submit = funds.querySelector("[data-um-fund-submit]");
        const options = Array.from(funds.querySelectorAll("[data-um-fund-pick]"));
        const active = () => options.find((option) => option.classList.contains("is-active")) || options[0];

        const render = () => {
            const option = active();

            if (!option) {
                submit.disabled = true;
                return;
            }

            const balance = Number(option.dataset.balance || 0);
            const amount = Number(String(amountInput.value).replace(/[^0-9.]/g, "")) || 0;
            const adding = modeInput.value === "add";
            const after = adding ? balance + amount : balance - amount;
            const over = !adding && amount > balance;
            const ok = amount > 0 && !over;

            funds.querySelector("[data-um-fund-available]").textContent = money(balance, option);
            funds.querySelector("[data-um-fund-current]").textContent = money(balance, option);
            funds.querySelector("[data-um-fund-symbol]").textContent = option.dataset.symbol || "";
            funds.querySelector("[data-um-fund-code]").textContent = option.dataset.code || "";

            const afterCell = funds.querySelector("[data-um-fund-after]");
            afterCell.textContent = money(Math.max(0, after), option);
            afterCell.classList.toggle("is-over", over);
            afterCell.classList.toggle("is-in", !over && adding && amount > 0);

            field.classList.toggle("is-invalid", over);
            error.classList.toggle("um-hidden", !over);
            errorBalance.textContent = over ? money(balance, option) : "";

            submit.disabled = !ok;
            submit.classList.toggle("um-btn--primary", adding);
            submit.classList.toggle("um-btn--warning", !adding);
            submit.textContent = fill(adding ? submit.dataset.labelAdd : submit.dataset.labelSubtract, amount > 0 ? money(amount, option) : submit.dataset.labelFunds);
        };

        options.forEach((option) => option.addEventListener("click", () => {
            options.forEach((other) => other.classList.toggle("is-active", other === option));
            walletInput.value = option.dataset.walletId;
            render();
        }));

        funds.querySelectorAll("[data-um-fund-mode-pick]").forEach((button) => button.addEventListener("click", () => {
            funds.querySelectorAll("[data-um-fund-mode-pick]").forEach((other) => other.classList.toggle("is-active", other === button));
            modeInput.value = button.dataset.umFundModePick;
            render();
        }));

        funds.querySelectorAll("[data-um-fund-quick]").forEach((chip) => chip.addEventListener("click", () => {
            amountInput.value = chip.dataset.umFundQuick;
            render();
        }));

        amountInput.addEventListener("input", () => {
            amountInput.value = String(amountInput.value).replace(/[^0-9.]/g, "");
            render();
        });

        render();
    }

    /* ── Notify user ────────────────────────────────────────────────────── */

    const notify = page.querySelector("[data-um-notify]");

    if (notify) {
        const i18n = notify.dataset;
        const typeInput = notify.querySelector("[data-um-notify-type]");
        const title = notify.querySelector("[data-um-notify-title]");
        const message = notify.querySelector("[data-um-notify-message]");
        const submit = notify.querySelector("[data-um-notify-submit]");
        const readiness = notify.querySelector("[data-um-notify-ready]");
        const setText = (selector, text) => {
            const node = notify.querySelector(selector);
            if (node) {
                node.textContent = text;
            }
        };

        const render = () => {
            const push = typeInput.value === "push";
            const hasMessage = message.value.trim().length > 0;
            const hasTitle = title.value.trim().length > 0;
            const ok = hasMessage && (push || hasTitle);
            const titleShown = title.value.trim() || (push ? i18n.i18nTitlePh : i18n.i18nPvSubject);
            const messageShown = message.value.trim() || i18n.i18nPvMessage;

            setText("[data-um-notify-title-label]", push ? i18n.i18nTitle : i18n.i18nSubject);
            notify.querySelector("[data-um-notify-title-optional]").classList.toggle("um-hidden", !push);
            title.placeholder = push ? i18n.i18nTitlePh : i18n.i18nSubjectPh;
            title.required = !push;

            const titleCount = notify.querySelector("[data-um-notify-title-count]");
            titleCount.textContent = title.value.length + " / 255";
            titleCount.classList.toggle("is-hot", title.value.length > 230);

            const messageCount = notify.querySelector("[data-um-notify-message-count]");
            messageCount.textContent = fill(push ? i18n.i18nCountPush : i18n.i18nCountEmail, message.value.length);
            messageCount.classList.toggle("is-hot", push && message.value.length > 240);

            setText("[data-um-notify-hint]", push ? i18n.i18nHintPush : i18n.i18nHintEmail);
            setText("[data-um-notify-preview-kind]", push ? i18n.i18nPreviewPush : i18n.i18nPreviewEmail);
            notify.querySelector("[data-um-notify-preview-push]").classList.toggle("um-hidden", !push);
            notify.querySelector("[data-um-notify-preview-email]").classList.toggle("um-hidden", push);
            setText("[data-um-pv-push-title]", titleShown);
            setText("[data-um-pv-push-text]", messageShown);
            setText("[data-um-pv-mail-subject]", titleShown);
            setText("[data-um-pv-mail-title]", titleShown);
            setText("[data-um-pv-mail-text]", messageShown);

            readiness.classList.toggle("is-ready", ok);
            setText("[data-um-notify-ready-text]", ok ? (push ? i18n.i18nReadyPush : i18n.i18nReadyEmail) : (hasMessage ? i18n.i18nNeedSubject : i18n.i18nNeedMessage));
            setText("[data-um-notify-cta]", push ? i18n.i18nCtaPush : i18n.i18nCtaEmail);
            submit.disabled = !ok;
        };

        notify.querySelectorAll("[data-um-notify-channel]").forEach((option) => option.addEventListener("click", () => {
            notify.querySelectorAll("[data-um-notify-channel]").forEach((other) => other.classList.toggle("is-active", other === option));
            typeInput.value = option.dataset.umNotifyChannel;
            render();
        }));

        notify.querySelectorAll("[data-um-notify-template]").forEach((chip) => chip.addEventListener("click", () => {
            notify.querySelectorAll("[data-um-notify-template]").forEach((other) => other.classList.toggle("is-active", other === chip));
            title.value = chip.dataset.title || "";
            message.value = chip.dataset.message || "";
            render();
        }));

        const clearTemplate = () => notify.querySelectorAll("[data-um-notify-template]").forEach((chip) => chip.classList.remove("is-active"));
        title.addEventListener("input", () => { clearTemplate(); render(); });
        message.addEventListener("input", () => { clearTemplate(); render(); });

        render();
    }

    /* ── Reset password ─────────────────────────────────────────────────── */

    const reset = page.querySelector("[data-um-reset]");

    if (reset) {
        const methodInput = reset.querySelector("[data-um-reset-method]");
        const password = reset.querySelector("[data-um-reset-password]");
        const strength = reset.querySelector("[data-um-reset-strength]");
        const strengthLabel = reset.querySelector("[data-um-reset-strength-label]");
        const submit = reset.querySelector("[data-um-reset-submit]");
        const warning = reset.querySelector("[data-um-reset-warning]");
        const labels = (page.dataset.i18nStrength || "").split("|");

        const score = (value) => Math.min(4,
            (value.length >= 8 ? 1 : 0)
            + (value.length >= 12 ? 1 : 0)
            + (/[A-Z]/.test(value) && /[a-z]/.test(value) ? 1 : 0)
            + (/[0-9]/.test(value) && /[^A-Za-z0-9]/.test(value) ? 1 : 0));

        const render = () => {
            const manual = methodInput.value === "manual";
            const points = manual ? score(password.value) : 0;

            reset.querySelectorAll("[data-um-reset-manual]").forEach((node) => node.classList.toggle("um-hidden", !manual));
            password.disabled = !manual;
            strength.dataset.score = String(points);
            strengthLabel.textContent = password.value ? labels[Math.max(1, points)] || labels[1] : labels[0];
            warning.textContent = manual ? warning.dataset.manual : warning.dataset.link;
            submit.textContent = manual ? submit.dataset.labelManual : submit.dataset.labelLink;
            submit.disabled = manual && points < 2;
        };

        reset.querySelectorAll("[data-um-reset-pick]").forEach((option) => option.addEventListener("click", () => {
            reset.querySelectorAll("[data-um-reset-pick]").forEach((other) => other.classList.toggle("is-active", other === option));
            methodInput.value = option.dataset.umResetPick;
            render();
        }));

        reset.querySelector("[data-um-reset-toggle]").addEventListener("click", (event) => {
            const visible = password.type === "password";
            password.type = visible ? "text" : "password";
            password.classList.toggle("is-visible", visible);
            event.currentTarget.textContent = visible ? page.dataset.i18nHide : page.dataset.i18nShow;
        });

        reset.querySelector("[data-um-reset-generate]").addEventListener("click", () => {
            const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%";
            const random = new Uint32Array(16);
            window.crypto.getRandomValues(random);
            password.value = Array.from(random, (n) => alphabet[n % alphabet.length]).join("");
            password.type = "text";
            password.classList.add("is-visible");
            reset.querySelector("[data-um-reset-toggle]").textContent = page.dataset.i18nHide;
            render();
        });

        password.addEventListener("input", render);
        render();
    }

    /* ── Convert to merchant ────────────────────────────────────────────── */

    const convert = page.querySelector("[data-um-convert]");

    if (convert) {
        const name = convert.querySelector("[data-um-convert-name]");
        const submit = convert.querySelector("[data-um-convert-submit]");
        const render = () => { submit.disabled = name.value.trim().length < 2; };
        name.addEventListener("input", render);
        render();
    }

    /* ── Suspend account ────────────────────────────────────────────────── */

    const suspend = page.querySelector("[data-um-suspend]");

    if (suspend) {
        const reasonInput = suspend.querySelector("[data-um-suspend-reason]");
        const durationInput = suspend.querySelector("[data-um-suspend-duration]");
        const until = suspend.querySelector("[data-um-suspend-until]");
        const submit = suspend.querySelector("[data-um-suspend-submit]");

        const render = () => {
            const active = suspend.querySelector("[data-um-suspend-duration-pick].is-active");
            const indefinite = durationInput.value === "indefinite";

            until.textContent = indefinite ? suspend.dataset.i18nIndefinite : fill(suspend.dataset.i18nUntil, active?.dataset.until || "");
            submit.textContent = indefinite ? suspend.dataset.i18nCtaIndefinite : fill(suspend.dataset.i18nCtaFor, active?.dataset.label || "");
            submit.disabled = reasonInput.value === "";
        };

        suspend.querySelectorAll("[data-um-suspend-reason-pick]").forEach((chip) => chip.addEventListener("click", () => {
            suspend.querySelectorAll("[data-um-suspend-reason-pick]").forEach((other) => other.classList.toggle("is-active", other === chip));
            reasonInput.value = chip.dataset.umSuspendReasonPick;
            render();
        }));

        suspend.querySelectorAll("[data-um-suspend-duration-pick]").forEach((button) => button.addEventListener("click", () => {
            suspend.querySelectorAll("[data-um-suspend-duration-pick]").forEach((other) => other.classList.toggle("is-active", other === button));
            durationInput.value = button.dataset.umSuspendDurationPick;
            render();
        }));

        render();
    }

    /* ── Delete account ─────────────────────────────────────────────────── */

    const remove = page.querySelector("[data-um-delete]");

    if (remove) {
        const confirmInput = remove.querySelector("[data-um-delete-confirm]");
        const submit = remove.querySelector("[data-um-delete-submit]");
        const forfeit = remove.querySelector('[data-um-switch="forfeit"]');
        const holdsBalance = remove.dataset.holdsBalance === "1";

        const render = () => {
            const typed = confirmInput.value.trim() === "DELETE";
            const acknowledged = !holdsBalance || (forfeit && forfeit.getAttribute("aria-checked") === "true");
            submit.disabled = !(typed && acknowledged);
        };

        confirmInput.addEventListener("input", render);
        remove.addEventListener("um:switch", render);
        render();
    }

    /* ── Overview range: repaint the KPI strip and chart from JSON ─────── */

    const overview = page.querySelector("[data-um-overview]");

    if (overview && window.fetch) {
        const chart = overview.querySelector(".um-chart");
        const setText = (key, text) => {
            const node = overview.querySelector('[data-um-kpi="' + key + '"]');
            if (node) {
                node.textContent = text;
            }
        };

        const paint = (data) => {
            ["in_total", "out_total", "net", "in_note", "out_note", "net_note"].forEach((key) => setText(key, data[key]));

            const axis = overview.querySelector("[data-um-chart-axis]");
            const grid = overview.querySelector("[data-um-chart-grid]");
            const bars = overview.querySelector("[data-um-chart-bars]");
            const labels = overview.querySelector("[data-um-chart-labels]");

            axis.replaceChildren(...data.ticks.map((tick) => Object.assign(document.createElement("span"), { textContent: tick })));
            grid.replaceChildren(...data.ticks.map((tick, i) => Object.assign(document.createElement("span"), { className: i === data.ticks.length - 1 ? "is-base" : "" })));
            chart.style.setProperty("--um-buckets", String(data.bars.length));

            bars.replaceChildren(...data.bars.map((bar) => {
                const group = document.createElement("div");
                group.className = "um-chart__group" + (bar.current ? " is-current" : "");
                group.title = bar.tip;
                const inBar = document.createElement("span");
                inBar.className = "um-chart__bar";
                inBar.style.height = bar.in + "%";
                const outBar = document.createElement("span");
                outBar.className = "um-chart__bar um-chart__bar--out";
                outBar.style.height = bar.out + "%";
                group.append(inBar, outBar);
                return group;
            }));

            labels.replaceChildren(...data.bars.map((bar) => Object.assign(document.createElement("span"), { textContent: bar.label, className: bar.current ? "is-current" : "" })));
        };

        overview.querySelectorAll("[data-um-range]").forEach((link) => link.addEventListener("click", (event) => {
            event.preventDefault();
            overview.querySelectorAll("[data-um-range]").forEach((other) => {
                other.classList.toggle("is-active", other === link);
                if (other === link) {
                    other.setAttribute("aria-current", "true");
                } else {
                    other.removeAttribute("aria-current");
                }
            });
            chart.classList.add("is-loading");

            fetch(link.href, { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
                .then((response) => (response.ok ? response.json() : Promise.reject(response)))
                .then((data) => {
                    paint(data);
                    window.history.replaceState(null, "", link.href);
                })
                .catch(() => { window.location.href = link.href; })
                .finally(() => chart.classList.remove("is-loading"));
        }));
    }

    /* ── Wallets: four rows show, the rest scroll inside the card ─────── */

    const walletList = page.querySelector("[data-um-wallet-list]");

    if (walletList) {
        const visible = parseInt(walletList.dataset.umWalletVisible, 10) || 4;
        const rows = [...walletList.querySelectorAll(".um-wallet")];

        walletList.style.maxHeight = rows.length > visible
            ? rows.slice(0, visible).reduce((sum, row) => sum + row.getBoundingClientRect().height, 0) + "px"
            : "none";
    }

    /* ── Rail height: the controls list ends where the main column ends ── */

    const rail = page.querySelector(".um-rail");
    const controlsList = page.querySelector(".um-controls");
    const main = page.querySelector(".um-main");

    if (rail && controlsList && main) {
        /*
         * With align-items: stretch the row is as tall as its taller column,
         * so a long controls list would stretch the main column instead of
         * scrolling. The list is therefore capped to what the main column's
         * own content leaves: measure with the list at its minimum, then give
         * it the remainder. Stacked (rail under main) the CSS cap applies.
         */
        const sync = () => {
            const beside = Math.abs(rail.getBoundingClientRect().top - main.getBoundingClientRect().top) < 2
                && rail.getBoundingClientRect().left !== main.getBoundingClientRect().left;

            controlsList.style.maxHeight = "";

            if (!beside) {
                return;
            }

            controlsList.style.maxHeight = "240px";

            /*
             * The rail and the controls card both stretch, so their own box
             * heights say nothing; add up the parts that do not: every other
             * rail card, the gaps between them and the controls header.
             */
            const controlsCard = controlsList.closest(".um-card");
            const gap = parseFloat(getComputedStyle(rail).rowGap) || 16;
            const railRest = [...rail.children]
                .filter((child) => child !== controlsCard)
                .reduce((sum, child) => sum + child.getBoundingClientRect().height, 0)
                + gap * (rail.children.length - 1)
                + (controlsCard.querySelector(".um-card__head")?.getBoundingClientRect().height || 0);
            const room = Math.max(240, Math.floor(main.getBoundingClientRect().height - railRest));
            controlsList.style.maxHeight = room + "px";
        };

        sync();
        window.addEventListener("resize", sync);
        window.addEventListener("load", sync);
    }
})();
