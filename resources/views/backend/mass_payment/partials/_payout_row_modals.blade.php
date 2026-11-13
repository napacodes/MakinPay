{{--
    The three dialogs a payout line's actions open. One of each serves every
    line: mass-payment-admin.js points the form at the line's own URL and
    names the line in the title. After a refused submit the server reopens the
    same dialog, pointed at the same line, from the posted mpa_action_url,
    accepted only when it is an action URL of this batch.

    The account dialog is filled from a JSON answer and emptied when it
    closes, so the details are never written into the page or the session.
--}}
@php
    $mpaRowPrefix  = url('admin/mass-payments/'.$batch->uuid.'/rows/');
    $mpaRowOldUrl  = (string) old('mpa_action_url', '');
    $mpaRowOldUrl  = str_starts_with($mpaRowOldUrl, $mpaRowPrefix.'/') ? $mpaRowOldUrl : '';
    $mpaPaidOld    = old('mpa_modal') === 'mark-paid' && $mpaRowOldUrl !== '';
    $mpaNotPaidOld = old('mpa_modal') === 'mark-not-paid' && $mpaRowOldUrl !== '';
@endphp

<div class="modal fade mpa-modal"
     id="mpa-mark-paid-modal"
     tabindex="-1"
     aria-labelledby="mpa-mark-paid-title"
     aria-hidden="true"
     data-mpa-row-modal="mark-paid"
     @if($mpaPaidOld && $errors->any()) data-mpa-reopen @endif>
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST" action="{{ $mpaPaidOld ? $mpaRowOldUrl : '#' }}" data-mpa-action-form>
                @csrf
                <input type="hidden" name="mpa_modal" value="mark-paid">
                <input type="hidden" name="mpa_action_url" value="{{ $mpaPaidOld ? $mpaRowOldUrl : '' }}" data-mpa-action-url>

                <div class="modal-header">
                    <h5 class="modal-title" id="mpa-mark-paid-title">{{ __('Record this payout as paid') }} <span data-mpa-line-label></span></h5>
                    <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>

                <div class="modal-body">
                    <p class="mpa-muted">{{ __('Only record a payout as paid once the money has reached the account. The line is settled; no money moves in the merchant\'s wallet.') }}</p>

                    <label class="form-label" for="mpa-mark-paid-reference">{{ __('Payment reference') }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text"
                           class="form-control @if($mpaPaidOld) @error('reference') is-invalid @enderror @endif"
                           id="mpa-mark-paid-reference"
                           name="reference"
                           dir="ltr"
                           autocomplete="off"
                           maxlength="{{ \App\Http\Requests\Backend\MassPayment\MarkMassPayoutRowPaidRequest::REFERENCE_MAX_LENGTH }}"
                           value="{{ $mpaPaidOld ? old('reference') : '' }}"
                           required>
                    @if($mpaPaidOld)
                        @error('reference')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    @endif
                    <p class="mpa-muted mt-2 mb-0">{{ __('The bank\'s or the provider\'s reference. One reference can settle only one payout.') }}</p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-success">{{ __('Record as paid') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade mpa-modal"
     id="mpa-mark-not-paid-modal"
     tabindex="-1"
     aria-labelledby="mpa-mark-not-paid-title"
     aria-hidden="true"
     data-mpa-row-modal="mark-not-paid"
     @if($mpaNotPaidOld && $errors->any()) data-mpa-reopen @endif>
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST" action="{{ $mpaNotPaidOld ? $mpaRowOldUrl : '#' }}" data-mpa-action-form>
                @csrf
                <input type="hidden" name="mpa_modal" value="mark-not-paid">
                <input type="hidden" name="mpa_action_url" value="{{ $mpaNotPaidOld ? $mpaRowOldUrl : '' }}" data-mpa-action-url>

                <div class="modal-header">
                    <h5 class="modal-title" id="mpa-mark-not-paid-title">{{ __('Record this payout as not paid') }} <span data-mpa-line-label></span></h5>
                    <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>

                <div class="modal-body">
                    <div class="mpa-notice mpa-notice--danger mb-3" role="status">
                        <span class="mpa-notice__icon" aria-hidden="true"><x-icon name="warning" width="18" height="18"/></span>
                        <p class="mpa-notice__copy">
                            <strong>{{ __('The payout is refunded in full to the merchant\'s wallet. This cannot be undone.') }}</strong>
                            <span>{{ __('If this payout went to a provider, ask the provider first: it can be recorded as not paid only after a check finds no payment.') }}</span>
                        </p>
                    </div>

                    <label class="form-label" for="mpa-mark-not-paid-reason">{{ __('Reason') }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <textarea class="form-control @if($mpaNotPaidOld) @error('reason') is-invalid @enderror @endif"
                              id="mpa-mark-not-paid-reason"
                              name="reason"
                              rows="3"
                              maxlength="{{ \App\Http\Requests\Backend\MassPayment\MarkMassPayoutRowNotPaidRequest::REASON_MAX_LENGTH }}"
                              required>{{ $mpaNotPaidOld ? old('reason') : '' }}</textarea>
                    @if($mpaNotPaidOld)
                        @error('reason')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    @endif
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-danger">{{ __('Record as not paid and refund') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade mpa-modal"
     id="mpa-reveal-modal"
     tabindex="-1"
     aria-labelledby="mpa-reveal-title"
     aria-hidden="true"
     data-mpa-reveal-modal>
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mpa-reveal-title">{{ __('Payout account') }} <span data-mpa-line-label></span></h5>
                <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>

            <div class="modal-body">
                <p class="mpa-muted" data-mpa-reveal-status role="status">{{ __('Loading…') }}</p>
                <dl class="mpa-facts mb-0" data-mpa-reveal-details hidden></dl>
                <p class="mpa-muted mt-3 mb-0">{{ __('Each time an account is shown, it is recorded on the batch\'s timeline.') }}</p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-coreui-dismiss="modal">{{ __('Close') }}</button>
            </div>
        </div>
    </div>
</div>
