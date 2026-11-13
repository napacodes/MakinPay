@php
	$selectedCaps = old('capabilities', collect($provider->resolved_capabilities ?? [])->filter()->keys()->all());
	$selectedCaps = is_array($selectedCaps) ? $selectedCaps : [];
	$currenciesValue = old('supported_currencies', is_array($provider->supported_currencies) ? implode(', ', $provider->supported_currencies) : '');
	$countriesValue = old('supported_countries', is_array($provider->supported_countries) ? implode(', ', $provider->supported_countries) : '');
@endphp

<form action="{{ route('admin.virtual-card.provider.update', $provider) }}" method="post" class="vpo-config-form">
	@csrf
	@method('PUT')

	<div class="vpo-config-section vpo-config-section--profile">
		<div class="vpo-config-section__head">
			<h6><i class="fa-solid fa-building" aria-hidden="true"></i>{{ __('Provider profile') }}</h6>
		</div>

		<div class="row g-3">
			<div class="col-md-7">
				<label class="form-label" for="name">{{ __('Provider name') }}</label>
				<input class="form-control" id="name" type="text" name="name" value="{{ old('name', $provider->name) }}" required>
			</div>
			<div class="col-md-5">
				<label class="form-label" for="payment_gateway_id">{{ __('Payment gateway') }}</label>
				<select class="form-select" id="payment_gateway_id" name="payment_gateway_id">
					<option value="">{{ __('Not linked') }}</option>
					@foreach($gateways as $gateway)
						<option value="{{ $gateway->id }}" @selected((int) old('payment_gateway_id', $provider->payment_gateway_id) === $gateway->id)>
							{{ $gateway->name }} ({{ $gateway->code }}){{ $gateway->status ? '' : ' - '.__('Inactive') }}
						</option>
					@endforeach
				</select>
			</div>
			<div class="col-12">
				<label class="form-label" for="status">{{ __('Availability') }}</label>
				<div class="vc-provider-form__switch">
					<span>
						<strong>{{ __('Accept new card requests') }}</strong>
						<small>{{ __('Turn off to pause new issuance without affecting existing cards.') }}</small>
					</span>
					<div class="form-check form-switch mb-0">
						<input class="form-check-input coevs-switch" id="status" type="checkbox" name="status" value="1" @checked(old('status', $provider->status))>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="vpo-config-split">
		<div class="vpo-config-section vpo-config-section--coverage">
			<div class="vpo-config-section__head">
				<h6><i class="fa-solid fa-earth-americas" aria-hidden="true"></i>{{ __('Market coverage') }}</h6>
			</div>

			<div class="row g-3">
				<div class="col-12">
					<label class="form-label" for="supported_currencies">{{ __('Currencies') }}</label>
					<input class="form-control" id="supported_currencies" type="text" name="supported_currencies" value="{{ $currenciesValue }}" placeholder="USD, EUR, NGN">
					<div class="form-text">{{ __('Leave empty for global availability.') }}</div>
				</div>
				<div class="col-12">
					<label class="form-label" for="supported_countries">{{ __('Countries') }}</label>
					<input class="form-control" id="supported_countries" type="text" name="supported_countries" value="{{ $countriesValue }}" placeholder="US, GB, NG">
					<div class="form-text">{{ __('Leave empty for global availability.') }}</div>
				</div>
			</div>
		</div>

		<div class="vpo-config-section vpo-config-section--pricing">
			<div class="vpo-config-section__head">
				<h6><i class="fa-solid fa-receipt" aria-hidden="true"></i>{{ __('Issuance pricing') }}</h6>
			</div>

			<div class="row g-3">
				<div class="col-sm-6">
					<label class="form-label" for="issue_fee">{{ __('Fixed fee') }}</label>
					<div class="input-group">
						<input type="number" class="form-control" id="issue_fee" name="issue_fee" value="{{ old('issue_fee', $provider->issue_fee) }}" min="0" step="0.01" required>
						<span class="input-group-text">{{ siteCurrency() }}</span>
					</div>
				</div>
				<div class="col-sm-6">
					<label class="form-label" for="issue_fee_pct">{{ __('Percentage fee') }}</label>
					<div class="input-group">
						<input type="number" class="form-control" id="issue_fee_pct" name="issue_fee_pct" value="{{ old('issue_fee_pct', $provider->issue_fee_pct) }}" min="0" max="100" step="0.01">
						<span class="input-group-text">%</span>
					</div>
				</div>
				<div class="col-12">
					<label class="form-label" for="min_balance">{{ __('Minimum wallet balance') }}</label>
					<div class="input-group">
						<input type="number" class="form-control" id="min_balance" name="min_balance" value="{{ old('min_balance', $provider->min_balance) }}" min="0" step="0.01">
						<span class="input-group-text">{{ siteCurrency() }}</span>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="vpo-config-section vpo-config-section--features">
		<div class="vpo-config-section__head">
			<h6><i class="fa-solid fa-toggle-on" aria-hidden="true"></i>{{ __('Available features') }}</h6>
		</div>

		<div class="vpo-config-checks vpo-config-checks--capabilities">
			@foreach($capabilityLabels as $key => $label)
				<label class="vpo-config-check">
					<input type="checkbox" class="admin-check" name="capabilities[]" value="{{ $key }}" @checked(in_array($key, $selectedCaps, true))>
					<span>{{ $label }}</span>
				</label>
			@endforeach
		</div>
	</div>

	<div class="vpo-config-form__footer">
		<button class="btn btn-outline-secondary" type="button" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
		<button class="btn btn-primary" type="submit">
			<i class="fa-solid fa-check" aria-hidden="true"></i> {{ __('Save changes') }}
		</button>
	</div>
</form>
