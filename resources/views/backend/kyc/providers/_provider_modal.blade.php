@php
    $modalId = 'manage-kyc-provider-'.str_replace('_', '-', $entry['driver']);
    $isConfigured = $entry['provider'] !== null;
    $provider = $entry['form_provider'];
@endphp

<div class="modal fade pgm-modal kpm-modal" id="{{ $modalId }}" tabindex="-1" role="dialog"
     aria-labelledby="{{ $modalId }}-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl" role="document">
        <div class="modal-content pgm-modal__content kpm-modal__content">
            <div class="modal-header pgm-modal__header kpm-modal__header">
                <div class="pgm-modal__title">
                    <span class="pgm-modal__icon">
                        <i class="fa-solid fa-fingerprint" aria-hidden="true"></i>
                    </span>
                    <div>
                        <span class="kpm-modal__eyebrow">{{ __('KYC Provider') }}</span>
                        <h2 class="modal-title" id="{{ $modalId }}-title">
                            {{ $isConfigured ? __('Manage :name', ['name' => $entry['name']]) : __('Connect :name', ['name' => $entry['name']]) }}
                        </h2>
                        <p>{{ __('Configure coverage, secure credentials, and verification routing in one place.') }}</p>
                    </div>
                </div>
                <button type="button" class="pgm-modal__close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
            <div class="modal-body pgm-modal__body kpm-modal__body">
                @include('backend.kyc.providers._form', [
                    'action'    => $isConfigured
                        ? route('admin.kyc.providers.update', $provider)
                        : route('admin.kyc.providers.store'),
                    'method'    => $isConfigured ? 'PUT' : 'POST',
                    'provider'  => $provider,
                    'catalog'   => (array) config('kyc.catalog', []),
                    'countries' => $countries,
                    'modal'     => true,
                ])
            </div>
        </div>
    </div>
</div>
