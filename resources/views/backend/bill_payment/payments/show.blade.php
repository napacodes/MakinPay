@extends('backend.bill_payment.layout')

@section('title', __('Bill payment :reference', ['reference' => $payment->reference]))

@push('styles')
    {{-- The mark-paid and mark-failed dialogs reuse the manual-request review modal's look. --}}
    <link rel="stylesheet" href="{{ asset('backend/css/transaction-admin.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/transaction-admin.css'))) }}">
@endpush

{{--
    The detail page supplies its own header: the biller as the title, the BP
    reference as the ID chip, the payment status as the one pill, and the
    amounts as the strip. Its one primary action is Mark paid; the refund is
    destructive, so it lives in the menu after the divider.
--}}
@section('sub_header')
    @php
        $isOpen     = ! ($payment->status?->isTerminal() ?? false);
        $statusTone = match ($payment->status?->color()) {
            'success' => 'success',
            'warning' => 'warning',
            'danger'  => 'danger',
            default   => 'neutral',
        };
        $ageTone = match ($row['age']) {
            \App\Services\Dashboard\DashboardSla::STATE_OVER => 'danger',
            \App\Services\Dashboard\DashboardSla::STATE_NEAR => 'warning',
            default                                          => 'neutral',
        };
        $canOpenLedger = auth('admin')->user()?->can('transaction-list') ?? false;
    @endphp

    <x-page-header
        :title="$payment->biller_name"
        :subtitle="$payment->customer_number_label.': '.$payment->customer_number"
        icon="receipt"
        :badge="$payment->reference"
        :breadcrumbs="[
            ['label' => __('Money movement')],
            ['label' => __('Bill payments'), 'url' => route('admin.bill-payment.index')],
            ['label' => $payment->reference],
        ]"
        :back="route('admin.bill-payment.queue')"
        :back-label="__('Back to queue')"
        :status="['tone' => $statusTone, 'label' => $payment->status?->label() ?? __('Unknown')]"
        :meta="array_values(array_filter([
            getCountryDisplayLabel($payment->country, false) ?? $payment->country,
            $payment->provider?->name ?? $payment->provider_code,
            $payment->created_at?->format('d M Y, H:i'),
        ]))"
        :stats="[
            ['label' => __('Bill amount'), 'value' => money($payment->bill_amount, $payment->bill_currency, false).' '.$payment->bill_currency, 'tone' => 'brand', 'icon' => 'receipt'],
            ['label' => __('Fee'), 'value' => money($payment->fee, $payment->currency, false).' '.$payment->currency, 'note' => $payment->tax_amount !== null ? __('+ :tax tax', ['tax' => money($payment->tax_amount, $payment->currency, false)]) : null, 'tone' => 'neutral', 'icon' => 'fee'],
            ['label' => __('Wallet debit'), 'value' => money($payment->total_amount, $payment->currency, false).' '.$payment->currency, 'tone' => 'success', 'icon' => 'wallet-payment'],
            $payment->processed_at !== null
                ? ['label' => __('Processed'), 'value' => $payment->processed_at->format('d M Y'), 'note' => $payment->processed_at->format('H:i'), 'tone' => 'success', 'icon' => 'clock']
                : ['label' => __('Waiting'), 'value' => $row['ageLabel'], 'tone' => $ageTone, 'icon' => 'clock'],
        ]"
    >
        @if($canAct && $row['canMarkPaid'])
            <x-slot:actions>
                <button type="button"
                        class="ph-btn ph-btn--primary"
                        data-bpa-open="mark-paid"
                        @include('backend.bill_payment.payments._trigger', ['payment' => $payment])>
                    <x-icon name="check" width="14" height="14"/>
                    {{ __('Mark paid') }}
                </button>
            </x-slot:actions>
        @endif

        <x-slot:menu>
            @if($payment->transaction && $canOpenLedger)
                <a class="dropdown-item" href="{{ route('admin.transaction.show', $payment->transaction->trx_id) }}">
                    {{ __('Open debit transaction') }}
                </a>
            @endif
            @if($canAct && $isOpen && $row['claim'] !== 'mine')
                <form action="{{ route('admin.bill-payment.claim', $payment) }}"
                      method="POST"
                      class="bpa-menu-form"
                      @if($row['claim'] === 'other') data-bpa-confirm="{{ __('Claimed by :name :ago. Take it over?', ['name' => $row['claimHolder'], 'ago' => $row['claimAgo']]) }}" @endif>
                    @csrf
                    @if($row['claim'] === 'other')
                        <input type="hidden" name="take_over" value="1">
                    @endif
                    <button type="submit" class="dropdown-item">
                        {{ $row['claim'] === 'other' ? __('Take over') : __('Claim') }}
                    </button>
                </form>
            @endif
            @if($canAct && ($row['canMarkFailed'] || $canConfirmNotPaid))
                <div class="dropdown-divider"></div>
            @endif
            @if($canAct && $row['canMarkFailed'])
                <button type="button"
                        class="dropdown-item is-danger"
                        data-bpa-open="mark-failed"
                        @include('backend.bill_payment.payments._trigger', ['payment' => $payment])>
                    {{ __('Mark failed and refund') }}
                </button>
            @endif
            @if($canConfirmNotPaid)
                <button type="button"
                        class="dropdown-item is-danger"
                        data-bpa-open="confirm-not-paid"
                        @include('backend.bill_payment.payments._trigger', ['payment' => $payment])>
                    {{ __('Confirm not charged and refund…') }}
                </button>
            @endif
        </x-slot:menu>
    </x-page-header>
