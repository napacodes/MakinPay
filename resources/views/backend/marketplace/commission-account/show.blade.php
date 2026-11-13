@extends('backend.layouts.app')

@section('title', __('Commission Account Details'))

@push('styles')
    @php
        $p2pAdminCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/p2p-admin.css'));
    @endphp
    <link rel="stylesheet" href="{{ asset('backend/css/p2p-admin.css?v=' . $p2pAdminCssVersion) }}">
@endpush

@section('content')
    @php
        $headerStats = $account ? [
            ['label' => __('Wallets'), 'value' => number_format($summary['wallets']->count())],
            ['label' => __('Commission entries'), 'value' => number_format($summary['commission_count'])],
            ['label' => __('Refund entries'), 'value' => number_format($summary['refund_count'])],
            ['label' => __('Reconciliation'), 'value' => $summary['unreconciled_count'] === 0 ? __('Healthy') : __('Attention')],
        ] : [];
    @endphp

    <x-page-header
        :title="__('Commission Account Details')"
        :subtitle="__('A read-only view of platform commission balances, ledger activity, refunds, and wallet reconciliation.')"
        icon="fa-wallet"
        :stats="$headerStats"
        class="mb-3"
    >
        <x-slot:actions>
            <a href="{{ route('admin.marketplace.settings.edit') }}" class="btn btn-outline-secondary">
                <i class="fa-solid fa-gear me-1" aria-hidden="true"></i>@lang('Settings')
            </a>
            @if($account)
                <a href="{{ route('admin.marketplace.settings.commission-account.export') }}" class="btn btn-primary">
                    <i class="fa-solid fa-file-csv me-1" aria-hidden="true"></i>@lang('Export CSV')
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="p2p-refresh commission-report">
        @if(! $account)
            <section class="settings-panel commission-report__empty">
                <x-admin-not-found
                    :title="__('Protected commission account not configured')"
                    :message="__('Create the protected platform account before viewing balances and ledger activity.')"
                    icon="fa-wallet"
                    :action-url="route('admin.marketplace.settings.edit')"
                    :action-label="__('Open marketplace settings')"
                    action-icon="fa-gear"
                />
            </section>
        @else
            <section class="commission-report__identity" aria-label="{{ __('Protected account identity') }}">
                <span class="commission-report__identity-icon" aria-hidden="true">
                    <i class="fa-solid fa-wallet"></i>
                </span>
                <div class="commission-report__identity-copy">
                    <div class="commission-report__identity-title">
                        <strong>@lang('Marketplace Commission')</strong>
                        <span class="fb-pill fb-pill--success">
                            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>@lang('Protected')
                        </span>
                        <span class="fb-pill fb-pill--success">
                            <i class="fa-solid fa-eye" aria-hidden="true"></i>@lang('Read only')
                        </span>
                    </div>
                    <span>@lang('Platform-owned wallet identity') · @lang('Account ID') <b class="fb-mono">#{{ $account->id }}</b></span>
                </div>
                <div class="commission-report__identity-controls">
                    <span><i class="fa-solid fa-ban" aria-hidden="true"></i>@lang('No login')</span>
                    <span><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>@lang('No manual balance controls')</span>
                </div>
            </section>

            <section class="commission-report__metrics" aria-label="{{ __('Commission performance') }}">
                @foreach([
                    ['label' => __('Today'), 'icon' => 'fa-calendar-day', 'tone' => 'primary', 'rows' => $summary['today']],
                    ['label' => __('Last 30 days'), 'icon' => 'fa-calendar-days', 'tone' => 'info', 'rows' => $summary['last_30_days']],
                    ['label' => __('Lifetime net'), 'icon' => 'fa-chart-line', 'tone' => 'success', 'rows' => $summary['lifetime']],
                ] as $metric)
                    <article class="commission-metric">
                        <div class="commission-metric__head">
                            <span class="commission-metric__icon commission-metric__icon--{{ $metric['tone'] }}">
                                <i class="fa-solid {{ $metric['icon'] }}" aria-hidden="true"></i>
                            </span>
                            <span>{{ $metric['label'] }}</span>
                        </div>
                        <div class="commission-metric__amounts">
                            @forelse($metric['rows'] as $row)
                                <div>
                                    <strong>{{ money($row['net'], $row['currency'], false) }}</strong>
                                    <span>{{ $row['currency'] }}</span>
                                </div>
                            @empty
                                <span class="commission-report__muted">@lang('No activity')</span>
                            @endforelse
                        </div>
                    </article>
                @endforeach

                <article class="commission-metric">
                    <div class="commission-metric__head">
                        <span class="commission-metric__icon commission-metric__icon--warning">
                            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                        </span>
                        <span>@lang('Lifetime refunds')</span>
                    </div>
                    <div class="commission-metric__amounts">
                        @forelse($summary['lifetime'] as $row)
                            <div>
                                <strong>{{ money($row['refunded'], $row['currency'], false) }}</strong>
                                <span>{{ $row['currency'] }}</span>
                            </div>
                        @empty
                            <span class="commission-report__muted">@lang('No refunds')</span>
                        @endforelse
                    </div>
                </article>
            </section>

            <section class="settings-panel settings-panel--wide commission-report__panel">
                <div class="settings-panel__head">
                    <span class="settings-panel__icon settings-panel__icon--success">
                        <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
                    </span>
                    <div class="settings-panel__title">
                        <h2>@lang('Wallet balances & reconciliation')</h2>
                        <span>@lang('Current wallet balances are compared with the immutable marketplace commission ledger by currency.')</span>
                    </div>
                </div>

                @if($summary['wallets']->isNotEmpty())
                    <div class="table-responsive commission-report__table-wrap">
                        <table class="table commission-report__table mb-0">
                            <thead>
                            <tr>
                                <th>@lang('Currency')</th>
                                <th>@lang('Wallet balance')</th>
                                <th>@lang('Gross credited')</th>
                                <th>@lang('Refunded')</th>
                                <th>@lang('Ledger net')</th>
                                <th>@lang('Difference')</th>
                                <th class="text-end">@lang('Status')</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($summary['wallets'] as $wallet)
                                <tr>
                                    <td>
                                        <strong>{{ $wallet['currency'] }}</strong>
                                        <span class="commission-report__cell-meta">{{ $wallet['wallet_reference'] ?: __('Wallet missing') }}</span>
                                    </td>
                                    <td>{{ money($wallet['balance'], $wallet['currency'], false) }}</td>
                                    <td class="text-success">+{{ money($wallet['credited'], $wallet['currency'], false) }}</td>
                                    <td class="text-danger">-{{ money($wallet['refunded'], $wallet['currency'], false) }}</td>
                                    <td>{{ money($wallet['ledger_net'], $wallet['currency'], false) }}</td>
                                    <td class="{{ $wallet['is_reconciled'] ? 'text-success' : 'text-danger' }}">
                                        {{ money($wallet['difference'], $wallet['currency'], false) }}
                                    </td>
                                    <td class="text-end">
                                        <span class="fb-pill {{ $wallet['is_reconciled'] ? 'fb-pill--success' : 'fb-pill--danger' }}">
                                            <i class="fa-solid {{ $wallet['is_reconciled'] ? 'fa-circle-check' : 'fa-triangle-exclamation' }}" aria-hidden="true"></i>
                                            {{ $wallet['is_reconciled'] ? __('Reconciled') : __('Review required') }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <x-admin-not-found
                        :title="__('No commission wallets yet')"
                        :message="__('A currency wallet will be created automatically when the first commission is credited.')"
                        icon="fa-wallet"
                    />
                @endif
            </section>

            <section class="settings-panel settings-panel--wide commission-report__panel">
                <div class="settings-panel__head">
                    <span class="settings-panel__icon settings-panel__icon--primary">
                        <i class="fa-solid fa-receipt" aria-hidden="true"></i>
                    </span>
                    <div class="settings-panel__title">
                        <h2>@lang('Recent commission ledger')</h2>
                        <span>@lang('Credits and commission reversals only. Newest entries appear first.')</span>
                    </div>
                </div>

                @if($transactions->isNotEmpty())
                    <div class="table-responsive commission-report__table-wrap">
                        <table class="table commission-report__table mb-0">
                            <thead>
                            <tr>
                                <th>@lang('Entry')</th>
                                <th>@lang('Order')</th>
                                <th>@lang('Amount')</th>
                                <th>@lang('Wallet')</th>
                                <th class="text-end">@lang('Recorded')</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($transactions as $transaction)
                                @php($order = $ordersByReference->get($transaction->trx_reference))
                                <tr>
                                    <td>
                                        <strong>{{ $transaction->trx_type->label() }}</strong>
                                        <span class="commission-report__cell-meta fb-mono">{{ $transaction->trx_id }}</span>
                                    </td>
                                    <td>
                                        <strong>#{{ $order?->id ?? '—' }}</strong>
                                        <span class="commission-report__cell-meta">{{ $order?->vendor?->display_name ?? $transaction->trx_reference }}</span>
                                    </td>
                                    <td class="{{ $transaction->trx_type === \App\Enums\TrxType::MARKETPLACE_REFUND ? 'text-danger' : 'text-success' }}">
                                        {{ $transaction->amount_flow->sign($transaction->status) }}{{ money($transaction->amount, $transaction->currency, false) }} {{ $transaction->currency }}
                                    </td>
                                    <td><span class="commission-report__wallet-ref fb-mono">{{ $transaction->wallet_reference }}</span></td>
                                    <td class="text-end">
                                        <strong>{{ $transaction->created_at?->format('Y-m-d H:i') }}</strong>
                                        <span class="commission-report__cell-meta">{{ $transaction->created_at?->diffForHumans() }}</span>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($transactions->hasPages())
                        <div class="d-flex justify-content-end mt-3">
                            {{ $transactions->links() }}
                        </div>
                    @endif
                @else
                    <x-admin-not-found
                        :title="__('No commission activity yet')"
                        :message="__('Commission credits and refund reversals will appear after marketplace orders are processed.')"
                        icon="fa-receipt"
                    />
                @endif
            </section>
        @endif
    </div>
@endsection
