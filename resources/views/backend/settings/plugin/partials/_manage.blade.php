{{--
    The shell only. Everything inside #edit-append is fetched from
    PluginController@edit by editFormByModal(), so the header that names the
    plugin has to come back with the form rather than live out here — there is
    one container for the response to fill, and the design puts the provider's
    logo, group and state in the header.

    The close control stays in the shell so the dialog can always be
    dismissed, including while the request is still in flight.
--}}
<div class="modal fade settings-plugin-modal" id="manageModal" aria-hidden="true" aria-labelledby="pluginManageModalLabel" tabindex="-1">
    {{-- Not modal-dialog-scrollable: that expects .modal-header and
         .modal-body as direct children of .modal-content, and here they
         arrive a level deeper inside the AJAX container, so the class did
         nothing and the dialog grew past the viewport. It scrolls its own
         body instead. --}}
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <button type="button" class="settings-plugin-modal__close settings-plugin-modal__close--floating"
                    data-coreui-dismiss="modal" aria-label="{{ __('Close') }}">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
            <div id="edit-append" class="settings-plugin-modal__shell"></div>
        </div>
    </div>
</div>