@endsection

@section('sub_content')
    @php
        $isOpen        = ! ($payment->status?->isTerminal() ?? false);
        $canOpenLedger = auth('admin')->user()?->can('transaction-list') ?? false;
        $billDecimals  = \App\Support\Money::decimalsIfKnown((string) $payment->bill_currency) ?? 8;
        $owner         = $payment->user;
        $tokenLabel    = trim((string) ($payment->biller?->token_label ?? '')) ?: __('Token');
        $fulfilment    = $payment->fulfilment === \App\Enums\BillPayment\BillPaymentFulfilment::Operator
            ? __('Paid by an operator')
            : __('Paid by the provider');
    @endphp

    @include('backend.bill_payment.payments._errors')

    @if($contradiction !== null)
        <div class="bpa-notice bpa-notice--danger" role="alert" data-bpa-contradiction>
            <span class="bpa-notice__icon" aria-hidden="true"><x-icon name="warning" width="18" height="18"/></span>
            <p class="bpa-notice__copy">
                <strong>{{ __('The provider contradicts how this payment was resolved.') }}</strong>
                <span>{{ $contradiction['note'] }}</span>
                @if($contradiction['at'] !== null)
                    <span class="bpa-muted d-block">{{ __('Flagged :time.', ['time' => $contradiction['at']->format('d M Y, H:i')]) }}</span>
                @endif
            </p>
        </div>
    @endif

    @if($ledgerAnomaly !== null)
        <div class="bpa-notice bpa-notice--danger" role="alert">
            <span class="bpa-notice__icon" aria-hidden="true"><x-icon name="warning" width="18" height="18"/></span>
            <p class="bpa-notice__copy">
                <strong>{{ __('This payment\'s ledger entry was changed outside Bill payments. It needs a developer to reconcile it.') }}</strong>
                <span>{{ __('The debit transaction reads ":status". Neither mark paid nor mark failed will move money until it is reconciled.', ['status' => $ledgerAnomaly]) }}</span>
            </p>
        </div>
    @elseif($isOpen && $payment->dispatchState() === \App\Enums\BillPayment\BillPaymentDispatchState::NeedsReview)
        <div class="bpa-notice" role="status">
            <span class="bpa-notice__icon" aria-hidden="true"><x-icon name="info" width="18" height="18"/></span>
            <p class="bpa-notice__copy">
                <strong>{{ __('This payment needs review.') }}</strong>
                <span>{{ $reviewNote ?? __('The payment needs a person to check it before it is resolved.') }}</span>
            </p>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="bpa-stack">
                <section class="card border-0 bpa-card" aria-labelledby="bpa-payment-title">
                    <div class="card-body">
                        <div class="bpa-section-head">
                            <h2 class="bpa-section-head__title" id="bpa-payment-title">{{ __('Payment') }}</h2>
                        </div>

                        <dl class="bpa-facts">
                            <div class="bpa-facts__item">
                                <dt>{{ $payment->customer_number_label }}</dt>
                                <dd>
                                    <span class="bpa-copyable">
                                        <span class="bpa-mono" dir="ltr">{{ $payment->customer_number }}</span>
                                        <button type="button" class="bpa-copy" data-bpa-copy="{{ $payment->customer_number }}" aria-label="{{ __('Copy the customer number') }}" title="{{ __('Copy') }}">
                                            <x-icon name="clipboard" width="14" height="14"/>
                                        </button>
                                    </span>
                                </dd>
                            </div>
                            <div class="bpa-facts__item">
                                <dt>{{ __('Amount to pay at the biller') }}</dt>
                                <dd>
                                    <span class="bpa-copyable">
                                        <span dir="ltr">{{ money($payment->bill_amount, $payment->bill_currency, false) }} {{ $payment->bill_currency }}</span>
                                        <button type="button" class="bpa-copy" data-bpa-copy="{{ \App\Support\DecimalMoney::normalize((string) $payment->bill_amount, $billDecimals) }}" aria-label="{{ __('Copy the amount') }}" title="{{ __('Copy') }}">
                                            <x-icon name="clipboard" width="14" height="14"/>
                                        </button>
                                    </span>
                                </dd>
                            </div>
                            <div class="bpa-facts__item">
                                <dt>{{ __('Package') }}</dt>
                                <dd>{{ $payment->item_name ?: '—' }}</dd>
                            </div>
                            <div class="bpa-facts__item">
                                <dt>{{ __('Stage') }}</dt>
                                <dd>{{ $payment->dispatchState()->label() }}</dd>
                            </div>
                            <div class="bpa-facts__item">
                                <dt>{{ __('Receipt reference') }}</dt>
                                <dd class="bpa-mono" dir="ltr">{{ $payment->receipt_reference ?: '—' }}</dd>
                            </div>
                            <div class="bpa-facts__item">
                                <dt>{{ __('Provider') }}</dt>
                                <dd>
                                    {{ $payment->provider?->name ?? $payment->provider_code }}
                                    <span class="bpa-muted d-block">{{ $fulfilment }}</span>
                                </dd>
                            </div>
                            <div class="bpa-facts__item">
                                <dt>{{ __('Provider request ID') }}</dt>
                                <dd class="bpa-mono bpa-break" dir="ltr">{{ $payment->provider_request_id }}</dd>
                            </div>
                            <div class="bpa-facts__item">
                                <dt>{{ __('Provider transaction ID') }}</dt>
                                <dd class="bpa-mono bpa-break" dir="ltr">{{ $payment->provider_transaction_id ?: '—' }}</dd>
                            </div>
                            <div class="bpa-facts__item">
                                <dt>{{ __('Submitted') }}</dt>
                                <dd>{{ $payment->created_at?->format('d M Y, H:i') }} <span class="bpa-muted">· {{ $payment->channel === 'api' ? __('App') : __('Web') }}</span></dd>
                            </div>
                            <div class="bpa-facts__item">
                                <dt>{{ __('Processed') }}</dt>
                                <dd>{{ $payment->processed_at?->format('d M Y, H:i') ?? '—' }}</dd>
                            </div>
                            @if($payment->failure_reason)
                                <div class="bpa-facts__item bpa-facts__item--wide">
                                    <dt>{{ __('Reason shown to the customer') }}</dt>
                                    <dd>{{ $payment->failure_reason }}</dd>
                                </div>
                            @endif
                            @if($payment->admin_note)
                                <div class="bpa-facts__item bpa-facts__item--wide">
                                    <dt>{{ __('Internal note') }}</dt>
                                    <dd class="bpa-prewrap">{{ $payment->admin_note }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if($canAct && $token !== null)
                            <div class="bpa-token">
                                <span class="bpa-token__label">{{ $tokenLabel }}</span>
                                <span class="bpa-copyable">
                                    <span class="bpa-token__value bpa-mono" dir="ltr">{{ $token }}</span>
                                    <button type="button" class="bpa-copy" data-bpa-copy="{{ $token }}" aria-label="{{ __('Copy the :label', ['label' => $tokenLabel]) }}" title="{{ __('Copy') }}">
                                        <x-icon name="clipboard" width="14" height="14"/>
                                    </button>
                                </span>
                                <span class="bpa-muted">{{ __('Stored encrypted. Shown only to the customer and to admins who can act on bill payments.') }}</span>
                            </div>
                        @endif
                    </div>
                </section>

                <section class="card border-0 bpa-card" aria-labelledby="bpa-timeline-title">
                    <div class="card-body">
                        <div class="bpa-section-head">
                            <h2 class="bpa-section-head__title" id="bpa-timeline-title">{{ __('Timeline') }}</h2>
                        </div>

                        @if($timeline === [])
                            <p class="bpa-muted mb-0">{{ __('No events recorded.') }}</p>
                        @else
                            <ol class="bpa-timeline">
                                @foreach($timeline as $event)
                                    <li class="bpa-timeline__item bpa-timeline__item--{{ $event['actorType'] }}">
                                        <span class="bpa-timeline__dot" aria-hidden="true"></span>
                                        <div class="bpa-timeline__body">
                                            <div class="bpa-timeline__head">
                                                <strong>{{ $event['actor'] }}</strong>
                                                <time class="bpa-muted" datetime="{{ $event['at']?->toIso8601String() }}">{{ $event['at']?->format('d M Y, H:i:s') }}</time>
                                            </div>
                                            <span class="bpa-timeline__move">
                                                @if($event['from'] !== null && $event['from'] !== $event['to'])
                                                    {{ $event['from'] }} <span aria-hidden="true">→</span><span class="visually-hidden">{{ __('to') }}</span> {{ $event['to'] }}
                                                @else
                                                    {{ $event['to'] }}
                                                @endif
                                            </span>
                                            @if($event['note'])
                                                <p class="bpa-timeline__note">{{ $event['note'] }}</p>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>
                </section>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="bpa-stack">
                @if($providerChecks !== null)
                    {{--
                        How the reconcile run has been asking the provider
                        about this payment, and the admin's own check. The
                        button runs the same check now; what it may change is
                        decided by the service, never by this page.
                    --}}
                    <section class="card border-0 bpa-card" aria-labelledby="bpa-provider-checks-title" data-bpa-provider-checks>
                        <div class="card-body">
                            <div class="bpa-section-head">
                                <h2 class="bpa-section-head__title" id="bpa-provider-checks-title">{{ __('Provider checks') }}</h2>
                            </div>

                            <dl class="bpa-facts bpa-facts--single">
                                <div class="bpa-facts__item">
                                    <dt>{{ __('Checks so far') }}</dt>
                                    <dd>{{ $providerChecks['attempts'] }}</dd>
                                </div>
                                <div class="bpa-facts__item">
                                    <dt>{{ __('Last check') }}</dt>
                                    <dd>{{ $providerChecks['checkedAt']?->format('d M Y, H:i') ?? '—' }}</dd>
                                </div>
                                <div class="bpa-facts__item">
                                    <dt>{{ __('Next check') }}</dt>
                                    <dd>{{ $providerChecks['nextAt']?->format('d M Y, H:i') ?? '—' }}</dd>
                                </div>
                                <div class="bpa-facts__item">
                                    <dt>{{ __('Provider final answer due') }}</dt>
                                    <dd>{{ $providerChecks['finalAt']?->format('d M Y, H:i') ?? '—' }}</dd>
                                </div>
                                @if($providerChecks['reviewReason'] !== null)
                                    <div class="bpa-facts__item">
                                        <dt>{{ __('Why it is in review') }}</dt>
                                        <dd>{{ $providerChecks['reviewReason'] }}</dd>
                                    </div>
                                @endif
                                <div class="bpa-facts__item">
                                    <dt>{{ __('Last admin check') }}</dt>
                                    <dd data-bpa-last-provider-check>
                                        @if($providerChecks['last'] !== null)
                                            {{ $providerChecks['last']['outcome'] }}
                                            <span class="bpa-muted d-block">
                                                {{ $providerChecks['last']['at']?->format('d M Y, H:i') ?? '—' }}@if($providerChecks['last']['by'] !== null) · {{ $providerChecks['last']['by'] }}@endif
                                            </span>
                                        @else
                                            <span class="bpa-muted">—</span>
                                        @endif
                                    </dd>
                                </div>
                            </dl>

                            @if($canCheck)
                                <form action="{{ route('admin.bill-payment.check-provider', $payment) }}" method="POST" class="mt-3" data-bpa-action-form>
                                    @csrf
                                    <button type="submit" class="btn btn-outline-primary btn-sm w-100" data-bpa-check-provider>
                                        <i class="fa-solid fa-rotate" aria-hidden="true"></i>
                                        <span>{{ __('Check with provider') }}</span>
                                    </button>
                                </form>
                            @endif

                            @if($canAct && $isOpen && $payment->dispatchState() === \App\Enums\BillPayment\BillPaymentDispatchState::NeedsReview && ! $canConfirmNotPaid)
                                <p class="bpa-muted small mt-2 mb-0">{{ __('To refund it as not charged, check with the provider first. The option appears in the menu for :minutes minutes after a check that gives no final answer.', ['minutes' => \App\Services\BillPayment\BillPaymentService::CONFIRM_NOT_PAID_CHECK_MINUTES]) }}</p>
                            @endif
                        </div>
                    </section>
                @endif

                <section class="card border-0 bpa-card" aria-labelledby="bpa-customer-title">
                    <div class="card-body">
                        <div class="bpa-section-head">
                            <h2 class="bpa-section-head__title" id="bpa-customer-title">{{ __('Customer') }}</h2>
                        </div>

                        @if($owner)
                            <dl class="bpa-facts bpa-facts--single">
                                <div class="bpa-facts__item">
                                    <dt>{{ __('Name') }}</dt>
                                    <dd>{{ $owner->name }}</dd>
                                </div>
                                <div class="bpa-facts__item">
                                    <dt>{{ __('Email') }}</dt>
                                    <dd class="bpa-break">{{ $owner->email }}</dd>
                                </div>
                                <div class="bpa-facts__item">
                                    <dt>{{ __('Wallet') }}</dt>
                                    <dd>
                                        {{ $payment->wallet?->currency?->code ?? $payment->currency }}
                                        <span class="bpa-muted d-block bpa-mono bpa-break" dir="ltr">{{ $payment->wallet?->uuid ?? __('Wallet unavailable') }}</span>
                                    </dd>
                                </div>
                            </dl>

                            <a href="{{ route('admin.user.manage', $owner->username) }}" class="btn btn-outline-primary btn-sm w-100 mt-3">
                                {{ __('Open user') }}
                            </a>
                        @else
                            <p class="bpa-muted mb-0">{{ __('The customer\'s account has been removed.') }}</p>
                        @endif
                    </div>
                </section>

                <section class="card border-0 bpa-card" aria-labelledby="bpa-ledger-title">
                    <div class="card-body">
                        <div class="bpa-section-head">
                            <h2 class="bpa-section-head__title" id="bpa-ledger-title">{{ __('Ledger') }}</h2>
                        </div>

                        @php
                            /* The refund row is listed only once there is one. */
                            $ledgerRows = array_values(array_filter([
                                ['label' => __('Wallet debit'), 'transaction' => $payment->transaction, 'always' => true],
                                ['label' => __('Refund'), 'transaction' => $payment->refundTransaction, 'always' => false],
                            ], fn (array $ledgerRow): bool => $ledgerRow['always'] || $ledgerRow['transaction'] !== null));
                        @endphp

                        <dl class="bpa-facts bpa-facts--single">
                            @foreach($ledgerRows as $ledgerRow)
                                <div class="bpa-facts__item">
                                    <dt>{{ $ledgerRow['label'] }}</dt>
                                    <dd>
                                        @if($ledgerRow['transaction'])
                                            @if($canOpenLedger)
                                                <a href="{{ route('admin.transaction.show', $ledgerRow['transaction']->trx_id) }}" class="bpa-mono" dir="ltr">{{ $ledgerRow['transaction']->trx_id }}</a>
                                            @else
                                                <span class="bpa-mono" dir="ltr">{{ $ledgerRow['transaction']->trx_id }}</span>
                                            @endif
                                            <span class="bpa-pill bpa-pill--{{ $ledgerRow['transaction']->status?->color() ?? 'secondary' }}">{{ $ledgerRow['transaction']->status?->label() }}</span>
                                        @else
                                            <span class="bpa-muted">{{ __('No linked transaction') }}</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </section>

                <section class="card border-0 bpa-card" aria-labelledby="bpa-handling-title">
                    <div class="card-body">
                        <div class="bpa-section-head">
                            <h2 class="bpa-section-head__title" id="bpa-handling-title">{{ __('Handling') }}</h2>
                        </div>

                        <dl class="bpa-facts bpa-facts--single">
                            <div class="bpa-facts__item">
                                <dt>{{ __('Claimed by') }}</dt>
                                <dd>@include('backend.bill_payment.payments._claim', ['row' => $row])</dd>
                            </div>
                            <div class="bpa-facts__item">
                                <dt>{{ __('Resolved by') }}</dt>
                                <dd>
                                    @if($payment->resolvedBy)
                                        {{ $payment->resolvedBy->name }}
                                    @elseif(! $isOpen)
                                        {{ __('The provider') }}
                                    @else
                                        <span class="bpa-muted">—</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </div>
                </section>
            </div>
        </div>
    </div>

    @if($canAct)
        @include('backend.bill_payment.payments._mark_paid_modal')
        @include('backend.bill_payment.payments._mark_failed_modal')
    @endif
    @if($canConfirmNotPaid)
        @include('backend.bill_payment.payments._confirm_not_paid_modal')
    @endif
@endsection
