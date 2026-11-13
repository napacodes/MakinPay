@php
    $smtpTestFromAddress = (string) config('mail.from.address');
    $smtpTestMailer      = (string) config('mail.default');
    $smtpTestHost        = (string) config('mail.mailers.'.$smtpTestMailer.'.host', '');
    $smtpTestRecipient   = auth('admin')->user()?->email ?: $smtpTestFromAddress;
@endphp

<button type="button"
        class="btn btn-primary settings-site-header-action settings-site-test-btn"
        data-coreui-toggle="modal"
        data-coreui-target="#smtpTestModal">
	<x-icon name="cil-send" height="18" width="18"/> {{ __('Send Test Email') }}
</button>

{{--
	Rendered through the stack, not here: this partial is included inside the
	sticky section head, whose backdrop-filter would become this dialog's
	containing block.
--}}
@push('settings-site-modals')
<div class="modal fade settings-smtp-modal"
     id="smtpTestModal"
     tabindex="-1"
     aria-labelledby="smtpTestModalLabel"
     aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header settings-smtp-modal__header">
				<div class="settings-smtp-modal__title">
                    <span class="settings-smtp-modal__icon">
                        <i class="fas fa-paper-plane"></i>
                    </span>
					<div>
						<span class="settings-smtp-modal__eyebrow">{{ __('Mail Delivery Check') }}</span>
						<h5 class="modal-title" id="smtpTestModalLabel">{{ __('Send a Test Email') }}</h5>
					</div>
				</div>
				<button type="button"
				        class="settings-maintenance-modal__close"
				        data-coreui-dismiss="modal"
				        aria-label="{{ __('Close') }}">
					<x-icon name="close" height="18" width="18"/>
				</button>
			</div>
			<div class="modal-body settings-smtp-modal__body">
				<p class="settings-smtp-modal__intro">
					{{ __('Delivers a real email using the saved mail settings, so you can confirm outgoing mail works end to end.') }}
					<strong>{{ __('Unsaved changes on this page are not included — save first, then test.') }}</strong>
				</p>

				<div class="settings-smtp-modal__facts">
                    <span>
                        <i class="far fa-envelope" aria-hidden="true"></i>
                        <strong>{{ __('From') }}</strong>
                        <code>{{ $smtpTestFromAddress ?: __('not set') }}</code>
                    </span>
					<span>
                        <i class="fas fa-server" aria-hidden="true"></i>
                        <strong>{{ __('Server') }}</strong>
                        <code>{{ $smtpTestMailer }}{{ $smtpTestHost !== '' ? ' — '.$smtpTestHost : '' }}</code>
                    </span>
				</div>

				<div class="mb-3">
					<label class="form-label" for="smtpTestRecipient">{{ __('Deliver To') }}</label>
					<input type="email"
					       class="form-control"
					       id="smtpTestRecipient"
					       name="test_email"
					       value="{{ $smtpTestRecipient }}"
					       placeholder="name@example.com"
					       autocomplete="email"
					       required>
					<div class="form-text">{{ __('Use an inbox you can open right now — your own email is a good choice.') }}</div>
				</div>

				<div class="settings-smtp-modal__result settings-smtp-modal__result--success d-none" data-smtp-test-success role="status">
                    <span class="settings-smtp-modal__result-icon"><i class="fas fa-circle-check"></i></span>
					<div>
						<strong>{{ __('Delivered to the mail server') }}</strong>
						<p data-smtp-test-success-text></p>
					</div>
				</div>

				<div class="settings-smtp-modal__result settings-smtp-modal__result--danger d-none" data-smtp-test-failure role="alert">
                    <span class="settings-smtp-modal__result-icon"><i class="fas fa-triangle-exclamation"></i></span>
					<div>
						<strong>{{ __('Sending failed') }}</strong>
						<p data-smtp-test-failure-text></p>
						<code class="d-none" data-smtp-test-failure-detail></code>
					</div>
				</div>
			</div>
			<div class="modal-footer settings-smtp-modal__footer">
				<button type="button" class="btn btn-light" data-coreui-dismiss="modal">
					<x-icon name="close-1" height="18" width="18"/>
					{{ __('Close') }}
				</button>
				<button type="button"
				        class="btn btn-primary settings-site-header-action"
				        data-smtp-test-send>
					<i class="fas fa-paper-plane"></i>
					<span data-smtp-test-send-label>{{ __('Send Test Email') }}</span>
				</button>
			</div>
		</div>
	</div>
</div>
@endpush

@push('scripts')
	<script>
        "use strict";

        $(document).ready(function () {
            const $modal = $('#smtpTestModal');
            const $recipient = $('#smtpTestRecipient');
            const $sendButton = $('[data-smtp-test-send]');
            const $sendLabel = $('[data-smtp-test-send-label]');
            const $success = $('[data-smtp-test-success]');
            const $successText = $('[data-smtp-test-success-text]');
            const $failure = $('[data-smtp-test-failure]');
            const $failureText = $('[data-smtp-test-failure-text]');
            const $failureDetail = $('[data-smtp-test-failure-detail]');

            function resetResults() {
                $success.addClass('d-none');
                $failure.addClass('d-none');
                $failureDetail.addClass('d-none').text('');
                $recipient.removeClass('is-invalid');
            }

            function setSending(isSending) {
                $sendButton.prop('disabled', isSending).attr('aria-busy', isSending ? 'true' : null);
                $sendButton.find('i')
                    .toggleClass('fa-paper-plane', !isSending)
                    .toggleClass('fa-spinner fa-spin', isSending);
                $sendLabel.text(isSending ? '{{ __('Sending…') }}' : '{{ __('Send Test Email') }}');
            }

            $modal.on('shown.coreui.modal shown.bs.modal', function () {
                resetResults();
                $recipient.trigger('focus');
            });

            $sendButton.on('click', function () {
                resetResults();

                const recipientInput = $recipient.get(0);
                if (!recipientInput.checkValidity()) {
                    $recipient.addClass('is-invalid');
                    recipientInput.reportValidity();
                    return;
                }

                setSending(true);

                $.ajax({
                    url: '{{ route('admin.app.smtp-test-email') }}',
                    type: 'POST',
                    dataType: 'json',
                    contentType: 'application/json',
                    data: JSON.stringify({ test_email: $recipient.val() }),
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    success: function (data) {
                        setSending(false);
                        $successText.text(data.message);
                        $success.removeClass('d-none');
                        notifyEvs('success', data.message);
                    },
                    error: function (xhr) {
                        setSending(false);

                        const response = xhr.responseJSON || {};

                        if (xhr.status === 422) {
                            $recipient.addClass('is-invalid');
                            $failureText.text(response.message || '{{ __('Please enter a valid email address.') }}');
                        } else {
                            $failureText.text(response.message || '{{ __('Unexpected error occurred.') }}');
                            if (response.error) {
                                $failureDetail.removeClass('d-none').text(response.error);
                            }
                        }

                        $failure.removeClass('d-none');
                    }
                });
            });
        });
	</script>
@endpush
