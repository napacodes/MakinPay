@extends('frontend.layouts.user.index')
@section('title', __('Pay :biller', ['biller' => $biller->name]))

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/bill-payment.css?v='.config('app.version').'-'.filemtime(public_path('frontend/css/bill-payment.css'))) }}">
@endpush

@section('content')
    @php
        $bpIsFixed = $biller->amount_type === \App\Enums\BillPayment\BillerAmountType::Fixed;
        $bpItems = $biller->activeItems;
        $bpCountry = getCountryByCode((string) $biller->country);
        $bpCountryName = title((string) ($bpCountry['name'] ?? $biller->country));
        $bpLabel = trim((string) $biller->customer_number_label) !== '' ? $biller->customer_number_label : __('Account number');
        $bpExample = trim((string) $biller->customer_number_example);
        // BP1: a provider the breaker paused takes no new payments.
        $bpPaused = $biller->provider?->isPaused() === true;
        $bpCanPay = ! $bpPaused && $wallets->isNotEmpty() && (! $bpIsFixed || $bpItems->isNotEmpty());
        $bpFeeRule = $feeDescription === '0' ? __('No fee') : $feeDescription;
    @endphp

    @include('frontend.user.partials._feature_summary_statistics', ['trxType' => \App\Enums\TrxType::BILL_PAYMENT])

    <div class="bp">
        <div class="row">
            <div class="col-lg-12 col-xl-7">
                <div class="single-form-card">
                    <x-user-feature-header
                        :title="__('Pay a bill')"
                        :subtitle="__('Check the details on your bill, then pay from your wallet.')"
                        icon="fas fa-receipt"
                    >
                        @if($biller->category)
                            <a class="btn btn-light-success btn-sm" href="{{ route('user.bill-payment.billers', ['category' => $biller->category->code, 'country' => $biller->country]) }}">
                                <i class="fa-solid fa-arrow-right bp-arrow bp-arrow--back" aria-hidden="true"></i> {{ __('Back') }}
                            </a>
                        @endif
                    </x-user-feature-header>

                    <div class="card-main">
                        <div class="bp-biller-strip">
                            @include('frontend.user.bill_payment.partials._logo', [
                                'logo' => $biller->logo,
                                'icon' => $biller->category?->icon,
                                'size' => 'lg',
                            ])
                            <div class="bp-biller-strip__copy">
                                <h2 class="bp-biller-strip__name">{{ $biller->name }}</h2>
                                <p class="bp-biller-strip__meta">
                                    <span>{{ $bpCountryName }}</span>
                                    @if($biller->category)
                                        <span class="bp-payment__dot" aria-hidden="true"></span>
                                        <span>{{ $biller->category->name }}</span>
                                    @endif
                                    <span class="bp-payment__dot" aria-hidden="true"></span>
                                    <span>{{ $amountHint }}</span>
                                </p>
                                @if($biller->description)
                                    <p class="bp-biller-strip__description">{{ $biller->description }}</p>
                                @endif
                            </div>
                        </div>

                        @if($bpPaused)
                            <div class="bp-callout bp-callout--warning" data-bp-temporarily-unavailable>
                                <span class="bp-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-pause"></i></span>
                                <div class="bp-callout__copy">
                                    <strong>{{ __('Temporarily unavailable') }}</strong>
                                    <p>{{ __('Payments to :biller are temporarily unavailable. Please try again later.', ['biller' => $biller->name]) }}</p>
                                </div>
                            </div>
                        @elseif($wallets->isEmpty())
                            <div class="bp-callout bp-callout--warning" data-bp-no-wallet>
                                <span class="bp-callout__icon" aria-hidden="true"><i class="fa-solid fa-wallet"></i></span>
                                <div class="bp-callout__copy">
                                    <strong>{{ __('You need a :currency wallet to pay this biller', ['currency' => $currency]) }}</strong>
                                    <p>{{ __('This biller is paid in :currency. Add or switch on a :currency wallet, then come back to pay.', ['currency' => $currency]) }}</p>
                                    <a class="btn btn-base btn-sm" href="{{ route('user.wallet.index') }}">{{ __('Manage wallets') }}</a>
                                </div>
                            </div>
                        @elseif($bpIsFixed && $bpItems->isEmpty())
                            <div class="bp-callout bp-callout--warning">
                                <span class="bp-callout__icon" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
                                <div class="bp-callout__copy">
                                    <strong>{{ __('No packages to pay right now') }}</strong>
                                    <p>{{ __('This biller has no packages available at the moment. Try again later.') }}</p>
                                </div>
                            </div>
                        @endif

                        @if($bpCanPay)
                            <form method="POST" action="{{ route('user.bill-payment.store', $biller->id) }}" class="bp-form"
                                  data-bp-pay-form
                                  data-quote-url="{{ route('user.bill-payment.quote', $biller->id) }}"
                                  data-currency="{{ $currency }}"
                                  data-decimals="{{ $decimals }}"
                                  data-pattern="{{ $biller->customer_number_pattern }}"
                                  data-pattern-message="{{ $bpExample !== '' ? __('This does not look like a :label. Example: :example', ['label' => $bpLabel, 'example' => $bpExample]) : __('This does not look like a :label.', ['label' => $bpLabel]) }}"
                                  data-no-fee="{{ __('No fee') }}"
                                  data-short-message="{{ __('Your wallet balance is too low for this payment.') }}"
                                  data-error-message="{{ __('We could not work out the total. Check the details and try again.') }}"
                                  data-processing-label="{{ __('Processing...') }}"
                                  @if($verifyUrl)
                                      data-verify-url="{{ $verifyUrl }}"
                                      data-verify-name-label="{{ __('Account name: :name') }}"
                                  @endif>
                                @csrf
                                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                                <input type="hidden" name="expected_total" value="" data-bp-expected-total>

                                <div class="single-input-inner style-border">
                                    <label class="form-label" for="bp-customer-number">{{ $bpLabel }}</label>
                                    <input type="text" id="bp-customer-number" class="form-control bp-mono" name="customer_number"
                                           value="{{ old('customer_number', $prefillNumber) }}"
                                           placeholder="{{ $bpExample !== '' ? __('e.g. :example', ['example' => $bpExample]) : __('Enter the :label', ['label' => $bpLabel]) }}"
                                           maxlength="64" autocomplete="off" spellcheck="false" dir="ltr" required
                                           @readonly($savedBill !== null)
                                           @if($biller->customer_number_help) aria-describedby="bp-customer-number-help" @endif
                                           data-bp-customer-number>
                                    @if($biller->customer_number_help)
                                        <span class="small color-base fw-500" id="bp-customer-number-help">{{ $biller->customer_number_help }}</span>
                                    @endif
                                    <span class="bp-field-hint" data-bp-number-hint aria-live="polite" hidden></span>
                                    @if($verifyUrl)
                                        <span class="small color-base fw-500 d-block mt-1" data-bp-customer-name aria-live="polite" hidden></span>
                                    @endif
                                    @error('customer_number')<div class="text-danger small mt-1" data-bp-error="customer_number">{{ $message }}</div>@enderror
                                </div>

                                @if($bpIsFixed)
                                    <fieldset class="bp-items-field mt-3">
                                        <legend class="form-label">{{ __('Choose a package') }}</legend>
                                        <div class="bp-items">
                                            @foreach($bpItems as $item)
                                                <label class="bp-item">
                                                    <input type="radio" class="bp-item__input" name="biller_item_id" value="{{ $item->id }}" required data-bp-item
                                                           @checked((string) old('biller_item_id', $prefillItemId) === (string) $item->id || ($bpItems->count() === 1 && old('biller_item_id') === null))>
                                                    <span class="bp-item__body">
                                                        <span class="bp-item__check" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                                                        <span class="bp-item__name">{{ $item->name }}</span>
                                                        <span class="bp-item__price" dir="ltr">{{ money($item->amount, $currency, false) }} {{ $currency }}</span>
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                        @error('biller_item_id')<div class="text-danger small mt-1" data-bp-error="biller_item_id">{{ $message }}</div>@enderror
                                        @error('amount')<div class="text-danger small mt-1" data-bp-error="amount">{{ $message }}</div>@enderror
                                    </fieldset>
                                @else
                                    <div class="single-input-inner style-border mt-3">
                                        <label class="form-label" for="bp-amount">{{ __('Amount') }}</label>
                                        <div class="input-group">
                                            <input type="number" id="bp-amount" class="form-control" name="amount"
                                                   value="{{ old('amount') }}"
                                                   step="{{ \App\Support\AmountEntry::smallestUnit($currency) }}"
                                                   min="{{ \App\Support\AmountEntry::smallestUnit($currency) }}"
                                                   inputmode="decimal" placeholder="{{ __('Enter amount') }}" required data-bp-amount>
                                            <span class="input-group-text">{{ $currency }}</span>
                                        </div>
                                        <span class="small color-base fw-500">{{ $amountHint }}</span>
                                        @error('amount')<div class="text-danger small mt-1" data-bp-error="amount">{{ $message }}</div>@enderror
                                    </div>
                                @endif

                                <div class="single-select-inner style-border mt-3">
                                    <label for="bp-wallet">{{ __('Pay from') }}</label>
                                    <select id="bp-wallet" class="form-select" name="wallet_id" required data-bp-wallet>
                                        @if($wallets->count() > 1)
                                            <option value="">{{ __('Select wallet') }}</option>
                                        @endif
                                        @foreach($wallets as $wallet)
                                            <option value="{{ $wallet->id }}" @selected((string) old('wallet_id') === (string) $wallet->id || $wallets->count() === 1)>
                                                {{ $wallet->currency->code }} · {{ money($wallet->balance, $wallet->currency->code, false) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('wallet_id')<div class="text-danger small mt-1" data-bp-error="wallet_id">{{ $message }}</div>@enderror
                                </div>

                                @if($hasWalletPin)
                                    {{-- Shown only to a customer who has set a PIN, as on send money. --}}
                                    <div class="single-input-inner style-border mb-0 mt-3">
                                        <label class="form-label" for="bp-wallet-pin">{{ __('Wallet PIN') }}</label>
                                        <input type="password" id="bp-wallet-pin" class="form-control" name="wallet_pin"
                                               inputmode="numeric" maxlength="6" autocomplete="off"
                                               placeholder="{{ __('Enter your 6-digit wallet PIN') }}" required>
                                        <span class="small color-base fw-500">{{ __('Confirm this payment with your wallet PIN.') }}</span>
                                    </div>
                                @endif

                                @if($savedBill)
                                    {{-- Already one of "My bills": paying it again only moves it to the top. --}}
                                    <input type="hidden" name="save" value="1">
                                    <div class="bp-save bp-save--kept mt-3" data-bp-saved-from="{{ $savedBill->id }}">
                                        <i class="fa-solid fa-bookmark" aria-hidden="true"></i>
                                        <span class="bp-save__kept-name">{{ __('From My bills: :name', ['name' => $savedBill->title()]) }}</span>
                                        <a class="bp-save__other" href="{{ route('user.bill-payment.create', $biller->id) }}">{{ __('Pay another number') }}</a>
                                    </div>
                                @else
                                    @php
                                        $bpSaveChecked = session()->hasOldInput() ? old('save') === '1' : true;
                                    @endphp
                                    <div class="bp-save mt-3" data-bp-save>
                                        <div class="form-check mb-0">
                                            <input class="form-check-input" type="checkbox" name="save" value="1" id="bp-save" data-bp-save-toggle @checked($bpSaveChecked)>
                                            <label class="form-check-label fw-semibold" for="bp-save">{{ __('Save to My bills') }}</label>
                                        </div>
                                        <div class="bp-save__name" data-bp-save-name @if(! $bpSaveChecked) hidden @endif>
                                            <label class="form-label" for="bp-save-name">{{ __('Name it') }} <span class="bp-save__optional">{{ __('(optional)') }}</span></label>
                                            <input type="text" id="bp-save-name" class="form-control" name="save_name"
                                                   value="{{ old('save_name') }}" maxlength="{{ \App\Services\BillPayment\SavedBillBook::NICKNAME_MAX }}"
                                                   placeholder="{{ __('e.g. Living room TV') }}" autocomplete="off">
                                            @error('save_name')<div class="text-danger small mt-1" data-bp-error="save_name">{{ $message }}</div>@enderror
                                        </div>
                                    </div>
                                @endif

                                @error('expected_total')
                                    <div class="bp-callout bp-callout--warning mt-3" data-bp-error="expected_total">
                                        <span class="bp-callout__icon" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
                                        <div class="bp-callout__copy"><strong>{{ $message }}</strong></div>
                                    </div>
                                @enderror

                                <button type="submit" class="btn btn-base w-100 mt-4 bp-submit" data-bp-submit>
                                    <x-icon name="check" height="20" />
                                    {{ __('Pay bill') }}
                                </button>

                                <p class="bp-reassure">
                                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                                    {{ __('Your wallet is charged now. If the bill cannot be paid, the full amount goes back to your wallet.') }}
                                </p>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-12 col-xl-5">
                <div class="single-form-card">
                    <x-user-feature-header
                        :title="__('Summary')"
                        :subtitle="__('Worked out by us before you pay.')"
                        icon="fas fa-chart-pie"
                        compact
                    />
                    <div class="card-main">
                        <ul class="summery-list list-unstyled bp-summary" data-bp-summary>
                            <li>
                                <span>{{ __('Bill amount') }}</span>
                                <span dir="ltr" data-bp-quote="bill_amount">-</span>
                            </li>
                            <li>
                                <span>
                                    {{ __('Fee') }}
                                    <small class="bp-summary__rule" data-bp-quote-text="fee_description">{{ $bpFeeRule }}</small>
                                </span>
                                <span dir="ltr" data-bp-quote="fee">-</span>
                            </li>
                            <li class="bp-summary__total">
                                <strong>{{ __('Total debit') }}</strong>
                                <strong dir="ltr" data-bp-quote="total">-</strong>
                            </li>
                            <li>
                                <span>{{ __('Wallet balance') }}</span>
                                <span dir="ltr" data-bp-quote="balance">-</span>
                            </li>
                            <li>
                                <span>{{ __('Balance after') }}</span>
                                <span dir="ltr" data-bp-quote="balance_after">-</span>
                            </li>
                        </ul>
                        <div class="bp-summary__alert" role="alert" data-bp-quote-error hidden></div>
                    </div>
                </div>

                @if($processingNote || $biller->instructions)
                    <div class="single-form-card">
                        <div class="card-main bp-notes">
                            @if($processingNote)
                                <div class="bp-note">
                                    <span class="bp-note__icon" aria-hidden="true"><i class="fa-solid fa-clock"></i></span>
                                    <div class="bp-note__copy">
                                        <strong>{{ __('How long it takes') }}</strong>
                                        <p>{{ $processingNote }}</p>
                                    </div>
                                </div>
                            @endif
                            @if($biller->instructions)
                                <div class="bp-note bp-note--info">
                                    <span class="bp-note__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
                                    <div class="bp-note__copy">
                                        <strong>{{ __('Before you pay') }}</strong>
                                        <p>{!! nl2br(e($biller->instructions)) !!}</p>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('frontend/js/bill-payment.js?v='.config('app.version').'-'.filemtime(public_path('frontend/js/bill-payment.js'))) }}"></script>
@endpush
