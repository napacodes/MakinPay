<div class="col-lg-12 col-xl-5">
    <div class="single-form-card">
        <x-user-feature-header
            :title="__('Summary')"
            :subtitle="__('Review timing, charges, and received amount before submitting.')"
            icon="fas fa-receipt"
            compact
        />
        <div class="card-main">
            {{-- Three money lines, read top to bottom: what was typed, what the
                 fee is, what arrives - then what the wallet loses. Under
                 "fee deducted" the first and last are the same figure; under
                 "fee on top" the first and third are. Either way nobody has
                 to subtract anything. --}}
            <ul class="summery-list list-unstyled">
                <li class="d-flex justify-content-between">
                    <span>{{ __('Processing Time') }}</span>
                    <span class="processing-time"></span>
                </li>
                <li class="d-flex justify-content-between">
                    <span>{{ __('Amount') }}</span>
                    <span class="summary-amount"></span>
                </li>
                <li class="d-flex justify-content-between">
                    <span>{{ __('Charge') }}</span>
                    <span class="summary-charge"></span>
                </li>
                <li class="list-unstyled p-0"><ul class="list-unstyled m-0" data-summary-tax></ul></li>
                <li class="d-flex justify-content-between text-success">
                    <strong>{{ __('You receive') }}</strong>
                    <strong class="received-withdraw-amount"></strong>
                </li>
                <li class="d-flex justify-content-between">
                    <strong>{{ __('Wallet debited') }}</strong>
                    <strong class="my-wallet-decreased"></strong>
                </li>
                <li class="d-flex justify-content-between text-primary">
                    <span>{{ __('Conversion Rate') }}</span>
                    <span class="conversion-rate"></span>
                </li>
            </ul>
        </div>
    </div>
</div>
