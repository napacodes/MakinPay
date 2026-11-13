@extends('frontend.layouts.user.index')
@section('title', __('Pay Bills'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/bill-payment.css?v='.config('app.version').'-'.filemtime(public_path('frontend/css/bill-payment.css'))) }}">
@endpush

@section('content')
    <div class="bp">
        <div class="single-form-card">
            <x-user-feature-header
                :title="__('Pay Bills')"
                :subtitle="__('Pay electricity, water, TV, internet and more straight from your wallet.')"
                icon="fas fa-receipt"
            >
                <a class="btn btn-light-success btn-sm" href="{{ route('user.bill-payment.history') }}">
                    <i class="fas fa-list" aria-hidden="true"></i> {{ __('History') }}
                </a>
            </x-user-feature-header>

            <div class="card-main">
                @if($awaitingCount > 0)
                    <div class="bp-banner" role="status" data-bp-awaiting="{{ $awaitingCount }}">
                        <span class="bp-banner__icon" aria-hidden="true"><i class="fa-solid fa-clock"></i></span>
                        <span class="bp-banner__copy">
                            <strong class="bp-banner__title">
                                {{ trans_choice(':count bill is being processed|:count bills are being processed', $awaitingCount, ['count' => $awaitingCount]) }}
                            </strong>
                            @if($awaitingNote)
                                <span class="bp-banner__note">{{ $awaitingNote }}</span>
                            @endif
                        </span>
                        <a class="bp-banner__link" href="{{ route('user.bill-payment.history', ['status' => \App\Http\Controllers\Frontend\BillPaymentController::OPEN_FILTER]) }}">
                            {{ __('Track') }}
                            <i class="fa-solid fa-arrow-right bp-arrow" aria-hidden="true"></i>
                        </a>
                    </div>
                @endif

                @if($savedBills->isNotEmpty())
                    <section class="bp-saved" aria-labelledby="bp-saved-title" data-bp-saved-list>
                        <div class="bp-toolbar">
                            <h2 class="bp-section-title" id="bp-saved-title">{{ __('My bills') }}</h2>
                            <span class="bp-saved__hint">{{ __('Saved when you pay, so next time it is one tap.') }}</span>
                        </div>
                        <div class="bp-saved__grid">
                            @foreach($savedBills as $savedEntry)
                                @php
                                    $savedBill = $savedEntry['bill'];
                                    $savedLast = $savedEntry['last'];
                                @endphp
                                <article class="bp-saved-card" data-bp-saved="{{ $savedBill->id }}">
                                    <div class="bp-saved-card__head">
                                        @include('frontend.user.bill_payment.partials._logo', [
                                            'logo' => $savedBill->biller->logo,
                                            'icon' => $savedBill->biller->category?->icon,
                                            'size' => 'sm',
                                        ])
                                        <div class="bp-saved-card__copy">
                                            <strong class="bp-saved-card__title">{{ $savedBill->title() }}</strong>
                                            <span class="bp-saved-card__meta">
                                                @if(filled($savedBill->nickname))
                                                    <span>{{ $savedBill->biller->name }}</span>
                                                    <span class="bp-payment__dot" aria-hidden="true"></span>
                                                @endif
                                                <span class="bp-mono" dir="ltr">{{ $savedBill->customer_number }}</span>
                                            </span>
                                        </div>
                                        <form method="POST" action="{{ route('user.bill-payment.saved.destroy', $savedBill->id) }}"
                                              data-bp-forget data-confirm="{{ __('Remove :name from My bills? Your payments stay in your history.', ['name' => $savedBill->title()]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="bp-saved-card__remove" aria-label="{{ __('Remove :name from My bills', ['name' => $savedBill->title()]) }}">
                                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </div>
                                    <div class="bp-saved-card__foot">
                                        <span class="bp-saved-card__last">
                                            @if($savedLast === null)
                                                {{ __('Not paid yet') }}
                                            @elseif($savedLast->status === \App\Enums\BillPayment\BillPaymentStatus::Completed)
                                                <span dir="ltr">{{ __('Last paid :amount · :date', ['amount' => money($savedLast->total_amount, $savedLast->currency, false).' '.$savedLast->currency, 'date' => $savedLast->created_at?->format('d M')]) }}</span>
                                            @elseif($savedLast->status === \App\Enums\BillPayment\BillPaymentStatus::Failed)
                                                {{ __('Last payment refunded · :date', ['date' => $savedLast->created_at?->format('d M')]) }}
                                            @else
                                                <span class="bp-saved-card__open">{{ __('Payment being processed') }}</span>
                                            @endif
                                        </span>
                                        <a class="btn btn-base btn-sm bp-saved-card__pay"
                                           href="{{ route('user.bill-payment.create', ['biller' => $savedBill->biller_id, 'saved' => $savedBill->id]) }}">
                                            {{ __('Pay') }}
                                        </a>
                                    </div>
                                    {{-- BP3: a monthly reminder. Saved on change; nothing is paid by itself. --}}
                                    <form method="POST" action="{{ route('user.bill-payment.saved.reminder', $savedBill->id) }}" class="bp-saved-card__remind" data-bp-remind>
                                        @csrf
                                        <label class="bp-saved-card__remind-label" for="bp-remind-{{ $savedBill->id }}">
                                            <i class="fa-solid fa-bell" aria-hidden="true"></i>
                                            <span>{{ __('Remind me') }}</span>
                                        </label>
                                        <select id="bp-remind-{{ $savedBill->id }}" name="remind_day" class="form-select form-select-sm bp-saved-card__remind-day" data-bp-autosubmit
                                                aria-label="{{ __('Remind me to pay :name on a day of each month', ['name' => $savedBill->title()]) }}">
                                            <option value="" @selected($savedBill->remind_day === null)>{{ __('Off') }}</option>
                                            @for($remindDay = \App\Models\SavedBill::REMIND_DAY_MIN; $remindDay <= \App\Models\SavedBill::REMIND_DAY_MAX; $remindDay++)
                                                <option value="{{ $remindDay }}" @selected($savedBill->remind_day === $remindDay)>{{ __('On the :day', ['day' => \Illuminate\Support\Carbon::create(2026, 1, $remindDay)?->format('jS')]) }}</option>
                                            @endfor
                                        </select>
                                        <noscript><button type="submit" class="btn btn-light btn-sm">{{ __('Save') }}</button></noscript>
                                    </form>
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endif

                <div class="bp-toolbar">
                    <h2 class="bp-section-title">{{ __('What are you paying?') }}</h2>
                    @include('frontend.user.bill_payment.partials._country_switch')
                </div>

                @if($categories->isEmpty())
                    <x-user-not-found
                        :title="__('No billers yet')"
                        :message="__('There are no billers you can pay here yet. Check back soon.')"
                        icon="fa-receipt"
                    />
                @else
                    <div class="bp-categories">
                        @foreach($categories as $category)
                            @php
                                $bpCategoryIcon = in_array($category->icon, (array) config('bill_payments.category_icons', []), true) ? $category->icon : 'bill-other';
                            @endphp
                            <a class="bp-category" data-bp-category="{{ $category->code }}"
                               href="{{ route('user.bill-payment.billers', ['category' => $category->code, 'country' => $country]) }}">
                                <span class="bp-category__icon" aria-hidden="true">
                                    <x-icon :name="$bpCategoryIcon" class="bp-category__svg" />
                                </span>
                                <span class="bp-category__name">{{ $category->name }}</span>
                                <span class="bp-category__count">
                                    {{ trans_choice(':count biller|:count billers', $category->billers_count, ['count' => $category->billers_count]) }}
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="single-form-card">
            <x-user-feature-header
                :title="__('Recent payments')"
                :subtitle="__('Your latest bills and where each one stands.')"
                icon="fas fa-clock-rotate-left"
                compact
            >
                @if($recentPayments->isNotEmpty())
                    <a class="btn btn-light-success btn-sm" href="{{ route('user.bill-payment.history') }}">{{ __('View all') }}</a>
                @endif
            </x-user-feature-header>

            <div class="card-main">
                @if($recentPayments->isEmpty())
                    <x-user-not-found
                        :title="__('No bills paid yet')"
                        :message="__('Your bill payments and their receipts will appear here.')"
                        icon="fa-receipt"
                    />
                @else
                    <div class="bp-payments">
                        @foreach($recentPayments as $payment)
                            @include('frontend.user.bill_payment.partials._payment_row')
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('frontend/js/bill-payment.js?v='.config('app.version').'-'.filemtime(public_path('frontend/js/bill-payment.js'))) }}"></script>
@endpush
