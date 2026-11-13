@php
    $deleteWallets = $user->wallets()->with('currency')->get();
    $heldBalances  = $deleteWallets->filter(fn ($wallet): bool => bccomp((string) $wallet->balance, '0', 8) > 0);
    /* Vault money left the wallets; it is deleted with the account all the same. */
    $deleteVaults  = \Illuminate\Support\Facades\Schema::hasTable('vaults') ? $user->vaults()->with('wallet.currency')->get() : collect();
    $heldVaults    = $deleteVaults->filter(fn ($vault): bool => bccomp((string) $vault->balance, '0', 8) > 0);
    $holdsBalance  = $heldBalances->isNotEmpty() || $heldVaults->isNotEmpty();
    $walletCodes   = $deleteWallets->map(fn ($wallet) => $wallet->currency?->code)->filter()->implode(' · ');
@endphp
<div class="modal fade um-modal um-modal--sm" id="umDeleteModal" tabindex="-1" aria-labelledby="umDeleteTitle" aria-hidden="true" data-um-delete data-holds-balance="{{ $holdsBalance ? '1' : '0' }}">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('admin.user.destroy', $user->id) }}" autocomplete="off">
            @csrf
            @method('DELETE')
            <input type="hidden" name="forfeit" value="0" data-um-switch-value="forfeit">

            <div class="um-modal__head">
                <span class="um-modal__tile um-modal__tile--danger"><x-icon name="trash"/></span>
                <div class="um-modal__heading">
                    <h3 class="um-modal__title" id="umDeleteTitle">{{ __('Delete account') }}</h3>
                    <p class="um-modal__sub">{{ __('Permanently remove :name and all associated data.', ['name' => $user->name]) }}</p>
                </div>
                <button type="button" class="um-modal__close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}">×</button>
            </div>

            <div class="um-modal__body um-modal__body--tight">
                <div class="um-callout um-callout--danger"><x-icon name="trash"/><span><strong>{{ __('This cannot be undone.') }}</strong> {{ __('Consider suspending instead if you may need the records later.') }}</span></div>

                <div class="um-ledger">
                    <div class="um-ledger__head">{{ __('What will be deleted') }}</div>
                    <div class="um-ledger__row"><span>{{ __('Profile, contact details and KYC documents') }}</span><span class="um-ledger__value">{{ __('Removed') }}</span></div>
                    <div class="um-ledger__row"><span>{{ __('Wallets') }} @if($walletCodes)({{ $walletCodes }})@endif</span><span class="um-ledger__value {{ $heldBalances->isNotEmpty() ? 'um-ledger__value--warning' : 'um-ledger__value--success' }}">{{ $heldBalances->isNotEmpty() ? __('Balance remaining') : __('Empty') }}</span></div>
                    @if($deleteVaults->isNotEmpty())
                        <div class="um-ledger__row" data-um-delete-vaults><span>{{ __('Savings vaults (:count)', ['count' => $deleteVaults->count()]) }}</span><span class="um-ledger__value {{ $heldVaults->isNotEmpty() ? 'um-ledger__value--warning' : 'um-ledger__value--success' }}">{{ $heldVaults->isNotEmpty() ? __('Balance remaining') : __('Empty') }}</span></div>
                    @endif
                    <div class="um-ledger__row"><span>{{ __('Transactions (:count)', ['count' => $tabCounts['transactions']]) }}</span><span class="um-ledger__value um-ledger__value--muted">{{ __('Removed with the account') }}</span></div>
                    <div class="um-ledger__row"><span>{{ __('Support tickets (:count open)', ['count' => $tabCounts['open_tickets']]) }}</span><span class="um-ledger__value">{{ __('Removed') }}</span></div>
                    <div class="um-ledger__row"><span>{{ __('Referral links (:count)', ['count' => $tabCounts['referrals']]) }}</span><span class="um-ledger__value">{{ __('Detached') }}</span></div>
                </div>

                @if($holdsBalance)
                    @if($heldBalances->isNotEmpty())
                        <div class="um-callout um-callout--warning"><x-icon name="wallet-plus"/><span>{{ __('Wallets still hold') }} <strong>{{ $heldBalances->map(fn ($wallet) => money($wallet->balance, $wallet->currency?->code))->implode(' · ') }}</strong>. {{ __('Withdraw or zero the balances (Manage funds) before deleting, or the funds are forfeited.') }}</span></div>
                    @endif
                    @if($heldVaults->isNotEmpty())
                        <div class="um-callout um-callout--warning" data-um-delete-vault-warning><x-icon name="wallet-plus"/><span>{{ __('Savings vaults still hold') }} <strong dir="ltr">{{ $heldVaults->map(fn ($vault) => money($vault->balance, $vault->wallet?->currency?->code))->implode(' · ') }}</strong>. {{ __('The customer can move it back to a wallet; deleting the account forfeits it.') }}</span></div>
                    @endif

                    <label class="um-switch-row">
                        <button type="button" class="um-switch" role="switch" aria-checked="false" data-um-switch="forfeit"></button>
                        <span class="um-switch-row__copy"><span class="um-switch-row__label">{{ __('I understand remaining funds are forfeited') }}</span><span class="um-switch-row__hint">{{ __('Required while any wallet or savings vault holds money.') }}</span></span>
                    </label>
                @endif

                <div class="um-group">
                    <span class="um-group__label"><span>{{ __('Type') }} <span class="um-kbd">DELETE</span> {{ __('to confirm') }}</span></span>
                    <input type="text" name="confirm" class="um-input um-input--lg um-input--mono um-input--danger" placeholder="DELETE" autocomplete="off" spellcheck="false" data-um-delete-confirm>
                    @error('confirm')<span class="um-field__error">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="um-modal__foot">
                <button type="button" class="um-btn" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="um-btn um-btn--danger" disabled data-um-delete-submit>{{ __('Delete permanently') }}</button>
            </div>
        </form>
    </div>
</div>
