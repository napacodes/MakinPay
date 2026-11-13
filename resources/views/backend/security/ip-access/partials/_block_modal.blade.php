{{--
    Blocking an address by hand.

    It left the page for a dialog when the list took the full width. The
    form is unchanged — one address, one reason — but the copy now says
    what a manual block means next to an automatic one: it is permanent,
    and the guard will not touch it.
--}}
<div class="modal fade" id="ipaBlockModal" tabindex="-1" aria-labelledby="ipaBlockTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('admin.ip-access.store') }}">
            @csrf

            <div class="modal-header">
                <h2 class="modal-title h6" id="ipaBlockTitle">{{ __('Block an address') }}</h2>
                <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3">
                    <label for="ipaAddress" class="form-label">{{ __('IP address') }}</label>
                    <input type="text" id="ipaAddress" name="ip_address" value="{{ old('ip_address') }}"
                           class="form-control @error('ip_address') is-invalid @enderror"
                           placeholder="203.0.113.8" dir="ltr" required>
                    @error('ip_address')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="ipaReason" class="form-label">{{ __('Reason') }}</label>
                    <input type="text" id="ipaReason" name="reason" value="{{ old('reason') }}"
                           class="form-control @error('reason') is-invalid @enderror"
                           placeholder="{{ __('Repeated failed sign-ins') }}">
                    @error('reason')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="form-text">{{ __('Shown only to administrators, so the next person knows why.') }}</div>
                </div>

                <p class="mb-0 small text-body-secondary">
                    {{ __('A block you write has no expiry and is never rewritten by the guard. It affects every account that uses the address.') }}
                </p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Block address') }}</button>
            </div>
        </form>
    </div>
</div>
