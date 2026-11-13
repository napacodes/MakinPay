{{--
    Verification consent.

    Shared by both journeys: the template form, where it gates documents we
    collected ourselves, and the provider-hosted handover, where it gates the
    applicant being sent to the partner at all. The wording is
    administrator-controlled and stored with the submission exactly as rendered
    here, so a later edit never rewrites what someone already agreed to.

    @param int    $step        Step number shown in the label.
    @param string $consentText Statement to display, :site already resolved.
--}}
<div class="kyc-verify-field kyc-consent">
    <label class="kyc-verify-field__label" for="kyc-consent-accept">
        <span class="kyc-verify-field__step">{{ $step }}</span>
        {{ __('Verification Consent') }}
        <span class="kyc-verify-field__optional kyc-consent__required">{{ __('Required') }}</span>
    </label>
    <p class="kyc-verify-field__hint">
        {{ __('Your documents are checked by our identity verification partner. Please read this and agree before submitting.') }}
    </p>

    <div class="kyc-consent__statement" tabindex="0" role="region"
         aria-label="{{ __('Identity verification consent statement') }}">
        @foreach(preg_split('/\R\s*\R/', trim((string) ($consentText ?? ''))) ?: [] as $consentParagraph)
            @if(trim($consentParagraph) !== '')
                <p>{{ trim($consentParagraph) }}</p>
            @endif
        @endforeach
    </div>

    <div class="kyc-consent__accept">
        <input type="checkbox" class="form-check-input" id="kyc-consent-accept"
               name="verification_consent" value="1" required
               @checked(old('verification_consent'))>
        <label class="form-check-label" for="kyc-consent-accept">
            {{ __('I have read and agree to the statement above.') }}
        </label>
    </div>

    @error('verification_consent')
        <span class="kyc-consent__error">{{ $message }}</span>
    @enderror
</div>
