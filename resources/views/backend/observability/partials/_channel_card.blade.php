@php
    /*
     * One channel. Connected or not, the card always exposes the form, so an
     * administrator changing a rotated webhook does not have to disconnect
     * first and lose the delivery history in the process.
     *
     * Stored secrets are never rendered back into the inputs. The form posts a
     * full replacement; leaving a token in the DOM so it could be "kept" would
     * put it in every page cache and browser history entry the panel touches.
     */
    $isConnected = $card['connected'];
    $formId      = 'obs-channel-'.$card['key'];

    $brand = match ($card['key']) {
        'slack'   => 'fa-slack',
        'discord' => 'fa-discord',
        'teams'   => 'fa-microsoft',
        default   => 'fa-plug',
    };

    [$stateLabel, $stateTone] = match ($card['status']) {
        'connected' => [__('Live'), 'connected'],
        'retrying'  => [__('Retrying'), 'retrying'],
        'failing'   => [__('Failing'), 'failing'],
        default     => $isConnected
            ? [__('Untested'), 'retrying']
            : [__('Off'), 'not_connected'],
    };

    $view = match (true) {
        ! $isConnected                         => 'off',
        $card['status'] === 'connected'        => 'live',
        in_array($card['status'], ['failing', 'retrying'], true) => 'problem',
        default                                => 'untested',
    };

    $needs = collect($card['fields'])->pluck('label')->join(', ');
@endphp

<article class="obs-channel obs-channel--{{ $stateTone }}" id="obs-card-{{ $card['key'] }}" aria-labelledby="{{ $formId }}-name">
    <div class="obs-channel__head">
        <span class="obs-channel__icon obs-channel__icon--{{ $card['key'] }}" aria-hidden="true">
            <i class="fa-brands {{ $brand }}"></i>
        </span>

        <span class="obs-channel__identity">
            <h2 class="obs-channel__name" id="{{ $formId }}-name">{{ $card['label'] }}</h2>
            <span class="obs-channel__kind">{{ __('Incoming webhook') }}</span>
        </span>

        <span class="obs-channel__state obs-channel__state--{{ $stateTone }}">
            <span class="obs-channel__dot" aria-hidden="true"></span>
            {{ $stateLabel }}
        </span>
    </div>

    <div class="obs-channel__body">
        @switch($view)
            @case('live')
                <dl class="obs-channel__facts">
                    <div>
                        <dt>{{ __('Last delivery') }}</dt>
                        <dd>{{ $card['lastSeen']?->diffForHumans() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('Latency') }}</dt>
                        <dd>{{ $card['lastMs'] !== null ? __(':ms ms', ['ms' => number_format($card['lastMs'])]) : '—' }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('Sent · 24h') }}</dt>
                        <dd>{{ number_format($card['sentToday']) }}</dd>
                    </div>
                </dl>
                @break

            @case('problem')
                <div class="obs-channel__notice obs-channel__notice--{{ $card['status'] === 'failing' ? 'danger' : 'warning' }}">
                    @if($card['lastFailure'])
                        <span class="obs-channel__error">
                            {{ $card['lastFailure']->response_code ? __('HTTP :code', ['code' => $card['lastFailure']->response_code]) : __('No response') }}@if($card['lastFailure']->response_summary) · {{ \Illuminate\Support\Str::limit($card['lastFailure']->response_summary, 60) }}@endif
                        </span>
                    @endif
                    <span class="obs-channel__notice-text">
                        @if($card['status'] === 'failing')
                            {{ trans_choice('{0}Repeated failures. Check the webhook URL.|{1}Rejected the last attempt. Paste a new webhook URL to resume alerts.|[2,*]Rejected :count attempts in a row. Paste a new webhook URL to resume alerts.', $card['failures'], ['count' => $card['failures']]) }}
                        @else
                            {{ __('The last attempt did not land. It will be retried.') }}
                        @endif
                    </span>
                </div>
                @break

            @case('untested')
                <div class="obs-channel__notice obs-channel__notice--warning">
                    <span class="obs-channel__notice-title">{{ __('Saved, but never tested') }}</span>
                    <span class="obs-channel__notice-text">{{ __('Send a test to confirm it works.') }}</span>
                </div>
                @break

            @default
                <div class="obs-channel__notice obs-channel__notice--empty">
                    <span class="obs-channel__notice-title">{{ __('Not receiving alerts') }}</span>
                    @if($needs !== '')
                        <span class="obs-channel__notice-text">{{ __('Needs: :fields.', ['fields' => $needs]) }}</span>
                    @endif
                </div>
        @endswitch
    </div>

    @can('observability-manage')
        <div class="obs-channel__actions">
            @if(! $isConnected)
                <button class="btn obs-channel__connect"
                        type="button"
                        data-coreui-toggle="collapse"
                        data-coreui-target="#{{ $formId }}"
                        aria-expanded="false"
                        aria-controls="{{ $formId }}">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    <span>{{ __('Connect :channel', ['channel' => $card['label']]) }}</span>
                </button>
            @else
                <button class="btn {{ $view === 'problem' ? 'btn-primary' : 'btn-outline-secondary' }}"
                        type="button"
                        data-coreui-toggle="collapse"
                        data-coreui-target="#{{ $formId }}"
                        aria-expanded="false"
                        aria-controls="{{ $formId }}">
                    <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                    <span>{{ $view === 'problem' ? __('Update webhook') : __('Edit') }}</span>
                </button>

                <form method="POST" action="{{ route('admin.observability.channels.test', $card['key']) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary obs-channel__compact" title="{{ __('Send test') }}">
                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                        <span class="obs-channel__compact-label">{{ __('Send test') }}</span>
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.observability.channels.disconnect', $card['key']) }}" class="obs-channel__end">
                    @csrf
                    <button type="submit"
                            class="btn obs-channel__disconnect"
                            aria-label="{{ __('Disconnect :channel', ['channel' => $card['label']]) }}"
                            title="{{ __('Disconnect') }}">
                        <i class="fa-solid fa-link-slash" aria-hidden="true"></i>
                    </button>
                </form>
            @endif
        </div>

        <div class="collapse" id="{{ $formId }}">
            <form class="obs-channel__form" method="POST" action="{{ route('admin.observability.channels.connect', $card['key']) }}">
                @csrf

                @foreach($card['fields'] as $field => $definition)
                    @php($inputId = $formId.'-'.$field)
                    <div class="mb-2">
                        <label class="form-label" for="{{ $inputId }}">{{ $definition['label'] }}</label>
                        <input type="{{ $definition['secret'] ? 'password' : 'text' }}"
                               id="{{ $inputId }}"
                               name="credentials[{{ $field }}]"
                               class="form-control form-control-sm @error('credentials.'.$field) is-invalid @enderror"
                               autocomplete="off"
                               aria-describedby="{{ $inputId }}-help">
                        <small id="{{ $inputId }}-help" class="obs-channel__help">{{ $definition['help'] }}</small>
                        @error('credentials.'.$field)
                            <span class="invalid-feedback d-block">{{ $message }}</span>
                        @enderror
                    </div>
                @endforeach

                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    <span>{{ __('Save') }}</span>
                </button>
            </form>
        </div>
    @endcan
</article>
