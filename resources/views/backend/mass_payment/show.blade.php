@extends('backend.mass_payment.layout')

@section('title', __('Mass payment :reference', ['reference' => $batch->reference]))

{{--
    The detail page supplies its own header: the MP reference as the title,
    the batch status as the one pill, and the money as the strip. Its one
    primary action is Resume; cancelling the remaining rows refunds them and
    closing after review settles the batch for good, so both live in the
    menu after the divider.
--}}
@section('sub_header')
    @php
        $statusTone = match ($status->color()) {
            'success' => 'success',
            'warning' => 'warning',
            'danger'  => 'danger',
            'primary', 'info' => 'brand',
            default   => 'neutral',
        };
        $owner    = $batch->user;
        $currency = $figures['currency'];
    @endphp

    <x-page-header
        :title="$batch->reference"
        :subtitle="$isPayout
            ? __(':merchant paying :count external accounts through :method from their :currency wallet', [
                'merchant' => $owner?->name ?? __('Removed user'),
                'count'    => number_format((int) $batch->row_count),
                'method'   => $payout['method'],
                'currency' => $currency,
            ])
            : __(':merchant paying :count rows from their :currency wallet', [
                'merchant' => $owner?->name ?? __('Removed user'),
                'count'    => number_format((int) $batch->row_count),
                'currency' => $currency,
            ])"
        icon="people"
        :breadcrumbs="[
            ['label' => __('Money movement')],
            ['label' => __('Mass payments'), 'url' => route('admin.mass-payment.index')],
            ['label' => $batch->reference],
        ]"
        :back="route('admin.mass-payment.index')"
        :back-label="__('Back to mass payments')"
        :status="['tone' => $statusTone, 'label' => $status->label()]"
        :meta="array_values(array_filter([
            $batch->channel === 'api' ? __('App') : __('Web'),
            match ($batch->source) { 'csv' => __('CSV upload'), 'schedule' => __('Rung up by a schedule'), default => __('Entered by hand') },
            $batch->created_at?->format('d M Y, H:i'),
        ]))"
        :stats="[
            ['label' => __('Total reserved'), 'value' => money($figures['total'], $currency, false).' '.$currency, 'note' => __('incl. :fee fees', ['fee' => money($figures['fee'], $currency, false)]), 'tone' => 'brand', 'icon' => 'wallet-payment'],
            ['label' => __('Paid'), 'value' => money($figures['paid'], $currency, false).' '.$currency, 'note' => __(':count rows', ['count' => number_format($counts['paid'])]), 'tone' => 'success', 'icon' => 'send-money'],
            ['label' => __('Released'), 'value' => money($figures['released'], $currency, false).' '.$currency, 'note' => __(':count rows', ['count' => number_format($counts['failed'])]), 'tone' => 'neutral', 'icon' => 'refund'],
            ['label' => __('Still reserved'), 'value' => money($figures['held'], $currency, false).' '.$currency, 'note' => __(':count rows', ['count' => number_format($counts['pending'])]), 'tone' => $counts['pending'] > 0 ? 'warning' : 'neutral', 'icon' => 'clock'],
        ]"
    >
        <x-slot:actions>
            <a href="{{ route('admin.mass-payment.report', $batch) }}" class="ph-btn ph-btn--secondary" data-mpa-report>
                <x-icon name="file" width="14" height="14"/>
                {{ __('Report') }}
            </a>
            @if($canResume)
                <form action="{{ route('admin.mass-payment.resume', $batch) }}" method="POST" class="mpa-inline-form" data-mpa-action-form>
                    @csrf
                    <button type="submit" class="ph-btn ph-btn--primary" data-mpa-resume>
                        <x-icon name="play" width="14" height="14"/>
                        {{ __('Resume') }}
                    </button>
                </form>
            @elseif($canApprove)
                <form action="{{ route('admin.mass-payout.approve', $batch) }}" method="POST" class="mpa-inline-form" data-mpa-action-form>
                    @csrf
                    <button type="submit" class="ph-btn ph-btn--primary" data-mpa-approve>
                        <x-icon name="check" width="14" height="14"/>
                        {{ __('Approve and send') }}
                    </button>
                </form>
            @endif
        </x-slot:actions>

        <x-slot:menu>
            @if($owner)
                <a class="dropdown-item" href="{{ route('admin.user.manage', $owner->username) }}">{{ __('Open merchant') }}</a>
            @endif
            @if($canCancel || $canClose || $canApprove)
                <div class="dropdown-divider"></div>
            @endif
            @if($canApprove)
                <button type="button"
                        class="dropdown-item is-danger"
                        data-coreui-toggle="modal"
                        data-coreui-target="#mpa-reject-modal"
                        data-mpa-open="reject">
                    {{ __('Reject and refund…') }}
                </button>
            @endif
            @if($canCancel)
                <button type="button"
                        class="dropdown-item is-danger"
                        data-coreui-toggle="modal"
                        data-coreui-target="#mpa-cancel-modal"
                        data-mpa-open="cancel-remaining">
                    {{ __('Cancel remaining rows and refund…') }}
                </button>
            @endif
            @if($canClose)
                <button type="button"
                        class="dropdown-item is-danger"
                        data-coreui-toggle="modal"
                        data-coreui-target="#mpa-close-modal"
                        data-mpa-open="close-after-review">
                    {{ __('Close after review…') }}
                </button>
            @endif
        </x-slot:menu>
    </x-page-header>
