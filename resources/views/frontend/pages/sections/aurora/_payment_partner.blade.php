@php
	$locale = $locale ?? app()->getLocale();
	$names  = $repeatedContents
		->map(fn ($repeatedContent): string => trim((string) ($repeatedContent->content_data['gateway_name'] ?? '')))
		->filter()
		->values();
@endphp
@if($names->isNotEmpty())
	<section class="au-strip" aria-label="{{ $data['caption'][$locale] ?? __('Integrations') }}">
		<div class="au-container au-strip__inner">
			@if($data['caption'][$locale] ?? null)
				<span class="au-strip__caption">{{ $data['caption'][$locale] }}</span>
			@endif
			<ul class="au-strip__names">
				@foreach($names as $name)
					<li class="au-strip__name">{{ $name }}</li>
				@endforeach
			</ul>
		</div>
	</section>
@endif
