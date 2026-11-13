{{--
    Template builder — edit.

    The body is fetched over AJAX from admin.kyc.template.edit and dropped into
    #edit-template-form, so the markup lives in template/edit.blade.php.

    The header names the template being edited rather than the screen: the
    title and the last-saved line arrive on the body's form root and the
    builder script lifts them here once it lands.
--}}
<div class="modal fade" id="editTemplateModal" tabindex="-1" aria-labelledby="editTemplateLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content kyc-template-content">
            <div class="modal-header">
                <div>
                    <span class="kt-eyebrow">{{ __('KYC template') }}</span>
                    <h5 id="editTemplateLabel" class="modal-title" data-kt-modal-title>{{ __('Edit KYC template') }}</h5>
                </div>
                <span class="kt-saved ms-auto" data-kt-modal-saved></span>
                <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body p-0" id="edit-template-form"></div>
        </div>
    </div>
</div>
