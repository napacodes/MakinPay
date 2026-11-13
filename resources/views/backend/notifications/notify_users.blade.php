@extends('backend.layouts.app')

@section('title', __('Notify users'))

{{--
	A guided composer in four steps — audience, channel, message, delivery —
	with a live preview of what recipients will see and a confirm sheet
	before anything is queued.

	The form posts exactly what the bare form did: user_type, notify_type,
	title, message, schedule_at. The option cards are real radio inputs and
	the schedule field is a real datetime-local, so the page submits correctly
	before notify-users.js has run; the script only keeps the preview, the
	counters and the readiness line honest, and asks once before sending.

	Block @php only in this file. Blade matches an inline @php( with the block
	pattern, so one inline directive above a block's @endphp swallows every
	line between them and the view dies on the next @endforeach.
--}}
@push('styles')
	@php
		$notifyCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/notify-users.css'));
	@endphp
	<link rel="stylesheet" href="{{ asset('backend/css/notify-users.css?v='.$notifyCssVersion) }}">
@endpush

@push('scripts')
	@php
		$notifyJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/notify-users.js'));
	@endphp
	<script src="{{ asset('backend/js/notify-users.js?v='.$notifyJsVersion) }}"></script>
@endpush

@section('content')
	@php
		$defaultAudience = old('user_type', 'all');
		$defaultChannel = old('notify_type', 'email');
		$defaultSchedule = old('schedule_at', '');
		$defaultTiming = $defaultSchedule !== '' ? 'schedule' : 'now';
	@endphp

	<div class="nu-page">
		<x-page-header
			:title="__('Notify users')"
			:subtitle="__('Send an announcement by email or in-app push to a group of accounts.')"
			icon="notification"
			tile="soft"
			{{--
				The composer card below is the page. Board 2g drops the
				header's own card here, so the screen carries one surface
				instead of two stacked ones.
			--}}
			variant="compact">
			<x-slot:actions>
				<a href="{{ route('admin.notifications.template.index') }}" class="nu-btn nu-btn--secondary">{{ __('Manage templates') }}</a>
			</x-slot:actions>
		</x-page-header>

		<div class="nu-layout">
			<form class="nu-composer"
			      method="POST"
			      action="{{ route('admin.notifications.notifyToUser.send') }}"
			      novalidate
			      data-notify-users
			      data-pusher="{{ $pusherEnabled ? '1' : '0' }}"
			      {{-- Wording is translated here, not assembled in JavaScript. --}}
			      data-label-summary="{{ __(':channel to :count :audience · :when') }}"
			      data-label-channel-email="{{ __('Email') }}"
			      data-label-channel-push="{{ __('Push') }}"
			      data-label-now="{{ __('Immediately') }}"
			      data-label-need-message="{{ __('Write a message to continue') }}"
			      data-label-need-title="{{ __('Add a title for the email subject') }}"
			      data-label-need-time="{{ __('Pick a future date and time') }}"
			      data-label-cta-send="{{ __('Send notification') }}"
			      data-label-cta-schedule="{{ __('Schedule send') }}"
			      data-label-title-count="{{ __(':count / 255') }}"
			      data-label-message-count="{{ __(':count characters') }}"
			      data-label-push-count="{{ __(':count / 240 recommended') }}"
			      data-label-optional="{{ __('(optional)') }}"
			      data-label-placeholder-email="{{ __('e.g. Scheduled maintenance on Saturday') }}"
			      data-label-placeholder-push="{{ __('Notification from Admin') }}"
			      data-label-preview-title="{{ __('Your subject line') }}"
			      data-label-preview-message="{{ __('Your message will appear here as recipients will see it.') }}"
			      data-label-kind-email="{{ __('Email') }}"
			      data-label-kind-push="{{ __('In-app') }}"
			      data-label-audience-row="{{ __(':label · :count active') }}"
			      data-label-row-email="{{ __('Email') }}"
			      data-label-row-push="{{ __('In-app push') }}"
			      data-label-row-push-realtime="{{ __('In-app push (realtime)') }}"
			      data-label-content-email="{{ __('Subject + message ready') }}"
			      data-label-content-push="{{ __('Push ready') }}"
			      data-label-content-missing="{{ __('Message missing') }}"
			      data-label-confirm-send="{{ __('Send to :count :audience?') }}"
			      data-label-confirm-schedule="{{ __('Schedule this notification?') }}"
			      data-label-confirm-body-email="{{ __('An email is queued for every active account in this audience. This cannot be recalled once queued.') }}"
			      data-label-confirm-body-push="{{ __('Only accounts with notifications enabled will receive it. This cannot be recalled once queued.') }}"
			      data-label-confirm-cta-send="{{ __('Yes, send now') }}"
			      data-label-confirm-cta-schedule="{{ __('Confirm schedule') }}"
			      data-label-processing="{{ __('Processing…') }}"
			      data-label-time-ok="{{ __('You can cancel a scheduled send from Background Tasks.') }}"
			      data-label-time-bad="{{ __('Choose a time in the future.') }}">
				@csrf

				{{-- 1. Audience --}}
				<section class="nu-step">
					<div class="nu-step__head">
						<h2 class="nu-step__title"><span class="nu-step__n" aria-hidden="true">1</span>{{ __('Audience') }}</h2>
						<span class="nu-step__aside">{!! __('Only :active accounts receive messages', ['active' => '<strong>'.__('active').'</strong>']) !!}</span>
					</div>
					<div class="nu-options nu-options--audience" role="radiogroup" aria-label="{{ __('Audience') }}">
						@foreach($audiences as $audience)
							<label class="nu-option nu-option--stack">
								<input type="radio"
								       class="nu-option__input"
								       name="user_type"
								       value="{{ $audience['value'] }}"
								       data-nu-audience
								       data-label="{{ $audience['label'] }}"
								       data-noun="{{ $audience['noun'] }}"
								       data-count="{{ $audience['count'] }}"
								       @checked($defaultAudience === $audience['value'])>
								<span class="nu-option__row">
									<span class="nu-option__label">{{ $audience['label'] }}</span>
									<span class="nu-radio" aria-hidden="true"></span>
								</span>
								<span class="nu-option__meta"><strong dir="ltr">{{ number_format($audience['count']) }}</strong> {{ __('active') }}</span>
							</label>
						@endforeach
					</div>
					@error('user_type')
						<p class="nu-error">{{ $message }}</p>
					@enderror
				</section>

				{{-- 2. Channel --}}
				<section class="nu-step">
					<div class="nu-step__head">
						<h2 class="nu-step__title"><span class="nu-step__n" aria-hidden="true">2</span>{{ __('Channel') }}</h2>
					</div>
					<div class="nu-options nu-options--channel" role="radiogroup" aria-label="{{ __('Channel') }}">
						<label class="nu-option nu-option--channel">
							<input type="radio" class="nu-option__input" name="notify_type" value="email" data-nu-channel @checked($defaultChannel === 'email')>
							<span class="nu-option__tile" aria-hidden="true"><x-icon name="mail-notify" height="20" width="20" class="icon-custom-size"/></span>
							<span class="nu-option__copy">
								<span class="nu-option__label">{{ __('Email') }}</span>
								<span class="nu-option__desc">{{ __('Branded email to each inbox. Title becomes the subject.') }}</span>
							</span>
							<span class="nu-radio" aria-hidden="true"></span>
						</label>
						<label class="nu-option nu-option--channel">
							<input type="radio" class="nu-option__input" name="notify_type" value="push" data-nu-channel @checked($defaultChannel === 'push')>
							<span class="nu-option__tile" aria-hidden="true"><x-icon name="push-notify" height="20" width="20" class="icon-custom-size"/></span>
							<span class="nu-option__copy">
								<span class="nu-option__label">{{ __('In-app push') }}</span>
								<span class="nu-option__desc">{{ $pusherEnabled ? __('Notification feed with realtime delivery. Respects user preferences.') : __('Notification feed. Respects user preferences.') }}</span>
							</span>
							<span class="nu-radio" aria-hidden="true"></span>
						</label>
					</div>
					<div class="nu-note {{ $pusherEnabled ? 'is-on' : 'is-off' }}" data-nu-push-note hidden>
						<span class="nu-note__dot" aria-hidden="true"></span>
						{{ $pusherEnabled
							? __('Realtime delivery is on — users see it instantly; it also lands in their notification feed.')
							: __('Realtime (Pusher) is off — the message will appear the next time users open the app.') }}
					</div>
					@error('notify_type')
						<p class="nu-error">{{ $message }}</p>
					@enderror
				</section>

				{{-- 3. Message --}}
				<section class="nu-step nu-step--message">
					<div class="nu-step__head">
						<h2 class="nu-step__title"><span class="nu-step__n" aria-hidden="true">3</span>{{ __('Message') }}</h2>
					</div>
					<div class="nu-field">
						<div class="nu-field__head">
							<label class="nu-field__label" for="nu-title">{{ __('Title') }} <span class="nu-field__hint" data-nu-title-hint></span></label>
							<span class="nu-field__count" data-nu-title-count dir="ltr"></span>
						</div>
						<input type="text"
						       id="nu-title"
						       name="title"
						       class="nu-input"
						       maxlength="255"
						       autocomplete="off"
						       value="{{ old('title') }}"
						       placeholder="{{ __('e.g. Scheduled maintenance on Saturday') }}"
						       data-nu-title>
						@error('title')
							<p class="nu-error">{{ $message }}</p>
						@enderror
					</div>
					<div class="nu-field">
						<div class="nu-field__head">
							<label class="nu-field__label" for="nu-message">{{ __('Message') }} <span class="nu-field__required" aria-hidden="true">*</span></label>
							<span class="nu-field__count" data-nu-message-count dir="ltr"></span>
						</div>
						<textarea id="nu-message"
						          name="message"
						          class="nu-input nu-input--area"
						          rows="6"
						          required
						          placeholder="{{ __('Write the message your audience will read. Plain text; line breaks are kept.') }}"
						          data-nu-message>{{ old('message') }}</textarea>
						<p class="nu-field__help" data-nu-hint="email">{{ __('The message is wrapped in the branded email template with your logo and footer. Line breaks are preserved.') }}</p>
						<p class="nu-field__help" data-nu-hint="push" hidden>{{ __('Keep it short — long pushes are truncated in the feed. Use the email channel for detailed announcements.') }}</p>
						@error('message')
							<p class="nu-error">{{ $message }}</p>
						@enderror
					</div>
				</section>

				{{-- 4. Delivery --}}
				<section class="nu-step nu-step--last">
					<div class="nu-step__head">
						<h2 class="nu-step__title"><span class="nu-step__n" aria-hidden="true">4</span>{{ __('Delivery') }}</h2>
					</div>
					{{--
						Timing is a page choice the server never reads — "now" is an empty
						schedule_at — but radios only exclude each other when they share a
						name, so it has one.
					--}}
					<div class="nu-options nu-options--timing" role="radiogroup" aria-label="{{ __('Delivery') }}">
						<label class="nu-option nu-option--row">
							<input type="radio" class="nu-option__input" name="timing" value="now" data-nu-timing @checked($defaultTiming === 'now')>
							<span class="nu-option__copy">
								<span class="nu-option__label">{{ __('Send now') }}</span>
								<span class="nu-option__desc">{{ __('Queued immediately') }}</span>
							</span>
							<span class="nu-radio" aria-hidden="true"></span>
						</label>
						<label class="nu-option nu-option--row">
							<input type="radio" class="nu-option__input" name="timing" value="schedule" data-nu-timing @checked($defaultTiming === 'schedule')>
							<span class="nu-option__copy">
								<span class="nu-option__label">{{ __('Schedule') }}</span>
								<span class="nu-option__desc">{{ __('Pick a date and time') }}</span>
							</span>
							<span class="nu-radio" aria-hidden="true"></span>
						</label>
					</div>
					<div class="nu-schedule" data-nu-schedule-panel @if($defaultTiming !== 'schedule') hidden @endif>
						<div class="nu-field">
							<label class="nu-field__label" for="nu-schedule-at">{{ __('Send on') }}</label>
							<input type="datetime-local"
							       id="nu-schedule-at"
							       name="schedule_at"
							       class="nu-input nu-input--date"
							       min="{{ $minSchedule }}"
							       value="{{ $defaultSchedule }}"
							       data-nu-schedule-at
							       dir="ltr"
							       @disabled($defaultTiming !== 'schedule')>
						</div>
						<p class="nu-schedule__note">
							{!! __('Times use the site timezone (:timezone).', ['timezone' => '<strong>'.e($timezone).'</strong>']) !!}
							<span data-nu-schedule-note>{{ __('You can cancel a scheduled send from Background Tasks.') }}</span>
						</p>
					</div>
					@error('schedule_at')
						<p class="nu-error">{{ $message }}</p>
					@enderror
				</section>

				<footer class="nu-foot">
					<div class="nu-foot__status">
						<span class="nu-foot__dot" data-nu-ready-dot aria-hidden="true"></span>
						<span data-nu-summary-line aria-live="polite"></span>
					</div>
					<div class="nu-foot__actions">
						<button type="button" class="nu-btn nu-btn--secondary" data-nu-clear>{{ __('Clear') }}</button>
						<button type="submit" class="nu-btn nu-btn--primary" data-nu-submit>
							<x-icon name="send" height="15" width="15" class="icon-custom-size"/>
							<span data-nu-cta>{{ __('Send notification') }}</span>
						</button>
					</div>
				</footer>

				{{-- Confirm sheet: the last look before anything is queued. --}}
				<div class="nu-sheet" data-nu-sheet hidden>
					<div class="nu-sheet__dialog" role="dialog" aria-modal="true" aria-labelledby="nu-confirm-title">
						<div class="nu-sheet__head">
							<h3 class="nu-sheet__title" id="nu-confirm-title" data-nu-confirm-title></h3>
							<p class="nu-sheet__body" data-nu-confirm-body></p>
						</div>
						<dl class="nu-sheet__rows">
							<div class="nu-sheet__row"><dt>{{ __('Audience') }}</dt><dd data-nu-confirm="audience"></dd></div>
							<div class="nu-sheet__row"><dt>{{ __('Channel') }}</dt><dd data-nu-confirm="channel"></dd></div>
							<div class="nu-sheet__row"><dt>{{ __('Content') }}</dt><dd data-nu-confirm="content"></dd></div>
							<div class="nu-sheet__row"><dt>{{ __('Timing') }}</dt><dd data-nu-confirm="timing"></dd></div>
						</dl>
						<div class="nu-sheet__actions">
							<button type="button" class="nu-btn nu-btn--secondary" data-nu-sheet-close>{{ __('Back') }}</button>
							<button type="button" class="nu-btn nu-btn--primary" data-nu-confirm-submit>{{ __('Yes, send now') }}</button>
						</div>
					</div>
				</div>
			</form>

			<aside class="nu-rail">
				<div class="nu-card">
					<div class="nu-card__head">
						<span class="nu-card__title">{{ __('Live preview') }}</span>
						<span class="nu-card__kind" data-nu-preview-kind>{{ __('Email') }}</span>
					</div>
					<div class="nu-preview">
						<div class="nu-mail" data-nu-preview="email">
							<div class="nu-mail__subject">
								<span class="nu-mail__subject-label">{{ __('Subject') }}</span>
								<span class="nu-mail__subject-text" data-nu-preview-title="email">{{ __('Your subject line') }}</span>
							</div>
							<div class="nu-mail__band">{{ $siteTitle }}</div>
							<div class="nu-mail__body">
								<h4 class="nu-mail__title" data-nu-preview-title="email">{{ __('Your subject line') }}</h4>
								<p class="nu-mail__text" data-nu-preview-message>{{ __('Your message will appear here as recipients will see it.') }}</p>
							</div>
							<div class="nu-mail__foot">{{ __('Automated message from :site. If you did not expect it, please ignore.', ['site' => $siteTitle]) }}</div>
						</div>
						<div class="nu-push" data-nu-preview="push" hidden>
							<span class="nu-push__tile" aria-hidden="true"><x-icon name="push-notify" height="18" width="18" class="icon-custom-size"/></span>
							<div class="nu-push__copy">
								<div class="nu-push__head">
									<span class="nu-push__title" data-nu-preview-title="push">{{ __('Notification from Admin') }}</span>
									<span class="nu-push__time">{{ __('now') }}</span>
								</div>
								<p class="nu-push__text" data-nu-preview-message>{{ __('Your message will appear here as recipients will see it.') }}</p>
								<span class="nu-push__link">{{ __('View notification') }}</span>
							</div>
						</div>
					</div>
				</div>

				<div class="nu-card nu-summary">
					<span class="nu-card__title">{{ __('Summary') }}</span>
					<div class="nu-check is-ok" data-nu-check="audience">
						<span class="nu-check__mark" aria-hidden="true"><x-icon name="check" height="10" width="10" class="icon-custom-size"/></span>
						<span class="nu-check__copy"><span class="nu-check__label">{{ __('Audience') }}</span><span class="nu-check__value" data-nu-check-value></span></span>
					</div>
					<div class="nu-check is-ok" data-nu-check="channel">
						<span class="nu-check__mark" aria-hidden="true"><x-icon name="check" height="10" width="10" class="icon-custom-size"/></span>
						<span class="nu-check__copy"><span class="nu-check__label">{{ __('Channel') }}</span><span class="nu-check__value" data-nu-check-value></span></span>
					</div>
					<div class="nu-check" data-nu-check="content">
						<span class="nu-check__mark" aria-hidden="true"><x-icon name="check" height="10" width="10" class="icon-custom-size"/></span>
						<span class="nu-check__copy"><span class="nu-check__label">{{ __('Content') }}</span><span class="nu-check__value" data-nu-check-value></span></span>
					</div>
					<div class="nu-check is-ok" data-nu-check="timing">
						<span class="nu-check__mark" aria-hidden="true"><x-icon name="check" height="10" width="10" class="icon-custom-size"/></span>
						<span class="nu-check__copy"><span class="nu-check__label">{{ __('Timing') }}</span><span class="nu-check__value" data-nu-check-value></span></span>
					</div>
				</div>
			</aside>
		</div>
	</div>
@endsection
