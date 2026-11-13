@php
    /*
     * The install run takes the page over.
     *
     * The progress here is drawn by the browser, not streamed from the
     * server: the install is one long synchronous POST, so there is nothing
     * to poll until it returns. The panel says so in as many words rather
     * than dressing a client-side estimate up as a server log — the numbers
     * are a pacing aid, and the server's own log is the record.
     */
@endphp

<section class="pu-card pu-run">
    <header class="pu-run__head">
        <span class="pu-run__spinner" aria-hidden="true"></span>

        <div class="pu-run__title">
            <h2>{{ __('Installing v:version', ['version' => $latest?->version]) }}</h2>
            <p>{{ __('Do not close this window. The site is in maintenance mode for users.') }}</p>
        </div>

        <span class="pu-pill pu-pill--warning pu-run__maintenance">
            <span class="pu-pill__dot pu-pill__dot--pulse"></span>{{ __('Maintenance mode active') }}
        </span>

        <span class="pu-run__pct" id="pu-overall-pct">0%</span>
    </header>

    <div class="pu-progress pu-run__bar">
        <div class="pu-progress__bar" id="pu-overall-bar"></div>
    </div>

    <div class="pu-run__meta">
        <span>{{ __('Elapsed') }} <strong id="pu-elapsed">0s</strong></span>
        <span id="pu-remaining">{{ __('estimating…') }}</span>
        <span id="pu-detail-phase">{{ __('Preparing') }}</span>
        <span id="pu-detail-title">{{ __('Preparing…') }}</span>
    </div>

    <div class="pu-run__cols">
        <div class="pu-run__pipeline">
            <div class="pu-pipeline" id="pu-pipeline"></div>

            <div class="pu-detail__action" id="pu-detail-action">
                <strong>{{ __('Initialising update.') }}</strong>
            </div>

            <div class="pu-stats">
                <div class="pu-stat">
                    <div class="pu-stat__label" id="pu-stat-1-label">{{ __('Phase elapsed') }}</div>
                    <div class="pu-stat__value" id="pu-stat-1-value">0.0s</div>
                    <div class="pu-stat__sub" id="pu-stat-1-sub"></div>
                </div>
                <div class="pu-stat">
                    <div class="pu-stat__label" id="pu-stat-2-label">{{ __('Progress') }}</div>
                    <div class="pu-stat__value" id="pu-stat-2-value">—</div>
                    <div class="pu-stat__sub" id="pu-stat-2-sub"></div>
                </div>
                <div class="pu-stat">
                    <div class="pu-stat__label" id="pu-stat-3-label">{{ __('Step') }}</div>
                    <div class="pu-stat__value" id="pu-stat-3-value">—</div>
                    <div class="pu-stat__sub" id="pu-stat-3-sub"></div>
                </div>
            </div>
        </div>

        <div class="pu-run__log">
            <div class="pu-section-head">
                <span class="pu-section-head__title">{{ __('Live log') }}</span>
                <span class="pu-section-head__meta">{{ __('this browser · the server writes its own log to storage/logs') }}</span>
            </div>

            <div class="pu-log">
                <div class="pu-log__head">
                    <span><i class="fas fa-terminal" aria-hidden="true"></i> {{ __('Install run') }}</span>
                    <div class="pu-log__head-actions">
                        <button type="button" class="pu-log__head-btn is-active" data-action="toggle-autoscroll">
                            <i class="fas fa-arrow-down" aria-hidden="true"></i><span class="label">{{ __('Auto-scroll on') }}</span>
                        </button>
                    </div>
                </div>
                <div class="pu-log__body" id="pu-log-body"></div>
            </div>
        </div>
    </div>
</section>
