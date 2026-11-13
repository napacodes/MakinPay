{{--
    One dialog for every destructive path in this module. Delete, Flush from
    the Failed jobs toolbar and Flush from the header's ⋯ menu differ only in
    their copy and the form they post to, and each trigger carries both on
    `data-` attributes — a second modal was a second set of ids and strings to
    keep in step.

    It moved out of the Failed jobs tab when the header started opening it,
    because the header renders on all four.
--}}
<div class="modal fade bt-dialog" id="bt-confirm-modal" tabindex="-1" aria-labelledby="bt-confirm-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered bt-dialog__dialog bt-dialog__dialog--confirm">
        <div class="modal-content bt-dialog__content">
            <div class="bt-confirm">
                <span class="bt-confirm__tile" aria-hidden="true"><i class="fa-solid fa-trash"></i></span>
                <h2 class="bt-confirm__title" id="bt-confirm-title" data-bt-confirm-title></h2>
                <span class="bt-confirm__text" data-bt-confirm-body></span>
            </div>

            <form method="POST" action="" id="bt-confirm-form" class="bt-confirm__actions">
                @csrf
                <input type="hidden" name="_method" value="DELETE" data-bt-confirm-method>
                <button type="button" class="bt-btn-ghost" data-coreui-dismiss="modal">@lang('Cancel')</button>
                <button type="submit" class="bt-btn-destructive" data-bt-confirm-cta></button>
            </form>
        </div>
    </div>
</div>
