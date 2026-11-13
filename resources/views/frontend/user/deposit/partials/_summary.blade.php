<div class="col-lg-12 col-xl-5">
    <div class="single-form-card">
        <x-user-feature-header
            :title="__('Summary')"
            :subtitle="__('Check fees, conversion, and final payable before confirming.')"
            icon="fas fa-chart-pie"
            compact
        />
        <div class="card-main">
            <ul class="summery-list list-unstyled">
                <li class="d-flex justify-content-between">
                    <span>{{ __('Payment Method') }}</span>
                    <span class="summary-method-name"></span>
                </li>
                <li class="d-flex justify-content-between">
                    <span>{{ __('Amount') }}</span>
                    <span class="summary-amount"></span>
                </li>
                {{-- Amount and Total already have their counterparts further down
                     (Wallet Added, Payable). The charge had none, so a fee the
                     admin configured in the method's currency was only ever shown
                     in the base currency. --}}
                <li class="d-flex justify-content-between">
                    <span>{{ __('Charge') }}</span>
                    <span class="text-end">
                        <span class="summary-charge"></span>
                        <small class="d-block text-muted summary-charge-alt"></small>
                    </span>
                </li>
                <li class="list-unstyled p-0"><ul class="list-unstyled m-0" data-summary-tax></ul></li>
                <li class="d-flex justify-content-between">
                    <strong>{{ __('Total') }}</strong>
                    <strong class="summary-total" ></strong>
                </li>

                <li class="d-flex justify-content-between text-primary">
                    <strong>{{ __('Conversion Rate') }}</strong>
                    <strong class="summary-rate" ></strong>
                </li>
                <li class="d-flex justify-content-between">
                    <strong>{{ __('Wallet Added') }}</strong>
                    <strong class="wallet-added"></strong>
                </li>
                <li class="d-flex justify-content-between">
                    <strong>{{ __('Payable') }}</strong>
                    <strong class="summary-payable"></strong>
                </li>
            </ul>
        </div>
    </div>
</div>

