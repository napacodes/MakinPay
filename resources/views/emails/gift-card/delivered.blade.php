@php
    $currencySymbol = $giftCard->currency?->symbol ?? siteCurrency('symbol');
    $amountText = $currencySymbol.money($giftCard->amount, $giftCard->currency?->code, false);
@endphp
<x-mail::message>
# 🎁 {{ __('You have a gift card!') }}

{{ __(':sender has sent you a :site_title gift card.', ['sender' => $giftCard->sender_name, 'site_title' => setting('site_title')]) }}

<x-mail::panel>
**{{ __('Gift Card Value') }}:** {{ $amountText }}
**{{ __('Gift Code') }}:** `{{ $giftCard->code }}`
**{{ __('Expires') }}:** {{ optional($giftCard->expires_at)->format('M d, Y') ?? __('No expiry') }}
</x-mail::panel>

@if($giftCard->message)
> {!! e($giftCard->message) !!}
@endif

<x-mail::button :url="$previewUrl" color="primary">
{{ __('Open Your Gift') }}
</x-mail::button>

{{ __('Tap the button above to view the full design and redeem the amount into your wallet.') }}

{{ __('Thanks') }},<br>
{{ config('app.name') }}
</x-mail::message>
