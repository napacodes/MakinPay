{{--
    One "Mark failed" dialog for the whole page, filled by
    bill-payment-admin.js from the button that opened it, like the mark-paid
    one. A preset only fills the reason box; the reason the customer reads is
    whatever the box says when the form is sent.
--}}
@php
    $bpaFailedOld = old('bpa_modal') === 'mark-failed';

    $bpaReasonPresets = [
        ['label' => __('Invalid customer number'), 'text' => __('The biller did not recognise the customer number.')],
        ['label' => __('Biller unavailable'), 'text' => __('The biller could not take the payment at the time.')],
        ['label' => __('Amount not accepted by the biller'), 'text' => __('The biller did not accept this amount.')],
        ['label' => __('Other'), 'text' => ''],
    ];
@endphp

<div class="modal fade manual-request-review-modal bpa-action-modal"
     id="bpa-mark-failed-modal"
     tabindex="-1"
     aria-labelledby="bpa-mark-failed-title"
     aria-hidden="true"
     data-bpa-modal="mark-failed"
     @if($bpaFailedOld && $errors->any()) data-bpa-reopen="{{ old('bpa_payment') }}" @endif>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down manual-request-review-modal__dialog">
        <div class="modal-content manual-request-review-modal__content">
            <div class="modal-header manual-request-review-modal__header">
                <div class="manual-request-review-modal__identity">
                    <span class="manual-request-review-modal__icon bpa-action-modal__icon--danger" aria-hidden="true">
                        <i class="fa-solid fa-xmark"></i>
                    </span>
                    <div class="manual-request-review-modal__title-group">
                        <span class="manual-request-review-modal__eyebrow">{{ __('Mark failed and refund') }}</span>
                        <h5 class="modal-title manual-request-review-modal__title" id="bpa-mark-failed-title" data-bpa-fill="biller"></h5>
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
                <input type="hidden" name="bpa_modal" value="mark-failed">
                <input type="hidden" name="bpa_payment" value="{{ $bpaFailedOld ? old('bpa_payment') : '' }}" data-bpa-fill-value="uuid">

                <div class="modal-body manual-request-review-modal__body">
                    <div class="manual-request-review-modal__callout bpa-action-modal__callout--danger" role="status">
                        <span class="manual-request-review-modal__callout-icon" aria-hidden="true">
                            <i class="fa-solid fa-info"></i>
                        </span>
                        <div>
                            <strong data-bpa-refund-line
                                    data-text="{{ __(':total will be returned to the customer\'s wallet now. This cannot be undone.') }}">{{ __('The full amount will be returned to the customer\'s wallet now. This cannot be undone.') }}</strong>
                            <span>{{ __('Only do this if the biller was not paid. If you are not sure, check with the biller first.') }}</span>
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

                    <section class="manual-request-review-modal__section" aria-labelledby="bpa-mark-failed-reason-title">
                        <div class="manual-request-review-modal__section-head manual-request-review-modal__section-head--compact">
                            <span class="manual-request-review-modal__section-icon" aria-hidden="true">
                                <i class="fa-solid fa-message"></i>
                            </span>
                            <div>
                                <h6 class="manual-request-review-modal__section-title" id="bpa-mark-failed-reason-title">{{ __('Why it failed') }}</h6>
                                <p class="manual-request-review-modal__section-subtitle">{{ __('The customer reads this reason on their receipt.') }}</p>
                            </div>
                        </div>

                        <div class="bpa-reasons" role="group" aria-label="{{ __('Common reasons') }}">
                            @foreach($bpaReasonPresets as $preset)
                                <button type="button" class="bpa-reason" data-bpa-reason="{{ $preset['text'] }}">{{ $preset['label'] }}</button>
                            @endforeach
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="bpa-failed-reason">{{ __('Reason') }} <span class="text-danger" aria-hidden="true">*</span></label>
                            <textarea class="form-control manual-request-review-modal__remarks @if($bpaFailedOld) @error('reason') is-invalid @enderror @endif"
                                      id="bpa-failed-reason"
                                      name="reason"
                                      rows="3"
                                      maxlength="500"
                                      required
                                      data-bpa-reason-input>{{ $bpaFailedOld ? old('reason') : '' }}</textarea>
                            @if($bpaFailedOld)
                                @error('reason')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            @endif
                        </div>

                        <div>
                            <label class="form-label" for="bpa-failed-note">{{ __('Internal note') }}</label>
                            <textarea class="form-control manual-request-review-modal__remarks @if($bpaFailedOld) @error('admin_note') is-invalid @enderror @endif"
                                      id="bpa-failed-note"
                                      name="admin_note"
                                      rows="2"
                                      maxlength="2000"
                                      placeholder="{{ __('Optional. Only admins see this.') }}">{{ $bpaFailedOld ? old('admin_note') : '' }}</textarea>
                            @if($bpaFailedOld)
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
                    <button type="submit" class="btn manual-request-review-modal__action manual-request-review-modal__action--reject">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        <span>{{ __('Mark failed and refund') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
