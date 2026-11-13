<div class="col-lg-12 col-xl-5">
    <div class="single-form-card">
        <x-user-feature-header
            :title="__('Summary')"
            :subtitle="__('Track debited balance, credited amount, and the exchange rate.')"
            icon="fas fa-chart-column"
            compact
        />
        <div class="card-main">
            {{-- Amount, fee, debited, credited - and the two rates. The old
                 "Total" line repeated "Debited Source Wallet" and was the
                 figure a customer had to subtract the fee from by hand. --}}
            <ul class="summery-list list-unstyled">
                <li class="d-flex justify-content-between"><span>{{ __('Amount') }}</span><span class="summary-amount"></span></li>
                <li class="d-flex justify-content-between"><span>{{ __('Fee') }}</span><span class="summary-fee"></span></li>
                <li class="list-unstyled p-0"><ul class="list-unstyled m-0" data-summary-tax></ul></li>
                <li class="d-flex justify-content-between"><strong>{{ __('Debited Source Wallet') }}</strong><strong class="summary-debited"></strong></li>
                <li class="d-flex justify-content-between text-success"><strong>{{ __('Credited Destination Wallet') }}</strong><strong class="summary-credited"></strong></li>
                <li class="d-flex justify-content-between"><span>{{ __('Conversion Rate') }}</span><span class="default-summary-rate"></span></li>
                <li class="d-flex justify-content-between"><span>{{ __('Exchange Rate') }}</span><span class="exchange-summary-rate"></span></li>
            </ul>
        </div>
    </div>
</div>
