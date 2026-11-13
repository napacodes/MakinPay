"use strict";

/*
 * Bill payment provider screen: the connection test.
 *
 * Posts to the provider's test-connection route and renders what the
 * provider answered, in the same envelope the mobile recharge test uses
 * ({provider, result: {ok, mode, message, latency_ms, details}}). It is a
 * display aid only: the server builds the driver from the saved
 * credentials, and nothing here sees a key.
 */
(function () {
    function ready(callback) {
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", callback);
        } else {
            callback();
        }
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute("content") || "" : "";
    }

    function paint(target, tone, lines) {
        target.hidden = false;
        target.className = "bpa-setup__result alert alert-" + tone + " mt-3 mb-0 py-2 px-3 small";
        target.textContent = "";

        lines.forEach(function (line, index) {
            if (index > 0) {
                target.appendChild(document.createElement("br"));
            }

            if (line.strong) {
                var strong = document.createElement("strong");

                strong.textContent = line.text;
                target.appendChild(strong);
            } else {
                target.appendChild(document.createTextNode(line.text));
            }
        });
    }

    function summary(result) {
        var parts = [];
        var details = result.details || {};

        if (result.mode) {
            parts.push(String(result.mode).toUpperCase());
        }

        if (result.latency_ms !== null && result.latency_ms !== undefined) {
            parts.push(result.latency_ms + " ms");
        }

        if (details.http_status) {
            parts.push("HTTP " + details.http_status);
        }

        return parts.join(" · ");
    }

    function bindTest() {
        document.addEventListener("click", function (event) {
            var button = event.target.closest("[data-bpa-provider-test]");

            if (!button || button.disabled) {
                return;
            }

            var url = button.getAttribute("data-test-url");
            var target = document.querySelector(button.getAttribute("data-result-target") || "");

            if (!url || !target) {
                return;
            }

            var label = button.textContent;

            button.disabled = true;
            button.textContent = button.getAttribute("data-testing-label") || label;
            paint(target, "info", [{ text: button.getAttribute("data-testing-message") || "" }]);

            fetch(url, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Accept": "application/json",
                    "X-CSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest"
                }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.json();
                })
                .then(function (payload) {
                    var result = (payload && payload.result) || {};
                    var provider = (payload && payload.provider) || {};
                    var line = summary(result);

                    var lines = [
                        { text: (provider.name || "") + (line ? " · " + line : ""), strong: true },
                        { text: result.message || "" }
                    ];

                    // BP1: a passing test resumes a paused provider.
                    if (provider.resumed) {
                        lines.push({ text: button.getAttribute("data-resumed-message") || "" });

                        var notice = document.querySelector("[data-bpa-provider-paused]");

                        if (notice) {
                            notice.remove();
                        }
                    }

                    paint(target, result.ok ? "success" : "danger", lines);
                })
                .catch(function (failure) {
                    paint(target, "danger", [{
                        text: (button.getAttribute("data-fallback-message") || "") + " (" + failure.message + ")"
                    }]);
                })
                .finally(function () {
                    button.disabled = false;
                    button.textContent = label;
                });
        });
    }

    ready(bindTest);
})();
