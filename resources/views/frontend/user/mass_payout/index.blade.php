@extends('frontend.layouts.user.index')
@section('title', __('Mass payouts'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/mass-payment.css?v='.config('app.version').'-'.filemtime(public_path('frontend/css/mass-payment.css'))) }}">
@endpush

@section('content')
    <div class="mp">
        <div class="single-form-card">
            <x-user-feature-header
                :title="__('Mass payouts')"
                :subtitle="__('Pay many bank or mobile money accounts at once from one of your wallets, through one payout method.')"
                icon="fas fa-building-columns"
            >
                <a class="btn btn-light-success btn-sm" href="{{ route('user.mass-payout.create') }}" data-mp-new>
                    <i class="fas fa-plus" aria-hidden="true"></i> {{ __('New batch') }}
                </a>
            </x-user-feature-header>

            <div class="card-main">
                @if($batches->isEmpty())
                    <x-user-not-found
                        :title="__('No mass payouts yet')"
                        :message="__('Upload a CSV or type in the accounts and amounts. You review every line before any money moves.')"
                        icon="fa-building-columns"
                        :action-url="route('user.mass-payout.create')"
                        :action-label="__('New batch')"
                        action-icon="fa-plus"
                    />
                @else
                    <div class="mp-batches">
                        @foreach($batches as $batch)
                            <a class="mp-batch" href="{{ route('user.mass-payout.show', $batch) }}" data-mp-batch="{{ $batch->uuid }}">
                                <span class="mp-batch__icon" aria-hidden="true"><i class="fa-solid fa-building-columns"></i></span>
                                <span class="mp-batch__body">
                                    @if(filled($batch->name))
                                        <span class="mp-batch__title" data-mp-batch-name>{{ $batch->name }}</span>
                                    @endif
                                    <span class="mp-batch__title mp-mono {{ filled($batch->name) ? 'mp-batch__ref' : '' }}" dir="ltr">{{ $batch->reference }}</span>
                                    <span class="mp-batch__meta">
                                        <span>{{ trans_choice(':count row|:count rows', (int) $batch->row_count, ['count' => (int) $batch->row_count]) }}</span>
                                        <span class="mp-dot" aria-hidden="true"></span>
                                        <span>{{ (string) ($batch->metadata['payout']['method_name'] ?? '') }}</span>
                                        <span class="mp-dot" aria-hidden="true"></span>
                                        <span dir="ltr">{{ $batch->created_at?->format('d M Y, H:i') }}</span>
                                    </span>
                                </span>
                                <span class="mp-batch__side">
                                    <span class="mp-batch__amount" dir="ltr">{{ money($batch->total_payable, $batch->currency_code, false) }} {{ $batch->currency_code }}</span>
                                    @include('frontend.user.mass_payment.partials._status_pill', ['status' => $batch->status()])
                                </span>
                                <i class="fa-solid fa-chevron-right mp-arrow" aria-hidden="true"></i>
                            </a>
                        @endforeach
                    </div>

                    @if($batches->hasPages())
                        <div class="mp-pagination">
                            {{ $batches->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
@endsection
