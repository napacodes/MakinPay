{{-- Mass payout opt-in. NULL ("Not offered") is the default, so no method is
     offered to mass payouts until an admin chooses a driver here. The server
     re-checks the method's shape on save; this select only narrows the list.

     Expects: $massPayoutFieldId, $massPayoutOptions (driver => label, the
     driver's own readiness issues and, on edit, the method's compatibility
     issues), $massPayoutCurrent (?string) and $massPayoutIssues (list of
     reasons the saved method is not offered, or null on create). --}}
<div class="row mb-3">
    <div class="col-lg-6 col-md-6 col-12">
        <label class="form-label" for="{{ $massPayoutFieldId }}">{{ __('Mass payout') }}</label>
        <select class="form-select" id="{{ $massPayoutFieldId }}" name="mass_payout_driver" data-mass-payout-driver>
            <option value="" @selected($massPayoutCurrent === null)>{{ __('Not offered') }}</option>
            @foreach($massPayoutOptions as $driver => $option)
                @php $incompatible = ($option['compatibility'] ?? []) !== [] && $massPayoutCurrent !== $driver; @endphp
                <option value="{{ $driver }}" @selected($massPayoutCurrent === $driver) @disabled($incompatible)>
                    {{ $option['label'] }}@if($incompatible) — {{ __('not compatible with this method') }}@endif
                </option>
            @endforeach
        </select>
        <span class="d-block text-muted small mt-1">{{ __('Lets merchants pay many external accounts in one batch through this method.') }}</span>
    </div>
    <div class="col-lg-6 col-md-6 col-12 mt-md-0 mt-3">
        @if($massPayoutIssues !== null && $massPayoutCurrent !== null)
            @if($massPayoutIssues === [])
                <div class="alert alert-success py-2 px-3 mb-2">
                    <i class="fas fa-circle-check me-1"></i>{{ __('Ready: offered for mass payouts.') }}
                </div>
            @else
                <div class="alert alert-warning py-2 px-3 mb-2">
                    <strong class="d-block mb-1"><i class="fas fa-triangle-exclamation me-1"></i>{{ __('Not offered yet') }}</strong>
                    <ul class="mb-0 ps-3 small">
                        @foreach($massPayoutIssues as $issue)
                            <li>{{ $issue }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
        @foreach($massPayoutOptions as $driver => $option)
            @if($option['issues'] !== [] && ($option['compatibility'] ?? []) === [] && $massPayoutCurrent !== $driver)
                <div class="small text-muted mb-1" data-mass-payout-readiness="{{ $driver }}">
                    <strong>{{ $option['label'] }}:</strong> {{ implode(' ', $option['issues']) }}
                </div>
            @endif
        @endforeach
    </div>
</div>
