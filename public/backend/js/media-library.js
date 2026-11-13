"use strict";

(function () {
    const root = () => document.querySelector("[data-media-library]");

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute("content") : "";
    }

    function notify(type, message) {
        if (typeof window.notifyEvs === "function") {
            window.notifyEvs(type, message);
        }
    }

    /* ───────────────────────────  storage providers  ─────────────────────── */

    function toggleProviderFields(form) {
        const driverField = form.querySelector("[data-media-provider-driver]");

        if (!driverField) {
            return;
        }

        const isS3 = driverField.value === "s3";

        form.querySelectorAll("[data-s3-fields]").forEach((el) => el.classList.toggle("d-none", !isS3));
        form.querySelectorAll("[data-local-fields]").forEach((el) => el.classList.toggle("d-none", isS3));

        form.querySelectorAll("[data-s3-fields] input").forEach((input) => {
            const name = input.getAttribute("name");

            if (name === "access_key" || name === "secret_key") {
                return;
            }

            input.required = isS3 && (name === "bucket" || name === "region");
        });
    }

    function showCoreuiModal(selector) {
        const modalElement = document.querySelector(selector);

        if (!modalElement || !window.coreui || !window.coreui.Modal) {
            return;
        }

        window.coreui.Modal.getOrCreateInstance(modalElement).show();
    }

    /* ──────────────────────────  missing artwork  ────────────────────────── */

    /**
     * A file that does not answer becomes a tile, not a wall of alt text.
     *
     * A broken `<img>` renders its alt attribute at the text's own size, which
     * spills straight out of the 4:3 preview and across the card below it. A
     * file can be missing for ordinary reasons — removed from disk, an S3
     * object that 404s — so the library has to show that calmly instead of
     * looking broken itself.
     */
    function markImageMissing(image) {
        const preview = image.parentElement;

        if (!preview || preview.querySelector("[data-media-missing]")) {
            return;
        }

        const tile = document.createElement("span");

        tile.setAttribute("data-media-missing", "");
        tile.setAttribute("dir", "ltr");
        tile.className = "mlib-page-card mlib-page-card--missing";
        tile.textContent = image.getAttribute("data-ext") || "?";
        tile.title = image.getAttribute("alt") || "";

        preview.classList.add("mlib-preview--missing");
        image.remove();
        preview.insertBefore(tile, preview.firstChild);
    }

    /* ─────────────────────────────  inspector  ───────────────────────────── */

    const EXT_CLASSES = ["pdf", "zip", "mp4", "mp3", "csv", "docx"];
    const PREVIEW_CLASSES = ["document", "archive", "video", "audio", "other", "vector", "missing"];

    function setText(scope, selector, value) {
        const el = scope.querySelector(selector);

        if (el) {
            el.textContent = value === null || value === undefined || value === "" ? "—" : value;
        }
    }

    /**
     * Paint the inspector from a card's own payload.
     *
     * The preview node is rebuilt rather than toggled: an image and a file card
     * are different elements, and a page that opened on an image has no
     * extension card to reveal when the admin clicks a PDF.
     */
    function renderInspector(inspector, asset) {
        const preview = inspector.querySelector("[data-inspector-preview]");

        if (preview) {
            PREVIEW_CLASSES.forEach((type) => preview.classList.remove("mlib-preview--" + type));

            const ext = String(asset.extension || "").toLowerCase();

            if (!asset.is_image) {
                preview.classList.add("mlib-preview--" + asset.aggregate_type);
            } else if (ext === "svg") {
                /* A logo is shown whole; only photographs are cropped to fill. */
                preview.classList.add("mlib-preview--vector");
            }

            const existing = preview.querySelector("[data-inspector-image], [data-inspector-extcard], [data-media-missing]");

            if (existing) {
                existing.remove();
            }

            const node = document.createElement(asset.is_image ? "img" : "span");

            if (asset.is_image) {
                node.setAttribute("data-inspector-image", "");
                node.setAttribute("data-media-image", "");
                node.setAttribute("data-ext", asset.extension || "");
                node.src = asset.url;
                node.alt = asset.alt_text || asset.display_title || "";
            } else {
                node.setAttribute("data-inspector-extcard", "");
                node.setAttribute("dir", "ltr");
                node.className = "mlib-page-card";

                if (EXT_CLASSES.indexOf(ext) !== -1) {
                    node.classList.add("mlib-page-card--" + ext);
                }

                node.textContent = asset.extension;
            }

            preview.insertBefore(node, preview.firstChild);
        }

        setText(inspector, "[data-inspector-type]", asset.aggregate_type);
        setText(inspector, "[data-inspector-file]", asset.original_name);
        setText(inspector, "[data-inspector-uploaded]", asset.uploaded_at);
        setText(inspector, "[data-inspector-uploader]", asset.uploader);
        setText(inspector, "[data-inspector-size]", asset.size_human);
        setText(inspector, "[data-inspector-dimensions]", asset.dimensions);
        setText(inspector, "[data-inspector-provider]", asset.provider);
        setText(inspector, "[data-inspector-folder]", asset.folder);

        const url = inspector.querySelector("[data-inspector-url]");

        if (url) {
            url.textContent = asset.url;
            url.setAttribute("title", asset.url);
        }

        const open = inspector.querySelector("[data-inspector-open]");

        if (open) {
            open.setAttribute("href", asset.url);
        }

        const remove = inspector.querySelector("[data-inspector-delete]");

        if (remove) {
            remove.setAttribute("data-url", asset.destroy_url);
        }

        const form = inspector.querySelector("[data-media-inspector-form]");

        if (form) {
            form.setAttribute("data-action", asset.update_url);

            const title = form.querySelector("[data-inspector-title]");
            const alt = form.querySelector("[data-inspector-alt]");
            const caption = form.querySelector("[data-inspector-caption]");

            if (title) {
                title.value = asset.title || "";
            }

            if (alt) {
                alt.value = asset.alt_text || "";
            }

            if (caption) {
                caption.value = asset.caption || "";
            }
        }

        const copy = inspector.querySelector("[data-media-copy]");

        if (copy) {
            copy.textContent = copy.getAttribute("data-label-copy");
        }
    }

    function selectCard(card) {
        const container = root();

        if (!container) {
            return;
        }

        const inspector = container.querySelector("[data-media-inspector]");
        const payload = card.getAttribute("data-asset");

        if (!inspector || !payload) {
            return;
        }

        container.querySelectorAll("[data-media-card]").forEach((node) => {
            const selected = node === card;

            node.classList.toggle("is-selected", selected);
            node.setAttribute("aria-pressed", selected ? "true" : "false");

            const check = node.querySelector("[data-media-card-check]");

            if (check) {
                check.hidden = !selected;
            }
        });

        try {
            renderInspector(inspector, JSON.parse(payload));
        } catch (error) {
            console.error("Media inspector payload could not be read.", error);
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

    /** Keep the grid card in step with what the inspector just saved. */
    function syncCard(asset) {
        const container = root();

        if (!container || !asset) {
            return;
        }

        const card = container.querySelector('[data-asset-id="' + asset.id + '"]');

        if (!card) {
            return;
        }

        card.setAttribute("data-asset", JSON.stringify(asset));

        const title = card.querySelector(".mlib-card__title, .mlib-list__name");

        if (title) {
            title.textContent = asset.display_title;
            title.setAttribute("title", asset.display_title);
        }
    }

    function saveInspector(button) {
        const container = root();
        const inspector = container ? container.querySelector("[data-media-inspector]") : null;
        const form = inspector ? inspector.querySelector("[data-media-inspector-form]") : null;

        if (!form) {
            return;
        }

        const body = new FormData(form);

        body.set("_method", "PUT");
        body.set("_token", csrfToken());

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
                syncCard(result.data.asset);
            })
            .catch(() => notify("error", "Request failed."))
            .finally(() => {
                button.disabled = false;
            });
    }

    /* ─────────────────────────────  drop zone  ───────────────────────────── */

    function uploadFiles(zone, files) {
        if (!files || !files.length) {
            return;
        }

        const body = new FormData();
        const progress = zone.querySelector("[data-media-drop-progress]");

        body.set("_token", csrfToken());
        body.set("storage_provider_id", zone.getAttribute("data-provider") || "");

        if (zone.getAttribute("data-folder")) {
            body.set("folder", zone.getAttribute("data-folder"));
        }

        Array.prototype.forEach.call(files, (file) => body.append("files[]", file));

        if (progress) {
            progress.hidden = false;
            progress.textContent = "…";
        }

        fetch(zone.getAttribute("data-upload-url"), {
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

                /*
                 * The new cards belong wherever the current sort puts them, and
                 * only the server knows that once folders and filters are in
                 * play. Reloading is the honest answer and keeps every count on
                 * the page — folders, chips, KPIs — in agreement with the grid.
                 */
                window.location.reload();
            })
            .catch(() => notify("error", "Upload failed."))
            .finally(() => {
                if (progress) {
                    progress.hidden = true;
                }
            });
    }

    function bindDropzone(zone) {
        ["dragenter", "dragover"].forEach((type) => {
            zone.addEventListener(type, (event) => {
                event.preventDefault();
                zone.classList.add("is-dragging");
            });
        });

        ["dragleave", "drop"].forEach((type) => {
            zone.addEventListener(type, (event) => {
                event.preventDefault();
                zone.classList.remove("is-dragging");
            });
        });

        zone.addEventListener("drop", (event) => {
            uploadFiles(zone, event.dataTransfer ? event.dataTransfer.files : null);
        });

        zone.addEventListener("click", () => showCoreuiModal("#mediaUploadModal"));
    }

    /* ──────────────────────────  provider testing  ───────────────────────── */

    function testProvider(form) {
        const row = form.closest("[data-provider-row]");
        const button = form.querySelector("button");
        const body = new FormData(form);

        body.set("_token", csrfToken());

        if (button) {
            button.disabled = true;
        }

        fetch(form.getAttribute("action"), {
            method: "POST",
            body: body,
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
        })
            .then((response) => response.json())
            .then((data) => {
                notify(data.status === "success" ? "success" : "error", data.message);

                if (!row || !data.provider) {
                    return;
                }

                const status = row.querySelector("[data-provider-test-status]");
                const at = row.querySelector("[data-provider-test-at]");

                if (status) {
                    status.classList.remove(
                        "mlib-test__status--success",
                        "mlib-test__status--failed",
                        "mlib-test__status--untested"
                    );
                    status.classList.add("mlib-test__status--" + data.provider.last_check_status);

                    const label = status.getAttribute("data-label-" + data.provider.last_check_status);

                    if (label) {
                        status.textContent = label;
                    }
                }

                if (at) {
                    at.textContent = data.provider.last_checked_at || "—";
                }
            })
            .catch(() => notify("error", "Request failed."))
            .finally(() => {
                if (button) {
                    button.disabled = false;
                }
            });
    }

    /* ───────────────────────────────  wiring  ────────────────────────────── */

    /*
     * Capture phase, and on `document`: `error` does not bubble, so a listener
     * on the container would never hear it. Registered at once rather than in
     * DOMContentLoaded, because an image can fail before that fires.
     */
    document.addEventListener(
        "error",
        (event) => {
            const image = event.target;

            if (image instanceof HTMLImageElement && image.hasAttribute("data-media-image")) {
                markImageMissing(image);
            }
        },
        true
    );

    document.addEventListener("DOMContentLoaded", () => {
        document.querySelectorAll("[data-media-provider-form]").forEach(toggleProviderFields);

        /* An image cached as broken before this script ran reports complete with no size. */
        document.querySelectorAll("[data-media-image]").forEach((image) => {
            if (image.complete && image.naturalWidth === 0) {
                markImageMissing(image);
            }
        });

        document.addEventListener("change", (event) => {
            const driver = event.target.closest("[data-media-provider-driver]");

            if (driver) {
                toggleProviderFields(driver.closest("[data-media-provider-form]"));
            }

            const autosubmit = event.target.closest("[data-media-autosubmit]");

            if (autosubmit) {
                autosubmit.closest("form").submit();
            }
        });

        document.addEventListener("click", (event) => {
            const card = event.target.closest("[data-media-card]");

            if (card) {
                selectCard(card);

                return;
            }

            const save = event.target.closest("[data-media-save]");

            if (save) {
                saveInspector(save);

                return;
            }

            const copy = event.target.closest("[data-media-copy]");

            if (copy) {
                const value = document.querySelector("[data-inspector-url]");

                if (value && navigator.clipboard) {
                    navigator.clipboard.writeText(value.textContent.trim());
                }

                copy.textContent = copy.getAttribute("data-label-copied");

                return;
            }

            if (event.target.closest(".mlib-empty .admin-not-found__action")) {
                event.preventDefault();
                showCoreuiModal("#mediaUploadModal");
            }
        });

        document.querySelectorAll("[data-media-dropzone]").forEach(bindDropzone);

        document.querySelectorAll("[data-provider-test]").forEach((form) => {
            form.addEventListener("submit", (event) => {
                event.preventDefault();
                testProvider(form);
            });
        });
    });
})();
