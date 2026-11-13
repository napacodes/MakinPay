<section class="au-section">
	<div class="au-container">
		<div class="au-prose">
			{!! $data['content'][$locale ?? app()->getLocale()] ?? '' !!}
		</div>
	</div>
</section>
