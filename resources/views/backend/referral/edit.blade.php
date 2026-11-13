<form action="{{ route('admin.referral.update', $reward->id) }}" method="post">
    @csrf

    {{-- Total reward = Fixed + (Amount x Percentage), then clamped to any
         min/max. Leave a component at 0 to use only the other. --}}
    <div class="row mb-3">
        <div class="col-md-6">
            <label class="form-label" for="reward">{{ __(':type Reward', ['type' => ucwords($reward->type)]) }}</label>
            <div class="input-group">
                <input type="text" class="form-control @error('reward') is-invalid @enderror" name="reward" id="reward"
                       value="{{ old('reward', $reward->percentage) }}"
                       oninput="this.value = validateDouble(this.value)">
                <span class="input-group-text">%</span>
            </div>
            @error('reward')<div class="text-danger small">{{ $message }}</div>@enderror
        </div>

        <div class="col-md-6">
            <label class="form-label" for="fixed_amount">{{ __('Fixed Reward') }}</label>
            <div class="input-group">
                <input type="text" class="form-control @error('fixed_amount') is-invalid @enderror" name="fixed_amount" id="fixed_amount"
                       value="{{ old('fixed_amount', $reward->fixed_amount) }}" placeholder="{{ __('e.g. 0.50') }}"
                       oninput="this.value = validateDouble(this.value)">
                <span class="input-group-text">{{ siteCurrency() }}</span>
            </div>
            @error('fixed_amount')<div class="text-danger small">{{ $message }}</div>@enderror
        </div>
    </div>

    {{-- Rewards are computed on the transaction amount, which is base-denominated,
         so the floor and ceiling are read in the base currency. Without the badge
         the admin could not tell these from the percentage field above. --}}
    <details class="mb-3">
        <summary class="small text-muted">{{ __('Minimum / maximum reward') }}</summary>
        <small class="text-muted d-block mt-1">
            {{ __('A flat floor and ceiling on the reward, in :currency — not a percentage.', ['currency' => siteCurrency()]) }}
        </small>
        <div class="row mt-2">
            <div class="col-md-6">
                <label class="form-label" for="min_reward">{{ __('Min Reward') }}</label>
                <div class="input-group">
                    <input type="text" class="form-control" name="min_reward" id="min_reward"
                           value="{{ old('min_reward', $reward->min_reward) }}" placeholder="{{ __('No minimum') }}"
                           oninput="this.value = validateDouble(this.value)">
                    <span class="input-group-text">{{ siteCurrency() }}</span>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="max_reward">{{ __('Max Reward') }}</label>
                <div class="input-group">
                    <input type="text" class="form-control" name="max_reward" id="max_reward"
                           value="{{ old('max_reward', $reward->max_reward) }}" placeholder="{{ __('No maximum') }}"
                           oninput="this.value = validateDouble(this.value)">
                    <span class="input-group-text">{{ siteCurrency() }}</span>
                </div>
            </div>
        </div>
    </details>

    <span class="text-muted small d-block mb-3">
        {{ __('Reward when user :type. Total = Fixed + (Amount x Percentage).', ['type' => $reward->type]) }}
    </span>

    <div class="text-end">
        <button class="btn btn-primary" type="submit">
            <x-icon name="check" height="20"/> {{ __('Update Reward') }}
        </button>
    </div>
</form>
