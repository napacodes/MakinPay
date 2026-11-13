{{--
    Refund confirmation.

    A second surface rather than an inline form: a refund moves money out of a
    merchant's wallet and is not undone by closing a panel, so it gets its own
    deliberate confirm step.

    `max` is written by the script from `refunds.refundable`. It is a courtesy
    to the operator, not a control — the authoritative ceiling is checked by
    `MerchantRefundService` under a row lock, and this input is trivially
    editable in the inspector.
--}}
<div class="admin-trx-refund" data-trx-refund hidden>
    <div class="admin-trx-refund__scrim" data-trx-refund-close></div>

    <form class="admin-trx-refund__panel" role="dialog" aria-modal="true" aria-labelledby="trx-refund-title" data-trx-refund-form>
        <h2 class="admin-trx-refund__title" id="trx-refund-title">{{ __('Issue refund') }}</h2>
        <p class="admin-trx-refund__sub" data-d="refund_modal_hint"></p>

        <label class="admin-trx-detail__label" for="trx-refund-amount">{{ __('Refund amount') }}</label>
        <input class="form-control" id="trx-refund-amount" name="amount" type="number" step="any" min="0" required
               inputmode="decimal" data-trx-refund-amount>

        <label class="admin-trx-detail__label" for="trx-refund-reason">{{ __('Reason') }}</label>
        <input class="form-control" id="trx-refund-reason" name="reason" type="text" maxlength="255" data-trx-refund-reason>

        <p class="admin-trx-refund__error" data-d="refund_error" role="alert" hidden></p>

        <div class="admin-trx-refund__buttons">
            <button type="button" class="btn btn-light" data-trx-refund-close>{{ __('Cancel') }}</button>
            <button type="submit" class="btn btn-warning">{{ __('Confirm refund') }}</button>
        </div>
    </form>
</div>
