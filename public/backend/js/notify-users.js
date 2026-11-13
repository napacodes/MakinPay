"use strict";

/**
 * Notify Users composer.
 *
 * The form is complete without this file: the option cards are real radio
 * inputs, the schedule field is a real datetime-local, and the submit button
 * posts what the bare form always posted. This only keeps the counters, the
 * live preview, the readiness line and the summary honest, and asks once
 * before anything is queued. Nothing here is load bearing for the POST.
 */
(function () {
    const root = document.querySelector("[data-notify-users]");

    if (!root) {
        return;
    }

    const labels = root.dataset;
    const pusherOn = labels.pusher === "1";
    const locale = document.documentElement.lang || "en";

    const audienceInputs = Array.from(root.querySelectorAll("[data-nu-audience]"));
    const channelInputs = Array.from(root.querySelectorAll("[data-nu-channel]"));
    const timingInputs = Array.from(root.querySelectorAll("[data-nu-timing]"));
    const titleField = root.querySelector("[data-nu-title]");
    const messageField = root.querySelector("[data-nu-message]");
    const scheduleField = root.querySelector("[data-nu-schedule-at]");
    const schedulePanel = root.querySelector("[data-nu-schedule-panel]");
    const scheduleNote = root.querySelector("[data-nu-schedule-note]");
    const pushNote = root.querySelector("[data-nu-push-note]");
    const titleHint = root.querySelector("[data-nu-title-hint]");
    const titleCount = root.querySelector("[data-nu-title-count]");
    const messageCount = root.querySelector("[data-nu-message-count]");
    const readyDot = root.querySelector("[data-nu-ready-dot]");
    const summaryLine = root.querySelector("[data-nu-summary-line]");
    const submitButton = root.querySelector("[data-nu-submit]");
    const ctaLabel = root.querySelector("[data-nu-cta]");
    const clearButton = root.querySelector("[data-nu-clear]");
    const sheet = root.querySelector("[data-nu-sheet]");
    const confirmTitle = root.querySelector("[data-nu-confirm-title]");
    const confirmBody = root.querySelector("[data-nu-confirm-body]");
    const confirmSubmit = root.querySelector("[data-nu-confirm-submit]");

    /* The preview and summary live outside the form, beside it. */
    const previewKind = document.querySelector("[data-nu-preview-kind]");
    const previews = Array.from(document.querySelectorAll("[data-nu-preview]"));
    const previewTitles = Array.from(document.querySelectorAll("[data-nu-preview-title]"));
    const previewMessages = Array.from(document.querySelectorAll("[data-nu-preview-message]"));

    let confirmed = false;
    let lastFocus = null;

    function fill(template, values) {
        return Object.keys(values).reduce(
            (text, key) => text.split(":" + key).join(values[key]),
            template || ""
        );
    }

    function formatCount(count) {
        return Number(count).toLocaleString(locale);
    }

    function checked(inputs) {
        return inputs.find((input) => input.checked) || inputs[0] || null;
    }

    function whenLabel(state) {
        if (!state.scheduled || !state.scheduleAt) {
            return labels.labelNow;
        }

        const date = new Date(state.scheduleAt);

        if (Number.isNaN(date.getTime())) {
            return labels.labelNow;
        }

        return date.toLocaleString(locale, { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" });
    }

    function readState() {
        const audience = checked(audienceInputs);
        const channel = checked(channelInputs);
        const timing = checked(timingInputs);
        const scheduled = timing !== null && timing.value === "schedule";
        const scheduleAt = scheduled && scheduleField ? scheduleField.value : "";
        const isPush = channel !== null && channel.value === "push";
        const title = titleField ? titleField.value.trim() : "";
        const message = messageField ? messageField.value.trim() : "";

        /*
         * The server refuses a time before now in the site timezone; the
         * browser can only compare in its own clock, so this is a courtesy
         * check rather than the rule.
         */
        const scheduleOk = !scheduled || (scheduleAt !== "" && new Date(scheduleAt).getTime() >= Date.now());
        const titleOk = isPush || title !== "";

        return {
            audience: audience,
            audienceLabel: audience ? audience.dataset.label : "",
            audienceNoun: audience ? audience.dataset.noun : "",
            audienceCount: audience ? Number(audience.dataset.count) : 0,
            isPush: isPush,
            title: title,
            message: message,
            scheduled: scheduled,
            scheduleAt: scheduleAt,
            scheduleOk: scheduleOk,
            titleOk: titleOk,
            ready: message !== "" && titleOk && scheduleOk,
        };
    }

    function blocker(state) {
        if (state.message === "") {
            return labels.labelNeedMessage;
        }

        if (!state.titleOk) {
            return labels.labelNeedTitle;
        }

        if (!state.scheduleOk) {
            return labels.labelNeedTime;
        }

        return "";
    }

    function rows(state) {
        const when = whenLabel(state);
        const channelRow = state.isPush
            ? (pusherOn ? labels.labelRowPushRealtime : labels.labelRowPush)
            : labels.labelRowEmail;
        const contentRow = state.message === ""
            ? labels.labelContentMissing
            : (state.isPush ? labels.labelContentPush : labels.labelContentEmail);

        return {
            audience: { value: fill(labels.labelAudienceRow, { label: state.audienceLabel, count: formatCount(state.audienceCount) }), ok: true },
            channel: { value: channelRow, ok: true },
            content: { value: contentRow, ok: state.message !== "" && state.titleOk },
            timing: { value: when, ok: state.scheduleOk },
        };
    }

    function paintMessageStep(state) {
        if (titleHint) {
            titleHint.textContent = state.isPush ? labels.labelOptional : "";
        }

        if (titleField) {
            titleField.placeholder = state.isPush ? labels.labelPlaceholderPush : labels.labelPlaceholderEmail;
            titleField.required = !state.isPush;
        }

        if (titleCount && titleField) {
            const length = titleField.value.length;

            titleCount.textContent = fill(labels.labelTitleCount, { count: length });
            titleCount.classList.toggle("is-over", length > 230);
        }

        if (messageCount && messageField) {
            const length = messageField.value.length;

            messageCount.textContent = fill(state.isPush ? labels.labelPushCount : labels.labelMessageCount, { count: length });
            messageCount.classList.toggle("is-over", state.isPush && length > 240);
        }

        root.querySelectorAll("[data-nu-hint]").forEach((hint) => {
            hint.hidden = hint.dataset.nuHint !== (state.isPush ? "push" : "email");
        });

        if (pushNote) {
            pushNote.hidden = !state.isPush;
        }
    }

    function paintDelivery(state) {
        if (schedulePanel) {
            schedulePanel.hidden = !state.scheduled;
            schedulePanel.classList.toggle("is-invalid", state.scheduled && !state.scheduleOk);
        }

        /*
         * "Send now" posts an empty schedule. A disabled control is left out
         * of the submission, which is the same thing and cannot leak a date
         * typed earlier and then abandoned.
         */
        if (scheduleField) {
            scheduleField.disabled = !state.scheduled;
        }

        if (scheduleNote) {
            scheduleNote.textContent = state.scheduleOk ? labels.labelTimeOk : labels.labelTimeBad;
        }
    }

    function paintFooter(state) {
        const summary = rows(state);

        if (readyDot) {
            readyDot.classList.toggle("is-ready", state.ready);
        }

        if (summaryLine) {
            summaryLine.textContent = state.ready
                ? fill(labels.labelSummary, {
                    channel: state.isPush ? labels.labelChannelPush : labels.labelChannelEmail,
                    count: formatCount(state.audienceCount),
                    audience: state.audienceNoun,
                    when: summary.timing.value,
                })
                : blocker(state);
        }

        if (submitButton) {
            submitButton.disabled = !state.ready;
        }

        if (ctaLabel) {
            ctaLabel.textContent = state.scheduled ? labels.labelCtaSchedule : labels.labelCtaSend;
        }
    }

    function paintPreview(state) {
        const kind = state.isPush ? "push" : "email";
        const title = state.title !== ""
            ? state.title
            : (state.isPush ? labels.labelPlaceholderPush : labels.labelPreviewTitle);
        const message = state.message !== "" ? messageField.value : labels.labelPreviewMessage;

        if (previewKind) {
            previewKind.textContent = state.isPush ? labels.labelKindPush : labels.labelKindEmail;
        }

        previews.forEach((preview) => {
            preview.hidden = preview.dataset.nuPreview !== kind;
        });

        previewTitles.forEach((node) => {
            node.textContent = title;
        });

        previewMessages.forEach((node) => {
            node.textContent = message;
        });
    }

    function paintSummary(state) {
        const summary = rows(state);

        Object.keys(summary).forEach((key) => {
            const row = document.querySelector('[data-nu-check="' + key + '"]');

            if (!row) {
                return;
            }

            row.classList.toggle("is-ok", summary[key].ok);

            const value = row.querySelector("[data-nu-check-value]");

            if (value) {
                value.textContent = summary[key].value;
            }
        });
    }

    function paintConfirm(state) {
        const summary = rows(state);

        if (confirmTitle) {
            confirmTitle.textContent = state.scheduled
                ? labels.labelConfirmSchedule
                : fill(labels.labelConfirmSend, { count: formatCount(state.audienceCount), audience: state.audienceNoun });
        }

        if (confirmBody) {
            confirmBody.textContent = state.isPush ? labels.labelConfirmBodyPush : labels.labelConfirmBodyEmail;
        }

        if (confirmSubmit) {
            confirmSubmit.textContent = state.scheduled ? labels.labelConfirmCtaSchedule : labels.labelConfirmCtaSend;
        }

        Object.keys(summary).forEach((key) => {
            const cell = root.querySelector('[data-nu-confirm="' + key + '"]');

            if (cell) {
                cell.textContent = summary[key].value;
            }
        });
    }

    function repaint() {
        const state = readState();

        paintMessageStep(state);
        paintDelivery(state);
        paintFooter(state);
        paintPreview(state);
        paintSummary(state);
        paintConfirm(state);
    }

    function openSheet() {
        if (!sheet) {
            return;
        }

        lastFocus = document.activeElement;
        sheet.hidden = false;

        if (confirmSubmit) {
            confirmSubmit.focus();
        }
    }

    function closeSheet() {
        if (!sheet || sheet.hidden) {
            return;
        }

        sheet.hidden = true;

        if (lastFocus && typeof lastFocus.focus === "function") {
            lastFocus.focus();
        }
    }

    root.addEventListener("input", repaint);
    root.addEventListener("change", repaint);

    /*
     * The submit button asks first. Once the sheet has been confirmed the
     * same submit runs through untouched — the form posts itself, so the
     * browser's own validation, CSRF and redirect all behave as they always
     * did.
     */
    root.addEventListener("submit", (event) => {
        if (confirmed) {
            return;
        }

        event.preventDefault();

        const state = readState();

        if (!state.ready) {
            repaint();

            return;
        }

        paintConfirm(state);
        openSheet();
    });

    if (confirmSubmit) {
        confirmSubmit.addEventListener("click", () => {
            confirmed = true;
            confirmSubmit.disabled = true;
            confirmSubmit.textContent = labels.labelProcessing;

            if (submitButton) {
                submitButton.disabled = true;
            }

            if (typeof root.requestSubmit === "function") {
                root.requestSubmit();
            } else {
                root.submit();
            }
        });
    }

    root.querySelectorAll("[data-nu-sheet-close]").forEach((button) => {
        button.addEventListener("click", closeSheet);
    });

    if (sheet) {
        sheet.addEventListener("click", (event) => {
            if (event.target === sheet) {
                closeSheet();
            }
        });
    }

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            closeSheet();
        }
    });

    if (clearButton) {
        clearButton.addEventListener("click", () => {
            root.reset();

            /* reset() restores the server-rendered values; "old" input is not what Clear means. */
            audienceInputs.forEach((input) => { input.checked = input.value === "all"; });
            channelInputs.forEach((input) => { input.checked = input.value === "email"; });
            timingInputs.forEach((input) => { input.checked = input.value === "now"; });

            if (titleField) {
                titleField.value = "";
            }

            if (messageField) {
                messageField.value = "";
            }

            if (scheduleField) {
                scheduleField.value = "";
            }

            repaint();

            if (messageField) {
                messageField.focus();
            }
        });
    }

    repaint();
})();
