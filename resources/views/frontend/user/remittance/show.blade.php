@extends('frontend.layouts.user.index')
@section('title', __('Transfer :ref', ['ref' => $transfer->reference]))

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/remittance.css?v=' . filemtime(public_path('frontend/css/remittance.css'))) }}">
@endpush

@section('content')
    @php
        use App\Enums\PayoutMethod;
        use App\Enums\RemittanceStatus;

        // Map our enum to the design's 4-step pipeline.
        $stepIndex = match ($transfer->status) {
            RemittanceStatus::Draft, RemittanceStatus::Quoted, RemittanceStatus::Pending => 0,
            RemittanceStatus::Submitted     => 1,
            RemittanceStatus::Processing    => 2,
            RemittanceStatus::Completed     => 3,
            default                         => 0,
        };
        $isFailed   = in_array($transfer->status, [RemittanceStatus::Failed, RemittanceStatus::Refunded, RemittanceStatus::Canceled], true);
        $isComplete = $transfer->status === RemittanceStatus::Completed;

        $statusPillClass = match ($transfer->status) {
            RemittanceStatus::Completed                                       => 'success',
            RemittanceStatus::Failed, RemittanceStatus::Canceled              => 'danger',
            RemittanceStatus::Processing                                      => 'warning',
            RemittanceStatus::Refunded                                        => 'info',
            default                                                           => 'info',
        };

        $steps = [
            ['key' => 'submitted',  'label' => __('Submitted'),    'icon' => 'paper-plane'],
            ['key' => 'processing', 'label' => __('Processing'),   'icon' => 'arrows-rotate'],
            ['key' => 'payout',     'label' => __('Payout sent'),  'icon' => 'building-columns'],
            ['key' => 'completed',  'label' => __('Received'),     'icon' => 'circle-check'],
        ];

        $recipientName = $transfer->recipient_name;
        $recipientCountry = $transfer->recipient_country;
        $recipientPayoutMethod = PayoutMethod::tryFrom((string) data_get($transfer->recipient_snapshot, 'payout_method'))
            ?? $transfer->payout_method;
        $timeline = $transfer->statusEvents
            ->map(fn ($event) => [
                'status'    => $event->to_status,
                'note'      => $event->note,
                'timestamp' => $event->occurred_at,
            ]);

        if ($timeline->isEmpty()) {
            $timeline = collect(array_reverse($transfer->status_history ?? []));
        }
    @endphp

    <div class="row">
        <div class="col-12">
            <div class="single-form-card">
                @include('frontend.user.remittance.partials._page_header', [
                    'rmtPageTitle'    => $transfer->reference,
                    'rmtPageSubtitle' => __('International transfer · :status', ['status' => $transfer->status->label()]),
                    'rmtPageIcon'     => 'fas fa-paper-plane',
                    'rmtCurrentPage'  => 'show',
                ])
            </div>
        </div>
    </div>

    <div class="dk-rmt">
        <div class="row g-3">
            <div class="col-12 col-xl-8">

                {{-- Hero confirmation --}}
                <div class="dk-rmt__card dk-rmt__card--hero mb-3" style="text-align:center;padding:32px 28px;">
                    <div style="width:72px;height:72px;border-radius:50%;margin:0 auto 16px;display:grid;place-items:center;
                                background:{{ $isComplete ? '#dcfce7' : ($isFailed ? '#fee2e2' : '#eef2ff') }};
                                color:{{ $isComplete ? 'var(--dk-rmt-success)' : ($isFailed ? 'var(--dk-rmt-danger)' : 'var(--dk-rmt-primary)') }};
                                font-size:32px;">
                        <i class="fa-solid fa-{{ $isComplete ? 'circle-check' : ($isFailed ? 'circle-exclamation' : 'paper-plane') }}"></i>
                    </div>
                    <h1 class="dk-rmt__hero-title">
                        @if ($isComplete)
                            {{ __('Money received') }}
                        @elseif ($isFailed)
                            {{ __('Transfer could not be completed') }}
                        @else
                            {{ __('Your transfer is on the way') }}
                        @endif
                    </h1>
                    <p class="dk-rmt__hero-sub">
                        {{ __('Tracking ref') }}
                        <span class="dk-mono dk-rmt__tracking-ref" id="dk-rmt-ref">{{ $transfer->reference }}</span>
                        <button type="button" class="dk-rmt__copy-ref" id="dk-rmt-copy-ref"
                                aria-label="{{ __('Copy transfer reference') }}"
                                data-copy-success="{{ __('Reference copied.') }}"
                                data-copy-failure="{{ __('Could not copy the reference. Select and copy it manually.') }}">
                            <i class="fa-solid fa-copy" aria-hidden="true"></i>
                        </button>
                        <span class="visually-hidden" id="dk-rmt-copy-status" role="status" aria-live="polite"></span>
                    </p>
                </div>

                {{-- Stepper --}}
                @if (! $isFailed)
                    <div class="dk-rmt__card mb-3" style="padding:24px;">
                        <div class="dk-rmt__stepper">
                            @foreach ($steps as $i => $step)
                                @php
                                    $isDone   = $i < $stepIndex;
                                    $isActive = $i === $stepIndex;
                                    $stateCls = $isDone ? 'is-done' : ($isActive ? 'is-active' : '');
                                @endphp
                                <div class="dk-rmt__step {{ $stateCls }}">
                                    <div class="dk-rmt__step-circle">
                                        <i class="fa-solid fa-{{ $isDone ? 'check' : $step['icon'] }}"></i>
                                    </div>
                                    <div class="dk-rmt__step-label">{{ $step['label'] }}</div>
                                </div>
                                @if ($i < count($steps) - 1)
                                    <div class="dk-rmt__step-bar {{ $i < $stepIndex - 1 ? 'is-done' : ($i === $stepIndex - 1 ? 'is-half' : '') }}"></div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="dk-rmt__alert dk-rmt__alert--danger mb-3">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <div>
                            <strong>{{ __('Failure reason:') }}</strong>
                            {{ $transfer->failure_reason ?? __('Unknown error') }}
                        </div>
                    </div>
                @endif

                {{-- Money movement card --}}
                <div class="dk-rmt__card mb-3" style="padding:24px;">
                    <div style="font-weight:700;font-size:14px;color:var(--dk-rmt-heading);margin-bottom:14px;">
                        {{ __('Money movement') }}
                    </div>
                    <div class="dk-rmt__rows">
                        <div class="dk-rmt__row">
                            <div class="dk-rmt__row-key">{{ __('You paid') }}</div>
                            <div class="dk-rmt__row-val">{{ money($transfer->total_paid, $transfer->source_currency, false) }} {{ $transfer->source_currency }}</div>
                        </div>
                        <div class="dk-rmt__row">
                            <div class="dk-rmt__row-key">{{ __('Transfer fee') }}</div>
                            <div class="dk-rmt__row-val">{{ money($transfer->fee_amount, $transfer->source_currency, false) }} {{ $transfer->source_currency }}</div>
                        </div>
                        @foreach ((array) $transfer->tax_breakdown as $taxLine)
                            <div class="dk-rmt__row" data-tax-line>
                                <div class="dk-rmt__row-key">{{ $taxLine['name'] ?? __('Tax') }}</div>
                                <div class="dk-rmt__row-val">{{ money($taxLine['amount'] ?? 0, $transfer->source_currency, false) }} {{ $transfer->source_currency }}</div>
                            </div>
                        @endforeach
                        <div class="dk-rmt__row">
                            <div class="dk-rmt__row-key">{{ __('Exchange rate') }}</div>
                            <div class="dk-rmt__row-val dk-mono">1 {{ $transfer->source_currency }} = {{ \App\Support\Money::formatRate($transfer->exchange_rate) }} {{ $transfer->destination_currency }}</div>
                        </div>
                        <div class="dk-rmt__row-divider"></div>
                        <div class="dk-rmt__row-receive">
                            <div class="dk-rmt__row-receive-label">{{ __('Recipient gets') }}</div>
                            <div class="dk-rmt__row-receive-amount">
                                {{ money($transfer->receive_amount, $transfer->destination_currency, false) }}
                                <small>{{ $transfer->destination_currency }}</small>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Recipient --}}
                <div class="dk-rmt__card mb-3" style="padding:18px;">
                    <div style="font-weight:700;font-size:14px;color:var(--dk-rmt-heading);margin-bottom:12px;">{{ __('Recipient') }}</div>
                    @php
                        $tones = ['indigo','rose','amber','teal','violet','sky'];
                        $tone  = $tones[($transfer->beneficiary_id ?? 0) % count($tones)];
                        $initials = collect(explode(' ', $recipientName))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
                    @endphp
                    <div style="display:flex;align-items:center;gap:14px;">
                        <span class="dk-rmt__avatar dk-rmt__avatar--lg dk-rmt__avatar--{{ $tone }}">{{ $initials }}</span>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <strong style="color:var(--dk-rmt-heading);font-size:14.5px;">{{ $recipientName }}</strong>
                                <span class="dk-rmt__flag dk-rmt__flag--sm">{{ getCountryFlagEmoji($recipientCountry) }}</span>
                                <span style="font-size:11.5px;color:var(--dk-rmt-muted);">{{ $recipientCountry }}</span>
                            </div>
                            <div class="dk-rmt__masked mt-1">
                                <i class="{{ $recipientPayoutMethod->icon() }}"></i>
                                {{ $transfer->masked_recipient_account }}
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Timeline --}}
                @if ($timeline->isNotEmpty())
                    <div class="dk-rmt__card" style="padding:20px;">
                        <div style="font-weight:700;font-size:14px;color:var(--dk-rmt-heading);margin-bottom:14px;">
                            <i class="fa-solid fa-clock-rotate-left" style="color:var(--dk-rmt-primary);margin-right:6px;"></i>
                            {{ __('Timeline') }}
                        </div>
                        <div class="dk-rmt__timeline">
                            @foreach ($timeline as $entry)
                                @php
                                    $entryStatus = RemittanceStatus::tryFrom($entry['status']) ?? null;
                                    $rowCls = match (true) {
                                        $entryStatus === RemittanceStatus::Completed => 'is-success',
                                        $entryStatus === RemittanceStatus::Failed    => 'is-warning',
                                        $entryStatus === RemittanceStatus::Processing => 'is-warning',
                                        default                                       => '',
                                    };
                                @endphp
                                <div class="dk-rmt__timeline-row {{ $rowCls }}">
                                    <div class="dk-rmt__timeline-marker"><span></span></div>
                                    <div style="flex:1;">
                                        <div class="dk-rmt__timeline-time">{{ \Carbon\Carbon::parse($entry['timestamp'])->format('M d · h:i A') }}</div>
                                        <div class="dk-rmt__timeline-text">{{ $entryStatus?->label() ?? ucfirst($entry['status']) }}</div>
                                        @if (! empty($entry['note']))
                                            <div class="dk-rmt__timeline-meta">{{ $entry['note'] }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Right rail --}}
            <div class="col-12 col-xl-4">
                {{-- Status pill --}}
                <div class="dk-rmt__card mb-3" style="text-align:center;padding:18px;">
                    <div style="font-size:12px;color:var(--dk-rmt-muted);text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:8px;">
                        {{ __('Status') }}
                    </div>
                    <span class="dk-rmt__pill dk-rmt__pill--{{ $statusPillClass }}" style="font-size:13px;padding:6px 14px;">
                        <span class="dk-rmt__pill-dot"></span>
                        {{ $transfer->status->label() }}
                    </span>
                    <div style="font-size:11.5px;color:var(--dk-rmt-muted);margin-top:10px;">
                        {{ __('Started :ago', ['ago' => \Carbon\Carbon::parse($transfer->created_at)->diffForHumans()]) }}
                    </div>
                </div>

                {{-- Quick actions --}}
                <div class="dk-rmt__card mb-3">
                    <div style="font-weight:700;font-size:14px;color:var(--dk-rmt-heading);margin-bottom:14px;">
                        <i class="fa-solid fa-bolt" style="color:var(--dk-rmt-warning);margin-right:6px;"></i>
                        {{ __('Quick actions') }}
                    </div>
                    <a href="{{ route('user.remittance.create', ['beneficiary_id' => $transfer->beneficiary_id]) }}"
                       class="dk-rmt__btn dk-rmt__btn--primary dk-rmt__btn--full mb-2">
                        <i class="fa-solid fa-paper-plane"></i> {{ __('Send Again') }}
                    </a>
                    <a href="{{ route('user.beneficiaries.edit', $transfer->beneficiary_id) }}"
                       class="dk-rmt__btn dk-rmt__btn--secondary dk-rmt__btn--full">
                        <i class="fa-solid fa-pen"></i> {{ __('Edit Recipient') }}
                    </a>
                </div>

                {{-- References --}}
                <div class="dk-rmt__card" style="background:var(--dk-rmt-soft);">
                    <div style="font-weight:700;font-size:14px;color:var(--dk-rmt-heading);margin-bottom:10px;">
                        <i class="fa-solid fa-receipt" style="color:var(--dk-rmt-primary);margin-right:6px;"></i>
                        {{ __('References') }}
                    </div>
                    <div style="font-size:12.5px;color:var(--dk-rmt-body);display:flex;flex-direction:column;gap:6px;">
                        <div><strong style="color:var(--dk-rmt-heading);">{{ __('Transfer:') }}</strong> <span class="dk-mono">{{ $transfer->reference }}</span></div>
                        @if ($transfer->transaction)
                            <div><strong style="color:var(--dk-rmt-heading);">{{ __('Transaction:') }}</strong> <span class="dk-mono">{{ $transfer->transaction->trx_id }}</span></div>
                        @endif
                        @if ($transfer->gateway_reference)
                            <div><strong style="color:var(--dk-rmt-heading);">{{ __('Gateway:') }}</strong> <span class="dk-mono">{{ $transfer->gateway_reference }}</span></div>
                        @endif
                        @if ($transfer->payout_gateway)
                            <div><strong style="color:var(--dk-rmt-heading);">{{ __('Partner:') }}</strong> {{ $transfer->payout_gateway }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            const copyButton = document.getElementById('dk-rmt-copy-ref');
            const reference = document.getElementById('dk-rmt-ref');
            const status = document.getElementById('dk-rmt-copy-status');
            if (! copyButton || ! reference || ! status) { return; }

            function copyWithFallback(text) {
                const copyField = document.createElement('textarea');
                copyField.value = text;
                copyField.setAttribute('readonly', '');
                copyField.style.position = 'fixed';
                copyField.style.opacity = '0';
                copyField.style.pointerEvents = 'none';
                document.body.appendChild(copyField);
                copyField.select();
                copyField.setSelectionRange(0, text.length);

                const copied = document.execCommand('copy');
                copyField.remove();

                if (! copied) {
                    throw new Error('Copy command failed');
                }
            }

            async function copyReference(text) {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                    return;
                }

                copyWithFallback(text);
            }

            copyButton.addEventListener('click', async function () {
                try {
                    await copyReference(reference.textContent.trim());
                    const icon = copyButton.querySelector('i');
                    if (icon) { icon.className = 'fa-solid fa-check'; }
                    status.textContent = copyButton.dataset.copySuccess;
                    if (typeof window.notifyEvs === 'function') {
                        window.notifyEvs('success', copyButton.dataset.copySuccess);
                    }

                    window.setTimeout(function () {
                        if (icon) { icon.className = 'fa-solid fa-copy'; }
                    }, 1600);
                } catch (error) {
                    status.textContent = copyButton.dataset.copyFailure;
                    if (typeof window.notifyEvs === 'function') {
                        window.notifyEvs('error', copyButton.dataset.copyFailure);
                    }
                }
            });
        })();
    </script>
@endpush
