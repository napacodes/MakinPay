{{--
    The "Confirm not charged and refund" dialog for a provider payment in
    review, filled by bill-payment-admin.js from the menu item that opened it,
    like the mark-failed one. Offered only after a recent check with the
    provider gave no final answer; the service checks that again, under the
    payment's lock, when the form is sent.
--}}
@php
    $bpaConfirmOld = old('bpa_modal') === 'confirm-not-paid';
@endphp

<div class="modal fade manual-request-review-modal bpa-action-modal"
     id="bpa-confirm-not-paid-modal"
     tabindex="-1"
     aria-labelledby="bpa-confirm-not-paid-title"
     aria-hidden="true"
     data-bpa-modal="confirm-not-paid"
     @if($bpaConfirmOld && $errors->any()) data-bpa-reopen="{{ old('bpa_payment') }}" @endif>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down manual-request-review-modal__dialog">
        <div class="modal-content manual-request-review-modal__content">
            <div class="modal-header manual-request-review-modal__header">
                <div class="manual-request-review-modal__identity">
                    <span class="manual-request-review-modal__icon bpa-action-modal__icon--danger" aria-hidden="true">
                        <i class="fa-solid fa-rotate-left"></i>
                    </span>
                    <div class="manual-request-review-modal__title-group">
                        <span class="manual-request-review-modal__eyebrow">{{ __('Confirm not charged and refund') }}</span>
                        <h5 class="modal-title manual-request-review-modal__title" id="bpa-confirm-not-paid-title" data-bpa-fill="biller"></h5>
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
                <input type="hidden" name="bpa_modal" value="confirm-not-paid">
                <input type="hidden" name="bpa_payment" value="{{ $bpaConfirmOld ? old('bpa_payment') : '' }}" data-bpa-fill-value="uuid">

                <div class="modal-body manual-request-review-modal__body">
                    <div class="manual-request-review-modal__callout bpa-action-modal__callout--danger" role="status">
                        <span class="manual-request-review-modal__callout-icon" aria-hidden="true">
                            <i class="fa-solid fa-info"></i>
                        </span>
                        <div>
                            <strong data-bpa-refund-line
                                    data-text="{{ __(':total will be returned to the customer\'s wallet now. This cannot be undone.') }}">{{ __('The full amount will be returned to the customer\'s wallet now. This cannot be undone.') }}</strong>
                            <span>{{ __('The payment was sent to the provider. Only do this once the provider has told you it was not charged; if it was, the customer keeps the bill and the money.') }}</span>
                        </div>
                    </div>

                    <section class="manual-request-review-modal__section" aria-label="{{ __('Payment summary') }}">
                        <div class="manual-request-review-modal__summary-grid">
                            <div class="manual-request-review-modal__summary-item">
                                <span data-bpa-fill="customer-label">{{ __('Account number') }}</span>
                                <strong class="bpa-mono" dir="ltr" data-bpa-fill="customer-number"></strong>
                            </div>
                            <div class="manual-request-review-modal__summary-item">
                                <span>{{ __('Bill amount') }}</span>
                                <strong dir="ltr" data-bpa-fill="amount"></strong>
                            </div>
                            <div class="manual-request-review-modal__summary-item manual-request-review-modal__summary-item--highlight">
                                <span>{{ __('Refund to the wallet') }}</span>
                                <strong dir="ltr" data-bpa-fill="total"></strong>
                            </div>
                        </div>
                    </section>

                    <section class="manual-request-review-modal__section" aria-labelledby="bpa-confirm-not-paid-reason-title">
                        <div class="manual-request-review-modal__section-head manual-request-review-modal__section-head--compact">
                            <span class="manual-request-review-modal__section-icon" aria-hidden="true">
                                <i class="fa-solid fa-message"></i>
                            </span>
                            <div>
                                <h6 class="manual-request-review-modal__section-title" id="bpa-confirm-not-paid-reason-title">{{ __('How you confirmed it') }}</h6>
                                <p class="manual-request-review-modal__section-subtitle">{{ __('The customer reads the reason on their receipt. The note stays with the admins.') }}</p>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="bpa-confirm-reason">{{ __('Reason') }} <span class="text-danger" aria-hidden="true">*</span></label>
                            <textarea class="form-control manual-request-review-modal__remarks @if($bpaConfirmOld) @error('reason') is-invalid @enderror @endif"
                                      id="bpa-confirm-reason"
                                      name="reason"
                                      rows="2"
                                      maxlength="500"
                                      required
                                      placeholder="{{ __('For example: The provider did not complete the payment.') }}">{{ $bpaConfirmOld ? old('reason') : '' }}</textarea>
                            @if($bpaConfirmOld)
                                @error('reason')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            @endif
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="bpa-confirm-note">{{ __('Note') }} <span class="text-danger" aria-hidden="true">*</span></label>
                            <textarea class="form-control manual-request-review-modal__remarks @if($bpaConfirmOld) @error('note') is-invalid @enderror @endif"
                                      id="bpa-confirm-note"
                                      name="note"
                                      rows="3"
                                      minlength="{{ \App\Services\BillPayment\BillPaymentService::CONFIRM_NOT_PAID_NOTE_MIN }}"
                                      maxlength="1000"
                                      required
                                      placeholder="{{ __('Who you spoke to at the provider, or what their dashboard shows.') }}">{{ $bpaConfirmOld ? old('note') : '' }}</textarea>
                            @if($bpaConfirmOld)
                                @error('note')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            @endif
                        </div>

                        <div class="form-check">
                            <input class="form-check-input @if($bpaConfirmOld) @error('confirm_not_charged') is-invalid @enderror @endif"
                                   type="checkbox"
                                   id="bpa-confirm-not-charged"
                                   name="confirm_not_charged"
                                   value="1"
                                   required
                                   @checked($bpaConfirmOld && old('confirm_not_charged'))>
                            <label class="form-check-label" for="bpa-confirm-not-charged">
                                {{ __('I checked with the provider, and this payment was not charged.') }}
                            </label>
                            @if($bpaConfirmOld)
                                @error('confirm_not_charged')
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
                    <button type="submit" class="btn manual-request-review-modal__action manual-request-review-modal__action--reject">
                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                        <span>{{ __('Confirm not charged and refund') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
