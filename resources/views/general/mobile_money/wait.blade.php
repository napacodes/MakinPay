@extends('general.merchant.index')
@section('title', __('Approve the payment on your phone'))
@section('merchant_content')
    <div class="payment-link-checkout-wrap">
        <div class="card border-0 p-3 p-md-4 w-100 payment-link-card text-center"
             id="mobileMoneyWait"
             data-status-url="{{ $statusUrl }}"
             data-later-url="{{ $laterUrl }}">
            <div class="mb-3" aria-hidden="true">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10 text-primary"
                      style="inline-size: 4rem; block-size: 4rem;">
                    <i class="fas fa-mobile-alt fa-2x"></i>
                </span>
            </div>

            <h1 class="h5 fw-bold mb-2">{{ __('Approve the payment on your phone') }}</h1>

            <p class="text-muted mb-1">
                @if($maskedPhone !== '')
                    {{ __('We sent a payment request to :phone.', ['phone' => $maskedPhone]) }}
                @else
                    {{ __('We sent a payment request to your phone.') }}
                @endif
            </p>
            <p class="text-muted mb-3">
                {{ __('Enter your mobile money PIN on your phone to pay :amount.', [
                    'amount' => money($transaction->payable_amount, $transaction->payable_currency, false).' '.$transaction->payable_currency,
                ]) }}
            </p>

            <div class="d-flex align-items-center justify-content-center gap-2 mb-3" role="status" aria-live="polite">
                <span class="spinner-border spinner-border-sm text-primary" data-wait-spinner aria-hidden="true"></span>
                <span data-wait-message
                      data-waiting="{{ __('Waiting for your approval…') }}"
                      data-slow="{{ __('Still waiting. You can close this page — the payment is confirmed as soon as your network confirms it.') }}"
                      data-completed="{{ __('Payment approved. Taking you back…') }}"
                      data-failed="{{ __('The payment was declined or expired. Taking you back…') }}">{{ __('Waiting for your approval…') }}</span>
            </div>

            <p class="small text-muted mb-3">{{ __('No prompt on your phone? Check that the number is right and that the phone is on and has signal.') }}</p>

            <a href="{{ $laterUrl }}" class="btn btn-outline-secondary rounded-pill">{{ __('I will check later') }}</a>

            <div class="payment-checkout-footer mt-3">
                <i class="fas fa-lock me-1"></i>
                <span>{{ __('Reference: :reference', ['reference' => $transaction->trx_id]) }}</span>
            </div>
        </div>
    </div>
@endsection
@push('scripts')
    <script>
        "use strict";

        document.addEventListener('DOMContentLoaded', function () {
            const panel = document.getElementById('mobileMoneyWait');
            const message = panel.querySelector('[data-wait-message]');
            const spinner = panel.querySelector('[data-wait-spinner]');
            const statusUrl = panel.dataset.statusUrl;
            const startedAt = Date.now();
            const slowAfterMs = 3 * 60 * 1000;
            const stopAfterMs = 15 * 60 * 1000;
            let timer = null;

            const finish = (state, redirect) => {
                window.clearTimeout(timer);
                spinner.classList.add('d-none');
                message.textContent = message.dataset[state] || message.dataset.waiting;
                window.setTimeout(() => window.location.assign(redirect || panel.dataset.laterUrl), 1500);
            };

            const poll = () => {
                if (Date.now() - startedAt > stopAfterMs) {
                    spinner.classList.add('d-none');
                    message.textContent = message.dataset.slow;

                    return;
                }

                fetch(statusUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
                    .then((response) => response.ok ? response.json() : null)
                    .then((data) => {
                        if (data && data.final) {
                            finish(data.status === 'completed' ? 'completed' : 'failed', data.redirect);

                            return;
                        }

                        if (Date.now() - startedAt > slowAfterMs) {
                            message.textContent = message.dataset.slow;
                        }

                        timer = window.setTimeout(poll, 5000);
                    })
                    .catch(() => {
                        timer = window.setTimeout(poll, 8000);
                    });
            };

            timer = window.setTimeout(poll, 4000);
        });
    </script>
@endpush
