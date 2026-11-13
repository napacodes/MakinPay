@php
    use App\Services\Dashboard\DashboardQueueService;
    use App\Services\Dashboard\DashboardSla;

    /**
     * Named and ordered by the same map the panel behind this dialog draws its
     * rows from, and defaulted from the same constant the service classifies
     * against — so a queue an operator has just read as "Deposit approvals"
     * cannot appear here under another name, and a default cannot be corrected
     * in one file and left wrong in the other.
     */
    $dkNaming  = DashboardQueueService::naming();
    $dkTargets = $dashboard['sla']['targets'];
@endphp

<div class="dk-modal" data-dk-sla-modal hidden>
    <div class="dk-modal__backdrop" data-dk-sla-dismiss></div>
    <div class="dk-modal__dialog dk-modal__dialog--limits" role="dialog" aria-modal="true" aria-labelledby="dk-sla-title">
        <form data-dk-sla-form>
            @csrf
            @method('PUT')
            <div class="dk-modal__head">
                <div class="dk-modal__heading">
                    <span class="dk-limits__tile" aria-hidden="true"><i class="fa-solid fa-clock"></i></span>
                    <div>
                        <h3 class="dk-modal__title" id="dk-sla-title">{{ __('Response time limits') }}</h3>
                        <p class="dk-modal__sub">{{ __('How long a request may wait before the panel counts it as overdue. Drives the overdue count, the status pills and the meters.') }}</p>
                    </div>
                </div>
                <button type="button" class="dk-modal__close" data-dk-sla-dismiss aria-label="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>

            <div class="dk-modal__body">
                <div class="dk-sla">
                    <div class="dk-sla__row dk-sla__row--head">
                        <span aria-hidden="true"></span>
                        <span>{{ __('Request type') }}</span>
                        <span class="dk-t-end">{{ __('Limit') }}</span>
                        <span class="dk-t-end">{{ __('Default') }}</span>
                    </div>

                    @foreach($dkNaming as $dkKey => $dkRow)
                        @php
                            $dkField   = DashboardSla::TARGETS[$dkKey]['setting'];
                            $dkDefault = DashboardSla::TARGETS[$dkKey]['default'];
                            $dkValue   = $dkTargets[$dkKey] ?? $dkDefault;
                        @endphp
                        {{--
                            A row that no longer sits on its default is marked
                            at the inline start. Eight numbers all look alike,
                            and which of them somebody deliberately moved is
                            the question this dialog is usually opened to
                            answer — previously unanswerable without reading
                            every line against the column beside it.
                        --}}
                        <div class="dk-sla__row @if($dkValue !== $dkDefault) is-tuned @endif"
                             data-dk-sla-row
                             data-default="{{ $dkDefault }}"
                             data-queue-tint="{{ $dkKey }}">
                            <span class="dk-sla__tile" aria-hidden="true"><i class="fa-solid {{ $dkRow['icon'] }}"></i></span>

                            <label class="dk-sla__name" for="dk-sla-{{ $dkKey }}">
                                <span class="dk-sla__title">{{ $dkRow['title'] }}</span>
                                <span class="dk-sla__sub">{{ $dkRow['subtitle'] }}</span>
                            </label>

                            <span class="dk-field">
                                <input id="dk-sla-{{ $dkKey }}" name="{{ $dkField }}" type="number" min="1" max="720"
                                       value="{{ $dkValue }}" required inputmode="numeric" dir="ltr"
                                       data-dk-sla-input>
                                <span class="dk-field__unit">{{ __('h') }}</span>
                            </span>

                            <span class="dk-sla__default" dir="ltr">{{ $dkDefault }}{{ __('h') }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="dk-sla">
                    <div class="dk-sla__setting">
                        <span class="dk-sla__tile dk-sla__tile--plain" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
                        <label class="dk-sla__name" for="dk-sla-warning">
                            <span class="dk-sla__title">{{ __('Warn before the limit') }}</span>
                            <span class="dk-sla__sub">{{ __('Show "Due soon" once a request has used this share of its limit.') }}</span>
                        </label>
                        <span class="dk-field">
                            <input id="dk-sla-warning" name="{{ DashboardSla::WARNING_SETTING }}" type="number" min="10" max="99"
                                   value="{{ $dashboard['sla']['warning'] }}" required inputmode="numeric" dir="ltr">
                            <span class="dk-field__unit">%</span>
                        </span>
                    </div>

                    <div class="dk-sla__setting">
                        <span class="dk-sla__tile dk-sla__tile--plain" aria-hidden="true"><i class="fa-solid fa-bell"></i></span>
                        <label class="dk-sla__name" for="dk-sla-alerts">
                            <span class="dk-sla__title">{{ __('Notify admins when a queue goes overdue') }}</span>
                            <span class="dk-sla__sub">{{ __('Send a dashboard notification so nobody has to be watching this page. The same queue waits :hours hours before it can alert again.', ['hours' => 6]) }}</span>
                        </label>
                        <label class="dk-switch">
                            <input type="hidden" name="{{ DashboardSla::ALERTS_SETTING }}" value="0">
                            <input id="dk-sla-alerts" type="checkbox" name="{{ DashboardSla::ALERTS_SETTING }}" value="1" @checked($dashboard['sla']['alerts'])>
                            <span class="dk-switch__track" aria-hidden="true"></span>
                        </label>
                    </div>
                </div>

                <p class="dk-modal__error" data-dk-sla-error hidden role="alert"></p>
            </div>

            <div class="dk-modal__foot">
                <button type="button" class="dk-link-btn" data-dk-sla-reset>{{ __('Reset to defaults') }}</button>
                <div class="dk-modal__actions">
                    <button type="button" class="dk-btn dk-btn--ghost" data-dk-sla-dismiss>{{ __('Cancel') }}</button>
                    {{-- Ink, the same control the panel's own "Edit limits" is. --}}
                    <button type="submit" class="dk-limits__edit" data-dk-sla-save>{{ __('Save limits') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
