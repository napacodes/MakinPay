@extends('frontend.layouts.user.index')
@section('title', __('New mass payout'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/mass-payment.css?v='.config('app.version').'-'.filemtime(public_path('frontend/css/mass-payment.css'))) }}">
@endpush

@section('content')
    @php
        $mpSourceCsv = \App\Services\MassPayment\MassPaymentService::SOURCE_CSV;
        $mpSourceManual = \App\Services\MassPayment\MassPaymentService::SOURCE_MANUAL;
        $mpFieldLabel = fn (array $field): string => is_scalar($field['label'] ?? null) && trim((string) $field['label']) !== '' ? (string) $field['label'] : \Illuminate\Support\Str::headline((string) $field['name']);
        $mpFieldNames = array_map(fn (array $field): string => (string) $field['name'], $fields);
    @endphp

    <div class="mp">
        <div class="row">
            <div class="col-lg-12 col-xl-8">
                <div class="single-form-card">
                    <x-user-feature-header
                        :title="__('New mass payout')"
                        :subtitle="__('Choose the wallet and how the payouts are sent, then add the accounts and amounts. Nothing is sent until you review the batch and confirm it.')"
                        icon="fas fa-building-columns"
                    >
                        <a class="btn btn-light-success btn-sm" href="{{ route('user.mass-payout.index') }}">
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
                            @include('frontend.user.mass_payment.partials._pay_to', ['current' => 'accounts', 'walletsOpen' => $walletsOpen])

                            {{-- ---------- Step 1: wallet and method ---------- --}}
                            <form method="GET" action="{{ route('user.mass-payout.create') }}" class="mp-form" data-mp-choose-form>
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-5">
                                        <div class="single-select-inner style-border mb-0">
                                            <label for="mpo-wallet">{{ __('Pay from') }}</label>
                                            <select id="mpo-wallet" class="form-select" name="wallet" required>
                                                @if($wallets->count() > 1)
                                                    <option value="">{{ __('Select wallet') }}</option>
                                                @endif
                                                @foreach($wallets as $walletOption)
                                                    <option value="{{ $walletOption->uuid }}" @selected($wallet !== null && (string) $wallet->uuid === (string) $walletOption->uuid)>
                                                        {{ $walletOption->currency->code }} · {{ money($walletOption->balance, $walletOption->currency->code, false) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-5">
                                        <div class="single-select-inner style-border mb-0">
                                            <label for="mpo-method">{{ __('Send through') }}</label>
                                            <select id="mpo-method" class="form-select" name="method" @disabled($wallet === null || $methods->isEmpty())>
                                                @if($methods->count() !== 1)
                                                    <option value="">{{ $wallet === null ? __('Choose a wallet first') : __('Select payout method') }}</option>
                                                @endif
                                                @foreach($methods as $methodOption)
                                                    <option value="{{ $methodOption->id }}" @selected($method !== null && (int) $method->id === (int) $methodOption->id)>{{ $methodOption->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-light w-100" data-mp-choose>{{ __('Continue') }}</button>
                                    </div>
                                </div>

                                @if($wallet !== null && $methods->isEmpty())
                                    <div class="mp-callout mp-callout--info mt-3" data-mp-no-method>
                                        <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
                                        <div class="mp-callout__copy">
                                            <strong>{{ __('No payout method is available for :currency', ['currency' => $wallet->currency->code]) }}</strong>
                                            <p>{{ __('Choose a wallet in another currency, or ask support which payout methods are offered.') }}</p>
                                        </div>
                                    </div>
                                @endif
                            </form>

                            @if($wallet !== null && $method !== null)
                                {{-- ---------- Step 2: the payouts ---------- --}}
                                <form method="POST" action="{{ route('user.mass-payout.store') }}" enctype="multipart/form-data"
                                      class="mp-form mt-4" data-mp-create-form
                                      data-max-rows="{{ $maxRows }}"
                                      data-max-rows-message="{{ __('A batch may have at most :max rows.', ['max' => $maxRows]) }}"
                                      data-processing-label="{{ __('Reading your batch...') }}">
                                    @csrf
                                    <input type="hidden" name="wallet_id" value="{{ $wallet->uuid }}">
                                    <input type="hidden" name="method_id" value="{{ $method->id }}">
                                    @if($from !== null)
                                        <input type="hidden" name="replaces" value="{{ $from->uuid }}">
                                    @endif

                                    @foreach(['wallet_id', 'method_id'] as $mpField)
                                        @error($mpField)<div class="text-danger small mb-2" data-mp-error="{{ $mpField }}">{{ $message }}</div>@enderror
                                    @endforeach

                                    <div class="single-input-inner style-border">
                                        <label for="mp-name">{{ __('Name') }} <span class="color-base fw-400">({{ __('optional') }})</span></label>
                                        <input type="text" id="mp-name" class="form-control" name="name" value="{{ old('name', $from?->name ?? '') }}" maxlength="100" autocomplete="off" placeholder="{{ __('For example: Field team payouts') }}">
                                        @error('name')<div class="text-danger small mt-1" data-mp-error="name">{{ $message }}</div>@enderror
                                    </div>

                                    @if($from !== null)
                                        <div class="mp-callout mp-callout--info" data-mp-rebuilding>
                                            <span class="mp-callout__icon" aria-hidden="true"><i class="fa-solid fa-pen"></i></span>
                                            <div class="mp-callout__copy">
                                                <strong>{{ __('Fixing batch :reference', ['reference' => $from->reference]) }}</strong>
                                                <p>{{ __('Correct the rows and review again. The old draft is discarded once the new one is ready.') }}</p>
                                            </div>
                                        </div>
                                    @endif

                                    <fieldset class="mp-source">
                                        <legend class="form-label">{{ __('How do you want to add the payouts?') }}</legend>
                                        <div class="mp-tabs" role="radiogroup">
                                            <label class="mp-tab">
                                                <input type="radio" class="mp-tab__input" name="source" value="{{ $mpSourceCsv }}" data-mp-source @checked($source === $mpSourceCsv)>
                                                <span class="mp-tab__body"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> {{ __('Upload CSV') }}</span>
                                            </label>
                                            <label class="mp-tab">
                                                <input type="radio" class="mp-tab__input" name="source" value="{{ $mpSourceManual }}" data-mp-source @checked($source === $mpSourceManual)>
                                                <span class="mp-tab__body"><i class="fa-solid fa-table-list" aria-hidden="true"></i> {{ __('Enter rows') }}</span>
                                            </label>
                                        </div>
                                        @error('source')<div class="text-danger small mt-1" data-mp-error="source">{{ $message }}</div>@enderror
                                    </fieldset>

                                    <div class="mp-panel mt-3" data-mp-panel="{{ $mpSourceCsv }}" @if($source !== $mpSourceCsv) hidden @endif>
                                        <label class="mp-drop" for="mpo-file">
                                            <span class="mp-drop__icon" aria-hidden="true"><i class="fa-solid fa-cloud-arrow-up"></i></span>
                                            <span class="mp-drop__title" data-mp-file-name data-empty-label="{{ __('Choose a CSV file') }}">{{ __('Choose a CSV file') }}</span>
                                            <span class="mp-drop__hint">{{ __('Up to :rows rows and :size KB. Columns: amount, the account details below, and optionally note and reference.', ['rows' => $maxRows, 'size' => $maxUploadKb]) }}</span>
                                            <input type="file" id="mpo-file" name="file" class="mp-drop__input" accept=".csv,.txt,text/csv,text/plain" data-mp-file>
                                        </label>
                                        @error('file')<div class="text-danger small mt-1" data-mp-error="file">{{ $message }}</div>@enderror
                                        <p class="mp-help">
                                            <a href="{{ route('user.mass-payout.template', ['wallet' => $wallet->uuid, 'method' => $method->id]) }}" data-mp-template>
                                                <i class="fa-solid fa-download" aria-hidden="true"></i> {{ __('Download the template for :method', ['method' => $method->name]) }}
                                            </a>
                                            <span>{{ __('Account columns: :fields.', ['fields' => implode(', ', $mpFieldNames)]) }}</span>
                                        </p>
                                    </div>

                                    <div class="mp-panel mt-3" data-mp-panel="{{ $mpSourceManual }}" @if($source !== $mpSourceManual) hidden @endif>
                                        <div class="mp-grid-wrap">
                                            <table class="mp-grid" data-mp-grid>
                                                <thead>
                                                    <tr>
                                                        <th scope="col">{{ __('Amount') }}</th>
                                                        @foreach($fields as $field)
                                                            <th scope="col">{{ $mpFieldLabel($field) }}@if($field['validation'] === 'required')<span class="text-danger" aria-hidden="true"> *</span>@endif</th>
                                                        @endforeach
                                                        <th scope="col">{{ __('Note') }}</th>
                                                        <th scope="col">{{ __('Reference') }}</th>
                                                        <th scope="col"><span class="visually-hidden">{{ __('Remove') }}</span></th>
                                                    </tr>
                                                </thead>
                                                <tbody data-mp-grid-body>
                                                    @foreach($rows as $index => $mpRow)
                                                        <tr data-mp-grid-row>
                                                            <td><input type="text" class="form-control" name="rows[{{ $index }}][amount]" value="{{ $mpRow['amount'] }}" maxlength="64" inputmode="decimal" autocomplete="off" dir="ltr" placeholder="0.00" aria-label="{{ __('Amount') }}"></td>
                                                            @foreach($fields as $field)
                                                                <td><input type="text" class="form-control mp-mono" name="rows[{{ $index }}][account][{{ $field['name'] }}]" value="{{ $mpRow['account'][$field['name']] ?? '' }}" maxlength="{{ $field['type'] === 'textarea' ? 2000 : 255 }}" autocomplete="off" spellcheck="false" aria-label="{{ $mpFieldLabel($field) }}"></td>
                                                            @endforeach
                                                            <td><input type="text" class="form-control" name="rows[{{ $index }}][note]" value="{{ $mpRow['note'] }}" maxlength="255" autocomplete="off" aria-label="{{ __('Note') }}"></td>
                                                            <td><input type="text" class="form-control mp-mono" name="rows[{{ $index }}][reference]" value="{{ $mpRow['reference'] }}" maxlength="100" autocomplete="off" dir="ltr" aria-label="{{ __('Reference') }}"></td>
                                                            <td><button type="button" class="mp-grid__remove" data-mp-remove-row aria-label="{{ __('Remove row') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        <template data-mp-grid-template>
                                            <tr data-mp-grid-row>
                                                <td><input type="text" class="form-control" name="rows[__INDEX__][amount]" maxlength="64" inputmode="decimal" autocomplete="off" dir="ltr" placeholder="0.00" aria-label="{{ __('Amount') }}"></td>
                                                @foreach($fields as $field)
                                                    <td><input type="text" class="form-control mp-mono" name="rows[__INDEX__][account][{{ $field['name'] }}]" maxlength="{{ $field['type'] === 'textarea' ? 2000 : 255 }}" autocomplete="off" spellcheck="false" aria-label="{{ $mpFieldLabel($field) }}"></td>
                                                @endforeach
                                                <td><input type="text" class="form-control" name="rows[__INDEX__][note]" maxlength="255" autocomplete="off" aria-label="{{ __('Note') }}"></td>
                                                <td><input type="text" class="form-control mp-mono" name="rows[__INDEX__][reference]" maxlength="100" autocomplete="off" dir="ltr" aria-label="{{ __('Reference') }}"></td>
                                                <td><button type="button" class="mp-grid__remove" data-mp-remove-row aria-label="{{ __('Remove row') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></td>
                                            </tr>
                                        </template>
                                        <button type="button" class="btn btn-light btn-sm mt-2" data-mp-add-row>
                                            <i class="fa-solid fa-plus" aria-hidden="true"></i> {{ __('Add row') }}
                                        </button>
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
                                        {{ __('Reviewing moves no money and sends nothing. You will see every line, its fee and the total before you confirm.') }}
                                    </p>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-12 col-xl-4">
                <div class="single-form-card">
                    <div class="card-main mp-side">
                        <h2 class="mp-side__title">{{ __('How it works') }}</h2>
                        <ol class="mp-steps">
                            <li>{{ __('Choose the wallet to pay from and how the payouts are sent.') }}</li>
                            <li>{{ __('Add the accounts and amounts, from a file or by hand.') }}</li>
                            <li>{{ __('Review the batch: every account is checked and every line priced, with its fee.') }}</li>
                            <li>{{ __('Confirm with your wallet PIN. The total is reserved at once and the payouts are sent, after approval where it applies.') }}</li>
                            <li>{{ __('Anything that cannot be paid comes back to your wallet, fee included.') }}</li>
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
