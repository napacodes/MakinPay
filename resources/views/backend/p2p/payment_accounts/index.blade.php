@extends('backend.p2p.layout')

@section('title', __('P2P Payment Accounts'))

@section('p2p_title')
    {{ __('Payment accounts') }}
@endsection

@section('p2p_subtitle')
    {{ __('Ownership proofs customers attached to their P2P payment accounts.') }}
@endsection

@section('p2p_icon', 'credit-card')

@php
    $filters = [
        'pending'  => __('Waiting for review'),
        'verified' => __('Verified'),
        'all'      => __('All with a proof'),
    ];
@endphp

@section('p2p_content')
    <div class="p2p-refresh">
        <section class="fb-card pa-table-card">
            <div class="fb-card__head">
                <div>
                    <span class="fb-hero__eyebrow">@lang('Review')</span>
                    <h5>@lang('Payment account proofs')</h5>
                </div>
                <div class="fb-card__meta">
                    <span class="fb-pill {{ $pendingCount > 0 ? 'fb-pill--warning' : 'fb-pill--neutral' }}">
                        <i class="fa-solid fa-clock" aria-hidden="true"></i>
                        <span>{{ __(':count waiting', ['count' => number_format($pendingCount)]) }}</span>
                    </span>
                </div>
            </div>

            <div class="fb-toolbar fb-toolbar--directory">
                <nav class="fb-segment" aria-label="{{ __('Proof status filter') }}">
                    @foreach($filters as $key => $label)
                        <a href="{{ route('admin.p2p.payment-accounts.index', ['status' => $key]) }}"
                           class="fb-segment__item {{ $filter === $key ? 'is-active' : '' }}"
                           @if($filter === $key) aria-current="page" @endif>
                            <span>{{ $label }}</span>
                            @if($key === 'pending')
                                <span class="fb-segment__count">{{ number_format($pendingCount) }}</span>
                            @endif
                        </a>
                    @endforeach
                </nav>
            </div>

            @if($accounts->isEmpty())
                <x-admin-not-found
                    :title="$filter === 'pending' ? __('No proofs waiting') : __('No payment accounts here')"
                    :message="__('An account appears here when its owner uploads a proof file for it.')"
                    icon="fa-credit-card"
                />
            @else
                <div class="fb-card__body fb-card__body--flush">
                    <div class="fb-table table-responsive">
                        <table class="pa-table">
                            <thead>
                                <tr>
                                    <th>@lang('Trader')</th>
                                    <th>@lang('Method')</th>
                                    <th>@lang('Account')</th>
                                    <th>@lang('Proof')</th>
                                    <th>@lang('Status')</th>
                                    <th class="text-end">@lang('Action')</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($accounts as $account)
                                    @php
                                        $files = collect($account->renderedDetails())->where('type', 'file')->values();
                                        $isPending = $account->verification_status === \App\Models\P2P\PaymentAccount::VERIFICATION_PENDING;
                                    @endphp
                                    <tr data-payment-account="{{ $account->id }}">
                                        <td>
                                            <span class="fb-user__meta">
                                                <span class="fb-user__name">{{ $account->user?->name ?? __('Deleted user') }}</span>
                                                <span class="fb-user__handle">{{ $account->user?->username ? '@'.$account->user->username : $account->user?->email }}</span>
                                            </span>
                                        </td>
                                        <td>{{ $account->paymentMethod?->name ?? __('Removed method') }}</td>
                                        <td>
                                            <span class="fb-user__meta">
                                                <span class="fb-user__name">{{ $account->effective_label ?? $account->display_name }}</span>
                                                <span class="fb-user__handle">{{ $account->display_value }}</span>
                                            </span>
                                        </td>
                                        <td>
                                            @forelse($files as $file)
                                                <a href="{{ route('admin.p2p.payment-accounts.file', [$account, $file['key']]) }}" target="_blank" rel="noopener" class="d-block">
                                                    <i class="fa-regular fa-file" aria-hidden="true"></i>
                                                    {{ $file['label'] }}
                                                </a>
                                            @empty
                                                <span class="text-muted">@lang('No file')</span>
                                            @endforelse
                                            <small class="text-muted d-block">{{ $account->updated_at?->diffForHumans() }}</small>
                                        </td>
                                        <td>
                                            @if($isPending)
                                                <span class="fb-pill fb-pill--warning">
                                                    <i class="fa-solid fa-clock" aria-hidden="true"></i>
                                                    <span>@lang('Pending')</span>
                                                </span>
                                            @else
                                                <span class="fb-pill fb-pill--success">
                                                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                                    <span>@lang('Verified')</span>
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex flex-wrap gap-2 justify-content-end">
                                                @if($isPending)
                                                    <form method="POST" action="{{ route('admin.p2p.payment-accounts.verify', $account) }}">
                                                        @csrf
                                                        <button type="submit" class="fb-btn fb-btn--primary fb-btn--sm">@lang('Verify')</button>
                                                    </form>
                                                @endif
                                                <form method="POST" action="{{ route('admin.p2p.payment-accounts.reject', $account) }}" class="d-inline-flex gap-2">
                                                    @csrf
                                                    <label class="visually-hidden" for="reject-reason-{{ $account->id }}">@lang('Reason')</label>
                                                    <input type="text" id="reject-reason-{{ $account->id }}" name="reason" maxlength="255"
                                                           class="form-control form-control-sm fb-input" placeholder="{{ __('Reason (optional)') }}">
                                                    <button type="submit" class="fb-btn fb-btn--sm">{{ $isPending ? __('Decline') : __('Revoke') }}</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($accounts->hasPages())
                    <div class="fb-card__foot">
                        {{ $accounts->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
@endsection
