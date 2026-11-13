{{--
    One "Mark paid" dialog for the whole page. bill-payment-admin.js fills it
    from the data-bpa-* attributes of the button that opened it
    (payments/_trigger) and points the form at that payment.

    After a refused save the page comes back with the errors and the typed
    values; data-bpa-reopen names the payment, and the script reopens the
    dialog for it if its button is still on the page. That uuid is only ever
    compared with the buttons' own attributes, never used to build a URL.
--}}
@php
    $bpaPaidOld = old('bpa_modal') === 'mark-paid';
@endphp

<div class="modal fade manual-request-review-modal bpa-action-modal"
     id="bpa-mark-paid-modal"
     tabindex="-1"
     aria-labelledby="bpa-mark-paid-title"
     aria-hidden="true"
     data-bpa-modal="mark-paid"
     @if($bpaPaidOld && $errors->any()) data-bpa-reopen="{{ old('bpa_payment') }}" @endif>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down manual-request-review-modal__dialog">
        <div class="modal-content manual-request-review-modal__content">
            <div class="modal-header manual-request-review-modal__header">
                <div class="manual-request-review-modal__identity">
                    <span class="manual-request-review-modal__icon" aria-hidden="true">
                        <i class="fa-solid fa-receipt"></i>
                    </span>
                    <div class="manual-request-review-modal__title-group">
                        <span class="manual-request-review-modal__eyebrow">{{ __('Mark paid') }}</span>
                        <h5 class="modal-title manual-request-review-modal__title" id="bpa-mark-paid-title" data-bpa-fill="biller"></h5>
                        <span class="manual-request-review-modal__subtitle" data-bpa-fill="reference"></span>
                    </div>
                </div>

                <div class="manual-request-review-modal__header-actions">
                    <button type="button"
                            class="manual-request-review-modal__close"
                            data-coreui-dismiss="modal"
                            aria-label="{{ __('Close') }}">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <form method="POST" action="" class="manual-request-review-modal__form" data-bpa-action-form>
                @csrf
                <input type="hidden" name="bpa_modal" value="mark-paid">
                <input type="hidden" name="bpa_payment" value="{{ $bpaPaidOld ? old('bpa_payment') : '' }}" data-bpa-fill-value="uuid">

                <div class="modal-body manual-request-review-modal__body">
                    <div class="manual-request-review-modal__callout" role="status">
                        <span class="manual-request-review-modal__callout-icon" aria-hidden="true">
                            <i class="fa-solid fa-info"></i>
                        </span>
                        <div>
                            <strong>{{ __('Pay exactly this amount at the biller, then record its receipt.') }}</strong>
                            <span>{{ __('Marking it paid completes the payment and tells the customer. It cannot be undone here.') }}</span>
                        </div>
                    </div>

                    <section class="manual-request-review-modal__section" aria-labelledby="bpa-mark-paid-summary">
                        <div class="manual-request-review-modal__section-head">
                            <span class="manual-request-review-modal__section-icon" aria-hidden="true">
                                <i class="fa-solid fa-receipt"></i>
                            </span>
                            <div>
                                <h6 class="manual-request-review-modal__section-title" id="bpa-mark-paid-summary">{{ __('What to pay') }}</h6>
                                <p class="manual-request-review-modal__section-subtitle">{{ __('The customer\'s account at the biller and the amount it receives.') }}</p>
                            </div>
                        </div>

                        <div class="manual-request-review-modal__summary-grid">
                            <div class="manual-request-review-modal__summary-item">
                                <span data-bpa-fill="customer-label">{{ __('Account number') }}</span>
                                <strong class="bpa-copyable">
                                    <span class="bpa-mono" dir="ltr" data-bpa-fill="customer-number"></span>
                                    <button type="button" class="bpa-copy" data-bpa-fill-copy="customer-number" aria-label="{{ __('Copy the customer number') }}" title="{{ __('Copy') }}">
                                        <x-icon name="clipboard" width="14" height="14"/>
                                    </button>
                                </strong>
                            </div>
                            <div class="manual-request-review-modal__summary-item manual-request-review-modal__summary-item--highlight">
                                <span>{{ __('Amount to pay') }}</span>
                                <strong class="bpa-copyable">
                                    <span dir="ltr" data-bpa-fill="amount"></span>
                                    <button type="button" class="bpa-copy" data-bpa-fill-copy="amount-exact" aria-label="{{ __('Copy the amount') }}" title="{{ __('Copy') }}">
                                        <x-icon name="clipboard" width="14" height="14"/>
                                    </button>
                                </strong>
                            </div>
                            <div class="manual-request-review-modal__summary-item" data-bpa-show-if="item">
                                <span>{{ __('Package') }}</span>
                                <strong data-bpa-fill="item"></strong>
                            </div>
                            <div class="manual-request-review-modal__summary-item">
                                <span>{{ __('Customer paid') }}</span>
                                <strong dir="ltr" data-bpa-fill="total"></strong>
                            </div>
                        </div>
                    </section>

                    <section class="manual-request-review-modal__section" aria-labelledby="bpa-mark-paid-record">
                        <div class="manual-request-review-modal__section-head manual-request-review-modal__section-head--compact">
                            <span class="manual-request-review-modal__section-icon" aria-hidden="true">
                                <i class="fa-solid fa-check"></i>
                            </span>
                            <div>
                                <h6 class="manual-request-review-modal__section-title" id="bpa-mark-paid-record">{{ __('Record the payment') }}</h6>
                                <p class="manual-request-review-modal__section-subtitle">{{ __('The customer sees the receipt reference on their receipt.') }}</p>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="bpa-receipt-reference">{{ __('Receipt reference') }} <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="text"
                                   class="form-control bpa-mono @if($bpaPaidOld) @error('receipt_reference') is-invalid @enderror @endif"
                                   id="bpa-receipt-reference"
                                   name="receipt_reference"
                                   maxlength="128"
                                   required
                                   autocomplete="off"
                                   value="{{ $bpaPaidOld ? old('receipt_reference') : '' }}">
                            @if($bpaPaidOld)
                                @error('receipt_reference')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            @endif
                        </div>

                        <div class="mb-3" data-bpa-token-field hidden>
                            <label class="form-label" for="bpa-token">
                                <span data-bpa-fill="token-label">{{ __('Token') }}</span>
                                <span class="text-danger" aria-hidden="true">*</span>
                            </label>
                            <input type="text"
                                   class="form-control bpa-mono @if($bpaPaidOld) @error('token') is-invalid @enderror @endif"
                                   id="bpa-token"
                                   name="token"
                                   maxlength="512"
                                   autocomplete="off"
                                   value="">
                            <div class="form-text">{{ __('The customer needs this to use what they paid for. It is stored encrypted and shown only on their receipt.') }}</div>
                            @if($bpaPaidOld)
                                @error('token')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            @endif
                        </div>

                        <div>
                            <label class="form-label" for="bpa-paid-note">{{ __('Internal note') }}</label>
                            <textarea class="form-control manual-request-review-modal__remarks @if($bpaPaidOld) @error('admin_note') is-invalid @enderror @endif"
                                      id="bpa-paid-note"
                                      name="admin_note"
                                      rows="2"
                                      maxlength="2000"
                                      placeholder="{{ __('Optional. Only admins see this.') }}">{{ $bpaPaidOld ? old('admin_note') : '' }}</textarea>
                            @if($bpaPaidOld)
                                @error('admin_note')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            @endif
                        </div>
                    </section>
                </div>

                <div class="modal-footer manual-request-review-modal__footer">
                    <button type="button" class="btn btn-outline-secondary manual-request-review-modal__action" data-coreui-dismiss="modal">
                        {{ __('Cancel') }}
                    </button>
                    <button type="submit" class="btn manual-request-review-modal__action manual-request-review-modal__action--approve">
                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                        <span>{{ __('Mark paid') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
