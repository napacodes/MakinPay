@php
	$locale = $locale ?? app()->getLocale();

	$tiles = collect(['one', 'two', 'three', 'four', 'five', 'six'])
		->map(function (string $slot) use ($data, $locale): ?array {
			$item = $data['item_'.$slot][$locale] ?? null;

			if (! $item) {
				return null;
			}

			$parts = array_map('trim', explode(':', $item, 2));

			return ['icon' => ($data['icon_'.$slot] ?? null) ?: 'fa-solid fa-shield-halved', 'title' => $parts[0], 'text' => $parts[1] ?? ''];
		})
		->filter()
		->values();
@endphp
<section class="au-section au-security" id="security">
	<div class="au-container au-security__grid">
		<div class="au-security__intro" data-au-reveal>
			@if($data['subheading'][$locale] ?? null)
				<span class="au-eyebrow">{{ $data['subheading'][$locale] }}</span>
			@endif
			@if($data['heading'][$locale] ?? null)
				<h2 class="au-h2">{{ $data['heading'][$locale] }}</h2>
			@endif
			@if($data['description'][$locale] ?? null)
				<p class="au-lead">{{ $data['description'][$locale] }}</p>
			@endif
		</div>

		@if($tiles->isNotEmpty())
			<ul class="au-tiles">
				@foreach($tiles as $tile)
					<li class="au-tile" data-au-reveal style="--i: {{ $loop->index % 2 }}">
						<i class="{{ $tile['icon'] }}" aria-hidden="true"></i>
						<h3 class="au-tile__title">{{ $tile['title'] }}</h3>
						@if($tile['text'] !== '')
							<p class="au-tile__text">{{ $tile['text'] }}</p>
						@endif
					</li>
				@endforeach
			</ul>
		@endif
	</div>
</section>
