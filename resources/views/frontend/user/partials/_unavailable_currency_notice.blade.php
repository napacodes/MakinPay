@php
    /**
     * Explains a greyed-out wallet.
     *
     * The server refuses to convert at an unusable rate, and the selects above
     * stop the wallet being chosen — but a disabled option with no explanation
     * reads as a broken page. Naming the reason turns a dead end into
     * something the customer can act on, and tells support what to look at.
     *
     * Expects $wallets: any collection whose items expose ->currency.
     */
    $blocked = collect($wallets ?? [])
        ->filter(fn ($wallet): bool => $wallet->currency && ! $wallet->currency->isConvertible())
        ->map(fn ($wallet): string => (string) $wallet->currency->code)
        ->unique()
        ->values();
@endphp

@if($blocked->isNotEmpty())
    <div class="alert alert-warning d-flex align-items-start gap-2 py-2 px-3 mb-3" role="status">
        <i class="fas fa-circle-info mt-1" aria-hidden="true"></i>
        <div class="small">
            {{ trans_choice(
                '{1}The :codes wallet is unavailable right now because its exchange rate has not been set.|[2,*]These wallets are unavailable right now because their exchange rates have not been set: :codes.',
                $blocked->count(),
                ['codes' => $blocked->join(', ')]
            ) }}
            <span class="d-block text-muted">{{ __('Your balance is safe. Please try again later or contact support.') }}</span>
        </div>
    </div>
@endif
