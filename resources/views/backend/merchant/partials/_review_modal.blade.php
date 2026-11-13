{{--
    Merchant review / approval modal.

    Markup styled exclusively via `public/backend/css/merchant-review-modal.css`
    (no inline styles, no Bootstrap utility scaffolding for visuals — only
    for the modal frame and the form binding). All decisions live in CSS
    tokens that follow the rest of the admin theme.
--}}
@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('backend/css/merchant-review-modal.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/merchant-review-modal.css'))) }}">
    @endpush
@endonce

@php
    $statusToneMap = [
        \App\Enums\MerchantStatus::PENDING->value  => 'is-pending',
        \App\Enums\MerchantStatus::APPROVED->value => 'is-approved',
        \App\Enums\MerchantStatus::DISABLED->value => 'is-disabled',
        \App\Enums\MerchantStatus::REJECTED->value => 'is-rejected',
    ];
    $statusToneClass = $statusToneMap[$merchant->status->value] ?? '';
    $description     = trim((string) ($merchant->business_description ?? ''));
    $currencies      = $merchant->supportedCurrencies->pluck('code')->implode(', ') ?: $merchant->currency->code;
    /*
     * The fee is charged in the merchant's own currency: feePreview() hands
     * that code to PaymentLinkService::merchantFee(). Labelling these boxes
     * with the site's base instead is how somebody enters "0.30" meaning
     * thirty cents against a merchant priced in something else.
     */
    $feeUnit         = $merchant->currency?->code ?? siteCurrency();
@endphp

