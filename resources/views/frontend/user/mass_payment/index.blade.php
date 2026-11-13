@extends('frontend.layouts.user.index')
@section('title', __('Mass payments'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/mass-payment.css?v='.config('app.version').'-'.filemtime(public_path('frontend/css/mass-payment.css'))) }}">
@endpush

@section('content')
    <div class="mp">
        <div class="single-form-card">
            <x-user-feature-header
                :title="__('Mass payments')"
                :subtitle="__('Pay many wallets at once from one of yours, from a CSV file or rows you type in.')"
                icon="fas fa-users"
            >
                <a class="btn btn-light-success btn-sm" href="{{ route('user.mass-payment.schedules.index') }}" data-mp-schedules>
                    <i class="fas fa-rotate" aria-hidden="true"></i> {{ __('Scheduled batches') }}
                </a>
                <a class="btn btn-light-success btn-sm" href="{{ route('user.mass-payment.create') }}" data-mp-new>
                    <i class="fas fa-plus" aria-hidden="true"></i> {{ __('New batch') }}
                </a>
            </x-user-feature-header>

            <div class="card-main">
                @if($batches->isEmpty())
                    <x-user-not-found
                        :title="__('No mass payments yet')"
                        :message="__('Upload a CSV or type in a list of recipients and amounts. You review every line before any money moves.')"
                        icon="fa-users"
                        :action-url="route('user.mass-payment.create')"
                        :action-label="__('New batch')"
                        action-icon="fa-plus"
                    />
                @else
                    <div class="mp-batches">
                        @foreach($batches as $batch)
                            <a class="mp-batch" href="{{ route('user.mass-payment.show', $batch) }}" data-mp-batch="{{ $batch->uuid }}">
                                <span class="mp-batch__icon" aria-hidden="true"><i class="fa-solid fa-users"></i></span>
                                <span class="mp-batch__body">
                                    @if(filled($batch->name))
                                        <span class="mp-batch__title" data-mp-batch-name>{{ $batch->name }}</span>
                                    @endif
                                    <span class="mp-batch__title mp-mono {{ filled($batch->name) ? 'mp-batch__ref' : '' }}" dir="ltr">{{ $batch->reference }}</span>
                                    <span class="mp-batch__meta">
                                        <span>{{ trans_choice(':count row|:count rows', (int) $batch->row_count, ['count' => (int) $batch->row_count]) }}</span>
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
