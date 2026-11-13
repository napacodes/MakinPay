@php
	use App\Models\WalletEarnPlan;

	$locale = $locale ?? app()->getLocale();

	// Live plans — never seeded copy, so an edit in the admin panel shows here.
	$plans = WalletEarnPlan::query()->active()->orderBy('sort_order')->with('currency')->get();
@endphp

<section class="dk-section" id="earn">
	<div class="dk-container">
		<div class="dk-head" data-dk-reveal>
			@if($data['subheading'][$locale] ?? null)
				<span class="dk-eyebrow">{{ $data['subheading'][$locale] }}</span>
			@endif
			@if($data['heading'][$locale] ?? null)
				<h2>{{ $data['heading'][$locale] }}</h2>
			@endif
		</div>

		@if($plans->isEmpty())
			<x-user-not-found
				:title="__('No earn plans are live yet')"
				:message="__('Plans published from Wallet Earn appear here automatically.')"
				icon="fa-seedling" />
		@else
			<div class="dk-grid dk-grid--4">
				@foreach($plans as $plan)
					@php
						$code   = $plan->currency->code ?? __('Any wallet');
						$payout = $plan->payout_frequency?->value ?? '';
						$term   = trim($plan->duration_value.' '.$plan->duration_unit.($payout ? ' · '.__(ucfirst($payout)).' '.__('payout') : ''));
					@endphp
					<article class="dk-card dk-card--interactive dk-plan" data-dk-reveal style="--i: {{ $loop->index }}">
						<span class="dk-eyebrow">{{ $plan->name }}</span>
						<span class="dk-plan__apy">{{ feeRate($plan->profit_rate) }}%</span>
						<span class="dk-plan__meta">{{ __('APY · variable') }}</span>
						<span class="dk-plan__meta">{{ $code }} · {{ $term }}</span>
						@if($plan->description)
							<p style="font-size:.875rem">{{ $plan->description }}</p>
						@endif
					</article>
				@endforeach
			</div>
		@endif

		@if($data['disclosure'][$locale] ?? null)
			<p class="dk-disclosure">{{ $data['disclosure'][$locale] }}</p>
		@endif
	</div>
</section>