<div class="modal fade mr-modal" id="review-{{ $merchant->id }}" tabindex="-1" aria-labelledby="MerchantDetailsLabel-{{ $merchant->id }}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content mr-modal__content">
            {{-- Header --}}
            <div class="modal-header mr-modal__header">
                <div class="mr-modal__brand">
                    <img src="{{ asset($merchant->business_logo) }}"
                         alt="{{ $merchant->business_name }}"
                         class="mr-modal__logo"
                         loading="lazy">
                    <div class="mr-modal__brand-text">
                        <h5 class="mr-modal__title" id="MerchantDetailsLabel-{{ $merchant->id }}">{{ $merchant->business_name }}</h5>
                        <span class="mr-modal__subtitle">{{ $merchant->business_email }}</span>
                    </div>
                </div>

                <span class="mr-modal__status {{ $statusToneClass }}">
                    <span class="mr-modal__status-dot" aria-hidden="true"></span>
                    {{ $merchant->status->label() }}
                </span>

                <button type="button"
                        class="mr-modal__close"
                        data-coreui-dismiss="modal"
                        aria-label="{{ __('Close') }}">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>

            {{-- Action form --}}
            <form action="{{ route('admin.merchant.request-action') }}" method="post">
                @csrf
                <input type="hidden" name="merchant_id" value="{{ $merchant->id }}">

                {{-- Body --}}
                <div class="modal-body mr-modal__body">

                    {{-- Status callout --}}
                    @if($merchant->status === \App\Enums\MerchantStatus::APPROVED)
                        <div class="mr-modal__callout mr-modal__callout--success" role="status">
                            <span class="mr-modal__callout-icon" aria-hidden="true">
                                <i class="fa-solid fa-circle-check"></i>
                            </span>
                            <div>{{ __('This merchant is enabled and live.') }}</div>
                        </div>
                    @elseif($merchant->status === \App\Enums\MerchantStatus::DISABLED)
                        <div class="mr-modal__callout mr-modal__callout--warning" role="status">
                            <span class="mr-modal__callout-icon" aria-hidden="true">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </span>
                            <div>{{ __('This merchant is currently disabled.') }}</div>
                        </div>
                    @elseif($merchant->status === \App\Enums\MerchantStatus::PENDING)
                        <div class="mr-modal__callout mr-modal__callout--info" role="status">
                            <span class="mr-modal__callout-icon" aria-hidden="true">
                                <i class="fa-solid fa-hourglass-half"></i>
                            </span>
                            <div>{{ __('This request is pending review. Approving will enable merchant operations.') }}</div>
                        </div>
                    @elseif($merchant->status === \App\Enums\MerchantStatus::REJECTED)
                        <div class="mr-modal__callout mr-modal__callout--danger" role="status">
                            <span class="mr-modal__callout-icon" aria-hidden="true">
                                <i class="fa-solid fa-circle-xmark"></i>
                            </span>
                            <div>{{ __('This merchant request has been rejected.') }}</div>
                        </div>
                    @endif

                    {{-- Meta grid (replaces the chip wall) --}}
                    <div class="mr-modal__meta">
                        <div class="mr-modal__meta-item">
                            <span class="mr-modal__meta-icon" aria-hidden="true">
                                <i class="fa-solid fa-fingerprint"></i>
                            </span>
                            <div class="mr-modal__meta-body">
                                <span class="mr-modal__meta-label">{{ __('Merchant ID') }}</span>
                                <span class="mr-modal__meta-value" title="{{ $merchant->merchant_key }}">{{ $merchant->merchant_key }}</span>
                            </div>
                        </div>

                        <div class="mr-modal__meta-item">
                            <span class="mr-modal__meta-icon" aria-hidden="true">
                                <i class="fa-solid fa-user"></i>
                            </span>
                            <div class="mr-modal__meta-body">
                                <span class="mr-modal__meta-label">{{ __('Owner') }}</span>
                                <span class="mr-modal__meta-value">
                                    @if($merchant->user)
                                        <a href="{{ route('admin.user.manage', $merchant->user->username) }}"
                                           target="_blank"
                                           rel="noopener"
                                           title="{{ __('View user details') }}">{{ $merchant->user->name }}</a>
                                    @else
                                        {{ __('Owner account deleted') }}
                                    @endif
                                </span>
                            </div>
                        </div>

                        <div class="mr-modal__meta-item mr-modal__meta-item--full">
                            <span class="mr-modal__meta-icon" aria-hidden="true">
                                <i class="fa-solid fa-link"></i>
                            </span>
                            <div class="mr-modal__meta-body">
                                <span class="mr-modal__meta-label">{{ __('Site URL') }}</span>
                                <span class="mr-modal__meta-value">
                                    <a href="{{ safeUrl($merchant->site_url) }}"
                                       target="_blank"
                                       rel="noopener"
                                       title="{{ $merchant->site_url }}">{{ $merchant->site_url }}</a>
                                </span>
                            </div>
                        </div>

                        <div class="mr-modal__meta-item">
                            <span class="mr-modal__meta-icon" aria-hidden="true">
                                <i class="fa-solid fa-coins"></i>
                            </span>
                            <div class="mr-modal__meta-body">
                                <span class="mr-modal__meta-label">{{ __('Currencies') }}</span>
                                <span class="mr-modal__meta-value" title="{{ $currencies }}">{{ $currencies }}</span>
                            </div>
                        </div>

                        <div class="mr-modal__meta-item">
                            <span class="mr-modal__meta-icon" aria-hidden="true">
                                <i class="fa-solid fa-flask"></i>
                            </span>
                            <div class="mr-modal__meta-body">
                                <span class="mr-modal__meta-label">{{ __('Environment') }}</span>
                                <span class="mr-modal__meta-value">
                                    @if($merchant->isSandboxMode())
                                        <span class="mr-modal__meta-pill mr-modal__meta-pill--sandbox">{{ __('Sandbox') }}</span>
                                    @elseif($merchant->isProductionMode())
                                        <span class="mr-modal__meta-pill mr-modal__meta-pill--production">{{ __('Production') }}</span>
                                    @else
                                        {{ __('—') }}
                                    @endif
                                </span>
                            </div>
                        </div>
                    </div>

                    {{-- Business description --}}
                    <section class="mr-modal__section" aria-labelledby="mr-section-desc-{{ $merchant->id }}">
                        <header class="mr-modal__section-head">
                            <span class="mr-modal__section-icon" aria-hidden="true">
                                <i class="fa-solid fa-briefcase"></i>
                            </span>
                            <h3 class="mr-modal__section-title" id="mr-section-desc-{{ $merchant->id }}">{{ __('Requested Merchant') }}</h3>
                        </header>

                        <div class="mr-modal__quote">
                            <span class="mr-modal__quote-label">{{ __('Business Description') }}</span>
                            <div class="mr-modal__quote-text">
                                {{-- No "Show more" toggle. The one that used to live here
                                     was a Bootstrap collapse hook, in an admin that loads
                                     CoreUI (backend/js/app.js) and no Bootstrap at all, so
                                     it never expanded anything — a long description simply
                                     ended at 180 characters. The sheet scrolls; the text
                                     is whole. --}}
                                @if($description === '')
                                    <span class="mr-modal__quote-empty">{{ __('No description provided') }}</span>
                                @else
                                    {!! nl2br(e($description)) !!}
                                @endif
                            </div>
                        </div>

                        <div class="mr-modal__request-meta">
                            <i class="fa-regular fa-clock" aria-hidden="true"></i>
                            <span>{{ __('Request Date') }}: {{ $merchant->created_at?->format('Y-m-d H:i') }}</span>
                            <span class="mr-modal__request-meta-spacer"></span>
                            <span class="mr-modal__request-meta-ago">{{ $merchant->created_at?->diffForHumans() }}</span>
                        </div>
                    </section>

                    {{-- The reason the merchant was given. Collected since the form
                         request was written; shown back here for the first time. --}}
                    @if($merchant->status === \App\Enums\MerchantStatus::REJECTED && filled($merchant->rejection_reason))
                        <section class="mr-modal__section" aria-labelledby="mr-section-why-{{ $merchant->id }}">
                            <header class="mr-modal__section-head">
                                <span class="mr-modal__section-icon" aria-hidden="true">
                                    <i class="fa-solid fa-circle-xmark"></i>
                                </span>
                                <h3 class="mr-modal__section-title" id="mr-section-why-{{ $merchant->id }}">{{ __('Why this was rejected') }}</h3>
                            </header>

                            <div class="mr-modal__quote">
                                <span class="mr-modal__quote-label">{{ __('Reason sent to the merchant') }}</span>
                                <div class="mr-modal__quote-text">{!! nl2br(e($merchant->rejection_reason)) !!}</div>
                            </div>
                        </section>
                    @endif

                    {{-- Fee configuration --}}
                    @if($merchant->status !== \App\Enums\MerchantStatus::REJECTED)
                        <section class="mr-modal__section" aria-labelledby="mr-section-fee-{{ $merchant->id }}">
                            <header class="mr-modal__section-head">
                                <span class="mr-modal__section-icon" aria-hidden="true">
                                    <i class="fa-solid fa-percent"></i>
                                </span>
                                <h3 class="mr-modal__section-title" id="mr-section-fee-{{ $merchant->id }}">{{ __('Merchant Transaction Fee') }}</h3>
                            </header>

                            <label class="mr-modal__fee-label" for="merchant_fee_{{ $merchant->id }}">
                                {{ __('Charge on every successful transaction') }}
                            </label>

                            <div class="mr-modal__fee-field">
                                <input type="text"
                                       id="merchant_fee_{{ $merchant->id }}"
                                       name="fee"
                                       class="mr-modal__fee-input @error('fee') is-invalid @enderror"
                                       value="{{ old('fee', $merchant->fee) }}"
                                       placeholder="0.00"
                                       inputmode="decimal"
                                       autocomplete="off"
                                       oninput="this.value = validateDouble(this.value)"
                                       aria-describedby="feeHint-{{ $merchant->id }}"
                                       title="{{ __('Percentage of each successful transaction charged to this merchant') }}">
                                <span class="mr-modal__fee-suffix" aria-hidden="true">%</span>
                            </div>

                            <label class="mr-modal__fee-sublabel" for="merchant_fee_fixed_{{ $merchant->id }}">
                                {{ __('Fixed component') }}
                            </label>
                            <div class="mr-modal__fee-field">
                                <input type="text"
                                       id="merchant_fee_fixed_{{ $merchant->id }}"
                                       name="fee_fixed"
                                       class="mr-modal__fee-input @error('fee_fixed') is-invalid @enderror"
                                       value="{{ old('fee_fixed', $merchant->fee_fixed) }}"
                                       placeholder="{{ __('e.g. 0.30') }}"
                                       inputmode="decimal"
                                       autocomplete="off"
                                       oninput="this.value = validateDouble(this.value)"
                                       title="{{ __('Flat amount added to the percentage fee') }}">
                                <span class="mr-modal__fee-suffix" aria-hidden="true">{{ $feeUnit }}</span>
                            </div>

                            {{-- The floor and the ceiling used to hide inside a <details>.
                                 They are the two components most likely to surprise
                                 somebody, so they are on screen with the other two. --}}
                            <label class="mr-modal__fee-sublabel" for="merchant_fee_min_{{ $merchant->id }}">
                                {{ __('Minimum fee') }}
                            </label>
                            <div class="mr-modal__fee-field">
                                <input type="text"
                                       id="merchant_fee_min_{{ $merchant->id }}"
                                       name="fee_min"
                                       value="{{ old('fee_min', $merchant->fee_min) }}"
                                       class="mr-modal__fee-input @error('fee_min') is-invalid @enderror"
                                       placeholder="{{ __('No floor') }}"
                                       inputmode="decimal"
                                       autocomplete="off"
                                       oninput="this.value = validateDouble(this.value)">
                                <span class="mr-modal__fee-suffix" aria-hidden="true">{{ $feeUnit }}</span>
                            </div>

                            <label class="mr-modal__fee-sublabel" for="merchant_fee_max_{{ $merchant->id }}">
                                {{ __('Maximum fee') }}
                            </label>
                            <div class="mr-modal__fee-field">
                                <input type="text"
                                       id="merchant_fee_max_{{ $merchant->id }}"
                                       name="fee_max"
                                       value="{{ old('fee_max', $merchant->fee_max) }}"
                                       class="mr-modal__fee-input @error('fee_max') is-invalid @enderror"
                                       placeholder="{{ __('No cap') }}"
                                       inputmode="decimal"
                                       autocomplete="off"
                                       oninput="this.value = validateDouble(this.value)">
                                <span class="mr-modal__fee-suffix" aria-hidden="true">{{ $feeUnit }}</span>
                            </div>

                            <p class="mr-modal__fee-hint">
                                {{ __('A flat floor and ceiling on the total charge, in :currency — not a percentage.', ['currency' => $feeUnit]) }}
                            </p>

                            {{-- What that shape actually charges. Asked of the same
                                 calculator the checkout uses, so it cannot drift from
                                 the real figure. --}}
                            @php $feePreview = $merchant->feePreview(); @endphp
                            <div class="mr-modal__preview" role="group" aria-label="{{ __('What that actually charges') }}">
                                <h4 class="mr-modal__preview-title">{{ __('What that actually charges') }}</h4>
                                @foreach($feePreview as $row)
                                    <div class="mr-modal__preview-row">
                                        <span class="mr-modal__preview-on">{{ __('On :amount', ['amount' => money($row['amount'], $feeUnit, false).' '.$feeUnit]) }}</span>
                                        <span class="mr-modal__preview-out">
                                            @if($row['bound'] === 'floor')
                                                <span class="mr-modal__preview-bound">{{ __('floor applied') }}</span>
                                            @elseif($row['bound'] === 'cap')
                                                <span class="mr-modal__preview-bound">{{ __('cap applied') }}</span>
                                            @endif
                                            <span class="mr-modal__preview-fee">{{ money($row['fee'], $feeUnit, false) }}</span>
                                        </span>
                                    </div>
                                @endforeach
                            </div>

                            <p id="feeHint-{{ $merchant->id }}" class="mr-modal__fee-hint">
                                {{ __('Total fee = Fixed + (Amount x Percentage). Applies to successful transactions for this merchant.') }}
                            </p>

                            @error('fee')
                                <p class="mr-modal__fee-error">{{ $message }}</p>
                            @enderror
                            @error('fee_fixed')
                                <p class="mr-modal__fee-error">{{ $message }}</p>
                            @enderror
                            @error('fee_max')
                                <p class="mr-modal__fee-error">{{ $message }}</p>
                            @enderror
                        </section>
                    @endif

                    {{-- Rejection reason. Required to reject, unused on approve.
                         Without this field every rejection email interpolated
                         "No reason provided" into its {rejection_reason} slot. --}}
                    @if($merchant->status === \App\Enums\MerchantStatus::PENDING)
                        <section class="mr-modal__section" aria-labelledby="mr-section-reject-{{ $merchant->id }}">
                            <header class="mr-modal__section-head">
                                <span class="mr-modal__section-icon" aria-hidden="true">
                                    <i class="fa-solid fa-circle-xmark"></i>
                                </span>
                                <h3 class="mr-modal__section-title" id="mr-section-reject-{{ $merchant->id }}">{{ __('Rejection Reason') }}</h3>
                            </header>

                            <label class="mr-modal__fee-label" for="merchant_rejection_reason_{{ $merchant->id }}">
                                {{ __('Only used if you reject — the merchant reads this text') }}
                            </label>

                            <textarea id="merchant_rejection_reason_{{ $merchant->id }}"
                                      name="rejection_reason"
                                      rows="3"
                                      maxlength="500"
                                      class="mr-modal__reason-input @error('rejection_reason') is-invalid @enderror"
                                      placeholder="{{ __('Tell them what to fix so they can apply again.') }}"
                                      aria-describedby="rejectHint-{{ $merchant->id }}"
                                      oninput="this.nextElementSibling.textContent = this.value.length + ' / 500'">{{ old('rejection_reason') }}</textarea>
                            <span class="mr-modal__reason-count" aria-hidden="true">{{ mb_strlen((string) old('rejection_reason')) }} / 500</span>

                            <p id="rejectHint-{{ $merchant->id }}" class="mr-modal__fee-hint">
                                {{ __('Approving ignores this field. Rejecting without a reason is not allowed.') }}
                            </p>

                            @error('rejection_reason')
                                <p class="mr-modal__fee-error">{{ $message }}</p>
                            @enderror
                        </section>
                    @endif

                    {{-- Operational note --}}
                    @if($merchant->status === \App\Enums\MerchantStatus::APPROVED)
                        <div class="mr-modal__note">
                            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                            <span>{{ __('You can disable this merchant to pause payments and API access.') }}</span>
                        </div>
                    @elseif($merchant->status === \App\Enums\MerchantStatus::DISABLED)
                        <div class="mr-modal__note">
                            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                            <span>{{ __('Enable the merchant to resume payments and API access.') }}</span>
                        </div>
                    @endif
                </div>

                {{-- Footer --}}
                <div class="modal-footer mr-modal__footer">
                    @if($merchant->status === \App\Enums\MerchantStatus::PENDING)
                        <button type="submit"
                                name="action"
                                value="approve"
                                class="mr-modal__btn mr-modal__btn--approve"
                                title="{{ __('Approve this merchant and enable operations') }}">
                            <i class="fa-solid fa-check" aria-hidden="true"></i>
                            <span>{{ __('Approve') }}</span>
                        </button>
                        <button type="submit"
                                name="action"
                                value="reject"
                                class="mr-modal__btn mr-modal__btn--reject"
                                title="{{ __('Reject this merchant request') }}">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            <span>{{ __('Reject') }}</span>
                        </button>
                    @elseif($merchant->status === \App\Enums\MerchantStatus::APPROVED)
                        <button type="submit"
                                name="action"
                                value="approve"
                                class="mr-modal__btn mr-modal__btn--primary"
                                title="{{ __('Save the updated fee') }}">
                            <i class="fa-solid fa-rotate" aria-hidden="true"></i>
                            <span>{{ __('Update Fee') }}</span>
                        </button>
                        <button type="submit"
                                name="action"
                                value="disable"
                                class="mr-modal__btn mr-modal__btn--ghost-danger"
                                title="{{ __('Disable this merchant to pause payments and API access') }}">
                            <i class="fa-solid fa-ban" aria-hidden="true"></i>
                            <span>{{ __('Disable Merchant') }}</span>
                        </button>
                    @elseif($merchant->status === \App\Enums\MerchantStatus::DISABLED)
                        <button type="submit"
                                name="action"
                                value="enable"
                                class="mr-modal__btn mr-modal__btn--approve"
                                title="{{ __('Enable this merchant to resume payments and API access') }}">
                            <i class="fa-solid fa-power-off" aria-hidden="true"></i>
                            <span>{{ __('Enable Merchant') }}</span>
                        </button>
                        <button type="submit"
                                name="action"
                                value="approve"
                                class="mr-modal__btn mr-modal__btn--ghost-primary"
                                title="{{ __('Save the updated fee') }}">
                            <i class="fa-solid fa-rotate" aria-hidden="true"></i>
                            <span>{{ __('Update Fee') }}</span>
                        </button>
                    @else
                        <div class="mr-modal__footer-empty">
                            {{ __('This merchant is rejected. No further actions available.') }}
                        </div>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>
