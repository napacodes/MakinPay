@php
	$auroraFooterMark  = setting('small_logo', 'general/static/logo/digikash-mark.svg') ?: 'general/static/logo/digikash-mark.svg';
	$auroraFooterClass = $auroraFooterMark === 'general/static/logo/digikash-mark.svg' ? 'au-brand__mark' : 'au-brand__mark au-brand__mark--tile';
@endphp
<footer class="au-footer">
	<div class="au-container au-footer__inner">
		<div class="au-footer__grid">
			<div class="au-footer__brand">
				<a href="{{ route('home') }}" class="au-brand">
					<img src="{{ asset($auroraFooterMark) }}" alt="" class="{{ $auroraFooterClass }}" width="36" height="36">
					<span class="au-brand__name">{{ setting('site_title') }}</span>
				</a>
				<p class="au-footer__about">{{ __('The complete digital-wallet platform for users, merchants and agents.') }}</p>
			</div>

			@if(isset($footers) && $footers->isNotEmpty())
				@foreach($footers as $footer)
					<div>
						<h2 class="au-footer__title">{{ $footer->title_text }}</h2>
						<ul class="au-footer__list">
							@foreach($footer->items as $item)
								<li>
									@if($item->resolved_url)
										<a href="{{ $item->resolved_url }}" target="{{ $item->target }}">{{ $item->label_text }}</a>
									@else
										<span>{{ $item->content_text ?: $item->label_text }}</span>
									@endif
								</li>
							@endforeach
						</ul>
					</div>
				@endforeach
			@endif
		</div>

		<div class="au-footer__base">
			<span>&copy; {{ date('Y') }} {{ setting('site_title') }}. {{ __('All rights reserved.') }}</span>

			@if(isset($socials) && $socials->isNotEmpty())
				<ul class="au-socials">
					@foreach($socials as $social)
						<li>
							<a href="{{ $social->url }}" target="{{ $social->target }}" aria-label="{{ $social->name }}">
								<i class="{{ $social->icon_class }}" aria-hidden="true"></i>
							</a>
						</li>
					@endforeach
				</ul>
			@endif
		</div>
	</div>
</footer>
