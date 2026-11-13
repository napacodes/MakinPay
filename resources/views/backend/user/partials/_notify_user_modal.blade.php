@php
    $notifyFirst = $user->first_name ?: $user->username;
    $notifyTemplates = [
        ['label' => __('KYC reminder'), 'title' => __('Complete your verification'), 'message' => __("Hi :name,\n\nYour KYC documents are still pending. Please upload a clear photo of your ID to unlock full limits.", ['name' => $notifyFirst])],
        ['label' => __('Withdrawal update'), 'title' => __('Update on your withdrawal'), 'message' => __("Hi :name,\n\nYour withdrawal is being processed and should arrive within 24 hours.", ['name' => $notifyFirst])],
        ['label' => __('Security notice'), 'title' => __('New sign-in to your account'), 'message' => __('We noticed a new sign-in to your account. If this was not you, reset your password immediately.')],
        ['label' => __('Blank'), 'title' => '', 'message' => ''],
    ];
@endphp
<div class="modal fade um-modal um-modal--md" id="umNotifyModal" tabindex="-1" aria-labelledby="umNotifyTitle" aria-hidden="true"
     data-um-notify
     data-i18n-subject="{{ __('Subject') }}"
     data-i18n-title="{{ __('Title') }}"
     data-i18n-subject-ph="{{ __('e.g. Update on your withdrawal') }}"
     data-i18n-title-ph="{{ __('Notification from Admin') }}"
     data-i18n-hint-email="{{ __('Wrapped in the branded email template with logo and footer. Line breaks are preserved.') }}"
     data-i18n-hint-push="{{ __('Keep it short — long pushes are truncated in the feed. Delivered only if the user has notifications enabled.') }}"
     data-i18n-count-email="{{ __(':count characters', ['count' => '%s']) }}"
     data-i18n-count-push="{{ __(':count / 240 recommended', ['count' => '%s']) }}"
     data-i18n-preview-email="{{ __('Preview · email') }}"
     data-i18n-preview-push="{{ __('Preview · in-app') }}"
     data-i18n-pv-subject="{{ __('Your subject line') }}"
     data-i18n-pv-message="{{ __('Your message will appear here as :name will see it.', ['name' => $notifyFirst]) }}"
     data-i18n-ready-email="{{ __('Email to :email · sent immediately', ['email' => $user->email]) }}"
     data-i18n-ready-push="{{ __('In-app push to :name · delivered instantly', ['name' => $user->name]) }}"
     data-i18n-need-message="{{ __('Write a message to continue') }}"
     data-i18n-need-subject="{{ __('Add a subject for the email') }}"
     data-i18n-cta-email="{{ __('Send email') }}"
     data-i18n-cta-push="{{ __('Send push') }}">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('admin.notifications.notifyToUser.send') }}">
            @csrf
            <input type="hidden" name="user_id" value="{{ $user->id }}">
            <input type="hidden" name="notify_type" value="email" data-um-notify-type>

            <div class="um-modal__head">
                <span class="um-modal__tile"><x-icon name="notification"/></span>
                <div class="um-modal__heading">
                    <h3 class="um-modal__title" id="umNotifyTitle">{{ __('Notify :name', ['name' => $user->name]) }}</h3>
                    <p class="um-modal__sub">{{ __('Send a direct message by email or in-app push.') }}</p>
                </div>
                <button type="button" class="um-modal__close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}">×</button>
            </div>

            <div class="um-modal__body">
                <div class="um-group">
                    <span class="um-group__label">{{ __('Channel') }}</span>
                    <div class="um-options um-options--wide">
                        <button type="button" class="um-option um-option--row is-active" data-um-notify-channel="email">
                            <span class="um-tile um-tile--sm"><x-icon name="mail-notify"/></span>
                            <span class="um-option__copy"><span class="um-option__title">{{ __('Email') }}</span><span class="um-option__sub">{{ $user->email }}</span></span>
                        </button>
                        <button type="button" class="um-option um-option--row" data-um-notify-channel="push">
                            <span class="um-tile um-tile--sm"><x-icon name="push-notify"/></span>
                            <span class="um-option__copy"><span class="um-option__title">{{ __('In-app push') }}</span><span class="um-option__sub">{{ __('Notification feed · realtime') }}</span></span>
                        </button>
                    </div>
                </div>

                <div class="um-group">
                    <span class="um-group__label">{{ __('Template') }} <span class="is-optional">({{ __('optional') }})</span></span>
                    <div class="um-chips">
                        @foreach($notifyTemplates as $template)
                            <button type="button" class="um-chip" data-um-notify-template data-title="{{ $template['title'] }}" data-message="{{ $template['message'] }}">{{ $template['label'] }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="um-group">
                    <span class="um-group__label"><span><span data-um-notify-title-label>{{ __('Subject') }}</span> <span class="is-optional um-hidden" data-um-notify-title-optional>({{ __('optional') }})</span></span><span class="um-group__meta" data-um-notify-title-count>0 / 255</span></span>
                    <input type="text" name="title" class="um-input um-input--lg" maxlength="255" placeholder="{{ __('e.g. Update on your withdrawal') }}" data-um-notify-title>
                </div>

                <div class="um-group">
                    <span class="um-group__label"><span>{{ __('Message') }} <span class="is-required">*</span></span><span class="um-group__meta" data-um-notify-message-count></span></span>
                    <textarea name="message" rows="5" class="um-input um-input--tall" required placeholder="{{ __('Write the message :name will see…', ['name' => $notifyFirst]) }}" data-um-notify-message></textarea>
                    <span class="um-field__hint" data-um-notify-hint></span>
                </div>

                <div class="um-preview">
                    <span class="um-preview__kind" data-um-notify-preview-kind></span>
                    <div class="um-preview__push um-hidden" data-um-notify-preview-push>
                        <span class="um-tile"><x-icon name="push-notify"/></span>
                        <span class="um-option__copy">
                            <span class="um-preview__line"><span class="um-preview__title" data-um-pv-push-title></span><span class="um-preview__now">{{ __('now') }}</span></span>
                            <span class="um-preview__text" data-um-pv-push-text></span>
                        </span>
                    </div>
                    <div class="um-preview__email" data-um-notify-preview-email>
                        <div class="um-preview__subject"><span>{{ __('Subject') }}</span><span data-um-pv-mail-subject></span></div>
                        <div class="um-preview__brand">{{ setting('site_title', config('app.name')) }}</div>
                        <div class="um-preview__mail-body"><span class="um-preview__mail-title" data-um-pv-mail-title></span><span class="um-preview__mail-text" data-um-pv-mail-text></span></div>
                    </div>
                </div>
            </div>

            <div class="um-modal__foot">
                <span class="um-readiness" data-um-notify-ready><span class="um-readiness__dot"></span><span class="um-readiness__text" data-um-notify-ready-text></span></span>
                <button type="button" class="um-btn" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="um-btn um-btn--primary" disabled data-um-notify-submit><x-icon name="send"/><span data-um-notify-cta>{{ __('Send email') }}</span></button>
            </div>
        </form>
    </div>
</div>
