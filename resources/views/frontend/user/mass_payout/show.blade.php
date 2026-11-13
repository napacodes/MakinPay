@extends('frontend.layouts.user.index')
@section('title', __('Mass payout :reference', ['reference' => $batch->reference]))

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/mass-payment.css?v='.config('app.version').'-'.filemtime(public_path('frontend/css/mass-payment.css'))) }}">
@endpush

@section('content')
    @php
        use App\Enums\MassPayment\MassPaymentBatchStatus;

        $mpStatus = $batch->status();
        $mpMoney = fn ($amount) => money($amount, $currency, false).' '.$currency;
        $mpIsExpired = $isDraft && $batch->expires_at !== null && $batch->expires_at->isPast();
        $mpRunning = [MassPaymentBatchStatus::AwaitingApproval, MassPaymentBatchStatus::Queued, MassPaymentBatchStatus::Processing];
        $mpCanStop = in_array($mpStatus, $mpRunning, true) && $batch->stop_requested_at === null;
        $mpIsLive = in_array($mpStatus, $mpRunning, true);
        $mpPayableRows = $counts['pending'] + $counts['paid'] + $counts['failed'] + $counts['reviewed'];
        $mpPercent = fn (int $part) => $mpPayableRows === 0 ? 0 : round($part * 100 / $mpPayableRows, 2);
        $mpPayCount = (int) $batch->valid_count;
        $mpSkipCount = (int) $batch->invalid_count;
    @endphp

    <div class="mp" data-mp-batch-page="{{ $batch->uuid }}">
        <div class="single-form-card">
            <x-user-feature-header
                :title="filled($batch->name) ? $batch->name : __('Mass payout')"
                :subtitle="__('Reference :reference', ['reference' => $batch->reference])"
                icon="fas fa-building-columns"
            >
                <a class="btn btn-light-success btn-sm" href="{{ route('user.mass-payout.report', $batch) }}" data-mp-report>
                    <i class="fas fa-download" aria-hidden="true"></i> {{ __('Report') }}
                </a>
                <a class="btn btn-light-success btn-sm" href="{{ route('user.mass-payout.index') }}">
                    <i class="fas fa-list" aria-hidden="true"></i> {{ __('History') }}
                </a>
            </x-user-feature-header>

            <div class="card-main">
                <div class="mp-head">
                    <div class="mp-head__copy">
                        <span class="mp-head__wallet">{{ __('From your :currency wallet through :method', ['currency' => $currency, 'method' => $methodName]) }}</span>
                        @if(filled($batch->original_filename))
                            <span class="mp-head__file"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> {{ $batch->original_filename }}</span>
                        @endif
                    </div>
                    @include('frontend.user.mass_payment.partials._status_pill', ['status' => $mpStatus])
                </div>

                @if($isDraft)
                    {{-- ---------- Preview ---------- --}}
                    @include('frontend.user.mass_payment.partials._summary_cards', ['toLabel' => __('To accounts')])

                    @if($mpIsExpired)
                        <div class="mp-callout mp-callout--warning" data-mp-expired>
                            <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-hourglass-end"></i></span>
                            <div class="mp-callout__copy">
                                <strong>{{ __('This batch has expired') }}</strong>
                                <p>{{ __('A batch must be confirmed within a day of being created. Build it again to pay it.') }}</p>
                            </div>
                        </div>
                    @elseif($funding['short_by'] !== null)
                        <div class="mp-callout mp-callout--danger" data-mp-short>
                            <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-wallet"></i></span>
                            <div class="mp-callout__copy">
                                <strong>{{ __('Your wallet is short by :amount', ['amount' => $mpMoney($funding['short_by'])]) }}</strong>
                                <p>{{ __('Add funds to the wallet, or fix the batch to pay fewer rows.') }}</p>
                            </div>
                        </div>
                    @endif

                    @if(! $mpIsExpired && ((int) $batch->invalid_count > 0 || (int) $batch->warning_count > 0))
                        <p class="mp-help" data-mp-fix>
                            <a href="{{ route('user.mass-payout.create', ['from' => $batch->uuid]) }}">
                                <i class="fa-solid fa-pen" aria-hidden="true"></i> {{ __('Fix the rows') }}
                            </a>
                            <span>{{ __('Opens this batch in the grid so you can correct it and review again.') }}</span>
                        </p>
                    @endif
                @else
                    {{-- ---------- Progress and summary ---------- --}}
                    @if($mpStatus === MassPaymentBatchStatus::AwaitingApproval && $batch->stop_requested_at === null)
                        <div class="mp-callout mp-callout--info" data-mp-awaiting-approval>
                            <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-user-check"></i></span>
                            <div class="mp-callout__copy">
                                <strong>{{ __('Waiting for approval') }}</strong>
                                <p>{{ __('Your money is reserved. The payouts are sent as soon as our team approves the batch; if it is not approved, everything comes back to your wallet.') }}</p>
                            </div>
                        </div>
                    @endif

                    @include('frontend.user.mass_payment.partials._progress', ['statusUrl' => route('user.mass-payout.status', $batch)])

                    <div class="mp-callout mp-callout--info" data-mp-stale @unless($isStale) hidden @endunless>
                        <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-clock"></i></span>
                        <div class="mp-callout__copy">
                            <strong>{{ __('Still going; payouts continue automatically.') }}</strong>
                            <p>{{ __('You can leave this page. Your money stays reserved and each payout is confirmed with its provider on its own.') }}</p>
                        </div>
                    </div>

                    @if($mpStatus === MassPaymentBatchStatus::NeedsReview)
                        <div class="mp-callout mp-callout--warning" data-mp-on-hold>
                            <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-pause"></i></span>
                            <div class="mp-callout__copy">
                                <strong>{{ __('This batch is on hold') }}</strong>
                                <p>{{ __('Our team is reviewing it. Your money stays reserved; nothing more is sent until they resume it or return the rest to your wallet.') }}</p>
                            </div>
                        </div>
                    @endif

                    @if($batch->stop_requested_at !== null && ! $mpStatus->isTerminal())
                        <div class="mp-callout mp-callout--info" data-mp-stopping>
                            <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-hand"></i></span>
                            <div class="mp-callout__copy">
                                <strong>{{ __('Stopping') }}</strong>
                                <p>{{ __('Payouts not yet sent are being returned to your wallet. Payouts already sent finish with their provider.') }}</p>
                            </div>
                        </div>
                    @endif

                    @if($mpStatus->isTerminal())
                        <dl class="mp-facts" data-mp-final>
                            <div class="mp-fact"><dt>{{ __('Paid') }}</dt><dd>{{ trans_choice(':count payout|:count payouts', (int) $batch->paid_count, ['count' => (int) $batch->paid_count]) }}</dd></div>
                            <div class="mp-fact"><dt>{{ __('Not paid') }}</dt><dd>{{ trans_choice(':count payout|:count payouts', (int) $batch->failed_count, ['count' => (int) $batch->failed_count]) }}</dd></div>
                            <div class="mp-fact"><dt>{{ __('Total debited for paid rows') }}</dt><dd dir="ltr">{{ $mpMoney($batch->paid_payable) }}</dd></div>
                            <div class="mp-fact mp-fact--total"><dt>{{ __('Returned to your wallet') }}</dt><dd dir="ltr" data-mp-released>{{ $mpMoney($batch->released_amount) }}</dd></div>
                            @if($batch->completed_at)
                                <div class="mp-fact"><dt>{{ __('Finished') }}</dt><dd dir="ltr">{{ $batch->completed_at->format('d M Y, H:i') }}</dd></div>
                            @endif
                        </dl>
                    @else
                        <dl class="mp-facts">
                            <div class="mp-fact"><dt>{{ __('Reserved') }}</dt><dd dir="ltr">{{ $mpMoney($batch->total_payable) }}</dd></div>
                            @if($batch->confirmed_at)
                                <div class="mp-fact"><dt>{{ __('Confirmed') }}</dt><dd dir="ltr">{{ $batch->confirmed_at->format('d M Y, H:i') }}</dd></div>
                            @endif
                        </dl>
                    @endif

                    @if($mpCanStop)
                        <form method="POST" action="{{ route('user.mass-payout.stop', $batch) }}" class="mp-stop" data-mp-stop-form
                              data-confirm-message="{{ __('Stop this batch? Payouts not yet sent are returned to your wallet. Payouts already sent cannot be recalled. This cannot be undone.') }}">
                            @csrf
                            <button type="submit" class="btn btn-light-danger btn-sm" data-mp-stop>
                                <i class="fa-solid fa-hand" aria-hidden="true"></i> {{ __('Stop the remaining payouts') }}
                            </button>
                        </form>
                    @endif
                @endif

                {{-- ---------- Rows ---------- --}}
                @include('frontend.user.mass_payment.partials._rows', ['accountLabel' => __('Account'), 'rowView' => 'frontend.user.mass_payout.partials._row'])

                @if($isDraft && ! $mpIsExpired)
                    {{-- ---------- Confirm ---------- --}}
                    <form method="POST" action="{{ route('user.mass-payout.confirm', $batch) }}" class="mp-confirm" data-mp-confirm-form
                          data-processing-label="{{ __('Reserving...') }}">
                        @csrf
                        <input type="hidden" name="fingerprint" value="{{ $batch->preview_fingerprint }}">
                        <input type="hidden" name="expected_total" value="{{ $batch->total_payable }}">

                        @if($mpPayCount === 0)
                            <div class="mp-callout mp-callout--danger" data-mp-nothing-to-pay>
                                <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span>
                                <div class="mp-callout__copy">
                                    <strong>{{ __('There is no valid row to pay') }}</strong>
                                    <p>{{ __('Fix the rows marked invalid and review the batch again.') }}</p>
                                </div>
                            </div>
                        @endif

                        @if($mpSkipCount > 0 && $mpPayCount > 0)
                            <div class="form-check mp-check">
                                <input class="form-check-input" type="checkbox" name="skip_invalid" value="1" id="mpo-skip-invalid" data-mp-skip data-mp-required @checked(old('skip_invalid'))>
                                <label class="form-check-label" for="mpo-skip-invalid">
                                    {{ trans_choice('Skip :skip invalid row and pay :pay|Skip :skip invalid rows and pay :pay', $mpSkipCount, ['skip' => $mpSkipCount, 'pay' => $mpPayCount]) }}
                                </label>
                            </div>
                        @endif

                        @if((int) $batch->warning_count > 0)
                            <div class="form-check mp-check">
                                <input class="form-check-input" type="checkbox" name="acknowledge_warnings" value="1" id="mpo-acknowledge" data-mp-acknowledge data-mp-required @checked(old('acknowledge_warnings'))>
                                <label class="form-check-label" for="mpo-acknowledge">
                                    {{ __('I reviewed the rows marked as possible duplicates and want to pay them.') }}
                                </label>
                            </div>
                        @endif

                        @if($hasWalletPin)
                            <div class="single-input-inner style-border mb-0 mt-3">
                                <label class="form-label" for="mpo-wallet-pin">{{ __('Wallet PIN') }}</label>
                                <input type="password" id="mpo-wallet-pin" class="form-control" name="wallet_pin"
                                       inputmode="numeric" maxlength="6" autocomplete="off"
                                       placeholder="{{ __('Enter your 6-digit wallet PIN') }}" required>
                                <span class="small color-base fw-500">{{ __('Confirm this batch with your wallet PIN.') }}</span>
                                @error('wallet_pin')<div class="text-danger small mt-1" data-mp-error="wallet_pin">{{ $message }}</div>@enderror
                            </div>
                        @endif

                        @foreach(['fingerprint', 'expected_total', 'skip_invalid', 'acknowledge_warnings'] as $mpField)
                            @error($mpField)<div class="text-danger small mt-2" data-mp-error="{{ $mpField }}">{{ $message }}</div>@enderror
                        @endforeach

                        <div class="mp-confirm__actions">
                            <button type="submit" class="btn btn-base" data-mp-confirm @disabled($mpPayCount === 0)>
                                <i class="fa-solid fa-check" aria-hidden="true"></i>
                                {{ __('Confirm and reserve :total', ['total' => $mpMoney($batch->total_payable)]) }}
                            </button>
                        </div>

                        <p class="mp-reassure">
                            <i class="fa-solid fa-lock" aria-hidden="true"></i>
                            {{ __('The total is reserved from your wallet now. Any payout that cannot be made comes back to your wallet, fee included.') }}
                        </p>
                    </form>
                @endif

                @if($isDraft)
                    <form method="POST" action="{{ route('user.mass-payout.discard', $batch) }}" class="mp-discard" data-mp-discard-form
                          data-confirm-message="{{ __('Discard this batch? Nothing has been sent; you can build it again later.') }}">
                        @csrf
                        <button type="submit" class="btn btn-link mp-discard__btn" data-mp-discard>
                            <i class="fa-solid fa-trash-can" aria-hidden="true"></i> {{ __('Discard batch') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('frontend/js/mass-payment.js?v='.config('app.version').'-'.filemtime(public_path('frontend/js/mass-payment.js'))) }}"></script>
@endpush
