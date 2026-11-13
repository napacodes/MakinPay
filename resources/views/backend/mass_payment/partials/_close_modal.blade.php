{{--
    Close a batch held for a ledger anomaly once it has been reconciled by
    hand. The flagged rows are closed as reviewed and their reserved debits
    are left exactly as they are: no money moves. The note is the only
    record of what was done, so it is required.
--}}
@php
    $mpaCloseOld = old('mpa_modal') === 'close-after-review';
@endphp

<div class="modal fade mpa-modal"
     id="mpa-close-modal"
     tabindex="-1"
     aria-labelledby="mpa-close-title"
     aria-hidden="true"
     @if($mpaCloseOld && $errors->any()) data-mpa-reopen @endif>
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.mass-payment.close-after-review', $batch) }}" data-mpa-action-form>
                @csrf
                <input type="hidden" name="mpa_modal" value="close-after-review">

                <div class="modal-header">
                    <h5 class="modal-title" id="mpa-close-title">{{ __('Close :reference after review', ['reference' => $batch->reference]) }}</h5>
                    <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>

                <div class="modal-body">
                    <div class="mpa-notice mpa-notice--danger mb-3" role="status">
                        <span class="mpa-notice__icon" aria-hidden="true"><x-icon name="warning" width="18" height="18"/></span>
                        <p class="mpa-notice__copy">
                            <strong>{{ __('This moves no money. Only close the batch after reconciling it with the balance tools.') }}</strong>
                            <span>{{ __('Flagged rows are closed as reviewed and the batch is finished for good.') }}</span>
                        </p>
                    </div>

                    <label class="form-label" for="mpa-close-note">{{ __('How it was reconciled') }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <textarea class="form-control @if($mpaCloseOld) @error('note') is-invalid @enderror @endif"
                              id="mpa-close-note"
                              name="note"
                              rows="4"
                              maxlength="{{ \App\Http\Requests\Backend\MassPayment\CloseMassPaymentAfterReviewRequest::NOTE_MAX_LENGTH }}"
                              required>{{ $mpaCloseOld ? old('note') : '' }}</textarea>
                    @if($mpaCloseOld)
                        @error('note')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    @endif
                    <p class="mpa-muted mt-2 mb-0">{{ __('Only admins see this note.') }}</p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-coreui-dismiss="modal">{{ __('Not yet') }}</button>
                    <button type="submit" class="btn btn-danger">{{ __('Close after review') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
