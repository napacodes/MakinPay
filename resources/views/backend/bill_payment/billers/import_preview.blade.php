@extends('backend.bill_payment.layout')

@section('title', __('Review the import'))

@section('sub_header')
    @php
        $total = count($review);
        $ready = $total - $refused;
    @endphp
    <x-page-header
        :title="__('Review the import')"
        :subtitle="__(':file under :provider', ['file' => $filename !== '' ? $filename : __('your file'), 'provider' => $provider->name])"
        icon="receipt"
        :back="route('admin.bill-payment.billers.import')"
        :stats="[
            ['label' => __('Rows'), 'value' => $total, 'tone' => 'neutral', 'icon' => 'list'],
            ['label' => __('Ready'), 'value' => $ready, 'tone' => $ready > 0 ? 'success' : 'neutral', 'icon' => 'check'],
            ['label' => __('Refused'), 'value' => $refused, 'tone' => $refused > 0 ? 'danger' : 'neutral', 'icon' => 'pause'],
        ]"
    />
@endsection

@section('sub_content')
    @if($errors->has('file'))
        <div class="alert alert-danger" role="alert">{{ $errors->first('file') }}</div>
    @endif

    <div class="card border-0 bpa-card" data-bpa-import-review data-bpa-import-refused="{{ $refused }}">
        <div class="card-body">
            @if($refused > 0)
                <div class="alert alert-warning" role="alert" data-bpa-import-blocked>
                    {{ trans_choice('{1} :count row is refused, so nothing will be imported. Fix the file and upload it again.|[2,*] :count rows are refused, so nothing will be imported. Fix the file and upload it again.', $refused, ['count' => $refused]) }}
                </div>
            @else
                <div class="alert alert-success" role="alert" data-bpa-import-ready>
                    {{ trans_choice('{1} The row is ready. It will arrive switched off unless its status says yes.|[2,*] All :count rows are ready. They arrive switched off unless their status says yes.', $total, ['count' => $total]) }}
                </div>
            @endif

            <div class="table-responsive">
                <table class="table align-middle bpa-table mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Line') }}</th>
                            <th scope="col">{{ __('Biller') }}</th>
                            <th scope="col">{{ __('Category') }}</th>
                            <th scope="col">{{ __('Country') }}</th>
                            <th scope="col">{{ __('Amount') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col">{{ __('Verdict') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($review as $row)
                            <tr data-bpa-import-line="{{ $row['line'] }}" class="{{ $row['errors'] !== [] ? 'table-danger' : '' }}">
                                <td class="bpa-mono" dir="ltr">{{ $row['line'] }}</td>
                                <td>{{ $row['name'] !== '' ? $row['name'] : '—' }}</td>
                                <td>{{ $row['category'] !== null ? __($row['category']) : '—' }}</td>
                                <td>
                                    <span class="bpa-code" dir="ltr">{{ $row['country'] !== '' ? $row['country'] : '—' }}</span>
                                    <span class="bpa-muted">{{ $row['currency'] }}</span>
                                </td>
                                <td class="text-nowrap" dir="ltr">{{ $row['amount'] }}</td>
                                <td>
                                    <span class="bpa-pill bpa-pill--{{ $row['status'] ? 'success' : 'secondary' }}">{{ $row['status'] ? __('On') : __('Off') }}</span>
                                </td>
                                <td>
                                    @if($row['errors'] === [])
                                        <span class="bpa-pill bpa-pill--success">{{ __('Ready') }}</span>
                                    @else
                                        <ul class="mb-0 ps-3 small text-danger" data-bpa-import-errors>
                                            @foreach($row['errors'] as $error)
                                                <li>{{ $error }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-4">
                @if($refused === 0)
                    <form method="POST" action="{{ route('admin.bill-payment.billers.import.store', $token) }}" data-bpa-import-confirm>
                        @csrf
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-file-import" aria-hidden="true"></i>
                            {{ trans_choice('{1} Import :count biller|[2,*] Import :count billers', $total, ['count' => $total]) }}
                        </button>
                    </form>
                @endif
                <a href="{{ route('admin.bill-payment.billers.import') }}" class="btn btn-outline-secondary">{{ $refused > 0 ? __('Upload a corrected file') : __('Upload another file') }}</a>
            </div>
        </div>
    </div>
@endsection
