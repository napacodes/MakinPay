{{-- Create/edit an asset fee override.
     One modal serves both: `data-asset-fee-create` clears it and points the
     form at store, `data-asset-fee-edit` fetches the row and points it at
     update. Two near-identical modals would only drift apart. The labels
     the script swaps between ride on the form as data attributes so the
     script stays a plain static file. --}}
<div class="modal fade p2ps-modal" id="p2pAssetFeeModal" tabindex="-1" aria-labelledby="p2pAssetFeeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.p2p.asset-fees.store') }}" data-asset-fee-form
                  data-label-add="{{ __('Add override') }}"
                  data-label-save="{{ __('Save override') }}"
                  data-title-add="{{ __('Add fee override') }}"
                  data-title-edit="{{ __('Edit fee override') }}"
                  data-unit-default="{{ __('asset') }}"
                  data-error-title="{{ __('Could not load this override') }}"
                  data-error-text="{{ __('Please close the dialog and try again.') }}">
                @csrf
                <input type="hidden" name="_method" value="POST" data-asset-fee-method>

                <div class="modal-header p2ps-modal__head">
                    <span class="p2ps-card__icon p2ps-card__icon--violet"><i class="fa-solid fa-coins" aria-hidden="true"></i></span>
                    <div class="p2ps-modal__title">
                        <span class="p2ps-modal__eyebrow">{{ __('Per-asset fee') }}</span>
                        <h5 class="modal-title" id="p2pAssetFeeModalLabel" data-asset-fee-title>{{ __('Add fee override') }}</h5>
                        <span class="p2ps-modal__sub">{{ __('Both fees are charged in this asset. A flat 0.0005 on a BTC market is roughly thirty dollars; on a USDT market it is a twentieth of a cent.') }}</span>
                    </div>
                    <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>

                <div class="modal-body p2ps-modal__body">
                    <div class="p2ps-field">
                        <label for="asset_fee_currency_id">{{ __('Asset') }} <span class="p2ps-req" aria-hidden="true">*</span></label>
                        <select class="form-select p2ps-select" name="currency_id" id="asset_fee_currency_id" required data-asset-fee-currency>
                            <option value="">{{ __('Select an asset') }}</option>
                            @foreach($assetOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->code }} — {{ $option->name }}</option>
                            @endforeach
                        </select>
                        <span class="p2ps-hint" data-asset-fee-currency-locked hidden>
                            {{ __('The asset cannot be changed. Remove this override and add another to move it.') }}
                        </span>
                    </div>

                    <div class="p2ps-grid-2">
                        @foreach(['maker' => ['title' => __('Maker fee'), 'who' => __('Charged to the trader whose offer was taken.'), 'role' => __('Earns'), 'tone' => 'teal'],
                                  'taker' => ['title' => __('Taker fee'), 'who' => __('Charged to the trader who accepted the offer.'), 'role' => __('Pays'),  'tone' => 'brand']] as $side => $copy)
                            <div class="p2ps-panel">
                                <div class="p2ps-panel__head">
                                    <div>
                                        <span class="p2ps-panel__title">{{ $copy['title'] }}</span>
                                        <span class="p2ps-panel__sub">{{ $copy['who'] }}</span>
                                    </div>
                                    <span class="p2ps-role p2ps-role--{{ $copy['tone'] }}">{{ $copy['role'] }}</span>
                                </div>

                                <div class="p2ps-field">
                                    <label for="asset_fee_{{ $side }}_pct">{{ __('Percentage') }}</label>
                                    <div class="p2ps-input">
                                        <input type="text" inputmode="decimal"
                                               name="{{ $side }}_fee_pct" id="asset_fee_{{ $side }}_pct"
                                               placeholder="{{ __('e.g. 0.5') }}"
                                               oninput="this.value = validateDouble(this.value)">
                                        <span class="p2ps-input__suffix">%</span>
                                    </div>
                                </div>

                                <div class="p2ps-field">
                                    <label for="asset_fee_{{ $side }}_fixed">{{ __('Flat amount') }}</label>
                                    <div class="p2ps-input">
                                        <input type="text" inputmode="decimal"
                                               name="{{ $side }}_fee_fixed" id="asset_fee_{{ $side }}_fixed"
                                               placeholder="{{ __('e.g. 0.0005') }}"
                                               oninput="this.value = validateDouble(this.value)">
                                        <span class="p2ps-input__suffix" data-asset-fee-unit>{{ __('asset') }}</span>
                                    </div>
                                </div>

                                <div class="p2ps-field">
                                    <span class="p2ps-field__label">{{ __('Floor and cap') }} <span class="p2ps-optional">· {{ __('optional') }}</span></span>
                                    <div class="p2ps-grid-2 p2ps-grid-2--tight">
                                        <div class="p2ps-input p2ps-input--prefixed">
                                            <span class="p2ps-input__prefix">{{ __('Min') }}</span>
                                            <input type="text" inputmode="decimal"
                                                   name="{{ $side }}_fee_min" placeholder="{{ __('No minimum') }}"
                                                   aria-label="{{ __(':side minimum fee', ['side' => $copy['title']]) }}"
                                                   oninput="this.value = validateDouble(this.value)">
                                        </div>
                                        <div class="p2ps-input p2ps-input--prefixed">
                                            <span class="p2ps-input__prefix">{{ __('Max') }}</span>
                                            <input type="text" inputmode="decimal"
                                                   name="{{ $side }}_fee_max" placeholder="{{ __('No maximum') }}"
                                                   aria-label="{{ __(':side maximum fee', ['side' => $copy['title']]) }}"
                                                   oninput="this.value = validateDouble(this.value)">
                                        </div>
                                    </div>
                                    <span class="p2ps-hint">{{ __('A flat floor and ceiling on the total fee, in this asset — not a percentage.') }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <label class="p2ps-switch-row form-check form-switch" for="asset_fee_is_active">
                        <span class="p2ps-switch-row__copy">
                            <span class="p2ps-switch-row__title">{{ __('Use this override') }}</span>
                            <span class="p2ps-switch-row__sub">{{ __('Switch off to fall back to the default fee without losing these figures.') }}</span>
                        </span>
                        <input class="form-check-input" type="checkbox" role="switch"
                               name="is_active" value="1" id="asset_fee_is_active" checked>
                    </label>
                </div>

                <div class="modal-footer p2ps-modal__foot">
                    <span class="p2ps-modal__note">{{ __('Applies to new orders in this asset as soon as it is saved.') }}</span>
                    <div class="p2ps-modal__actions">
                        <button type="button" class="ph-btn ph-btn--secondary" data-coreui-dismiss="modal">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit" class="ph-btn ph-btn--primary">
                            <i class="fa-solid fa-check" aria-hidden="true"></i>
                            <span data-asset-fee-submit>{{ __('Add override') }}</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
