{{--
    Cancel the rows of this batch that are not paid yet. Each one is refunded
    its whole payable to the sender's wallet; a row already being paid
    finishes paid, and a row flagged for review is skipped and stays reserved
    until it is closed after review. The reason is kept on the audit trail.
--}}
@php
    $mpaCancelOld = old('mpa_modal') === 'cancel-remaining';
@endphp

<div class="modal fade mpa-modal"
     id="mpa-cancel-modal"
     tabindex="-1"
     aria-labelledby="mpa-cancel-title"
     aria-hidden="true"
     @if($mpaCancelOld && $errors->any()) data-mpa-reopen @endif>
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.mass-payment.cancel-remaining', $batch) }}" data-mpa-action-form>
                @csrf
                <input type="hidden" name="mpa_modal" value="cancel-remaining">

                <div class="modal-header">
                    <h5 class="modal-title" id="mpa-cancel-title">{{ __('Cancel remaining rows of :reference', ['reference' => $batch->reference]) }}</h5>
                    <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>

                <div class="modal-body">
                    <div class="mpa-notice mpa-notice--danger mb-3" role="status">
                        <span class="mpa-notice__icon" aria-hidden="true"><x-icon name="warning" width="18" height="18"/></span>
                        <p class="mpa-notice__copy">
                            <strong data-mpa-cancel-refundable>{{ __(':count rows, :amount, will be refunded to the sender\'s wallet. This cannot be undone.', [
                                'count'  => number_format($refundable['count']),
                                'amount' => money($refundable['amount'], $figures['currency'], false).' '.$figures['currency'],
                            ]) }}</strong>
                            <span>{{ __('Rows already paid stay paid. A row being paid right now finishes paid.') }}</span>
                            @if($flagged > 0)
                                <span data-mpa-cancel-flagged>{{ trans_choice('{1} 1 flagged row is not refunded. It stays reserved until it is closed after review.|[2,*] :count flagged rows are not refunded. They stay reserved until they are closed after review.', $flagged, ['count' => number_format($flagged)]) }}</span>
                            @endif
                        </p>
                    </div>

                    <label class="form-label" for="mpa-cancel-reason">{{ __('Reason') }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <textarea class="form-control @if($mpaCancelOld) @error('reason') is-invalid @enderror @endif"
                              id="mpa-cancel-reason"
                              name="reason"
                              rows="3"
                              maxlength="{{ \App\Http\Requests\Backend\MassPayment\CancelRemainingMassPaymentRequest::REASON_MAX_LENGTH }}"
                              required>{{ $mpaCancelOld ? old('reason') : '' }}</textarea>
                    @if($mpaCancelOld)
                        @error('reason')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    @endif
                    <p class="mpa-muted mt-2 mb-0">{{ __('Kept on the batch\'s timeline.') }}</p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-coreui-dismiss="modal">{{ __('Keep the batch') }}</button>
                    <button type="submit" class="btn btn-danger">{{ __('Cancel rows and refund') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
