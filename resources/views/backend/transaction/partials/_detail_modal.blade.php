{{--
    The transaction detail modal.

    Only the chrome is rendered here; every value is written by
    `backend/js/transaction-detail.js` from the `admin.transaction.show`
    payload. That split is deliberate — the same shell is opened from the list,
    from a deep link and as a mobile sheet, and duplicating the field markup
    three times is how one of them ends up a version behind.

    The strings the script needs travel in `data-trx-strings` rather than in the
    script itself, because a `.js` file in `public/` is outside Blade and would
    ship English into an Arabic panel with nothing to catch it.
--}}
@php
    $trxDetailStrings = [
        'copied'        => __('Copied'),
        'notCopied'     => __('Not copied'),
        'loadFailed'    => __('This transaction could not be loaded.'),
        'noFee'         => __('No fee'),
        'refunded'      => __('Refunded'),
        'netCredited'   => __('Net credited'),
        'netDebited'    => __('Net debited'),
        'payable'       => __('Payable (:currency)'),
        'amount'        => __('Amount'),
        'platformFee'   => __('Platform fee'),
        'tax'           => __('Tax'),
        'noRemarks'     => __('No remarks recorded.'),
        /*
         * Not "gateway payload": the screenshot that prompted this held a
         * subscription's own context — plan, billing cycle, trial conversion —
         * which never went near a gateway. `trx_data` is whatever the flow
         * that wrote the row chose to keep, and the label now says that.
         */
        'payloadToggle' => __('Raw transaction data · :count keys'),
        'noPayload'     => __('No additional data recorded.'),
        'copyPayload'   => __('Copy'),
        'partly'        => __('Partially refunded'),
        'fully'         => __('Refunded'),
        'upTo'          => __('Up to :amount remaining'),
        'provider'      => __('Provider'),
        'gatewayCode'   => __('Gateway code'),
        'processing'    => __('Processing'),
        'providerRef'   => __('Provider reference'),
        'wallet'        => __('Wallet'),
        'expires'       => __('Expires'),
        'reportingCur'  => __('Reporting currency'),
        'reporting'     => __('Reporting (:currency)'),
        'creditedTo'    => __('Net credited to :wallet'),
        'heldFrom'      => __('Held from :wallet until payout'),
        'webhookDelivered' => __('Delivered'),
        'webhookRetrying'  => __('Retrying'),
        'webhookFailed'    => __('Failed'),
        'webhookAttempts'  => __(':count attempts'),
        'webhookNext'      => __('next :when'),
    ];
@endphp

