"use strict";

/*
 * The admin transaction detail modal.
 *
 * A row click fetches `admin.transaction.show` and this file paints the shell
 * that `_detail_modal.blade.php` rendered. Nothing here computes a money value:
 * every amount arrives from the server as an already-quantized string and is
 * written to the DOM verbatim. The one number this file works out is the width
 * of the refund progress bar, which is decoration.
 *
 * Plain ES2019 on purpose — this panel has no build step.
 */
(function () {
    var root = document.querySelector('[data-trx-detail]');

    if (!root) {
        return;
    }

    var refundRoot = document.querySelector('[data-trx-refund]');
    var dialog     = root.querySelector('.admin-trx-detail__dialog');
    var strings    = JSON.parse(root.getAttribute('data-trx-strings') || '{}');

    var current = null;
    var trigger = null;

    /* Held so a reopen during the closing motion can cancel the pending hide. */
    var closeTimer = null;
    var refundTimer = null;

    // ----- helpers -----

    function slot(name, scope) {
        return (scope || root).querySelector('[data-d="' + name + '"]');
    }

    function slots(name, scope) {
        return (scope || root).querySelectorAll('[data-d="' + name + '"]');
    }

    function text(name, value) {
        Array.prototype.forEach.call(slots(name), function (el) {
            el.textContent = value == null ? '' : value;
        });
    }

    function show(el, visible) {
        if (el) {
            el.hidden = !visible;
        }
    }

    /** `:key` placeholders, the same convention the PHP side uses. */
    function line(key, replacements) {
        var out = strings[key] || '';

        Object.keys(replacements || {}).forEach(function (name) {
            out = out.split(':' + name).join(replacements[name]);
        });

        return out;
    }

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    function element(tag, className, content) {
        var el = document.createElement(tag);

        if (className) {
            el.className = className;
        }

        if (content != null) {
            el.textContent = content;
        }

        return el;
    }

    /*
     * The server sends ISO-8601; the browser is the only thing that knows the
     * reader's locale and offset, so short stamps are formatted here rather
     * than shipped pre-rendered.
     */
    function formatStamp(iso) {
        var date = new Date(iso);

        if (isNaN(date.getTime())) {
            return '';
        }

        return date.toLocaleString(document.documentElement.lang || undefined, {
            day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'
        });
    }

    function initialsOf(name) {
        return (name || '')
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map(function (part) { return part.charAt(0).toUpperCase(); })
            .join('');
    }

    // ----- painting -----

    function paintHead(data) {
        text('type_label', data.type_label);
        text('id', data.id);
        text('created_human', data.created_human);
        text('created_rel', data.created_rel);
        text('description', data.description);
        text('amount', data.sign + data.amount + ' ' + data.currency);

        var status = slot('status');

        if (status) {
            status.className = 'admin-users-pill admin-users-pill--' + data.status_color;
            status.textContent = data.status_label;
        }

        var tile = slot('tile');

        if (tile) {
            tile.className = 'admin-trx-tile admin-trx-tile--xl admin-trx-tile--' + data.status_color;

            /*
             * The glyph is the flow, not the type. There are forty-odd
             * transaction types and a map of them here would be a second copy
             * of the one the list already keeps — while the only thing the
             * tile has to answer at a glance is whether the money came in or
             * went out. An empty circle answered nothing.
             */
            tile.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"'
                + ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
                + ' stroke-linejoin="round" aria-hidden="true"><path d="'
                + (data.amount_flow === 'minus' ? 'M12 15V4M7.5 8.5L12 4l4.5 4.5' : 'M12 4v11M7.5 10.5L12 15l4.5-4.5')
                + '"></path><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"></path></svg>';
        }

        /*
         * A refunded payment says so beside its own title. Reading the amount
         * alone, a fully refunded payment is indistinguishable from one that
         * still stands.
         */
        var pill       = slot('refund_pill');
        var refunded   = parseFloat(data.refunds.refunded);
        var refundable = parseFloat(data.refunds.refundable);

        if (pill) {
            if (refunded > 0) {
                pill.textContent = refundable > 0 ? strings.partly : strings.fully;
            }

            show(pill, refunded > 0);
        }

        Array.prototype.forEach.call(slots('receipt_url'), function (el) {
            el.setAttribute('href', data.urls.receipt);
        });

        Array.prototype.forEach.call(slots('receipt_print_url'), function (el) {
            el.setAttribute('href', data.urls.receipt_print);
        });
    }

    function paintFx(data) {
        show(slot('fx'), !!data.fx);

        if (!data.fx) {
            return;
        }

        text('fx_entered', data.fx.entered_amount + ' ' + data.fx.entered_currency);
        text('fx_base_label', line('reporting', { currency: data.fx.base_currency }));
        text('fx_base', data.fx.base_amount + ' ' + data.fx.base_currency);
        text('fx_rate', data.fx.exchange_rate);
    }

    function paintBreakdown(data) {
        var host = slot('breakdown');

        if (!host) {
            return;
        }

        host.textContent = '';

        var inbound = data.amount_flow === 'plus';
        var fee     = parseFloat(data.fee);
        var refund  = parseFloat(data.refunds.refunded);

        function row(label, value, options) {
            var opts = options || {};
            var el   = element('div', 'admin-trx-detail__line' + (opts.strong ? ' is-strong' : ''));
            var key  = element('span', null, label);

            if (opts.note) {
                key.appendChild(element('small', 'admin-trx-detail__line-note', opts.note));
            }

            el.appendChild(key);
            el.appendChild(element('strong', opts.tone ? 'text-' + opts.tone : null, value));
            host.appendChild(el);
        }

        row(strings.amount, data.amount);
        row(strings.platformFee, (fee > 0 ? '− ' : '') + data.fee, {
            tone: fee > 0 ? 'danger' : null,
            note: fee > 0 ? null : strings.noFee
        });

        // GF6: a row per tax rule, in the payable currency, apart from the fee.
        ((data.tax && data.tax.lines) || []).forEach(function (tax) {
            row(tax.name, '− ' + tax.amount + ' ' + data.payable_currency, {
                tone: 'danger',
                note: strings.tax
            });
        });

        /*
         * Net first, then what has since been taken back out of it — the same
         * order the receipt prints. Refunded above net made the column stop
         * reading as a running sum.
         */
        row(inbound ? strings.netCredited : strings.netDebited, data.net_amount, { strong: true });

        if (refund > 0) {
            row(strings.refunded, '− ' + data.refunds.refunded, { tone: 'warning' });
        }

        // Only worth a row when it says something the net does not.
        if (data.payable_currency !== data.currency || data.payable_amount !== data.net_amount) {
            row(line('payable', { currency: data.payable_currency }), data.payable_amount);
        }

        /*
         * A bare wallet id under a column of figures says nothing. Name what
         * happened to the money and where it went.
         */
        text('settlement_note', data.wallet.reference
            ? line(inbound ? 'creditedTo' : 'heldFrom', { wallet: data.wallet.reference })
            : '');
    }

    function paintTimeline(data) {
        var host = slot('timeline');

        if (!host) {
            return;
        }

        host.textContent = '';

        data.timeline.forEach(function (entry, index) {
            var li   = element('li', 'admin-trx-detail__timeline-item');
            var dot  = element('span', 'admin-trx-detail__timeline-dot is-' + entry.state
                + (index === 0 ? ' is-first' : ''));
            var body = element('div', 'admin-trx-detail__timeline-body');
            var head = element('div', 'admin-trx-detail__timeline-head');

            head.appendChild(element('span', 'admin-trx-detail__timeline-title', entry.title));

            if (entry.at) {
                head.appendChild(element('time', 'admin-trx-detail__timeline-at', formatStamp(entry.at)));
            }

            body.appendChild(head);
            body.appendChild(element('div', 'admin-trx-detail__timeline-sub', entry.subtitle));

            li.appendChild(dot);
            li.appendChild(body);
            host.appendChild(li);
        });
    }

    function paintRefunds(data) {
        var items = data.refunds.items || [];

        show(slot('refunds_card'), items.length > 0);

        if (items.length === 0) {
            return;
        }

        text('refund_meta', data.refunds.refunded + ' · ' + data.refunds.refundable);

        var bar = slot('refund_bar');

        if (bar) {
            var total = parseFloat(data.amount);
            var done  = parseFloat(data.refunds.refunded);

            bar.style.width = (total > 0 ? Math.min(100, (done / total) * 100) : 0) + '%';
        }

        var host = slot('refund_items');

        if (!host) {
            return;
        }

        host.textContent = '';

        items.forEach(function (item) {
            var row  = element('div', 'admin-trx-detail__refund');
            var body = element('div', 'admin-trx-detail__refund-body');

            body.appendChild(element('code', null, item.id));
            body.appendChild(element('span', 'admin-trx-detail__refund-meta',
                [item.at_human, item.reason].filter(Boolean).join(' · ')));

            row.appendChild(body);
            row.appendChild(element('strong', 'text-warning', '−' + item.amount));
            host.appendChild(row);
        });
    }

    function paintWebhooks(data) {
        var items = data.webhooks || [];

        show(slot('webhooks_card'), items.length > 0);

        if (items.length === 0) {
            return;
        }

        var delivered = items.filter(function (item) {
            return item.status === 'delivered';
        }).length;

        text('webhook_meta', delivered + ' / ' + items.length);

        var host = slot('webhook_items');

        if (!host) {
            return;
        }

        host.textContent = '';

        items.forEach(function (item) {
            var row  = element('div', 'admin-trx-detail__refund');
            var body = element('div', 'admin-trx-detail__refund-body');
            var meta = [item.at_human, item.url, strings.webhookAttempts.replace(':count', item.attempts)];

            if (item.status === 'pending' && item.next_attempt_human) {
                meta.push(strings.webhookNext.replace(':when', item.next_attempt_human));
            }

            if (item.status !== 'delivered' && item.error) {
                meta.push(item.error);
            }

            body.appendChild(element('code', null, item.event));
            body.appendChild(element('span', 'admin-trx-detail__refund-meta', meta.filter(Boolean).join(' · ')));

            var tone  = item.status === 'delivered' ? 'text-success' : (item.status === 'failed' ? 'text-danger' : 'text-warning');
            var label = item.status === 'delivered' ? strings.webhookDelivered : (item.status === 'failed' ? strings.webhookFailed : strings.webhookRetrying);

            row.appendChild(body);
            row.appendChild(element('strong', tone, label + (item.status_code ? ' · ' + item.status_code : '')));
            host.appendChild(row);
        });
    }

    function paintReferences(data) {
        var host = slot('references');

        if (!host) {
            return;
        }

        host.textContent = '';

        function pair(label, value, mono) {
            if (!value) {
                return;
            }

            host.appendChild(element('dt', null, label));
            host.appendChild(element('dd', mono ? 'admin-trx-detail__mono' : null, value));
        }

        pair(strings.provider, data.provider);
        pair(strings.gatewayCode, data.gateway_code, true);
        pair(strings.processing, data.processing_label);
        pair(strings.providerRef, data.trx_reference, true);
        pair(strings.wallet, data.wallet.reference, true);
        pair(strings.expires, data.expires_at ? formatStamp(data.expires_at) : null);
        pair(strings.reportingCur, data.fx ? data.fx.base_currency : null);

        /*
         * A transfer between two wallets never reaches a gateway, so this is
         * empty more often than not. The toggle used to be offered anyway,
         * and opening it showed a black panel holding `[]` — which says
         * nothing, and reads like something went wrong. A sentence takes its
         * place, and the two are never on screen together.
         */
        var keys = Object.keys(data.trx_data || {});
        var hasPayload = keys.length > 0;
        var toggle = root.querySelector('[data-trx-payload-toggle]');
        var payload = slot('payload');

        text('payload_label', line('payloadToggle', { count: keys.length }));
        text('payload_empty', strings.noPayload);
        text('payload_copy_label', strings.copyPayload);

        if (toggle) {
            toggle.hidden = !hasPayload;
            toggle.setAttribute('aria-expanded', 'false');
        }

        show(slot('payload_empty'), !hasPayload);

        /* Collapsed again on every open: the last transaction's payload being
           expanded is not a decision about this one. */
        show(slot('payload_wrap'), false);

        if (payload) {
            payload.textContent = hasPayload ? JSON.stringify(data.trx_data, null, 2) : '';
        }
    }

    function paintRail(data) {
        var customer = data.customer;

        text('initials', customer ? initialsOf(customer.name) : '');
        text('customer_sub', customer ? '@' + customer.username + ' · ' + customer.email : '');
        show(slot('kyc'), !!(customer && customer.kyc_verified));

        var link = slot('customer_link');

        if (link) {
            link.textContent = customer ? customer.name : '';
            link.setAttribute('href', data.urls.customer || '#');
        }

        text('remarks', data.remarks || strings.noRemarks);

        show(slot('action_card'), data.can.approve || data.can.reject);
        text('action_note', data.description || '');

        show(slot('refund_open'), data.can.refund);

        var remaining = line('upTo', { amount: data.refunds.refundable + ' ' + data.currency });

        text('refund_hint', remaining);
        text('refund_modal_hint', remaining);
    }

    function paint(data) {
        current = data;

        paintHead(data);
        paintFx(data);
        paintBreakdown(data);
        paintTimeline(data);
        paintRefunds(data);
        paintWebhooks(data);
        paintReferences(data);
        paintRail(data);

        show(slot('error'), false);
    }

    // ----- transport -----

    function fail(message) {
        var box = slot('error');

        if (box) {
            box.textContent = message;
            box.hidden = false;
        }
    }

    function load(url) {
        return fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.json();
            })
            .then(function (body) { paint(body.data); })
            .catch(function () { fail(strings.loadFailed); });
    }

    function post(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf()
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (body) {
                return { ok: response.ok, body: body };
            });
        });
    }

    /** Laravel answers a 422 either with `message` or with a validation bag. */
    function messageFrom(body) {
        if (body && body.message) {
            return body.message;
        }

        if (body && body.errors) {
            var first = Object.keys(body.errors)[0];

            if (first) {
                return body.errors[first][0];
            }
        }

        return strings.loadFailed;
    }

    // ----- open / close -----

    /*
     * Reveal, then animate.
     *
     * A class set in the same frame as `hidden = false` has no "before" to
     * transition from: the element is laid out for the first time already
     * wearing its final values and the browser paints it there. Reading
     * `offsetWidth` forces that first layout, which is more dependable here
     * than waiting a frame — `requestAnimationFrame` does not run in a
     * background tab, so a deep link opened in a restored tab would sit at
     * opacity 0 until it was looked at.
     */
    function reveal() {
        /* Reopening during the closing motion must cancel the pending hide,
           or that timer fires a moment later and hides the panel that was
           just opened. */
        if (closeTimer) {
            window.clearTimeout(closeTimer);
            closeTimer = null;
        }

        root.hidden = false;
        void root.offsetWidth;
        root.classList.add('is-open');
        document.body.classList.add('admin-trx-detail-open');
    }

    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    /*
     * The panel is revealed before the transaction is fetched, so until the
     * response lands every slot still holds the last transaction opened.
     * `is-loading` covers them; `paint()` stays the only thing that writes
     * them.
     */
    function setLoading(state) {
        root.classList.toggle('is-loading', state);
        dialog.setAttribute('aria-busy', state ? 'true' : 'false');

        /*
         * A failure message outlives the transaction it was about. It is
         * cleared only at the end of a successful `paint()`, and it sits below
         * the body rather than inside it — so opening another row after one
         * that failed showed a skeleton and "this transaction could not be
         * loaded" at the same time, about two different transactions. Starting
         * a fetch is exactly the moment the previous outcome stops applying.
         */
        if (state) {
            show(slot('error'), false);
        }
    }

    function open(url, source) {
        trigger = source || null;

        setLoading(true);
        reveal();

        /*
         * Focus moves in at once rather than after the response. A dialog that
         * has taken the page but not the focus leaves a keyboard behind on the
         * list underneath it for as long as the request takes.
         */
        var closeButton = dialog.querySelector('[data-trx-detail-close]');

        if (closeButton) {
            closeButton.focus();
        }

        load(url).then(function () {
            setLoading(false);
        });
    }

    function finishClose() {
        closeTimer = null;
        root.hidden = true;
        setLoading(false);

        /*
         * Focus has to return to something that can hold it. A `tr` cannot, so
         * the list hands over the button that was actually activated.
         */
        if (trigger && typeof trigger.focus === 'function') {
            trigger.focus();
        }

        trigger = null;
        current = null;
    }

    function close() {
        if (root.hidden) {
            return;
        }

        root.classList.remove('is-open');
        document.body.classList.remove('admin-trx-detail-open');
        closeRefund();

        /*
         * `hidden` waits for the closing motion, on a timer rather than on
         * `transitionend`. With reduced motion there is no transition to end,
         * and an interrupted one fires nothing either — both of which would
         * leave the panel in the document holding the page's scroll. A timer
         * cannot be skipped.
         */
        if (closeTimer) {
            window.clearTimeout(closeTimer);
            closeTimer = null;
        }

        if (reducedMotion()) {
            finishClose();

            return;
        }

        closeTimer = window.setTimeout(finishClose, 260);
    }

    function openRefund() {
        if (!refundRoot || !current) {
            return;
        }

        var amount = refundRoot.querySelector('[data-trx-refund-amount]');

        if (amount) {
            amount.value = current.refunds.refundable;
            amount.setAttribute('max', current.refunds.refundable);
        }

        show(slot('refund_error', refundRoot), false);

        if (refundTimer) {
            window.clearTimeout(refundTimer);
            refundTimer = null;
        }

        refundRoot.hidden = false;
        void refundRoot.offsetWidth;
        refundRoot.classList.add('is-open');

        if (amount) {
            amount.focus();
        }
    }

    /*
     * "On screen" is not the same question as "still the active surface".
     * The refund panel stays in the document through its closing motion, so a
     * bare `!hidden` would keep sending Escape and Tab to a panel on its way
     * out for as long as that lasts. `is-open` is dropped the moment it starts
     * leaving.
     */
    function refundOpen() {
        return !!refundRoot && !refundRoot.hidden && refundRoot.classList.contains('is-open');
    }

    function closeRefund() {
        if (!refundRoot || refundRoot.hidden) {
            return;
        }

        refundRoot.classList.remove('is-open');

        if (refundTimer) {
            window.clearTimeout(refundTimer);
            refundTimer = null;
        }

        if (reducedMotion()) {
            refundRoot.hidden = true;

            return;
        }

        refundTimer = window.setTimeout(function () {
            refundTimer = null;
            refundRoot.hidden = true;
        }, 200);
    }

    function selectPane(name) {
        Array.prototype.forEach.call(root.querySelectorAll('[data-seg]'), function (button) {
            var active = button.getAttribute('data-seg') === name;

            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        root.setAttribute('data-active-pane', name);
    }

    /**
     * The confirmation, as a pill above the button — or, where a button says
     * so with `[data-flash-label]`, in the button's own words.
     *
     * A pill needs free space on one side. The payload's copy button has none:
     * it sits in the panel's top corner, with the disclosure row above it and
     * the JSON's first line below, so a pill lands on one or the other
     * whichever way it points. A button that briefly reads "Copied" needs no
     * room at all.
     */
    function flash(source, message) {
        if (!source) {
            return;
        }

        var label = source.querySelector('[data-flash-label]');
        var spoken = label ? label.textContent : null;

        source.setAttribute('data-flash', message);

        if (label) {
            label.textContent = message;
        }

        window.setTimeout(function () {
            source.removeAttribute('data-flash');

            if (label && spoken !== null) {
                label.textContent = spoken;
            }
        }, 1600);
    }

    /**
     * The clipboard without a secure context.
     *
     * `navigator.clipboard` does not exist on plain http — measured on this
     * panel over `http://`, where `isSecureContext` is false and the API is
     * simply absent — so every copy button in this dialogue answered "Not
     * copied" on any installation not yet on TLS, which is most of them on
     * the day they are installed. `execCommand` is deprecated and still the
     * only thing that works there.
     *
     * The textarea is fixed and transparent rather than `display: none`,
     * because a hidden element cannot be selected, and focus is handed back
     * so the dialogue does not lose it to a node that is about to be removed.
     */
    function copyWithoutClipboardApi(value) {
        var area = document.createElement('textarea');
        var focused = document.activeElement;
        var done = false;

        area.value = value;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '0';
        area.style.opacity = '0';
        area.style.pointerEvents = 'none';

        document.body.appendChild(area);
        area.select();
        area.setSelectionRange(0, value.length);

        try {
            done = document.execCommand('copy');
        } catch (error) {
            done = false;
        }

        document.body.removeChild(area);

        if (focused && typeof focused.focus === 'function') {
            focused.focus();
        }

        return done;
    }

    function copyToClipboard(value, source) {
        if (!value) {
            flash(source, strings.notCopied);

            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(
                function () { flash(source, strings.copied); },
                /* Permission refused, or a context that only looks secure. */
                function () { flash(source, copyWithoutClipboardApi(value) ? strings.copied : strings.notCopied); }
            );

            return;
        }

        flash(source, copyWithoutClipboardApi(value) ? strings.copied : strings.notCopied);
    }

    function submitAction(button) {
        var remarks = root.querySelector('[data-trx-remarks]');

        button.disabled = true;

        post(current.urls.action, {
            action: button.getAttribute('data-trx-action'),
            remarks: remarks ? remarks.value : null
        }).then(function (result) {
            button.disabled = false;

            if (!result.ok) {
                fail(messageFrom(result.body));
                return;
            }

            // Repaint from the row the server hands back, never from a guess
            // about what the action did to it.
            paint(result.body.data);
        });
    }

    // ----- events -----

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-trx-detail-close]')) {
            close();
            return;
        }

        if (event.target.closest('[data-trx-refund-close]')) {
            closeRefund();
            return;
        }

        if (event.target.closest('[data-trx-refund-open]')) {
            openRefund();
            return;
        }

        var copy = event.target.closest('[data-trx-copy]');

        if (copy) {
            copyToClipboard(current ? current.id : '', copy);

            return;
        }

        /* Declared before the toggle: the copy button lives inside the panel
           the toggle opens, and a click on it is not a click on the fold. */
        var copyPayload = event.target.closest('[data-trx-copy-payload]');

        if (copyPayload) {
            var pre = slot('payload');

            copyToClipboard(pre ? pre.textContent : '', copyPayload);

            return;
        }

        var toggle = event.target.closest('[data-trx-payload-toggle]');

        if (toggle) {
            var opened = toggle.getAttribute('aria-expanded') === 'true';

            toggle.setAttribute('aria-expanded', opened ? 'false' : 'true');
            show(slot('payload_wrap'), !opened);

            return;
        }

        var seg = event.target.closest('[data-seg]');

        if (seg) {
            selectPane(seg.getAttribute('data-seg'));
            return;
        }

        var act = event.target.closest('[data-trx-action]');

        if (act && current) {
            submitAction(act);
            return;
        }

        var row = event.target.closest('[data-trx-row]');

        if (!row || !row.getAttribute('data-trx-url') || event.target.closest('a, input, label')) {
            return;
        }

        event.preventDefault();
        open(row.getAttribute('data-trx-url'), event.target.closest('[data-trx-open], button[data-trx-row]'));
    });

    if (refundRoot) {
        refundRoot.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!current) {
                return;
            }

            var amount = refundRoot.querySelector('[data-trx-refund-amount]');
            var reason = refundRoot.querySelector('[data-trx-refund-reason]');
            var submit = refundRoot.querySelector('button[type="submit"]');

            if (submit) {
                submit.disabled = true;
            }

            post(current.urls.refund, {
                amount: amount ? amount.value : null,
                reason: reason ? reason.value : null
            }).then(function (result) {
                if (submit) {
                    submit.disabled = false;
                }

                if (!result.ok) {
                    var box = slot('refund_error', refundRoot);

                    if (box) {
                        box.textContent = messageFrom(result.body);
                        box.hidden = false;
                    }

                    return;
                }

                closeRefund();
                paint(result.body.data);
            });
        });
    }

    /*
     * A compact table has no chevron to tab to, so the row carries the focus
     * itself. A `tr` is not a button: Enter and Space do not become a click on
     * their own, which is exactly why the chevron existed. This supplies it.
     *
     * Space is prevented from scrolling the panel out from under the modal it
     * just opened.
     */
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        var row = event.target.closest('[data-trx-row][tabindex]');

        if (!row || !row.getAttribute('data-trx-url')) {
            return;
        }

        event.preventDefault();
        open(row.getAttribute('data-trx-url'), row);
    });

    document.addEventListener('keydown', function (event) {
        if (root.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            if (refundOpen()) {
                closeRefund();
            } else {
                close();
            }

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        /*
         * Focus trap. Without it Tab walks straight out of an `aria-modal`
         * dialog into the list behind it, which is still fully operable.
         */
        var scope = refundOpen() ? refundRoot : dialog;
        var items = scope.querySelectorAll(
            'a[href], button:not([disabled]), textarea, input, [tabindex]:not([tabindex="-1"])'
        );
        var visible = Array.prototype.filter.call(items, function (el) {
            return el.offsetParent !== null;
        });

        if (visible.length === 0) {
            return;
        }

        var first = visible[0];
        var last  = visible[visible.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    selectPane('details');

    /*
     * A deep link renders the same modal already open. There is no row behind
     * it to return focus to, so it never gets a trigger.
     */
    if (root.hasAttribute('data-trx-standalone')) {
        setLoading(true);
        reveal();
        load(root.getAttribute('data-trx-url')).then(function () {
            setLoading(false);
        });
    }
}());
