<footer class="dk-footer">
	<div class="dk-container">
		<div class="dk-footer__grid">
			@if(isset($footers) && $footers->isNotEmpty())
				@foreach($footers as $footer)
					<div>
						<h2 class="dk-footer__title">{{ $footer->title_text }}</h2>
						<ul class="dk-footer__list">
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

		<div class="dk-footer__base">
			<span>&copy; {{ date('Y') }} {{ setting('site_title') }}. {{ __('All rights reserved.') }}</span>

			@if(isset($socials) && $socials->isNotEmpty())
				<ul class="dk-footer__list" style="flex-direction:row;gap:16px">
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