{{--
    `$trxStandaloneUrl` is set only by the deep-link page, which has no list
    behind it: the script then opens the dialog on load and never looks for a
    row to return focus to.
--}}
<div class="admin-trx-detail @isset($trxStandaloneUrl) admin-trx-detail--standalone @endisset"
     data-trx-detail hidden
     @isset($trxStandaloneUrl) data-trx-standalone data-trx-url="{{ $trxStandaloneUrl }}" @endisset
     data-trx-strings="{{ json_encode($trxDetailStrings, JSON_UNESCAPED_UNICODE) }}">
    <div class="admin-trx-detail__scrim" data-trx-detail-close></div>

    <div class="admin-trx-detail__dialog" role="dialog" aria-modal="true" aria-labelledby="trx-detail-title">
        <header class="admin-trx-detail__head">
            <span class="admin-trx-tile admin-trx-tile--xl" data-d="tile" aria-hidden="true"></span>

            <div class="admin-trx-detail__headline">
                <div class="admin-trx-detail__titles">
                    <h2 class="admin-trx-detail__title" id="trx-detail-title" data-d="type_label"></h2>
                    <span class="admin-users-pill" data-d="status"></span>
                    <span class="admin-users-pill admin-users-pill--warning" data-d="refund_pill" hidden></span>
                </div>

                <div class="admin-trx-detail__sub">
                    <code data-d="id"></code>
                    <button type="button" class="admin-trx-iconbtn" data-trx-copy aria-label="{{ __('Copy ID') }}">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                    </button>
                    <span data-d="created_human"></span>
                    <span class="admin-trx-detail__rel" data-d="created_rel"></span>
                </div>
            </div>

            {{--
                The printer glyph goes to a printable page, the download glyph
                to a file. They were both wired to the download until an
                operator pointed out that the printer icon promised paper and
                delivered a file in a downloads folder.
            --}}
            <a class="btn btn-light btn-sm admin-trx-detail__receipt" data-d="receipt_print_url"
               href="#" target="_blank" rel="noopener">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"></path></svg>
                {{ __('Receipt') }}
            </a>

            <button type="button" class="admin-trx-iconbtn admin-trx-iconbtn--plain" data-trx-detail-close aria-label="{{ __('Close') }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"></path></svg>
            </button>
        </header>

        {{--
            The segmented control is a mobile affordance only. On the desktop
            modal both columns are on screen at once and there is nothing to
            switch between, so CSS hides it there rather than the script
            deciding — a viewport question belongs in a media query.
        --}}
        <div class="admin-trx-detail__seg" role="tablist" aria-label="{{ __('Transaction sections') }}" data-trx-seg>
            <button type="button" class="admin-trx-detail__seg-btn is-active" data-seg="details" role="tab" aria-selected="true">{{ __('Details') }}</button>
            <button type="button" class="admin-trx-detail__seg-btn" data-seg="timeline" role="tab" aria-selected="false">{{ __('Timeline') }}</button>
            <button type="button" class="admin-trx-detail__seg-btn" data-seg="data" role="tab" aria-selected="false">{{ __('Data') }}</button>
        </div>

        <div class="admin-trx-detail__body">
            {{--
                Shown only while the transaction is being fetched. It covers
                the body rather than blanking the slots, because `paint()` owns
                every `[data-d]` and a second writer clearing them is how the
                two end up disagreeing.

                `aria-hidden` because it says nothing a screen reader wants:
                the dialog announces the wait through `aria-busy` instead.
            --}}
            <div class="admin-trx-detail__loading" data-trx-loading aria-hidden="true">
                <div class="admin-trx-detail__loading-bar admin-trx-detail__loading-bar--title"></div>
                <div class="admin-trx-detail__loading-bar admin-trx-detail__loading-bar--sub"></div>
                <div class="admin-trx-detail__loading-bar admin-trx-detail__loading-bar--amount"></div>
                <div class="admin-trx-detail__loading-cards">
                    <div class="admin-trx-detail__loading-card"></div>
                    <div class="admin-trx-detail__loading-card"></div>
                </div>
            </div>

            <div class="admin-trx-detail__main">
                <section class="admin-trx-detail__hero" data-pane="details">
                    <div>
                        <div class="admin-trx-detail__eyebrow">{{ __('Amount') }}</div>
                        <div class="admin-trx-detail__amount" data-d="amount"></div>
                        <div class="admin-trx-detail__desc" data-d="description"></div>
                    </div>

                    <div class="admin-trx-detail__fx" data-d="fx" hidden>
                        <div><span>{{ __('Entered') }}</span><strong data-d="fx_entered"></strong></div>
                        <div><span data-d="fx_base_label"></span><strong data-d="fx_base"></strong></div>
                        <div class="admin-trx-detail__fx-rate"><span>{{ __('Rate') }}</span><code data-d="fx_rate"></code></div>
                    </div>
                </section>

                <div class="admin-trx-detail__grid">
                    <section class="admin-trx-detail__card" data-pane="details">
                        <h3 class="admin-trx-detail__card-title">{{ __('Settlement breakdown') }}</h3>
                        <div class="admin-trx-detail__lines" data-d="breakdown"></div>
                        <p class="admin-trx-detail__note" data-d="settlement_note"></p>
                    </section>

                    <section class="admin-trx-detail__card" data-pane="timeline">
                        <h3 class="admin-trx-detail__card-title">{{ __('Timeline') }}</h3>
                        <ol class="admin-trx-detail__timeline" data-d="timeline"></ol>
                    </section>
                </div>

                <section class="admin-trx-detail__card" data-d="refunds_card" data-pane="timeline" hidden>
                    <h3 class="admin-trx-detail__card-title">
                        {{ __('Refunds') }}
                        <span class="admin-trx-detail__card-meta" data-d="refund_meta"></span>
                    </h3>
                    <div class="admin-trx-detail__progress" aria-hidden="true"><span data-d="refund_bar"></span></div>
                    <div class="admin-trx-detail__refunds" data-d="refund_items"></div>
                </section>

                {{--
                    Whether the merchant was told. One row per webhook owed,
                    with the attempt count and the last answer, so "we never
                    got your IPN" can be answered from here rather than from
                    the log. Only a merchant payment has any.
                --}}
                <section class="admin-trx-detail__card" data-d="webhooks_card" data-pane="timeline" hidden>
                    <h3 class="admin-trx-detail__card-title">
                        {{ __('Merchant webhooks') }}
                        <span class="admin-trx-detail__card-meta" data-d="webhook_meta"></span>
                    </h3>
                    <div class="admin-trx-detail__refunds" data-d="webhook_items"></div>
                </section>

                <section class="admin-trx-detail__card" data-pane="data">
                    <h3 class="admin-trx-detail__card-title">{{ __('Processing & references') }}</h3>
                    <dl class="admin-trx-detail__dl" data-d="references"></dl>

                    {{--
                        A wallet-to-wallet transfer never touches a gateway, so
                        `trx_data` is empty far more often than not. Offering a
                        toggle there opened a black panel containing `[]`, which
                        tells an administrator nothing and reads like a fault.
                        The script shows one or the other, never both.
                    --}}
                    <button type="button" class="admin-trx-detail__payload-toggle" data-trx-payload-toggle aria-expanded="false" hidden>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"></polyline></svg>
                        <span data-d="payload_label"></span>
                    </button>
                    {{--
                        The panel is worth keeping: it is the only place the
                        stored record itself is visible, and when a provider
                        disputes a charge it is what gets pasted into the
                        ticket. Which is also why it has a copy button — doing
                        that by hand means selecting inside a scrolling box.
                    --}}
                    <div class="admin-trx-detail__payload-wrap" data-d="payload_wrap" hidden>
                        <button type="button" class="admin-trx-detail__payload-copy" data-trx-copy-payload>
                            {{-- Says "Copied" itself; there is no room beside
                                 it for the pill the other copy buttons use. --}}
                            <span data-d="payload_copy_label" data-flash-label></span>
                        </button>
                        <pre class="admin-trx-detail__payload" data-d="payload"></pre>
                    </div>
                    <p class="admin-trx-detail__payload-empty" data-d="payload_empty" hidden></p>
                </section>
            </div>

            <aside class="admin-trx-detail__rail">
                <section class="admin-trx-detail__customer" data-pane="details">
                    <span class="admin-trx-initials" data-d="initials"></span>
                    {{-- A flex child will not shrink below its content unless
                         it is told to, and a long email pushes the KYC badge
                         out of the rail. `min-w-0` is not a utility this panel
                         defines — it looked right and did nothing. --}}
                    <div class="admin-trx-detail__customer-copy">
                        <div class="admin-trx-detail__customer-name">
                            <a href="#" data-d="customer_link"></a>
                            <span class="admin-trx-detail__kyc" data-d="kyc" hidden title="{{ __('KYC verified') }}">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            </span>
                        </div>
                        <div class="admin-trx-detail__customer-sub" data-d="customer_sub"></div>
                    </div>
                </section>

                {{--
                    Only drawn when the payload says this admin may act. The
                    endpoint asks the same question again, so a card forced open
                    in the inspector buys nothing.
                --}}
                <section class="admin-trx-detail__action" data-d="action_card" hidden>
                    <div class="admin-trx-detail__action-head">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
                        {{ __('Awaiting manual review') }}
                    </div>
                    <p class="admin-trx-detail__action-note" data-d="action_note"></p>

                    <label class="admin-trx-detail__label" for="trx-detail-remarks">{{ __('Remarks (optional)') }}</label>
                    <textarea class="form-control" id="trx-detail-remarks" data-trx-remarks rows="2" maxlength="500"></textarea>
                    <div class="admin-trx-detail__hint">{{ __('Visible to the customer on their receipt') }}</div>

                    <div class="admin-trx-detail__action-buttons">
                        <button type="button" class="admin-trx-detail__approve" data-trx-action="approve">{{ __('Approve') }}</button>
                        <button type="button" class="admin-trx-detail__reject" data-trx-action="reject">{{ __('Reject') }}</button>
                    </div>
                </section>

                <button type="button" class="admin-trx-detail__refund-open" data-d="refund_open" data-trx-refund-open hidden>
                    <span class="admin-trx-tile admin-trx-tile--warning" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"></path><path d="M3 3v5h5"></path></svg>
                    </span>
                    <span class="admin-trx-detail__refund-copy">
                        <strong>{{ __('Issue refund') }}</strong>
                        <span data-d="refund_hint"></span>
                    </span>
                </button>

                <section class="admin-trx-detail__remarks" data-pane="data">
                    <div class="admin-trx-detail__eyebrow">{{ __('Internal remarks') }}</div>
                    <p data-d="remarks"></p>
                </section>

                <div class="admin-trx-detail__rail-foot">
                    <a class="btn btn-primary w-100 admin-trx-detail__download" data-d="receipt_url" href="#">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v11"></path><path d="M7.5 10.5 12 15l4.5-4.5"></path><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"></path></svg>
                        {{ __('Download receipt PDF') }}
                    </a>
                </div>
            </aside>
        </div>

        <p class="admin-trx-detail__error" data-d="error" role="alert" hidden></p>
    </div>
</div>

@include('backend.transaction.partials._refund_modal')
