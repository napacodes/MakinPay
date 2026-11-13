<script>
"use strict";
document.addEventListener('DOMContentLoaded', function () {
    const panel = document.getElementById('p2pOrderChat');
    const $ = window.jQuery;

    if (!panel || !$ || typeof $.ajax !== 'function') {
        return;
    }

    const lang = {
        sendFailed: @json(__('Could not send the message. Please try again.')),
        chatClosed: @json(__('Chat is closed for this order. The transcript stays available for your records.')),
        proofTooLarge: @json(__('Payment proof must not exceed 5 MB.')),
        you: @json(__('You')),
    };

    const dom = {
        messages: document.getElementById('p2pChatMessages'),
        loading: document.getElementById('p2pChatLoading'),
        empty: document.getElementById('p2pChatEmpty'),
        closed: document.getElementById('p2pChatClosed'),
        form: document.getElementById('p2pChatForm'),
        input: document.getElementById('p2pChatInput'),
        send: document.getElementById('p2pChatSend'),
        proof: document.getElementById('p2pChatProof'),
        proofPreview: document.getElementById('p2pChatProofPreview'),
        proofName: document.getElementById('p2pChatProofName'),
        proofRemove: document.getElementById('p2pChatProofRemove'),
        error: document.getElementById('p2pChatError'),
    };

    const state = {
        lastId: 0,
        canChat: panel.getAttribute('data-can-chat') === '1',
        sending: false,
        loaded: false,
        timer: null,
    };

    const indexUrl = panel.getAttribute('data-index-url');
    const storeUrl = panel.getAttribute('data-store-url');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const escapeHtml = function (value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const isNearBottom = function () {
        return dom.messages.scrollHeight - dom.messages.scrollTop - dom.messages.clientHeight < 80;
    };

    const scrollToBottom = function () {
        dom.messages.scrollTop = dom.messages.scrollHeight;
    };

    const syncEmptyState = function () {
        const hasBubbles = dom.messages.querySelector('.p2p-chat-msg') !== null;
        dom.empty.classList.toggle('d-none', hasBubbles);
    };

    const applyCanChat = function (canChat) {
        state.canChat = Boolean(canChat);
        dom.form.classList.toggle('d-none', !state.canChat);
        dom.closed.classList.toggle('d-none', state.canChat);
    };

    const appendMessage = function (message) {
        if (!message || !message.id || Number(message.id) <= state.lastId) {
            return;
        }

        state.lastId = Number(message.id);

        const bubble = document.createElement('div');
        bubble.className = 'p2p-chat-msg ' + (message.mine ? 'p2p-chat-msg--mine' : 'p2p-chat-msg--theirs');

        let proofHtml = '';
        if (message.attachment && message.attachment.url) {
            const proof = message.attachment;
            const preview = proof.is_image
                ? '<img src="' + escapeHtml(proof.url) + '" alt="' + escapeHtml(proof.name) + '" loading="lazy">'
                : '<span class="p2p-chat-proof__file-icon"><i class="fas fa-file-pdf" aria-hidden="true"></i></span>';

            proofHtml = '<a class="p2p-chat-proof" href="' + escapeHtml(proof.url) + '" target="_blank" rel="noopener">'
                + preview
                + '<span class="p2p-chat-proof__meta"><strong>' + escapeHtml(proof.name) + '</strong>'
                + '<small>' + escapeHtml(proof.size_label || '') + '</small></span>'
                + '<i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i>'
                + '</a>';
        }

        bubble.innerHTML = '<div class="p2p-chat-msg__bubble">'
            + escapeHtml(message.body).replace(/\n/g, '<br>')
            + proofHtml
            + '</div>'
            + '<div class="p2p-chat-msg__meta">'
            + escapeHtml(message.mine ? lang.you : (message.sender_name || ''))
            + ' · ' + escapeHtml(message.sent_at_human || '')
            + '</div>';

        dom.messages.appendChild(bubble);
    };

    const scheduleNextPoll = function () {
        clearTimeout(state.timer);
        state.timer = setTimeout(function () {
            if (document.hidden) {
                scheduleNextPoll();
                return;
            }
            fetchMessages();
        }, 6000);
    };

    const fetchMessages = function () {
        $.getJSON(indexUrl, { after: state.lastId }).done(function (res) {
            dom.loading.classList.add('d-none');

            const stick = !state.loaded || isNearBottom();
            (Array.isArray(res.messages) ? res.messages : []).forEach(appendMessage);
            applyCanChat(!!res.can_chat);
            syncEmptyState();

            if (stick) {
                scrollToBottom();
            }

            state.loaded = true;
        }).always(scheduleNextPoll);
    };

    const showError = function (text) {
        dom.error.textContent = text;
        dom.error.classList.remove('d-none');
    };

    const clearError = function () {
        dom.error.textContent = '';
        dom.error.classList.add('d-none');
    };

    const sendMessage = function () {
        const body = String(dom.input.value || '').trim();
        const proof = dom.proof && dom.proof.files ? dom.proof.files[0] : null;

        if ((body === '' && !proof) || state.sending || !state.canChat) {
            return;
        }

        if (proof && proof.size > (5 * 1024 * 1024)) {
            showError(lang.proofTooLarge);
            return;
        }

        state.sending = true;
        dom.send.disabled = true;
        clearError();

        const payload = new FormData();
        if (body !== '') {
            payload.append('body', body);
        }
        if (proof) {
            payload.append('proof', proof);
        }

        $.ajax({
            url: storeUrl,
            method: 'POST',
            dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: payload,
            processData: false,
            contentType: false,
        }).done(function (res) {
            if (res && res.message) {
                appendMessage(res.message);
                syncEmptyState();
                scrollToBottom();
            }
            dom.input.value = '';
            dom.input.style.height = '';
            clearProof();
            dom.input.focus();
        }).fail(function (xhr) {
            if (xhr && xhr.status === 403) {
                applyCanChat(false);
                showError(lang.chatClosed);
                return;
            }

            const errors = xhr && xhr.responseJSON && xhr.responseJSON.errors ? xhr.responseJSON.errors : {};
            const validation = errors.proof || errors.body;
            showError(Array.isArray(validation) && validation[0] ? validation[0] : lang.sendFailed);
        }).always(function () {
            state.sending = false;
            dom.send.disabled = false;
        });
    };

    dom.form.addEventListener('submit', function (event) {
        event.preventDefault();
        sendMessage();
    });

    dom.input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            sendMessage();
        }
    });

    dom.input.addEventListener('input', function () {
        dom.input.style.height = 'auto';
        dom.input.style.height = Math.min(dom.input.scrollHeight, 110) + 'px';
    });

    const clearProof = function () {
        if (!dom.proof) return;
        dom.proof.value = '';
        if (dom.proofName) dom.proofName.textContent = '';
        if (dom.proofPreview) dom.proofPreview.classList.add('d-none');
    };

    if (dom.proof) {
        dom.proof.addEventListener('change', function () {
            const file = dom.proof.files && dom.proof.files[0] ? dom.proof.files[0] : null;
            if (!file) {
                clearProof();
                return;
            }
            if (dom.proofName) dom.proofName.textContent = file.name;
            if (dom.proofPreview) dom.proofPreview.classList.remove('d-none');
            clearError();
        });
    }

    if (dom.proofRemove) {
        dom.proofRemove.addEventListener('click', clearProof);
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && state.loaded) {
            clearTimeout(state.timer);
            fetchMessages();
        }
    });

    fetchMessages();
});
</script>
