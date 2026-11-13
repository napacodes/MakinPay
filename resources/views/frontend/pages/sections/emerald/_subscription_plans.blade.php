@php
	use App\Enums\BillingCycle;
	use App\Models\SubscriptionPlan;

	$locale = $locale ?? app()->getLocale();

	// Live plans, prices and feature rows — never seeded copy.
	$plans = SubscriptionPlan::query()
		->where('status', true)
		->orderBy('sort_order')
		->with(['features', 'prices'])
		->get();

	$allPrices    = $plans->flatMap->prices;
	$halfDiscount = (int) ($allPrices->where('billing_cycle', BillingCycle::HalfYearly)->max('discount') ?: 0);
	$yearDiscount = (int) ($allPrices->where('billing_cycle', BillingCycle::Yearly)->max('discount') ?: 0);
	$currencyCode = siteCurrency('code');
	$currency     = siteCurrency('symbol') ?: $currencyCode;

	$amount = function (SubscriptionPlan $plan, BillingCycle $cycle) use ($currency, $currencyCode) {
		$price = $plan->prices->firstWhere('billing_cycle', $cycle);

		if (! $price || (float) $price->price <= 0) {
			return __('Free');
		}

		return $currency.money($price->price, $currencyCode, false);
	};
@endphp

<section class="dk-section" id="pricing">
	<div class="dk-container">
		<div class="dk-head dk-head--center" data-dk-reveal>
			@if($data['subheading'][$locale] ?? null)
				<span class="dk-eyebrow">{{ $data['subheading'][$locale] }}</span>
			@endif
			@if($data['heading'][$locale] ?? null)
				<h2>{{ $data['heading'][$locale] }}</h2>
			@endif

			@if($plans->isNotEmpty())
				<div class="dk-billing" role="radiogroup" aria-label="{{ __('Billing cycle') }}" data-dk-billing data-dk-billing-active="monthly">
					<button type="button" class="dk-billing__option is-selected" role="radio" aria-checked="true" tabindex="0" data-dk-billing-option="monthly">{{ __('Monthly') }}</button>
					<button type="button" class="dk-billing__option" role="radio" aria-checked="false" tabindex="-1" data-dk-billing-option="halfyearly">{{ __('6 months') }}@if($halfDiscount)<span class="dk-billing__save">-{{ $halfDiscount }}%</span>@endif</button>
					<button type="button" class="dk-billing__option" role="radio" aria-checked="false" tabindex="-1" data-dk-billing-option="annual">{{ __('Annual') }}@if($yearDiscount)<span class="dk-billing__save">-{{ $yearDiscount }}%</span>@endif</button>
				</div>
			@endif
		</div>

		@if($plans->isEmpty())
			<x-user-not-found
				:title="__('No plans are published yet')"
				:message="__('Subscription plans appear here as soon as they go live.')"
				icon="fa-layer-group" />
		@else
			<div class="dk-grid dk-grid--3 dk-grid--plans" style="align-items:start">
				@foreach($plans as $plan)
					<article class="dk-card dk-price {{ $plan->is_featured ? 'dk-price--featured' : '' }}"
					         style="position:relative"
					         data-dk-reveal>
						@if($plan->is_featured)
							<span class="dk-price__ribbon">{{ __('Most popular') }}</span>
						@endif

						<div>
							<span class="dk-eyebrow">{{ $plan->name }}</span>
							<div style="margin-top:10px">
								<span class="dk-price__amount"
								      data-dk-price
								      data-dk-price-monthly="{{ $amount($plan, BillingCycle::Monthly) }}"
								      data-dk-price-halfyearly="{{ $amount($plan, BillingCycle::HalfYearly) }}"
								      data-dk-price-annual="{{ $amount($plan, BillingCycle::Yearly) }}">{{ $amount($plan, BillingCycle::Monthly) }}</span>
							</div>
							@if($plan->description)
								<p style="font-size:.875rem;margin-top:8px">{{ $plan->description }}</p>
							@endif
						</div>

						<ul class="dk-price__list">
							@foreach($plan->features as $feature)
								@php($value = trim((string) $feature->feature_value))
								<li>
									<dfn>{{ $feature->feature_label }}</dfn>
									@if($value === '' || strtolower($value) === 'disabled' || $value === '0')
										<b class="dk-price__none" aria-label="{{ __('Not included') }}">&mdash;</b>
									@elseif(strtolower($value) === 'enabled')
										<b>{{ __('Included') }}</b>
									@else
										<b>{{ $value }}</b>
									@endif
								</li>
							@endforeach
						</ul>

						<a href="{{ route('user.register') }}" class="dk-btn {{ $plan->is_featured ? 'dk-btn--primary' : 'dk-btn--ghost' }}">
							{{ __('Get started') }}
						</a>
					</article>
				@endforeach
			</div>
		@endif
	</div>
</section>
