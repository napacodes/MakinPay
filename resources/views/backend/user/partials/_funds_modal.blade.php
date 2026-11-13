@php
    $fundWallets = $user->wallets()->with('currency')->get();
    $firstWallet = $fundWallets->first();
@endphp
<div class="modal fade um-modal" id="umFundsModal" tabindex="-1" aria-labelledby="umFundsTitle" aria-hidden="true" data-um-funds>
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('admin.user.update-balance') }}">
            @csrf
            <input type="hidden" name="user_id" value="{{ $user->id }}">
            <input type="hidden" name="wallet_id" value="{{ $firstWallet?->id }}" data-um-fund-wallet>
            <input type="hidden" name="transaction_type" value="add" data-um-fund-mode>

            <div class="um-modal__head">
                <span class="um-modal__tile"><x-icon name="wallet-plus"/></span>
                <div class="um-modal__heading">
                    <h3 class="um-modal__title" id="umFundsTitle">{{ __('Manage funds') }}</h3>
                    <p class="um-modal__sub">{{ __("Adjust :name's wallet balance. Logged to the audit trail.", ['name' => $user->name]) }}</p>
                </div>
                <button type="button" class="um-modal__close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}">×</button>
            </div>

            <div class="um-modal__body">
                <div class="um-group">
                    <span class="um-group__label">{{ __('Wallet') }}</span>
                    <div class="um-options">
                        @forelse($fundWallets as $wallet)
                            <button type="button"
                                    class="um-option {{ $loop->first ? 'is-active' : '' }}"
                                    data-um-fund-pick
                                    data-wallet-id="{{ $wallet->id }}"
                                    data-code="{{ $wallet->currency?->code }}"
                                    data-symbol="{{ $wallet->currency?->symbol }}"
                                    data-position="{{ \App\Support\Money::symbolPosition($wallet->currency?->code) }}"
                                    data-decimals="{{ \App\Support\Money::decimals($wallet->currency?->code) }}"
                                    data-balance="{{ \App\Support\Money::quantize($wallet->balance, $wallet->currency?->code) }}">
                                <span class="um-option__code">@if($wallet->currency?->flag)<img class="um-option__flag" src="{{ asset($wallet->currency->flag) }}" alt="" loading="lazy" onerror="this.remove()">@endif{{ $wallet->currency?->code }}</span>
                                <span class="um-option__value">{{ money($wallet->balance, $wallet->currency?->code) }}</span>
                            </button>
                        @empty
                            <span class="um-field__hint">{{ __('This user has no wallets to adjust.') }}</span>
                        @endforelse
                    </div>
                </div>

                <div class="um-group">
                    <span class="um-group__label">{{ __('Action') }}</span>
                    <div class="um-seg um-seg--halves" role="group">
                        <button type="button" class="um-seg__btn is-active" data-um-fund-mode-pick="add">{{ __('Add balance') }}</button>
                        <button type="button" class="um-seg__btn" data-um-fund-mode-pick="subtract">{{ __('Deduct balance') }}</button>
                    </div>
                </div>

                <div class="um-group">
                    <span class="um-group__label">{{ __('Amount') }}<span class="um-group__meta">{{ __('Available') }} <strong data-um-fund-available></strong></span></span>
                    <div class="um-amount-field" data-um-fund-field>
                        <span class="um-amount-field__symbol" data-um-fund-symbol></span>
                        <input type="text" name="amount" class="um-amount-field__input" inputmode="decimal" autocomplete="off" placeholder="0.00" data-um-fund-amount>
                        <span class="um-amount-field__code" data-um-fund-code></span>
                    </div>
                    <div class="um-chips">
                        @foreach([50, 100, 500, 1000] as $quick)
                            <button type="button" class="um-chip" data-um-fund-quick="{{ $quick }}">{{ number_format($quick) }}</button>
                        @endforeach
                    </div>
                    <span class="um-field__error um-hidden" data-um-fund-error>{{ __('Amount exceeds the available balance of :balance.', ['balance' => '']) }}<strong data-um-fund-error-balance></strong></span>
                </div>

                <div class="um-group">
                    <span class="um-group__label">{{ __('Note') }} <span class="is-optional">({{ __('shown to the user') }})</span></span>
                    <textarea name="note" rows="2" class="um-input" maxlength="255" placeholder="{{ __('e.g. Refund for failed withdrawal WD-20874') }}"></textarea>
                </div>

                <div class="um-compare">
                    <span class="um-compare__cell"><span class="um-compare__label">{{ __('Current') }}</span><span class="um-compare__value" data-um-fund-current></span></span>
                    <span class="um-compare__arrow" aria-hidden="true">→</span>
                    <span class="um-compare__cell um-compare__cell--end"><span class="um-compare__label">{{ __('After') }}</span><span class="um-compare__value" data-um-fund-after></span></span>
                </div>
            </div>

            <div class="um-modal__foot">
                <button type="button" class="um-btn" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="um-btn um-btn--primary" disabled data-um-fund-submit
                        data-label-add="{{ __('Add :amount', ['amount' => '%s']) }}"
                        data-label-subtract="{{ __('Deduct :amount', ['amount' => '%s']) }}"
                        data-label-funds="{{ __('funds') }}">{{ __('Add funds') }}</button>
            </div>
        </form>
    </div>
</div>
