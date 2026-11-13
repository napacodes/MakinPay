<section class="dk-section">
	<div class="dk-container">
		<div class="dk-card dk-card--panel" style="padding:clamp(24px,4vw,48px)">
			{!! $data['content'][$locale ?? app()->getLocale()] ?? '' !!}
		</div>
	</div>
</section>
