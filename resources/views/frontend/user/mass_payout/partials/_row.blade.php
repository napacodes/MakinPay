{{--
    One line of a payout batch in the preview and progress table. Expects
    $row (MassPaymentRow) and $currency. The account is only ever the masked
    label; the details never reach this page.
--}}
@php
    $mpRowStatus = $row->status();
    $mpRowIssue = $row->issue();
    $mpRowDispatch = $row->dispatchState();
    $mpRowWarnings = collect(is_array($row->warnings) ? $row->warnings : [])
        ->map(function ($warning) {
            $code = \App\Enums\MassPayment\MassPaymentWarning::tryFrom((string) data_get($warning, 'code'));
            $line = data_get($warning, 'duplicate_of_line');

            return $code?->message($line === null ? null : (int) $line);
        })
        ->filter()
        ->values();
    $mpRowFilter = match (true) {
        $mpRowStatus === \App\Enums\MassPayment\MassPaymentRowStatus::Invalid => 'invalid',
        $mpRowWarnings->isNotEmpty()                                           => 'warning',
        default                                                                => 'ok',
    };
    $mpAmount = fn ($value) => $value === null ? '—' : money($value, $currency, false);
@endphp
<tr class="mp-row mp-row--{{ $mpRowFilter }}" data-mp-row="{{ $mpRowFilter }}" data-mp-line="{{ $row->line_no }}">
    <td class="mp-row__line" dir="ltr">{{ $row->line_no }}</td>
    <td class="mp-row__recipient">
        <span class="mp-row__match">{{ $row->recipient_label }}</span>
        @if(filled($row->client_reference))
            <span class="mp-row__reference">{{ __('Ref') }} <span class="mp-mono" dir="ltr">{{ $row->client_reference }}</span></span>
        @endif
        @if(filled($row->provider_reference) && $mpRowDispatch === \App\Enums\MassPayment\MassPaymentDispatchState::Settled)
            <span class="mp-row__reference">{{ __('Payout ref') }} <span class="mp-mono" dir="ltr">{{ $row->provider_reference }}</span></span>
        @endif
    </td>
    <td class="mp-row__figure" dir="ltr">
        @if($row->net_amount === null)
            <span class="mp-mono mp-muted">{{ \Illuminate\Support\Str::limit((string) $row->amount_input, 24) }}</span>
        @else
            {{ $mpAmount($row->net_amount) }}
        @endif
    </td>
    <td class="mp-row__figure" dir="ltr">{{ $mpAmount($row->wallet_fee) }}</td>
    <td class="mp-row__figure mp-row__figure--total" dir="ltr">{{ $mpAmount($row->payable_amount) }}</td>
    <td class="mp-row__status">
        @if($mpRowDispatch !== null)
            <span class="mp-pill mp-pill--{{ $mpRowDispatch->color() }}" data-mp-dispatch="{{ $mpRowDispatch->value }}">{{ $mpRowDispatch->customerLabel() }}</span>
        @elseif($mpRowStatus !== null)
            <span class="mp-pill mp-pill--{{ $mpRowStatus->color() }}">{{ $mpRowStatus->label() }}</span>
        @endif
        @if($row->needs_review)
            <span class="mp-pill mp-pill--warning">{{ __('Under review') }}</span>
        @endif
    </td>
    <td class="mp-row__issue">
        @if($mpRowIssue !== null)
            <span class="mp-issue mp-issue--danger" data-mp-issue="{{ $mpRowIssue->value }}">{{ $row->issueMessage() }}</span>
        @endif
        @foreach($mpRowWarnings as $warning)
            <span class="mp-issue mp-issue--warning">{{ $warning }}</span>
        @endforeach
        @if(filled($row->note))
            <span class="mp-row__note">{{ \Illuminate\Support\Str::limit((string) $row->note, 80) }}</span>
        @endif
    </td>
</tr>
