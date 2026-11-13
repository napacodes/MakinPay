{{--
    Reject a payout batch waiting for approval. Every reserved row is refunded
    its whole payable to the merchant's wallet and the batch is closed; nothing
    was ever sent. The reason is kept on the batch and its timeline.
--}}
@php
    $mpaRejectOld = old('mpa_modal') === 'reject';
@endphp

<div class="modal fade mpa-modal"
     id="mpa-reject-modal"
     tabindex="-1"
     aria-labelledby="mpa-reject-title"
     aria-hidden="true"
     @if($mpaRejectOld && $errors->any()) data-mpa-reopen @endif>
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.mass-payout.reject', $batch) }}" data-mpa-action-form>
                @csrf
                <input type="hidden" name="mpa_modal" value="reject">

                <div class="modal-header">
                    <h5 class="modal-title" id="mpa-reject-title">{{ __('Reject :reference', ['reference' => $batch->reference]) }}</h5>
                    <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>

                <div class="modal-body">
                    <div class="mpa-notice mpa-notice--danger mb-3" role="status">
                        <span class="mpa-notice__icon" aria-hidden="true"><x-icon name="warning" width="18" height="18"/></span>
                        <p class="mpa-notice__copy">
                            <strong>{{ __(':count payouts, :amount, will be refunded to the merchant\'s wallet. This cannot be undone.', [
                                'count'  => number_format($counts['pending']),
                                'amount' => money($figures['held'], $figures['currency'], false).' '.$figures['currency'],
                            ]) }}</strong>
                            <span>{{ __('Nothing has been sent for this batch, so nothing needs to be recalled.') }}</span>
                        </p>
                    </div>

                    <label class="form-label" for="mpa-reject-reason">{{ __('Reason') }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <textarea class="form-control @if($mpaRejectOld) @error('reason') is-invalid @enderror @endif"
                              id="mpa-reject-reason"
                              name="reason"
                              rows="3"
                              maxlength="{{ \App\Http\Requests\Backend\MassPayment\RejectMassPayoutRequest::REASON_MAX_LENGTH }}"
                              required>{{ $mpaRejectOld ? old('reason') : '' }}</textarea>
                    @if($mpaRejectOld)
                        @error('reason')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    @endif
                    <p class="mpa-muted mt-2 mb-0">{{ __('Kept on the batch\'s timeline.') }}</p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-coreui-dismiss="modal">{{ __('Keep waiting') }}</button>
                    <button type="submit" class="btn btn-danger">{{ __('Reject and refund') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
