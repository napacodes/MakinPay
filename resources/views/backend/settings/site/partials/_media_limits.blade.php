@php
    /**
     * Upload ceilings, so an administrator can see PHP's limit beside the one
     * they configured. Raising the setting past what the server accepts has no
     * effect — the request is discarded before Laravel ever runs.
     */
    $limits = $mediaLimits ?? null;
@endphp

@if($limits)
    <button type="button"
            class="btn btn-primary settings-site-header-action settings-site-test-btn"
            data-coreui-toggle="modal"
            data-coreui-target="#mediaLimitsModal">
        <x-icon name="upload" height="18" width="18"/> {{ __('Server Limits') }}
    </button>

    {{--
        Rendered through the stack, not here: this partial is included inside
        the sticky section head, and a dialogue declared in there is clipped
        away by the scrolling body around it.
    --}}
    @push('settings-site-modals')
        <div class="modal fade settings-media-modal"
             id="mediaLimitsModal"
             tabindex="-1"
             aria-labelledby="mediaLimitsModalLabel"
             aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header settings-media-modal__header">
                        <div class="settings-media-modal__title">
                            <span class="settings-media-modal__icon" aria-hidden="true">
                                <x-icon name="upload" height="20" width="20"/>
                            </span>
                            <div>
                                <span class="settings-media-modal__eyebrow">{{ __('Media & Uploads') }}</span>
                                <h5 class="modal-title" id="mediaLimitsModalLabel">{{ __('Upload Limits') }}</h5>
                            </div>
                        </div>
                        <button type="button"
                                class="settings-maintenance-modal__close"
                                data-coreui-dismiss="modal"
                                aria-label="{{ __('Close') }}">
                            <x-icon name="close" height="18" width="18"/>
                        </button>
                    </div>

                    <div class="modal-body settings-media-modal__body">
                        {{--
                            The one sentence an administrator came for: what a
                            person can actually upload right now, and whether
                            this screen or the server decided it.
                        --}}
                        @if($limits['exceeds_server'])
                            <div class="settings-media-modal__verdict settings-media-modal__verdict--warning" role="alert">
                                <span class="settings-media-modal__verdict-icon" aria-hidden="true">
                                    <x-icon name="warning" height="18" width="18"/>
                                </span>
                                <div>
                                    <strong>{{ __('The server is the stricter limit right now.') }}</strong>
                                    <p>{{ __('You have asked for :configured MB, but PHP will only accept :server MB, so uploads are capped at :effective MB. Raise upload_max_filesize and post_max_size in php.ini to use the full value.', [
                                        'configured' => $limits['configured_mb'],
                                        'server'     => $limits['server_mb'],
                                        'effective'  => $limits['effective_mb'],
                                    ]) }}</p>
                                </div>
                            </div>
                        @else
                            <div class="settings-media-modal__verdict settings-media-modal__verdict--success" role="status">
                                <span class="settings-media-modal__verdict-icon" aria-hidden="true">
                                    <x-icon name="check" height="18" width="18"/>
                                </span>
                                <div>
                                    <strong>{{ __('Uploads up to :effective MB are accepted.', ['effective' => $limits['effective_mb']]) }}</strong>
                                    <p>{{ __('This panel and the server agree, so nothing is being quietly capped.') }}</p>
                                </div>
                            </div>
                        @endif

                        <ul class="settings-media-modal__facts">
                            <li class="settings-media-modal__fact">
                                <span class="settings-media-modal__fact-icon" aria-hidden="true">
                                    <x-icon name="cil-equalizer" height="16" width="16"/>
                                </span>
                                <span class="settings-media-modal__fact-copy">
                                    <strong>{{ __('Configured in this panel') }}</strong>
                                    <small>{{ __('The value on this screen') }}</small>
                                </span>
                                <span class="settings-media-modal__fact-value" dir="ltr">{{ $limits['configured_mb'] }} MB</span>
                            </li>

                            <li class="settings-media-modal__fact {{ $limits['exceeds_server'] ? 'settings-media-modal__fact--capped' : '' }}">
                                <span class="settings-media-modal__fact-icon" aria-hidden="true">
                                    <x-icon name="kpi-shield-check" height="16" width="16"/>
                                </span>
                                <span class="settings-media-modal__fact-copy">
                                    <strong>{{ __('Actually enforced') }}</strong>
                                    <small>{{ $limits['exceeds_server'] ? __('Held down by the server') : __('What an upload really gets') }}</small>
                                </span>
                                <span class="settings-media-modal__fact-value" dir="ltr">{{ $limits['effective_mb'] }} MB</span>
                            </li>

                            <li class="settings-media-modal__fact">
                                <span class="settings-media-modal__fact-icon" aria-hidden="true">
                                    <x-icon name="cil-memory" height="16" width="16"/>
                                </span>
                                <span class="settings-media-modal__fact-copy">
                                    <strong><code>upload_max_filesize</code></strong>
                                    <small>{{ __('php.ini — the largest single file PHP accepts') }}</small>
                                </span>
                                <span class="settings-media-modal__fact-value settings-media-modal__fact-value--raw" dir="ltr">{{ $limits['upload_max_filesize'] }}</span>
                            </li>

                            <li class="settings-media-modal__fact">
                                <span class="settings-media-modal__fact-icon" aria-hidden="true">
                                    <x-icon name="cil-memory" height="16" width="16"/>
                                </span>
                                <span class="settings-media-modal__fact-copy">
                                    <strong><code>post_max_size</code></strong>
                                    <small>{{ __('php.ini — the ceiling on the whole request') }}</small>
                                </span>
                                <span class="settings-media-modal__fact-value settings-media-modal__fact-value--raw" dir="ltr">{{ $limits['post_max_size'] }}</span>
                            </li>
                        </ul>

                        <p class="settings-media-modal__note">
                            <x-icon name="info" height="14" width="14"/>
                            <span>{{ __('Only a server administrator can raise the php.ini values, and PHP has to be restarted afterwards.') }}</span>
                        </p>
                    </div>

                    <div class="modal-footer settings-media-modal__footer">
                        <button type="button" class="btn btn-light" data-coreui-dismiss="modal">
                            {{ __('Close') }}
                        </button>
                        {{--
                            Straight to the field this dialogue is about. It is
                            reachable from the page header too, where the Media
                            section may not even be open.
                        --}}
                        <button type="button"
                                class="btn btn-primary"
                                data-coreui-dismiss="modal"
                                data-ss-goto-section="media_settings"
                                data-ss-goto-field="max_upload_size">
                            <x-icon name="cil-equalizer" height="16" width="16"/>
                            {{ __('Change the limit') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endpush
@endif
