/* =============================================================
 | DigiKash Web Push
 |
 | Registers the browser's push subscription against the account so
 | notifications reach the phone with the app closed. The service
 | worker itself is registered by pwa.js; this file only manages the
 | PushManager subscription and mirrors it to the server.
 ============================================================= */
(function () {
    "use strict";

    var CONFIG_URL = "/user/push-subscriptions/config";
    var SUBSCRIBE_URL = "/user/push-subscriptions";

    var supported = "serviceWorker" in navigator && "PushManager" in window && "Notification" in window;
    var config = null;

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute("content") : "";
    }

    /**
     * The VAPID public key travels as base64url but PushManager wants raw bytes.
     */
    function urlBase64ToUint8Array(base64String) {
        var padding = "=".repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
        var raw = window.atob(base64);
        var output = new Uint8Array(raw.length);

        for (var i = 0; i < raw.length; ++i) {
            output[i] = raw.charCodeAt(i);
        }

        return output;
    }

    function loadConfig() {
        if (config) {
            return Promise.resolve(config);
        }

        return fetch(CONFIG_URL, { headers: { Accept: "application/json" }, credentials: "same-origin" })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                config = data;

                return config;
            })
            .catch(function () {
                return null;
            });
    }

    function currentSubscription() {
        return navigator.serviceWorker.ready.then(function (registration) {
            return registration.pushManager.getSubscription();
        });
    }

    function sendSubscription(subscription) {
        var payload = subscription.toJSON();

        return fetch(SUBSCRIBE_URL, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": csrfToken()
            },
            body: JSON.stringify({
                endpoint: payload.endpoint,
                keys: payload.keys,
                content_encoding: (window.PushManager && PushManager.supportedContentEncodings)
                    ? PushManager.supportedContentEncodings[0]
                    : "aesgcm"
            })
        });
    }

    function forgetSubscription(endpoint) {
        return fetch(SUBSCRIBE_URL, {
            method: "DELETE",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": csrfToken()
            },
            body: JSON.stringify({ endpoint: endpoint })
        });
    }

    function subscribe() {
        return loadConfig().then(function (settings) {
            if (!settings || !settings.enabled || !settings.public_key) {
                return Promise.reject(new Error("web-push-unconfigured"));
            }

            return Notification.requestPermission().then(function (permission) {
                if (permission !== "granted") {
                    return Promise.reject(new Error("permission-" + permission));
                }

                return navigator.serviceWorker.ready.then(function (registration) {
                    return registration.pushManager.getSubscription().then(function (existing) {
                        if (existing) {
                            return existing;
                        }

                        return registration.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlBase64ToUint8Array(settings.public_key)
                        });
                    });
                });
            });
        }).then(function (subscription) {
            return sendSubscription(subscription).then(function () {
                return subscription;
            });
        });
    }

    function unsubscribe() {
        return currentSubscription().then(function (subscription) {
            if (!subscription) {
                return null;
            }

            var endpoint = subscription.endpoint;

            return subscription.unsubscribe().then(function () {
                return forgetSubscription(endpoint);
            });
        });
    }

    /**
     * A subscription can be dropped by the browser without telling the server,
     * so re-post whatever the browser still holds on every load.
     */
    function resync() {
        if (Notification.permission !== "granted") {
            return Promise.resolve(null);
        }

        return currentSubscription().then(function (subscription) {
            return subscription ? sendSubscription(subscription) : null;
        }).catch(function () {
            return null;
        });
    }

    function bootSettingsCard() {
        var card = document.querySelector("[data-dk-webpush-card]");

        if (!card) {
            return;
        }

        var button = card.querySelector("[data-dk-webpush-toggle]");
        var status = card.querySelector("[data-dk-webpush-status]");

        if (!supported) {
            card.hidden = false;
            button.disabled = true;
            status.textContent = button.getAttribute("data-unsupported-label")
                || "This browser cannot deliver notifications when the app is closed.";

            return;
        }

        loadConfig().then(function (settings) {
            if (!settings || !settings.enabled) {
                return;
            }

            card.hidden = false;
            render(Notification.permission === "granted" && settings.subscribed);
        });

        function render(isOn) {
            button.disabled = Notification.permission === "denied";
            button.textContent = isOn
                ? button.getAttribute("data-disable-label")
                : button.getAttribute("data-enable-label");
            card.setAttribute("data-enabled", isOn ? "1" : "0");

            if (Notification.permission === "denied") {
                status.textContent = "Notifications are blocked for this site. Enable them in your browser settings.";
            }
        }

        button.addEventListener("click", function () {
            var turningOn = card.getAttribute("data-enabled") !== "1";
            var original = button.textContent;

            button.disabled = true;
            button.textContent = button.getAttribute("data-working-label");

            (turningOn ? subscribe() : unsubscribe())
                .then(function () {
                    render(turningOn);
                })
                .catch(function () {
                    button.textContent = original;
                    render(!turningOn);
                })
                .then(function () {
                    if (Notification.permission !== "denied") {
                        button.disabled = false;
                    }
                });
        });
    }

    if (supported) {
        resync();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", bootSettingsCard);
    } else {
        bootSettingsCard();
    }

    window.DigiKashWebPush = {
        supported: supported,
        subscribe: subscribe,
        unsubscribe: unsubscribe,
        resync: resync
    };
})();
