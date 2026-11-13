@extends('frontend.layouts.user.index')
@section('title', __('Bill payment :reference', ['reference' => $payment->reference]))

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/bill-payment.css?v='.config('app.version').'-'.filemtime(public_path('frontend/css/bill-payment.css'))) }}">
@endpush

@section('content')
    @php
        use App\Enums\BillPayment\BillPaymentStatus;

        $bpStatus = $payment->status;
        $bpIsPaid = $bpStatus === BillPaymentStatus::Completed;
        $bpIsFailed = $bpStatus === BillPaymentStatus::Failed;
        $bpState = $bpIsPaid ? 'completed' : ($bpIsFailed ? 'failed' : 'processing');
        $bpTotal = money($payment->total_amount, $payment->currency, false).' '.$payment->currency;
        $bpDate = fn ($moment) => $moment?->format('d M Y, H:i');

        $bpSteps = [
            ['label' => __('Submitted'), 'time' => $bpDate($payment->created_at), 'state' => 'done'],
            ['label' => __('Being processed'), 'time' => $bpDate($payment->submitted_at), 'state' => ($bpIsPaid || $bpIsFailed) ? 'done' : 'current'],
            $bpIsFailed
                ? ['label' => __('Failed — refunded'), 'time' => $bpDate($payment->processed_at), 'state' => 'failed']
                : ['label' => __('Paid'), 'time' => $bpIsPaid ? $bpDate($payment->processed_at) : null, 'state' => $bpIsPaid ? 'done' : 'todo'],
        ];

        $bpTokenLabel = $tokenLabel !== '' ? $tokenLabel : __('Token');
    @endphp

    <div class="bp">
        <div class="single-form-card bp-no-print">
            <x-user-feature-header
                :title="__('Bill payment')"
                :subtitle="__('Reference :reference', ['reference' => $payment->reference])"
                icon="fas fa-receipt"
            >
                <a class="btn btn-light-success btn-sm" href="{{ route('user.bill-payment.history') }}">
                    <i class="fas fa-list" aria-hidden="true"></i> {{ __('History') }}
                </a>
            </x-user-feature-header>
        </div>

        <div class="row g-3">
            <div class="col-12 col-xl-8">
                <article class="bp-receipt" data-bp-receipt="{{ $bpState }}">
                    <header class="bp-receipt__hero bp-receipt__hero--{{ $bpState }}">
                        <span class="bp-receipt__badge" aria-hidden="true">
                            <i class="fa-solid {{ $bpIsPaid ? 'fa-check' : ($bpIsFailed ? 'fa-rotate' : 'fa-clock') }}"></i>
                        </span>
                        <div class="bp-receipt__headline">
                            <h2 class="bp-receipt__title">
                                @if($bpIsPaid)
                                    {{ __('Bill paid') }}
                                @elseif($bpIsFailed)
                                    {{ __('This bill was not paid') }}
                                @else
                                    {{ __('Your bill is being processed') }}
                                @endif
                            </h2>
                            <p class="bp-receipt__sub">
                                @if($bpIsFailed)
                                    {{ __(':total returned to your wallet', ['total' => $bpTotal]) }}
                                @elseif($bpIsPaid)
                                    {{ __(':total paid to :biller', ['total' => $bpTotal, 'biller' => $payment->biller_name]) }}
                                @else
                                    {{ $processingNote ?? __('We will let you know as soon as it is paid.') }}
                                @endif
                            </p>
                        </div>
                        @include('frontend.user.bill_payment.partials._status_pill', ['status' => $bpStatus])
                    </header>

                    <ol class="bp-steps" aria-label="{{ __('Payment progress') }}">
                        @foreach($bpSteps as $step)
                            <li class="bp-step is-{{ $step['state'] }}" @if($step['state'] === 'current') aria-current="step" @endif>
                                <span class="bp-step__dot" aria-hidden="true">
                                    @if($step['state'] === 'done')
                                        <i class="fa-solid fa-check"></i>
                                    @elseif($step['state'] === 'failed')
                                        <i class="fa-solid fa-xmark"></i>
                                    @endif
                                </span>
                                <span class="bp-step__label">{{ $step['label'] }}</span>
                                @if($step['time'])
                                    <span class="bp-step__time" dir="ltr">{{ $step['time'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>

                    @if($bpIsFailed && $payment->failure_reason)
                        <div class="bp-callout bp-callout--danger" data-bp-failure-reason>
                            <span class="bp-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span>
                            <div class="bp-callout__copy">
                                <strong>{{ __('Why it was not paid') }}</strong>
                                <p>{{ $payment->failure_reason }}</p>
                            </div>
                        </div>
                    @endif

                    @if($token !== null && $token !== '')
                        <section class="bp-token" data-bp-token>
                            <span class="bp-token__label">{{ $bpTokenLabel }}</span>
                            <div class="bp-token__row">
                                <code class="bp-token__value" id="bp-token-value" dir="ltr">{{ $token }}</code>
                                <button type="button" class="bp-token__copy bp-no-print" data-bp-copy="#bp-token-value"
                                        data-copied-label="{{ __('Copied') }}" aria-label="{{ __('Copy :label', ['label' => $bpTokenLabel]) }}">
                                    <i class="fa-solid fa-copy" aria-hidden="true"></i>
                                    <span data-bp-copy-text>{{ __('Copy') }}</span>
                                </button>
                            </div>
                            <span class="bp-token__help">{{ __('Enter this on your meter or device to use what you paid for.') }}</span>
                            @if($tokenDelivery)
                                {{-- Sent by email, SMS and push as the customer's preferences allow; the admin opened this. --}}
                                <form method="POST" action="{{ route('user.bill-payment.resend-token', $payment) }}" class="bp-no-print mt-2" data-bp-resend-token>
                                    @csrf
                                    <button type="submit" class="btn btn-light btn-sm"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> {{ __('Send it to me again') }}</button>
                                </form>
                            @endif
                        </section>
                    @endif

                    <dl class="bp-facts">
                        <div class="bp-fact">
                            <dt>{{ __('Biller') }}</dt>
                            <dd>{{ $payment->biller_name }}</dd>
                        </div>
                        <div class="bp-fact">
                            <dt>{{ $payment->customer_number_label }}</dt>
                            <dd class="bp-mono" dir="ltr">{{ $payment->customer_number }}</dd>
                        </div>
                        @if(filled(data_get($payment->metadata, 'customer_name')))
                            <div class="bp-fact">
                                <dt>{{ __('Account name') }}</dt>
                                <dd data-bp-customer-name>{{ data_get($payment->metadata, 'customer_name') }}</dd>
                            </div>
                        @endif
                        @if($payment->item_name)
                            <div class="bp-fact">
                                <dt>{{ __('Package') }}</dt>
                                <dd>{{ $payment->item_name }}</dd>
                            </div>
                        @endif
                        <div class="bp-fact">
                            <dt>{{ __('Reference') }}</dt>
                            <dd class="bp-mono" dir="ltr">{{ $payment->reference }}</dd>
                        </div>
                        @if($payment->receipt_reference)
                            <div class="bp-fact">
                                <dt>{{ __('Biller receipt') }}</dt>
                                <dd class="bp-mono" dir="ltr">{{ $payment->receipt_reference }}</dd>
                            </div>
                        @endif
                        <div class="bp-fact">
                            <dt>{{ __('Bill amount') }}</dt>
                            <dd dir="ltr">{{ money($payment->bill_amount, $payment->bill_currency, false) }} {{ $payment->bill_currency }}</dd>
                        </div>
                        <div class="bp-fact">
                            <dt>{{ __('Fee') }}</dt>
                            <dd dir="ltr">{{ money($payment->fee, $payment->currency, false) }} {{ $payment->currency }}</dd>
                        </div>
                        @foreach ((array) $payment->tax_breakdown as $taxLine)
                            <div class="bp-fact" data-tax-line>
                                <dt>{{ $taxLine['name'] ?? __('Tax') }}</dt>
                                <dd dir="ltr">{{ money($taxLine['amount'] ?? 0, $payment->currency, false) }} {{ $payment->currency }}</dd>
                            </div>
                        @endforeach
                        <div class="bp-fact bp-fact--total">
                            <dt>{{ $bpIsFailed ? __('Refunded') : __('Total debited') }}</dt>
                            <dd dir="ltr">{{ $bpTotal }}</dd>
                        </div>
                        <div class="bp-fact">
                            <dt>{{ __('Submitted') }}</dt>
                            <dd dir="ltr">{{ $bpDate($payment->created_at) }}</dd>
                        </div>
                        @if($payment->processed_at)
                            <div class="bp-fact">
                                <dt>{{ $bpIsFailed ? __('Refunded on') : __('Paid on') }}</dt>
                                <dd dir="ltr">{{ $bpDate($payment->processed_at) }}</dd>
                            </div>
                        @endif
                        @if($payment->transaction)
                            <div class="bp-fact">
                                <dt>{{ __('Transaction ID') }}</dt>
                                <dd class="bp-mono" dir="ltr">{{ $payment->transaction->trx_id }}</dd>
                            </div>
                        @endif
                        @if($payment->refundTransaction)
                            <div class="bp-fact">
                                <dt>{{ __('Refund transaction') }}</dt>
                                <dd class="bp-mono" dir="ltr">{{ $payment->refundTransaction->trx_id }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if($payment->biller?->instructions)
                        <div class="bp-note bp-note--info">
                            <span class="bp-note__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
                            <div class="bp-note__copy">
                                <strong>{{ __('From :biller', ['biller' => $payment->biller_name]) }}</strong>
                                <p>{!! nl2br(e($payment->biller->instructions)) !!}</p>
                            </div>
                        </div>
                    @endif
                </article>
            </div>

            <div class="col-12 col-xl-4 bp-no-print">
                <div class="single-form-card">
                    <div class="card-main bp-side-actions">
                        @if($payAgainUrl)
                            <a class="btn btn-base w-100" href="{{ $payAgainUrl }}" data-bp-pay-again>
                                <i class="fa-solid fa-rotate-right" aria-hidden="true"></i> {{ __('Pay this bill again') }}
                            </a>
                        @endif
                        <button type="button" class="btn {{ $payAgainUrl ? 'btn-light' : 'btn-base' }} w-100" data-bp-print>
                            <i class="fa-solid fa-receipt" aria-hidden="true"></i> {{ __('Print receipt') }}
                        </button>
                        <a class="btn btn-light w-100" href="{{ route('user.bill-payment.index') }}">
                            {{ __('Pay another bill') }}
                        </a>
                        @if($savedBill)
                            <span class="bp-saved-note" data-bp-saved-note>
                                <i class="fa-solid fa-bookmark" aria-hidden="true"></i>
                                {{ __('Saved in My bills as ":name"', ['name' => $savedBill->title()]) }}
                            </span>
                        @endif
                        <a class="btn btn-light w-100" href="{{ route('user.support-ticket.index') }}">
                            {{ __('Need help with this payment?') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('frontend/js/bill-payment.js?v='.config('app.version').'-'.filemtime(public_path('frontend/js/bill-payment.js'))) }}"></script>
@endpush
