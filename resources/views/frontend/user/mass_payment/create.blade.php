@extends('frontend.layouts.user.index')
@section('title', __('New mass payment'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/mass-payment.css?v='.config('app.version').'-'.filemtime(public_path('frontend/css/mass-payment.css'))) }}">
@endpush

@section('content')
    @php
        $mpRows = $gridRows;
        $mpOldWallet = (string) old('wallet_id', $from?->wallet?->uuid ?? '');
        $mpName = (string) old('name', $from?->name ?? '');
    @endphp

    <div class="mp">
        <div class="row">
            <div class="col-lg-12 col-xl-8">
                <div class="single-form-card">
                    <x-user-feature-header
                        :title="__('New mass payment')"
                        :subtitle="__('Add your recipients and amounts. Nothing is paid until you review the batch and confirm it.')"
                        icon="fas fa-users"
                    >
                        <a class="btn btn-light-success btn-sm" href="{{ route('user.mass-payment.index') }}">
                            <i class="fas fa-list" aria-hidden="true"></i> {{ __('History') }}
                        </a>
                    </x-user-feature-header>

                    <div class="card-main">
                        @if($wallets->isEmpty())
                            <div class="mp-callout mp-callout--warning" data-mp-no-wallet>
                                <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-wallet"></i></span>
                                <div class="mp-callout__copy">
                                    <strong>{{ __('You need an active wallet to pay from') }}</strong>
                                    <p>{{ __('Add or switch on a wallet, then come back to create a batch.') }}</p>
                                    <a class="btn btn-base btn-sm" href="{{ route('user.wallet.index') }}">{{ __('Manage wallets') }}</a>
                                </div>
                            </div>
                        @else
                            @include('frontend.user.mass_payment.partials._pay_to', ['current' => 'wallets', 'payoutsOpen' => $payoutsOpen])

                            @if($from !== null)
                                <div class="mp-callout mp-callout--info" data-mp-fixing>
                                    <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-pen"></i></span>
                                    <div class="mp-callout__copy">
                                        <strong>{{ __('Editing batch :reference', ['reference' => $from->reference]) }}</strong>
                                        <p>{{ __('Change any row, then review again. The old draft is discarded once the new one is built.') }}</p>
                                    </div>
                                </div>
                            @endif

                            <form method="POST" action="{{ route('user.mass-payment.store') }}" enctype="multipart/form-data"
                                  class="mp-form" data-mp-create-form
                                  data-max-rows="{{ $maxRows }}"
                                  data-max-rows-message="{{ __('A batch may have at most :max rows.', ['max' => $maxRows]) }}"
                                  data-processing-label="{{ __('Reading your batch...') }}"
                                  data-home-dial="{{ $home !== null ? preg_replace('/\D+/', '', (string) $home['dial_code']) : '' }}"
                                  data-kind-local="{{ __('Local number') }}"
                                  data-kind-international="{{ __('International number') }}"
                                  data-kind-needs-code="{{ __('Add the + country code') }}"
                                  data-kind-email="{{ __('Email') }}"
                                  data-kind-wallet="{{ __('Wallet ID') }}"
                                  data-total-template="{{ __(':rows rows · :amount before fees') }}">
                                @csrf
                                @if($from !== null)
                                    <input type="hidden" name="replaces" value="{{ $from->uuid }}">
                                @endif

                                <div class="single-input-inner style-border">
                                    <label for="mp-name">{{ __('Name') }} <span class="color-base fw-400">({{ __('optional') }})</span></label>
                                    <input type="text" id="mp-name" class="form-control" name="name" value="{{ $mpName }}" maxlength="100" autocomplete="off" placeholder="{{ __('For example: Salaries October') }}">
                                    @error('name')<div class="text-danger small mt-1" data-mp-error="name">{{ $message }}</div>@enderror
                                </div>

                                <div class="single-select-inner style-border mt-3">
                                    <label for="mp-wallet">{{ __('Pay from') }}</label>
                                    <select id="mp-wallet" class="form-select" name="wallet_id" required data-mp-wallet>
                                        @if($wallets->count() > 1)
                                            <option value="">{{ __('Select wallet') }}</option>
                                        @endif
                                        @foreach($wallets as $wallet)
                                            <option value="{{ $wallet->uuid }}" data-currency="{{ $wallet->currency->code }}" data-decimals="{{ \App\Support\Money::decimals((string) $wallet->currency->code) }}" @selected($mpOldWallet === (string) $wallet->uuid || $wallets->count() === 1)>
                                                {{ $wallet->currency->code }} · {{ money($wallet->balance, $wallet->currency->code, false) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <span class="small color-base fw-500">{{ __('Every recipient is paid in this wallet\'s currency.') }}</span>
                                    @error('wallet_id')<div class="text-danger small mt-1" data-mp-error="wallet_id">{{ $message }}</div>@enderror
                                </div>

                                <fieldset class="mp-source mt-4">
                                    <legend class="form-label">{{ __('How do you want to add the payments?') }}</legend>
                                    <div class="mp-tabs" role="radiogroup">
                                        <label class="mp-tab">
                                            <input type="radio" class="mp-tab__input" name="source" value="{{ \App\Services\MassPayment\MassPaymentService::SOURCE_CSV }}" data-mp-source @checked($source === \App\Services\MassPayment\MassPaymentService::SOURCE_CSV)>
                                            <span class="mp-tab__body"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> {{ __('Upload CSV') }}</span>
                                        </label>
                                        <label class="mp-tab">
                                            <input type="radio" class="mp-tab__input" name="source" value="{{ \App\Services\MassPayment\MassPaymentService::SOURCE_MANUAL }}" data-mp-source @checked($source === \App\Services\MassPayment\MassPaymentService::SOURCE_MANUAL)>
                                            <span class="mp-tab__body"><i class="fa-solid fa-table-list" aria-hidden="true"></i> {{ __('Enter rows') }}</span>
                                        </label>
                                    </div>
                                    @error('source')<div class="text-danger small mt-1" data-mp-error="source">{{ $message }}</div>@enderror
                                </fieldset>

                                <div class="mp-panel mt-3" data-mp-panel="{{ \App\Services\MassPayment\MassPaymentService::SOURCE_CSV }}" @if($source !== \App\Services\MassPayment\MassPaymentService::SOURCE_CSV) hidden @endif>
                                    <label class="mp-drop" for="mp-file">
                                        <span class="mp-drop__icon" aria-hidden="true"><i class="fa-solid fa-cloud-arrow-up"></i></span>
                                        <span class="mp-drop__title" data-mp-file-name data-empty-label="{{ __('Choose a CSV file') }}">{{ __('Choose a CSV file') }}</span>
                                        <span class="mp-drop__hint">{{ __('Up to :rows rows and :size KB. Columns: recipient, amount, and optionally note and reference.', ['rows' => $maxRows, 'size' => $maxUploadKb]) }}</span>
                                        <input type="file" id="mp-file" name="file" class="mp-drop__input" accept=".csv,.txt,text/csv,text/plain" data-mp-file>
                                    </label>
                                    @error('file')<div class="text-danger small mt-1" data-mp-error="file">{{ $message }}</div>@enderror
                                    <p class="mp-help">
                                        <a href="{{ route('user.mass-payment.template') }}" data-mp-template>
                                            <i class="fa-solid fa-download" aria-hidden="true"></i> {{ __('Download the template') }}
                                        </a>
                                        <span>{{ __('A recipient is an email address, a mobile number in international format (+...) or a wallet ID.') }}</span>
                                    </p>
                                </div>

                                <div class="mp-panel mt-3" data-mp-panel="{{ \App\Services\MassPayment\MassPaymentService::SOURCE_MANUAL }}" @if($source !== \App\Services\MassPayment\MassPaymentService::SOURCE_MANUAL) hidden @endif>
                                    <div class="mp-callout mp-callout--info mp-callout--compact" data-mp-recipient-hint>
                                        <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
                                        <div class="mp-callout__copy">
                                            <p>
                                                {{ __('A recipient is a phone number, an email or a wallet ID.') }}
                                                @if($home !== null)
                                                    <span class="fw-semibold">{{ __(':country numbers need no :dial', ['country' => $home['name'], 'dial' => $home['dial_code']]) }}</span>;
                                                    {{ __('numbers from other countries start with their + code.') }}
                                                @else
                                                    {{ __('Phone numbers start with their + country code.') }}
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                    <div class="mp-grid-wrap">
                                        <table class="mp-grid" data-mp-grid>
                                            <thead>
                                                <tr>
                                                    <th scope="col">{{ __('Recipient') }}</th>
                                                    <th scope="col">{{ __('Amount') }}</th>
                                                    <th scope="col">{{ __('Note') }}</th>
                                                    <th scope="col">{{ __('Reference') }}</th>
                                                    <th scope="col"><span class="visually-hidden">{{ __('Remove') }}</span></th>
                                                </tr>
                                            </thead>
                                            <tbody data-mp-grid-body>
                                                @foreach($mpRows as $index => $mpRow)
                                                    <tr data-mp-grid-row>
                                                        <td><input type="text" class="form-control mp-mono" name="rows[{{ $index }}][recipient]" value="{{ is_array($mpRow) ? ($mpRow['recipient'] ?? '') : '' }}" maxlength="191" autocomplete="off" spellcheck="false" dir="ltr" placeholder="{{ __('Phone, email or wallet ID') }}" aria-label="{{ __('Recipient') }}"><span class="mp-kind" data-mp-kind></span></td>
                                                        <td><input type="text" class="form-control" name="rows[{{ $index }}][amount]" value="{{ is_array($mpRow) ? ($mpRow['amount'] ?? '') : '' }}" maxlength="64" inputmode="decimal" autocomplete="off" dir="ltr" placeholder="0.00" aria-label="{{ __('Amount') }}"></td>
                                                        <td><input type="text" class="form-control" name="rows[{{ $index }}][note]" value="{{ is_array($mpRow) ? ($mpRow['note'] ?? '') : '' }}" maxlength="255" autocomplete="off" aria-label="{{ __('Note') }}"></td>
                                                        <td><input type="text" class="form-control mp-mono" name="rows[{{ $index }}][reference]" value="{{ is_array($mpRow) ? ($mpRow['reference'] ?? '') : '' }}" maxlength="100" autocomplete="off" dir="ltr" aria-label="{{ __('Reference') }}"></td>
                                                        <td><button type="button" class="mp-grid__remove" data-mp-remove-row aria-label="{{ __('Remove row') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <template data-mp-grid-template>
                                        <tr data-mp-grid-row>
                                            <td><input type="text" class="form-control mp-mono" name="rows[__INDEX__][recipient]" maxlength="191" autocomplete="off" spellcheck="false" dir="ltr" placeholder="{{ __('Phone, email or wallet ID') }}" aria-label="{{ __('Recipient') }}"><span class="mp-kind" data-mp-kind></span></td>
                                            <td><input type="text" class="form-control" name="rows[__INDEX__][amount]" maxlength="64" inputmode="decimal" autocomplete="off" dir="ltr" placeholder="0.00" aria-label="{{ __('Amount') }}"></td>
                                            <td><input type="text" class="form-control" name="rows[__INDEX__][note]" maxlength="255" autocomplete="off" aria-label="{{ __('Note') }}"></td>
                                            <td><input type="text" class="form-control mp-mono" name="rows[__INDEX__][reference]" maxlength="100" autocomplete="off" dir="ltr" aria-label="{{ __('Reference') }}"></td>
                                            <td><button type="button" class="mp-grid__remove" data-mp-remove-row aria-label="{{ __('Remove row') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></td>
                                        </tr>
                                    </template>
                                    <div class="mp-grid__tools">
                                        <div class="mp-grid__buttons">
                                            <button type="button" class="btn btn-light btn-sm" data-mp-add-row>
                                                <i class="fa-solid fa-plus" aria-hidden="true"></i> {{ __('Add row') }}
                                            </button>
                                            <button type="button" class="btn btn-light btn-sm" data-mp-paste-toggle aria-expanded="false" aria-controls="mp-paste">
                                                <i class="fa-solid fa-paste" aria-hidden="true"></i> {{ __('Paste from a spreadsheet') }}
                                            </button>
                                        </div>
                                        <span class="mp-grid__total" data-mp-grid-total aria-live="polite"></span>
                                    </div>
                                    <div class="mp-paste" id="mp-paste" data-mp-paste hidden>
                                        <label class="form-label" for="mp-paste-text">{{ __('Paste rows copied from Excel or Google Sheets') }}</label>
                                        <textarea id="mp-paste-text" class="form-control mp-mono" rows="4" dir="ltr" spellcheck="false" data-mp-paste-text
                                                  placeholder="{{ __('recipient, amount, note, reference — one payment per line') }}"></textarea>
                                        <div class="mp-paste__actions">
                                            <button type="button" class="btn btn-base btn-sm" data-mp-paste-apply>{{ __('Add these rows') }}</button>
                                            <span class="small color-base" data-mp-paste-result aria-live="polite"
                                                  data-added-template="{{ __(':count rows added.') }}"
                                                  data-full-template="{{ __(':count rows added; the batch is full.') }}"></span>
                                        </div>
                                    </div>
                                    <span class="mp-grid__limit" data-mp-grid-limit aria-live="polite" hidden></span>
                                    @error('rows')<div class="text-danger small mt-1" data-mp-error="rows">{{ $message }}</div>@enderror
                                    @foreach($errors->getMessages() as $mpField => $mpMessages)
                                        @if(str_starts_with($mpField, 'rows.'))
                                            <div class="text-danger small mt-1">{{ $mpMessages[0] }}</div>
                                            @break
                                        @endif
                                    @endforeach
                                </div>

                                <button type="submit" class="btn btn-base w-100 mt-4" data-mp-submit>
                                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                    {{ __('Review batch') }}
                                </button>

                                <p class="mp-reassure">
                                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                                    {{ __('Reviewing moves no money. You will see every line, its fee and the total before you confirm.') }}
                                </p>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-12 col-xl-4">
                <div class="single-form-card">
                    <div class="card-main mp-side">
                        <h2 class="mp-side__title">{{ __('How it works') }}</h2>
                        <ol class="mp-steps">
                            <li>{{ __('Add your recipients and amounts, from a file or by hand.') }}</li>
                            <li>{{ __('Review the batch: every line is checked and priced, with its fee.') }}</li>
                            <li>{{ __('Confirm with your wallet PIN. The total is reserved at once.') }}</li>
                            <li>{{ __('Payments go out one by one. Anything that cannot be paid comes back to your wallet.') }}</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('frontend/js/mass-payment.js?v='.config('app.version').'-'.filemtime(public_path('frontend/js/mass-payment.js'))) }}"></script>
@endpush
