{{--
    One amount per currency, each in its own currency - never a sum across them.
    $lines: list<array{currency: ?string, amount: string}> - from WalletEarnSummary, or the gift card page's sent value.
--}}
@forelse($lines as $line)
    <span class="d-block">{{ money($line['amount'], $line['currency'], false) }} {{ $line['currency'] }}</span>
@empty
    {{ money(0, null, false) }}
@endforelse
