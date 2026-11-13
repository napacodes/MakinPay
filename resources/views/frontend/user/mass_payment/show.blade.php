@extends('frontend.layouts.user.index')
@section('title', __('Mass payment :reference', ['reference' => $batch->reference]))

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/mass-payment.css?v='.config('app.version').'-'.filemtime(public_path('frontend/css/mass-payment.css'))) }}">
@endpush

@section('content')
    @php
        use App\Enums\MassPayment\MassPaymentBatchStatus;

        $mpStatus = $batch->status();
        $mpMoney = fn ($amount) => money($amount, $currency, false).' '.$currency;
        $mpIsExpired = $isDraft && $batch->expires_at !== null && $batch->expires_at->isPast();
        $mpCanStop = in_array($mpStatus, [MassPaymentBatchStatus::Queued, MassPaymentBatchStatus::Processing], true) && $batch->stop_requested_at === null;
        $mpIsLive = in_array($mpStatus, [MassPaymentBatchStatus::Queued, MassPaymentBatchStatus::Processing], true);
        $mpPayableRows = $counts['pending'] + $counts['paid'] + $counts['failed'] + $counts['reviewed'];
        $mpPercent = fn (int $part) => $mpPayableRows === 0 ? 0 : round($part * 100 / $mpPayableRows, 2);
        $mpPayCount = (int) $batch->valid_count;
        $mpSkipCount = (int) $batch->invalid_count;
    @endphp

    <div class="mp" data-mp-batch-page="{{ $batch->uuid }}">
        <div class="single-form-card">
            <x-user-feature-header
                :title="filled($batch->name) ? $batch->name : __('Mass payment')"
                :subtitle="__('Reference :reference', ['reference' => $batch->reference])"
                icon="fas fa-users"
            >
                @if($isDraft && ! $mpIsExpired)
                    <a class="btn btn-light-success btn-sm" href="{{ route('user.mass-payment.create', ['from' => $batch->uuid]) }}" data-mp-edit-rows>
                        <i class="fas fa-pen" aria-hidden="true"></i> {{ __('Edit rows') }}
                    </a>
                @endif
                <a class="btn btn-light-success btn-sm" href="{{ route('user.mass-payment.report', $batch) }}" data-mp-report>
                    <i class="fas fa-download" aria-hidden="true"></i> {{ __('Report') }}
                </a>
                <a class="btn btn-light-success btn-sm" href="{{ route('user.mass-payment.index') }}">
                    <i class="fas fa-list" aria-hidden="true"></i> {{ __('History') }}
                </a>
            </x-user-feature-header>

            <div class="card-main">
                <div class="mp-head">
                    <div class="mp-head__copy">
                        <span class="mp-head__wallet">{{ __('From your :currency wallet', ['currency' => $currency]) }}</span>
                        @if(filled($batch->original_filename))
                            <span class="mp-head__file"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> {{ $batch->original_filename }}</span>
                        @endif
                    </div>
                    @include('frontend.user.mass_payment.partials._status_pill', ['status' => $mpStatus])
                </div>

                @if($isDraft)
                    {{-- ---------- Preview ---------- --}}
                    @include('frontend.user.mass_payment.partials._summary_cards', ['toLabel' => __('To recipients')])

                    @if($mpIsExpired)
                        <div class="mp-callout mp-callout--warning" data-mp-expired>
                            <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-hourglass-end"></i></span>
                            <div class="mp-callout__copy">
                                <strong>{{ __('This batch has expired') }}</strong>
                                <p>{{ __('A batch must be confirmed within a day of being created. Upload it again to pay it.') }}</p>
                            </div>
                        </div>
                    @elseif($funding['short_by'] !== null)
                        <div class="mp-callout mp-callout--danger" data-mp-short>
                            <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-wallet"></i></span>
                            <div class="mp-callout__copy">
                                <strong>{{ __('Your wallet is short by :amount', ['amount' => $mpMoney($funding['short_by'])]) }}</strong>
                                <p>{{ __('Add funds to the wallet, or remove some rows and upload the batch again.') }}</p>
                            </div>
                        </div>
                    @endif
                @else
                    {{-- ---------- Progress and summary ---------- --}}
                    @include('frontend.user.mass_payment.partials._progress', ['statusUrl' => route('user.mass-payment.status', $batch)])

                    <div class="mp-callout mp-callout--info" data-mp-stale @unless($isStale) hidden @endunless>
                        <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-clock"></i></span>
                        <div class="mp-callout__copy">
                            <strong>{{ __('Still queued; payments continue automatically.') }}</strong>
                            <p>{{ __('You can leave this page. Your money stays reserved and the remaining payments go out on their own.') }}</p>
                        </div>
                    </div>

                    @if($mpStatus === MassPaymentBatchStatus::NeedsReview)
                        <div class="mp-callout mp-callout--warning" data-mp-on-hold>
                            <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-pause"></i></span>
                            <div class="mp-callout__copy">
                                <strong>{{ __('This batch is on hold') }}</strong>
                                <p>{{ __('Our team is reviewing it. Your money stays reserved; nothing more is paid until they resume it or return the rest to your wallet.') }}</p>
                            </div>
                        </div>
                    @endif

                    @if($batch->stop_requested_at !== null && ! $mpStatus->isTerminal())
                        <div class="mp-callout mp-callout--info" data-mp-stopping>
                            <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-hand"></i></span>
                            <div class="mp-callout__copy">
                                <strong>{{ __('Stopping') }}</strong>
                                <p>{{ __('Payments not yet made are being returned to your wallet.') }}</p>
                            </div>
                        </div>
                    @endif

                    @if($mpStatus->isTerminal())
                        <dl class="mp-facts" data-mp-final>
                            <div class="mp-fact"><dt>{{ __('Paid') }}</dt><dd>{{ trans_choice(':count payment|:count payments', (int) $batch->paid_count, ['count' => (int) $batch->paid_count]) }}</dd></div>
                            <div class="mp-fact"><dt>{{ __('Not paid') }}</dt><dd>{{ trans_choice(':count payment|:count payments', (int) $batch->failed_count, ['count' => (int) $batch->failed_count]) }}</dd></div>
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
                        <form method="POST" action="{{ route('user.mass-payment.stop', $batch) }}" class="mp-stop" data-mp-stop-form
                              data-confirm-message="{{ __('Stop this batch? Payments not yet made are returned to your wallet. This cannot be undone.') }}">
                            @csrf
                            <button type="submit" class="btn btn-light-danger btn-sm" data-mp-stop>
                                <i class="fa-solid fa-hand" aria-hidden="true"></i> {{ __('Stop the remaining payments') }}
                            </button>
                        </form>
                    @endif
                @endif

                {{-- ---------- Rows ---------- --}}
                @include('frontend.user.mass_payment.partials._rows', ['accountLabel' => __('Recipient'), 'rowView' => 'frontend.user.mass_payment.partials._row'])

                @if($isDraft && ! $mpIsExpired)
                    {{-- ---------- Confirm ---------- --}}
                    <form method="POST" action="{{ route('user.mass-payment.confirm', $batch) }}" class="mp-confirm" data-mp-confirm-form
                          data-processing-label="{{ __('Reserving...') }}">
                        @csrf
                        <input type="hidden" name="fingerprint" value="{{ $batch->preview_fingerprint }}">
                        <input type="hidden" name="expected_total" value="{{ $batch->total_payable }}">

                        @if($mpPayCount === 0)
                            <div class="mp-callout mp-callout--danger" data-mp-nothing-to-pay>
                                <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span>
                                <div class="mp-callout__copy">
                                    <strong>{{ __('There is no valid row to pay') }}</strong>
                                    <p>{{ __('Fix the rows marked invalid and upload the batch again.') }}</p>
                                </div>
                            </div>
                        @endif

                        @if($mpSkipCount > 0 && $mpPayCount > 0)
                            <div class="form-check mp-check">
                                <input class="form-check-input" type="checkbox" name="skip_invalid" value="1" id="mp-skip-invalid" data-mp-skip data-mp-required @checked(old('skip_invalid'))>
                                <label class="form-check-label" for="mp-skip-invalid">
                                    {{ trans_choice('Skip :skip invalid row and pay :pay|Skip :skip invalid rows and pay :pay', $mpSkipCount, ['skip' => $mpSkipCount, 'pay' => $mpPayCount]) }}
                                </label>
                            </div>
                        @endif

                        @if((int) $batch->warning_count > 0)
                            <div class="form-check mp-check">
                                <input class="form-check-input" type="checkbox" name="acknowledge_warnings" value="1" id="mp-acknowledge" data-mp-acknowledge data-mp-required @checked(old('acknowledge_warnings'))>
                                <label class="form-check-label" for="mp-acknowledge">
                                    {{ __('I reviewed the rows marked as possible duplicates and want to pay them.') }}
                                </label>
                            </div>
                        @endif

                        @if($hasWalletPin)
                            <div class="single-input-inner style-border mb-0 mt-3">
                                <label class="form-label" for="mp-wallet-pin">{{ __('Wallet PIN') }}</label>
                                <input type="password" id="mp-wallet-pin" class="form-control" name="wallet_pin"
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
                            {{ __('The total is reserved from your wallet now. Any payment that cannot be made comes back to your wallet, fee included.') }}
                        </p>
                    </form>
                @endif

                @if($rows->isNotEmpty())
                    {{-- Pay this batch again on a cadence: the lines are kept on a schedule and rung up afresh each time. --}}
                    <details class="mp-repeat mt-4" data-mp-repeat @if($errors->has('cadence') || $errors->has('starts_on') || $errors->has('pin') || $errors->has('weekday') || $errors->has('day_of_month')) open @endif>
                        <summary class="btn btn-light-success btn-sm"><i class="fa-solid fa-rotate" aria-hidden="true"></i> {{ __('Pay this batch again on a schedule') }}</summary>
                        <form method="POST" action="{{ route('user.mass-payment.schedules.store') }}" class="mp-repeat__form mt-3 d-flex flex-column gap-3" data-mp-repeat-form>
                            @csrf
                            <input type="hidden" name="batch" value="{{ $batch->uuid }}">
                            <p class="mb-0 text-muted">{{ __('Every run is a fresh batch of these :count lines: each recipient is checked again and each line priced as it is that day. Nothing is taken from your wallet until a run.', ['count' => $rows->count()]) }}</p>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="mp-repeat-name">{{ __('Name') }}</label>
                                    <input type="text" id="mp-repeat-name" name="name" class="form-control" maxlength="100" value="{{ old('name', $batch->name) }}" placeholder="{{ __('Payroll') }}">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="mp-repeat-cadence">{{ __('How often') }}</label>
                                    <select id="mp-repeat-cadence" name="cadence" class="form-select" data-mp-repeat-cadence>
                                        <option value="monthly" @selected(old('cadence', 'monthly') === 'monthly')>{{ __('Every month') }}</option>
                                        <option value="weekly" @selected(old('cadence') === 'weekly')>{{ __('Every week') }}</option>
                                        <option value="once" @selected(old('cadence') === 'once')>{{ __('Once, on a day') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-4" data-mp-repeat-for="monthly">
                                    <label class="form-label" for="mp-repeat-dom">{{ __('Day of the month') }}</label>
                                    <input type="number" id="mp-repeat-dom" name="day_of_month" class="form-control" min="1" max="31" value="{{ old('day_of_month', now()->day) }}">
                                    @error('day_of_month')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-4" data-mp-repeat-for="weekly">
                                    <label class="form-label" for="mp-repeat-weekday">{{ __('Weekday') }}</label>
                                    <select id="mp-repeat-weekday" name="weekday" class="form-select">
                                        @foreach([1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday')] as $mpDay => $mpDayName)
                                            <option value="{{ $mpDay }}" @selected((int) old('weekday', 5) === $mpDay)>{{ $mpDayName }}</option>
                                        @endforeach
                                    </select>
                                    @error('weekday')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="mp-repeat-starts">{{ __('First run on') }}</label>
                                    <input type="date" id="mp-repeat-starts" name="starts_on" class="form-control" min="{{ now()->toDateString() }}" value="{{ old('starts_on', now()->toDateString()) }}" required>
                                    @error('starts_on')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="mp-repeat-ends">{{ __('Last run on (optional)') }}</label>
                                    <input type="date" id="mp-repeat-ends" name="ends_on" class="form-control" value="{{ old('ends_on') }}">
                                    @error('ends_on')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="mp-repeat-low">{{ __('When the wallet is short') }}</label>
                                    <select id="mp-repeat-low" name="low_balance" class="form-select">
                                        <option value="retry" @selected(old('low_balance', 'retry') === 'retry')>{{ __('Try again in a few days, and tell me') }}</option>
                                        <option value="skip" @selected(old('low_balance') === 'skip')>{{ __('Skip that run, and tell me') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="mp-repeat-pin">{{ __('Wallet PIN') }}</label>
                                    <input type="password" id="mp-repeat-pin" name="pin" class="form-control" inputmode="numeric" autocomplete="off" maxlength="12" required>
                                    @error('pin')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="form-check">
                                <input type="hidden" name="remind" value="0">
                                <input class="form-check-input" type="checkbox" id="mp-repeat-remind" name="remind" value="1" @checked(old('remind'))>
                                <label class="form-check-label" for="mp-repeat-remind">{{ __('Remind me the day before each run') }}</label>
                            </div>
                            <div>
                                <button type="submit" class="btn btn-dark" data-mp-repeat-submit>{{ __('Schedule it') }}</button>
                                <a class="btn btn-link" href="{{ route('user.mass-payment.schedules.index') }}">{{ __('My scheduled batches') }}</a>
                            </div>
                        </form>
                    </details>
                @endif

                @if($isDraft)
                    <form method="POST" action="{{ route('user.mass-payment.discard', $batch) }}" class="mp-discard" data-mp-discard-form
                          data-confirm-message="{{ __('Discard this batch? Nothing has been paid; you can upload it again later.') }}">
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
