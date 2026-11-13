@extends('backend.bill_payment.layout')

@section('title', __('Import billers'))

@section('sub_header')
    <x-page-header
        :title="__('Import billers')"
        :subtitle="__('Billers from a spreadsheet, under a provider that pays by hand. Every row is checked before anything is written.')"
        icon="receipt"
        :back="route('admin.bill-payment.billers.index')"
    >
        <x-slot:actions>
            <a href="{{ route('admin.bill-payment.billers.import.template') }}" class="ph-btn ph-btn--secondary" data-bpa-import-template>
                <x-icon name="arrow-down-circle" width="14" height="14"/>
                {{ __('Download the template') }}
            </a>
        </x-slot:actions>
    </x-page-header>
@endsection

@section('sub_content')
    @php
        $columnNotes = [
            'name'                    => [true,  __('The biller as customers see it. A provider never gets two billers by one name in one country; a row naming one it already has is refused.')],
            'category'                => [true,  __('A category code from the Categories screen, such as electricity or tv (its name is accepted too).')],
            'country'                 => [true,  __('Two-letter country code, such as CI or NG.')],
            'currency'                => [true,  __('A currency the platform knows, such as XOF. Every amount on the row is in it.')],
            'customer_number_label'   => [false, __('What the number is called on the bill - Meter number, Smartcard number. Left blank: :default.', ['default' => \App\Services\BillPayment\BillerCsvImporter::DEFAULT_CUSTOMER_NUMBER_LABEL])],
            'customer_number_pattern' => [false, __('A regular expression the number must match, such as ^[0-9]{11}$. Needs an example.')],
            'customer_number_example' => [false, __('A number that matches the pattern, shown to the customer.')],
            'customer_number_help'    => [false, __('Where the customer finds the number.')],
            'amount_type'             => [false, __('open (any amount in a range) or fixed (packages). Left blank: open.')],
            'min_amount'              => [false, __('Open billers: the least a customer may pay, at the currency\'s own precision.')],
            'max_amount'              => [false, __('Open billers: the most a customer may pay.')],
            'packages'                => [false, __('Fixed billers: Name=amount, separated by |, such as Basic=10|Premium=25. At least one.')],
            'fee_percent'             => [false, __('A percentage fee for this biller alone. Left blank: the provider\'s rule.')],
            'fee_fixed'               => [false, __('A fixed fee in the biller\'s currency. Left blank: the provider\'s rule.')],
            'token_label'             => [false, __('What the receipt calls what the customer gets - Prepaid token - when the biller returns one.')],
            'processing_note'         => [false, __('Shown while the payment is being processed, such as "Paid within one working day".')],
            'instructions'            => [false, __('Shown on the receipt, such as how to use a token.')],
            'description'             => [false, __('A line under the name.')],
            'status'                  => [false, __('yes to switch the biller on at once. Left blank: off, so you can check it first.')],
        ];
    @endphp

    <div class="row g-4">
        <div class="col-12 col-xl-5">
            <div class="card border-0 bpa-card">
                <div class="card-body">
                    <h2 class="bpa-form-section__title mb-1">{{ __('The file') }}</h2>
                    <p class="bpa-form-section__hint">{{ __('A CSV with a header row, up to :max billers. Commas, semicolons or tabs between the columns, in any order.', ['max' => $maxRows]) }}</p>

                    @if($providers->isEmpty())
                        <div class="alert alert-warning mb-0" role="alert">{{ __('No provider pays billers by hand, so there is nothing to import under. Add one on the Providers screen first.') }}</div>
                    @else
                        <form method="POST" action="{{ route('admin.bill-payment.billers.import.preview') }}" enctype="multipart/form-data" novalidate data-bpa-import-form>
                            @csrf

                            <div class="mb-3">
                                <label class="form-label" for="bpa-import-provider">{{ __('Provider') }}</label>
                                <select id="bpa-import-provider" name="bill_payment_provider_id" class="form-select @error('bill_payment_provider_id') is-invalid @enderror">
                                    @foreach($providers as $provider)
                                        <option value="{{ $provider->id }}" @selected((string) old('bill_payment_provider_id', $providers->first()?->id) === (string) $provider->id)>{{ $provider->name }}</option>
                                    @endforeach
                                </select>
                                @error('bill_payment_provider_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <small class="bpa-muted">{{ __('Only providers that pay by hand are listed; a provider with a catalogue sync imports its own billers.') }}</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="bpa-import-file">{{ __('CSV file') }}</label>
                                <input type="file" id="bpa-import-file" name="file" accept=".csv,.txt,text/csv,text/plain" class="form-control @error('file') is-invalid @enderror">
                                @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> {{ __('Review the file') }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="card border-0 bpa-card mt-4">
                <div class="card-body">
                    <h2 class="bpa-form-section__title mb-1">{{ __('What an import does') }}</h2>
                    <ul class="bpa-muted small mb-0 ps-3">
                        <li>{{ __('Every row is checked by the same rules as the biller form, and you see each row\'s verdict before anything is written.') }}</li>
                        <li>{{ __('A file with a refused row is imported in no part. Fix the file and upload it again.') }}</li>
                        <li>{{ __('An import only creates billers. It never changes one that exists, and never touches billers the catalogue sync manages.') }}</li>
                        <li>{{ __('Imported billers arrive switched off unless their status column says yes.') }}</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="card border-0 bpa-card">
                <div class="card-body">
                    <h2 class="bpa-form-section__title mb-1">{{ __('Columns') }}</h2>
                    <p class="bpa-form-section__hint">{{ __('The template carries every column with two example billers. Only the first four are required.') }}</p>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" data-bpa-import-columns>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Column') }}</th>
                                    <th scope="col">{{ __('What goes in it') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($columns as $column)
                                    @php [$required, $note] = $columnNotes[$column] ?? [false, '']; @endphp
                                    <tr>
                                        <td class="text-nowrap">
                                            <code class="bpa-code" dir="ltr">{{ $column }}</code>
                                            @if($required)
                                                <span class="bpa-pill bpa-pill--secondary ms-1">{{ __('required') }}</span>
                                            @endif
                                        </td>
                                        <td class="small">{{ $note }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($categories->isNotEmpty())
                        <p class="bpa-muted small mt-3 mb-0">
                            {{ __('Category codes:') }}
                            @foreach($categories as $category)
                                <code class="bpa-code" dir="ltr">{{ $category->code }}</code>{{ $loop->last ? '' : ',' }}
                            @endforeach
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