@endsection

@section('sub_content')
    @php
        $owner    = $batch->user;
        $currency = $figures['currency'];
    @endphp

    @if($errors->any())
        <div class="mpa-notice mpa-notice--danger" role="alert">
            <span class="mpa-notice__icon" aria-hidden="true"><x-icon name="warning" width="18" height="18"/></span>
            <div class="mpa-notice__copy">
                <strong>{{ __('The mass payment was not updated.') }}</strong>
                <ul class="mpa-notice__list">
                    @foreach($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if($anomaly !== null || $flagged > 0)
        <div class="mpa-notice mpa-notice--danger" role="alert" data-mpa-anomaly>
            <span class="mpa-notice__icon" aria-hidden="true"><x-icon name="warning" width="18" height="18"/></span>
            <p class="mpa-notice__copy">
                <strong>{{ __('The ledger disagrees with this mass payment. Nothing is paid or refunded until it is reconciled.') }}</strong>
                @if($anomaly !== null)
                    <span>{{ $anomaly }}</span>
                @endif
                @if($flagged > 0)
                    <span>{{ trans_choice('{1} One line is flagged for review.|[2,*] :count lines are flagged for review.', $flagged, ['count' => $flagged]) }}</span>
                @endif
                <span>{{ __('Reconcile it with the balance tools, then use Close after review in the ⋯ menu. Closing moves no money.') }}</span>
            </p>
        </div>
    @elseif($holdNote !== null)
        <div class="mpa-notice" role="status" data-mpa-held>
            <span class="mpa-notice__icon" aria-hidden="true"><x-icon name="info" width="18" height="18"/></span>
            <p class="mpa-notice__copy">
                <strong>{{ __('This mass payment is on hold.') }}</strong>
                <span>{{ $holdNote }}</span>
            </p>
        </div>
    @endif

    @if($batch->stop_requested_at !== null && ! $status->isTerminal())
        <div class="mpa-notice" role="status">
            <span class="mpa-notice__icon" aria-hidden="true"><x-icon name="info" width="18" height="18"/></span>
            <p class="mpa-notice__copy">
                <strong>{{ __('Stopping.') }}</strong>
                <span>{{ __('The rows not yet paid are being refunded to the sender\'s wallet, one by one.') }}</span>
            </p>
        </div>
    @endif

    @if($status === \App\Enums\MassPayment\MassPaymentBatchStatus::AwaitingApproval && $batch->stop_requested_at === null)
        <div class="mpa-notice" role="status" data-mpa-awaiting-approval>
            <span class="mpa-notice__icon" aria-hidden="true"><x-icon name="info" width="18" height="18"/></span>
            <p class="mpa-notice__copy">
                <strong>{{ __('Waiting for approval.') }}</strong>
                <span>{{ __('The merchant\'s money is reserved and nothing has been sent. Approve to send every payout, or reject to refund them all.') }}</span>
                @unless($canApprove)
                    <span>{{ __('Approving or rejecting needs the Approve mass payouts permission.') }}</span>
                @endunless
            </p>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="mpa-stack">
                <section class="card border-0 mpa-card" aria-labelledby="mpa-rows-title">
                    <div class="card-body">
                        <div class="mpa-section-head">
                            <h2 class="mpa-section-head__title" id="mpa-rows-title">{{ __('Rows') }}</h2>
                            <form action="{{ route('admin.mass-payment.show', $batch) }}" method="GET" class="mpa-row-filter">
                                <label class="visually-hidden" for="mpa-row-status">{{ __('Row status') }}</label>
                                <select id="mpa-row-status" name="row_status" class="form-select form-select-sm" data-mpa-autosubmit>
                                    <option value="">{{ __('All rows (:count)', ['count' => number_format($counts['rows'])]) }}</option>
                                    @foreach($rowStatuses as $rowStatus)
                                        @if($counts[$rowStatus->value] > 0 || $rowFilter === $rowStatus)
                                            <option value="{{ $rowStatus->value }}" @selected($rowFilter === $rowStatus)>{{ $rowStatus->label() }} ({{ number_format($counts[$rowStatus->value]) }})</option>
                                        @endif
                                    @endforeach
                                </select>
                                <noscript><button type="submit" class="btn btn-outline-secondary btn-sm">{{ __('Filter') }}</button></noscript>
                            </form>
                        </div>

                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <caption class="visually-hidden">{{ __('Rows of :reference', ['reference' => $batch->reference]) }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col">{{ __('Recipient') }}</th>
                                        <th scope="col">{{ __('Amount') }}</th>
                                        <th scope="col">{{ __('Status') }}</th>
                                        <th scope="col">{{ __('Ledger') }}</th>
                                        @if($canResolve)
                                            <th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($rows as $row)
                                        @php
                                            $rowStatusEnum = $row->status();
                                            $ledgerLinks   = array_values(array_filter([
                                                ['label' => __('Debit'), 'transaction' => $row->debitTransaction],
                                                ['label' => __('Credit'), 'transaction' => $row->creditTransaction],
                                                ['label' => __('Refund'), 'transaction' => $row->refundTransaction],
                                            ], fn (array $link): bool => $link['transaction'] !== null));
                                            $rowAnomaly = is_array($row->metadata) && is_string($row->metadata['ledger_anomaly'] ?? null)
                                                ? \App\Http\Controllers\Backend\MassPayment\MassPaymentController::anomalyLabel($row->metadata['ledger_anomaly'])
                                                : null;
                                            $dispatch     = $row->dispatchState();
                                            $reviewReason = $dispatch === \App\Enums\MassPayment\MassPaymentDispatchState::NeedsReview
                                                ? \App\Http\Controllers\Backend\MassPayment\MassPaymentController::payoutReviewLabel(is_array($row->metadata) ? ($row->metadata['payout_review'] ?? null) : null)
                                                : null;
                                            $resolvable = $canResolve && $rowStatusEnum === \App\Enums\MassPayment\MassPaymentRowStatus::Pending && ! $row->needs_review
                                                && ($dispatch === \App\Enums\MassPayment\MassPaymentDispatchState::AwaitingOperator
                                                    || ($dispatch === \App\Enums\MassPayment\MassPaymentDispatchState::NeedsReview && $canResolveReview));
                                            $checkable = $canResolve && $rowStatusEnum === \App\Enums\MassPayment\MassPaymentRowStatus::Pending && ! $row->needs_review && $batch->payout_driver !== 'manual'
                                                && in_array($dispatch, [\App\Enums\MassPayment\MassPaymentDispatchState::AwaitingProvider, \App\Enums\MassPayment\MassPaymentDispatchState::Submitting, \App\Enums\MassPayment\MassPaymentDispatchState::NeedsReview], true);
                                        @endphp
                                        <tr data-mpa-line="{{ $row->line_no }}" @class(['mpa-row--flagged' => $row->needs_review && $rowStatusEnum === \App\Enums\MassPayment\MassPaymentRowStatus::Pending])>
                                            <td class="mpa-muted">{{ $row->line_no }}</td>
                                            <td>
                                                <div class="mpa-person">
                                                    <span class="mpa-person__name mpa-break">{{ $row->recipient_label ?: '—' }}</span>
                                                    @unless($isPayout)
                                                        <span class="mpa-muted mpa-break" dir="ltr">{{ $row->recipient_input }}</span>
                                                    @endunless
                                                    @if($isPayout && $row->provider_reference)
                                                        <span class="mpa-muted mpa-break" dir="ltr">{{ __('Provider ref: :reference', ['reference' => $row->provider_reference]) }}</span>
                                                    @endif
                                                    @if($row->client_reference)
                                                        <span class="mpa-muted mpa-break">{{ __('Ref: :reference', ['reference' => $row->client_reference]) }}</span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="text-nowrap">
                                                @if($row->net_amount !== null)
                                                    <strong dir="ltr">{{ money($row->net_amount, $currency, false) }} {{ $currency }}</strong>
                                                    @if($row->wallet_fee !== null && \App\Support\DecimalMoney::compare((string) $row->wallet_fee, '0') > 0)
                                                        <span class="mpa-muted d-block" dir="ltr">{{ __('+ :fee fee', ['fee' => money($row->wallet_fee, $currency, false)]) }}</span>
                                                    @endif
                                                @else
                                                    <span class="mpa-muted" dir="ltr">{{ $row->amount_input }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="mpa-pill mpa-pill--{{ $rowStatusEnum?->color() ?? 'secondary' }}">{{ $rowStatusEnum?->label() ?? $row->status }}</span>
                                                @if($dispatch !== null && ! $dispatch->isTerminal())
                                                    <span class="mpa-pill mpa-pill--{{ $dispatch->color() }}" data-mpa-dispatch="{{ $dispatch->value }}">{{ $dispatch->label() }}</span>
                                                @endif
                                                @if($reviewReason !== null)
                                                    <span class="mpa-hint">{{ $reviewReason }}</span>
                                                @endif
                                                @if($rowAnomaly !== null && $rowStatusEnum === \App\Enums\MassPayment\MassPaymentRowStatus::Pending && $row->needs_review)
                                                    <span class="mpa-hint">{{ $rowAnomaly }}</span>
                                                @elseif($row->issue())
                                                    <span class="mpa-muted d-block">{{ $row->issueMessage() }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                @forelse($ledgerLinks as $link)
                                                    <span class="mpa-ledger">
                                                        <span class="mpa-muted">{{ $link['label'] }}</span>
                                                        @if($canOpenLedger)
                                                            <a href="{{ route('admin.transaction.show', $link['transaction']->trx_id) }}" class="mpa-ref" dir="ltr">{{ $link['transaction']->trx_id }}</a>
                                                        @else
                                                            <span class="mpa-mono" dir="ltr">{{ $link['transaction']->trx_id }}</span>
                                                        @endif
                                                    </span>
                                                @empty
                                                    <span class="mpa-muted">—</span>
                                                @endforelse
                                            </td>
                                            @if($canResolve)
                                                <td class="text-end">
                                                    <div class="mpa-row-actions">
                                                        @if($checkable)
                                                            <form action="{{ route('admin.mass-payout.row.check', [$batch, $row->id]) }}" method="POST" class="mpa-inline-form" data-mpa-action-form>
                                                                @csrf
                                                                <button type="submit" class="btn btn-sm btn-outline-secondary" data-mpa-row-check="{{ $row->line_no }}">{{ __('Ask provider') }}</button>
                                                            </form>
                                                        @endif
                                                        @if($resolvable)
                                                            <button type="button"
                                                                    class="btn btn-sm btn-outline-success"
                                                                    data-mpa-row-action="mark-paid"
                                                                    data-mpa-url="{{ route('admin.mass-payout.row.mark-paid', [$batch, $row->id]) }}"
                                                                    data-mpa-line="{{ $row->line_no }}">{{ __('Paid…') }}</button>
                                                            <button type="button"
                                                                    class="btn btn-sm btn-outline-danger"
                                                                    data-mpa-row-action="mark-not-paid"
                                                                    data-mpa-url="{{ route('admin.mass-payout.row.mark-not-paid', [$batch, $row->id]) }}"
                                                                    data-mpa-line="{{ $row->line_no }}">{{ __('Not paid…') }}</button>
                                                        @endif
                                                        @if($row->payout_account_hash !== null)
                                                            <button type="button"
                                                                    class="btn btn-sm btn-link"
                                                                    data-mpa-reveal="{{ route('admin.mass-payout.row.reveal', [$batch, $row->id]) }}"
                                                                    data-mpa-reveal-error="{{ __('The account could not be shown. Reload the page and try again.') }}">{{ __('Account') }}</button>
                                                        @endif
                                                    </div>
                                                </td>
                                            @endif
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ $canResolve ? 6 : 5 }}">
                                                <x-admin-not-found
                                                    :title="__('No rows to show')"
                                                    :message="$rowFilter !== null ? __('No row of this batch has that status.') : __('This batch has no rows.')"
                                                    icon="fa-list"
                                                />
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        @if($rows->hasPages())
                            <div class="mt-3">{{ $rows->links() }}</div>
                        @endif
                    </div>
                </section>

                <section class="card border-0 mpa-card" aria-labelledby="mpa-timeline-title">
                    <div class="card-body">
                        <div class="mpa-section-head">
                            <h2 class="mpa-section-head__title" id="mpa-timeline-title">{{ __('Timeline') }}</h2>
                        </div>

                        @if($timeline === [])
                            <p class="mpa-muted mb-0">{{ __('No events recorded.') }}</p>
                        @else
                            <ol class="mpa-timeline">
                                @foreach($timeline as $event)
                                    <li class="mpa-timeline__item mpa-timeline__item--{{ $event['actorType'] }}">
                                        <span class="mpa-timeline__dot" aria-hidden="true"></span>
                                        <div class="mpa-timeline__body">
                                            <div class="mpa-timeline__head">
                                                <strong>{{ $event['label'] }}</strong>
                                                <span class="mpa-muted">{{ $event['actor'] }}</span>
                                                <time class="mpa-muted" datetime="{{ $event['at']?->toIso8601String() }}">{{ $event['at']?->format('d M Y, H:i:s') }}</time>
                                            </div>
                                            @if($event['from'] !== null && $event['to'] !== null && $event['from'] !== $event['to'])
                                                <span class="mpa-timeline__move">
                                                    {{ $event['from'] }} <span aria-hidden="true">→</span><span class="visually-hidden">{{ __('to') }}</span> {{ $event['to'] }}
                                                </span>
                                            @endif
                                            @if($event['detail'])
                                                <p class="mpa-timeline__note">{{ $event['detail'] }}</p>
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
            <div class="mpa-stack">
                <section class="card border-0 mpa-card" aria-labelledby="mpa-merchant-title">
                    <div class="card-body">
                        <div class="mpa-section-head">
                            <h2 class="mpa-section-head__title" id="mpa-merchant-title">{{ __('Merchant') }}</h2>
                        </div>

                        @if($owner)
                            <dl class="mpa-facts">
                                <div class="mpa-facts__item">
                                    <dt>{{ __('Name') }}</dt>
                                    <dd>{{ $owner->name }}</dd>
                                </div>
                                <div class="mpa-facts__item">
                                    <dt>{{ __('Email') }}</dt>
                                    <dd class="mpa-break">{{ $owner->email }}</dd>
                                </div>
                                <div class="mpa-facts__item">
                                    <dt>{{ __('Source wallet') }}</dt>
                                    <dd>
                                        {{ $currency }}
                                        <span class="mpa-muted d-block mpa-mono mpa-break" dir="ltr">{{ $batch->wallet?->uuid ?? __('Wallet unavailable') }}</span>
                                    </dd>
                                </div>
                            </dl>
                        @else
                            <p class="mpa-muted mb-0">{{ __('The merchant\'s account has been removed.') }}</p>
                        @endif
                    </div>
                </section>

                <section class="card border-0 mpa-card" aria-labelledby="mpa-batch-title">
                    <div class="card-body">
                        <div class="mpa-section-head">
                            <h2 class="mpa-section-head__title" id="mpa-batch-title">{{ __('Batch') }}</h2>
                        </div>

                        <dl class="mpa-facts">
                            <div class="mpa-facts__item">
                                <dt>{{ __('Batch ID') }}</dt>
                                <dd class="mpa-mono mpa-break" dir="ltr">{{ $batch->uuid }}</dd>
                            </div>
                            @if($isPayout)
                                <div class="mpa-facts__item">
                                    <dt>{{ __('Payout method') }}</dt>
                                    <dd>
                                        {{ $payout['method'] }}
                                        <span class="mpa-muted d-block">{{ $payout['driver'] }}</span>
                                    </dd>
                                </div>
                                <div class="mpa-facts__item">
                                    <dt>{{ __('Fees') }}</dt>
                                    <dd>{{ $payout['feeMode'] }}</dd>
                                </div>
                                <div class="mpa-facts__item">
                                    <dt>{{ __('Approval') }}</dt>
                                    <dd>
                                        {{ $payout['approval'] }}
                                        @if($batch->approved_at)
                                            <span class="mpa-muted d-block">{{ $batch->approved_at->format('d M Y, H:i') }}</span>
                                        @endif
                                    </dd>
                                </div>
                            @endif
                            @if($batch->original_filename)
                                <div class="mpa-facts__item">
                                    <dt>{{ __('File') }}</dt>
                                    <dd class="mpa-break">{{ $batch->original_filename }}</dd>
                                </div>
                            @endif
                            <div class="mpa-facts__item">
                                <dt>{{ __('Confirmed') }}</dt>
                                <dd>{{ $batch->confirmed_at?->format('d M Y, H:i') ?? '—' }}</dd>
                            </div>
                            <div class="mpa-facts__item">
                                <dt>{{ __('Last progress') }}</dt>
                                <dd>{{ $batch->last_progress_at?->format('d M Y, H:i') ?? '—' }}</dd>
                            </div>
                            <div class="mpa-facts__item">
                                <dt>{{ __('Finished') }}</dt>
                                <dd>{{ $batch->completed_at?->format('d M Y, H:i') ?? '—' }}</dd>
                            </div>
                            <div class="mpa-facts__item">
                                <dt>{{ __('Reviewed by') }}</dt>
                                <dd>{{ $batch->reviewedBy?->name ?? '—' }}</dd>
                            </div>
                            @if($batch->admin_note)
                                <div class="mpa-facts__item">
                                    <dt>{{ __('Review note') }}</dt>
                                    <dd class="mpa-prewrap">{{ $batch->admin_note }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                </section>
            </div>
        </div>
    </div>

    @if($canCancel)
        @include('backend.mass_payment.partials._cancel_modal')
    @endif
    @if($canClose)
        @include('backend.mass_payment.partials._close_modal')
    @endif
    @if($canApprove)
        @include('backend.mass_payment.partials._reject_modal')
    @endif
    @if($canResolve)
        @include('backend.mass_payment.partials._payout_row_modals')
    @endif
@endsection
