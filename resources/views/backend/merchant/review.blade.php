@php use App\Enums\MerchantStatus; @endphp
@extends('backend.layouts.app')
@section('title', $title)

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/merchant-review.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/merchant-review.css'))) }}">
@endpush

@section('content')
    @php
        $isPending  = $merchant->status === MerchantStatus::PENDING;
        $isApproved = $merchant->status === MerchantStatus::APPROVED;
        $isDisabled = $merchant->status === MerchantStatus::DISABLED;
        $isRejected = $merchant->status === MerchantStatus::REJECTED;

        $feeCurrency = $merchant->currency->code ?? siteCurrency();
        $description = trim((string) $merchant->business_description);

        $eyebrow = match (true) {
            $isPending  => __('Merchant request'),
            $isApproved => __('Live merchant'),
            $isDisabled => __('Paused merchant'),
            default     => __('Rejected request'),
        };

        $meta = [
            $merchant->user
                ? ['label' => $merchant->user->name, 'url' => route('admin.user.manage', $merchant->user->username)]
                : __('Owner account deleted'),
            $merchant->isSandboxMode() ? __('Sandbox') : ($merchant->isProductionMode() ? __('Production') : __('—')),
            __('Requested :date', ['date' => $merchant->created_at->format('Y-m-d H:i')]),
        ];
    @endphp

    <x-page-header
        :title="$merchant->business_name"
        :avatar="$merchant->business_logo"
        :eyebrow="$eyebrow"
        :subtitle="$merchant->business_email"
        :badge="$merchant->merchant_key"
        :back="$isPending ? route('admin.merchant.pending') : route('admin.merchant.index')"
        :back-label="$isPending ? __('Back to Pending merchants') : __('Back to Merchants')"
        :status="[
            'tone'  => match ($merchant->status->color()) {
                'success' => 'success',
                'warning' => 'warning',
                'danger'  => 'danger',
                default   => 'neutral',
            },
            'label' => $merchant->status->label(),
        ]"
        :meta="$meta"
    />

    <form action="{{ route('admin.merchant.request-action') }}" method="post" class="mrv">
        @csrf
        <input type="hidden" name="merchant_id" value="{{ $merchant->id }}">

        <div class="mrv__cols">

            <div class="mrv__evidence">

                @if($isPending)
                    @php $waited = $merchant->created_at->diffForHumans(null, true); @endphp
                    <div class="mrv__callout mrv__callout--{{ $merchant->created_at->diffInHours(now()) >= 48 ? 'danger' : 'warning' }}">
                        <span class="mrv__callout-icon" aria-hidden="true"><i class="fa-solid fa-hourglass-half"></i></span>
                        <span>
                            <strong>{{ __('Waiting :age', ['age' => $waited]) }}</strong>
                            <small>{{ __('Approving enables live payments and the merchant API immediately.') }}</small>
                        </span>
                    </div>
                @elseif($isApproved)
                    <div class="mrv__callout mrv__callout--success">
                        <span class="mrv__callout-icon" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span>
                        <span>
                            <strong>{{ __('Taking live payments') }}</strong>
                            <small>{{ __('A new fee applies from the moment you save. Nothing already settled changes.') }}</small>
                        </span>
                    </div>
                @elseif($isDisabled)
                    <div class="mrv__callout mrv__callout--neutral">
                        <span class="mrv__callout-icon" aria-hidden="true"><i class="fa-solid fa-ban"></i></span>
                        <span>
                            <strong>{{ __('Payments and API access are paused') }}</strong>
                            <small>{{ __('Settled transactions were not affected. Enabling resumes both.') }}</small>
                        </span>
                    </div>
                @else
                    <div class="mrv__callout mrv__callout--danger">
                        <span class="mrv__callout-icon" aria-hidden="true"><i class="fa-solid fa-circle-xmark"></i></span>
                        <span>
                            <strong>{{ __('This request was rejected') }}</strong>
                            <small>{{ __('The owner can submit a new request. This one cannot be reopened.') }}</small>
                        </span>
                    </div>
                @endif

                <section class="mrv__section">
                    <h2 class="mrv__label">{{ __('Checks') }}</h2>
                    <div class="mrv__checks">
                        @foreach($checks as $check)
                            <div class="mrv__check">
                                <span class="mrv__check-icon mrv__check-icon--{{ $check['tone'] }}" aria-hidden="true">
                                    <i class="fa-solid {{ $check['tone'] === 'pass' ? 'fa-check' : ($check['tone'] === 'warn' ? 'fa-triangle-exclamation' : 'fa-minus') }}"></i>
                                </span>
                                <span class="mrv__check-body">
                                    <span class="mrv__check-title">{{ $check['label'] }}</span>
                                    <span class="mrv__check-detail">{{ $check['detail'] }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </section>

                @if($isRejected && filled($merchant->rejection_reason))
                    <section class="mrv__section">
                        <h2 class="mrv__label">{{ __('Reason sent to the merchant') }}</h2>
                        <div class="mrv__quote">{!! nl2br(e($merchant->rejection_reason)) !!}</div>
                    </section>
                @endif

                <section class="mrv__section">
                    <h2 class="mrv__label">{{ $isPending ? __('What they say they do') : __('Business description') }}</h2>
                    <div class="mrv__quote">
                        @if($description === '')
                            <span class="mrv__empty">{{ __('No description provided.') }}</span>
                        @else
                            {!! nl2br(e($description)) !!}
                        @endif
                    </div>
                </section>

                <section class="mrv__section">
                    <h2 class="mrv__label">{{ __('Profile') }}</h2>
                    <dl class="mrv__rows">
                        <div class="mrv__row">
                            <dt>{{ __('Owner') }}</dt>
                            <dd>
                                @if($merchant->user)
                                    <a href="{{ route('admin.user.manage', $merchant->user->username) }}">{{ $merchant->user->name }}</a>
                                @else
                                    {{ __('Owner account deleted') }}
                                @endif
                            </dd>
                        </div>
                        <div class="mrv__row">
                            <dt>{{ __('Site URL') }}</dt>
                            <dd>
                                @if($merchant->site_url)
                                    <a href="{{ safeUrl($merchant->site_url) }}" target="_blank" rel="noopener">{{ $merchant->site_url }}</a>
                                @else
                                    <span class="mrv__empty">{{ __('Not provided') }}</span>
                                @endif
                            </dd>
                        </div>
                        @if($merchant->webhook_url)
                            <div class="mrv__row">
                                <dt>{{ __('Webhook URL') }}</dt>
                                <dd><span class="mrv__mono" dir="ltr">{{ $merchant->webhook_url }}</span></dd>
                            </div>
                        @endif
                        <div class="mrv__row">
                            <dt>{{ __('Currencies') }}</dt>
                            <dd class="mrv__chips">
                                @forelse($merchant->supportedCurrencies as $currency)
                                    <span class="mrv__chip">{{ $currency->code }}</span>
                                @empty
                                    <span class="mrv__chip">{{ $feeCurrency }}</span>
                                @endforelse
                            </dd>
                        </div>
                        @if($merchant->paymentMethods->isNotEmpty())
                            <div class="mrv__row">
                                <dt>{{ __('Payment methods') }}</dt>
                                <dd class="mrv__chips">
                                    @foreach($merchant->paymentMethods as $method)
                                        <span class="mrv__chip">{{ $method->name ?? $method->code ?? '—' }}</span>
                                    @endforeach
                                </dd>
                            </div>
                        @endif
                    </dl>
                </section>

            </div>

            <aside class="mrv__rail">

                @can('merchant-manage')
                    @if($isRejected)
                        <div class="mrv__rail-head">
                            <h2>{{ __('No further action') }}</h2>
                            <p>{{ __('A rejection is final. The owner can submit a new request, which arrives as a fresh one in the queue.') }}</p>
                        </div>
                    @else
                        <div class="mrv__rail-head">
                            <h2>{{ $isPending ? __('Your decision') : __('Pricing') }}</h2>
                            <p>
                                {{ $isPending
                                    ? __('Approving enables live payments and the merchant API. The fee below is set at the same moment.')
                                    : __('Charged on every successful transaction.') }}
                            </p>
                        </div>

                        {{-- The shape, before the four boxes that make it. --}}
                        <div class="mrv__formula" aria-hidden="true">
                            <span class="mrv__formula-label">{{ __('Fee on every payment') }}</span>
                            <span class="mrv__formula-line" dir="ltr">
                                <b>{{ money($merchant->fee_fixed, $feeCurrency, false) }}</b>
                                <i>+</i><i>(</i><em>{{ __('amount') }}</em><i>&times;</i>
                                <b>{{ feeRate($merchant->fee) }}%</b><i>)</i>
                            </span>
                            <span class="mrv__formula-note">
                                @if($merchant->fee_min !== null || $merchant->fee_max !== null)
                                    {{ __('Held between :min and :max :currency', [
                                        'min'      => $merchant->fee_min !== null ? money($merchant->fee_min, $feeCurrency, false) : __('no floor'),
                                        'max'      => $merchant->fee_max !== null ? money($merchant->fee_max, $feeCurrency, false) : __('no cap'),
                                        'currency' => $feeCurrency,
                                    ]) }}
                                @else
                                    {{ __('No floor and no cap') }}
                                @endif
                            </span>
                        </div>

                        <div class="mrv__fields">
                            <div class="mrv__field">
                                <label for="mrv-fee">{{ __('Percentage') }}</label>
                                <span class="mrv__input">
                                    <input id="mrv-fee" name="fee" type="text" inputmode="decimal" autocomplete="off"
                                           value="{{ old('fee', $merchant->fee) }}"
                                           class="@error('fee') is-invalid @enderror"
                                           oninput="this.value = validateDouble(this.value)">
                                    <span class="mrv__unit" aria-hidden="true">%</span>
                                </span>
                            </div>
                            <div class="mrv__field">
                                <label for="mrv-fee-fixed">{{ __('Fixed') }}</label>
                                <span class="mrv__input">
                                    <input id="mrv-fee-fixed" name="fee_fixed" type="text" inputmode="decimal" autocomplete="off"
                                           value="{{ old('fee_fixed', $merchant->fee_fixed) }}"
                                           class="@error('fee_fixed') is-invalid @enderror"
                                           oninput="this.value = validateDouble(this.value)">
                                    <span class="mrv__unit" aria-hidden="true">{{ $feeCurrency }}</span>
                                </span>
                            </div>
                            <div class="mrv__field">
                                <label for="mrv-fee-min">{{ __('Floor') }}</label>
                                <span class="mrv__input">
                                    <input id="mrv-fee-min" name="fee_min" type="text" inputmode="decimal" autocomplete="off"
                                           placeholder="{{ __('None') }}"
                                           value="{{ old('fee_min', $merchant->fee_min) }}"
                                           class="@error('fee_min') is-invalid @enderror"
                                           oninput="this.value = validateDouble(this.value)">
                                    <span class="mrv__unit" aria-hidden="true">{{ $feeCurrency }}</span>
                                </span>
                            </div>
                            <div class="mrv__field">
                                <label for="mrv-fee-max">{{ __('Cap') }}</label>
                                <span class="mrv__input">
                                    <input id="mrv-fee-max" name="fee_max" type="text" inputmode="decimal" autocomplete="off"
                                           placeholder="{{ __('None') }}"
                                           value="{{ old('fee_max', $merchant->fee_max) }}"
                                           class="@error('fee_max') is-invalid @enderror"
                                           oninput="this.value = validateDouble(this.value)">
                                    <span class="mrv__unit" aria-hidden="true">{{ $feeCurrency }}</span>
                                </span>
                            </div>
                        </div>

                        @error('fee') <p class="mrv__error">{{ $message }}</p> @enderror
                        @error('fee_fixed') <p class="mrv__error">{{ $message }}</p> @enderror
                        @error('fee_min') <p class="mrv__error">{{ $message }}</p> @enderror
                        @error('fee_max') <p class="mrv__error">{{ $message }}</p> @enderror

                        <div class="mrv__preview">
                            <h3>{{ __('What that actually charges') }}</h3>
                            @foreach($preview as $row)
                                <div class="mrv__preview-row">
                                    <span>{{ __('On :amount', ['amount' => money($row['amount'], $feeCurrency, false)]) }}</span>
                                    <span class="mrv__preview-out">
                                        @if($row['bound'] === 'floor')
                                            <span class="mrv__bound">{{ __('FLOOR') }}</span>
                                        @elseif($row['bound'] === 'cap')
                                            <span class="mrv__bound">{{ __('CAP') }}</span>
                                        @endif
                                        <b>{{ money($row['fee'], $feeCurrency, false) }}</b>
                                    </span>
                                </div>
                            @endforeach
                        </div>

                        @if($isPending)
                            <div class="mrv__field mrv__field--wide">
                                <label for="mrv-reason">
                                    {{ __('Rejection reason') }}
                                    <span class="mrv__hint">{{ __('— sent only if you reject') }}</span>
                                </label>
                                <textarea id="mrv-reason" name="rejection_reason" rows="3" maxlength="500"
                                          placeholder="{{ __('Tell them what to fix so they can apply again.') }}"
                                          class="mrv__textarea @error('rejection_reason') is-invalid @enderror"
                                          oninput="this.nextElementSibling.textContent = this.value.length + ' / 500'">{{ old('rejection_reason') }}</textarea>
                                <span class="mrv__count" aria-hidden="true">{{ mb_strlen((string) old('rejection_reason')) }} / 500</span>
                                @error('rejection_reason') <p class="mrv__error">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        {{-- Destructive work gets its own bounded room, never a
                             seat beside Save. --}}
                        @if($isApproved)
                            <div class="mrv__danger">
                                <h3>{{ __('Pause this merchant') }}</h3>
                                <p>{{ __('Stops new payments and rejects API calls at once. Settled transactions are untouched, and you can switch them back on at any time.') }}</p>
                                <button type="submit" name="action" value="disable" class="mrv__btn mrv__btn--danger">
                                    <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                    {{ __('Disable merchant') }}
                                </button>
                            </div>
                        @endif

                        <div class="mrv__bar">
                            @if($isPending)
                                <span class="mrv__bar-note">{{ __('Either decision emails the owner straight away.') }}</span>
                                <span class="mrv__bar-actions">
                                    <button type="submit" name="action" value="reject" class="mrv__btn mrv__btn--danger">
                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>{{ __('Reject request') }}
                                    </button>
                                    <button type="submit" name="action" value="approve" class="mrv__btn mrv__btn--primary">
                                        <i class="fa-solid fa-check" aria-hidden="true"></i>{{ __('Approve & enable') }}
                                    </button>
                                </span>
                            @elseif($isDisabled)
                                <span class="mrv__bar-note">{{ __('Enabling resumes payments and API access.') }}</span>
                                <span class="mrv__bar-actions">
                                    <button type="submit" name="action" value="approve" class="mrv__btn mrv__btn--ghost">
                                        <i class="fa-solid fa-rotate" aria-hidden="true"></i>{{ __('Save fee') }}
                                    </button>
                                    <button type="submit" name="action" value="enable" class="mrv__btn mrv__btn--primary">
                                        <i class="fa-solid fa-power-off" aria-hidden="true"></i>{{ __('Enable merchant') }}
                                    </button>
                                </span>
                            @else
                                <span class="mrv__bar-note">{{ __('A fee change is not announced to the merchant.') }}</span>
                                <span class="mrv__bar-actions">
                                    <button type="submit" name="action" value="approve" class="mrv__btn mrv__btn--primary">
                                        <i class="fa-solid fa-rotate" aria-hidden="true"></i>{{ __('Save fee') }}
                                    </button>
                                </span>
                            @endif
                        </div>
                    @endif
                @else
                    <div class="mrv__rail-head">
                        <h2>{{ __('Read only') }}</h2>
                        <p>{{ __('You can see this merchant but not decide on it. Ask an administrator with merchant management rights.') }}</p>
                    </div>
                @endcan

            </aside>

        </div>
    </form>
@endsection
