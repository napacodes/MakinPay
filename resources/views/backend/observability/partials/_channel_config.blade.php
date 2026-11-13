{{--
    What an alert looks like, and how often one is allowed to repeat.

    The preview shows the body a channel actually receives, built by the same
    AlertRouter::body() the test button and every real alert use. The three
    tabs differ only where the adapters differ — Slack's buttons, Discord's
    colour bar and fields, Teams' card action — so switching tabs never shows
    a field the channel would not get.

    The routing table is read straight from AlertRouter::MATRIX, so it cannot
    describe behaviour the code does not have. The frequency meter is derived
    from the same throttles.
--}}
@php
    $previewTab = collect($cards)->firstWhere('connected', true)['key'] ?? 'slack';
    $dialects   = [
        'slack'   => __('Slack'),
        'discord' => __('Discord'),
        'teams'   => __('Teams'),
    ];
    $sender = config('app.name');
@endphp

<div class="obs-channels__split">
    <section class="obs-panel" aria-labelledby="obs-preview-title">
        <div class="obs-panel__head">
            <div class="obs-panel__intro">
                <h2 class="obs-panel__title" id="obs-preview-title">{{ __('What arrives') }}</h2>
                <p class="obs-panel__lead">{{ __('Rendered from your most recent error, in each channel\'s own format.') }}</p>
            </div>

            <div class="obs-seg" role="tablist" aria-label="{{ __('Preview channel') }}">
                @foreach($dialects as $key => $label)
                    <button type="button"
                            class="obs-seg__btn @if($previewTab === $key) active @endif"
                            id="obs-preview-tab-{{ $key }}"
                            data-coreui-toggle="tab"
                            data-coreui-target="#obs-preview-{{ $key }}"
                            role="tab"
                            aria-controls="obs-preview-{{ $key }}"
                            aria-selected="{{ $previewTab === $key ? 'true' : 'false' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="obs-panel__body tab-content">
            @foreach($dialects as $key => $label)
                <div class="tab-pane fade @if($previewTab === $key) show active @endif"
                     id="obs-preview-{{ $key }}"
                     role="tabpanel"
                     aria-labelledby="obs-preview-tab-{{ $key }}"
                     tabindex="0">
                    <div class="obs-msg obs-msg--{{ $key }}">
                        <span class="obs-msg__avatar" aria-hidden="true"><i class="fa-solid fa-bell"></i></span>

                        <div class="obs-msg__main">
                            <div class="obs-msg__byline">
                                <strong class="obs-msg__sender">{{ $sender }}</strong>
                                <span class="obs-msg__tag">{{ $key === 'discord' ? __('BOT') : ($key === 'slack' ? __('APP') : __('Workflow')) }}</span>
                            </div>

                            @if($key === 'discord')
                                <div class="obs-msg__embed obs-msg__embed--{{ $previewMeta['badge'] }}">
                                    @if($previewMeta['url'])
                                        <a href="{{ $previewMeta['url'] }}" class="obs-msg__title">{{ $previewMeta['title'] }}</a>
                                    @else
                                        <span class="obs-msg__title">{{ $previewMeta['title'] }}</span>
                                    @endif
                                    <div class="obs-msg__text">{{ $preview }}</div>
                                    <dl class="obs-msg__fields">
                                        <div><dt>{{ __('Events') }}</dt><dd>{{ number_format($previewMeta['events']) }}</dd></div>
                                        <div><dt>{{ __('Users') }}</dt><dd>{{ number_format($previewMeta['users']) }}</dd></div>
                                        <div><dt>{{ __('Source') }}</dt><dd>{{ $previewMeta['source'] }}</dd></div>
                                    </dl>
                                </div>
                            @elseif($key === 'teams')
                                <div class="obs-msg__card">
                                    <span class="obs-msg__title">{{ $previewMeta['title'] }}</span>
                                    <div class="obs-msg__text">{{ $preview }}</div>
                                    <span class="obs-msg__button obs-msg__button--primary">{{ __('Open error') }}</span>
                                </div>
                            @else
                                <div class="obs-msg__text">{{ $preview }}</div>
                                <div class="obs-msg__buttons">
                                    <span class="obs-msg__button obs-msg__button--primary">{{ __('Acknowledge') }}</span>
                                    <span class="obs-msg__button">{{ __('Open error') }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="obs-panel" aria-labelledby="obs-routing-title">
        <div class="obs-panel__head">
            <div class="obs-panel__intro">
                <h2 class="obs-panel__title" id="obs-routing-title">{{ __('Severity routing') }}</h2>
                <p class="obs-panel__lead">
                    {{ __('Every connected channel is told about every alerting severity. The severity decides how often, and whether quiet hours apply.') }}
                </p>
            </div>
        </div>

        <div class="obs-panel__body">
            <div class="table-responsive obs-routing-box">
                <table class="table mb-0 align-middle obs-routing">
                    <thead>
                    <tr>
                        <th scope="col">{{ __('Level') }}</th>
                        <th scope="col">{{ __('Repeat limit') }}</th>
                        <th scope="col">{{ __('Frequency') }}</th>
                        <th scope="col">{{ __('Quiet hours') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($routing as $row)
                        <tr>
                            <td>
                                <x-observability.severity-badge :level="$row['level']"/>
                            </td>
                            <td class="obs-routing__limit">{{ $row['throttle'] }}</td>
                            <td>
                                <span class="obs-meter obs-meter--{{ $row['badge'] }}"
                                      role="img"
                                      aria-label="{{ __('Relative frequency: :n of 4', ['n' => $row['frequency']]) }}">
                                    @for($i = 1; $i <= 4; $i++)
                                        <span class="obs-meter__seg @if($i <= $row['frequency']) is-on @endif"></span>
                                    @endfor
                                </span>
                            </td>
                            <td class="@if($row['quietIgnored']) obs-routing__strong @endif">{{ $row['quiet'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
