@php
    use App\Enums\AmountFlow;
    use App\Enums\TrxStatus;
    use App\Enums\TrxType;
    use App\Support\DecimalMoney;
    use App\Support\Money;

    /*
     * dompdf implements neither flexbox nor grid, so every row on this page is
     * a table. It also has no support for `rotate`, so the status stamp is a
     * bordered cell sitting square rather than the tilted one in the design.
     */

    $trxData  = $transaction->trx_data ?? [];
    $currency = $transaction->currency;
    $timezone = setting('site_timezone', config('app.timezone', 'UTC'));

    $isSettled = $transaction->status === TrxStatus::COMPLETED;
    $isVoid    = in_array($transaction->status, [TrxStatus::FAILED, TrxStatus::CANCELED], true);
    $isInbound = $transaction->amount_flow === AmountFlow::PLUS;

    $stampColor = match ($transaction->status) {
        TrxStatus::COMPLETED => '#047857',
        TrxStatus::PENDING   => '#b45309',
        default              => '#b91c1c',
    };

    /*
     * The amount is printed in the flow colour only once the money has
     * actually moved. A green `+` on a failed transaction reads as a receipt
     * for a payment that never happened.
     */
    $amountColor = $isSettled ? ($isInbound ? '#047857' : '#111827') : '#6b7280';

    $refunded  = $transaction->refundedAmount();
    $feeAmount = (float) $transaction->fee;

    /*
     * Two units, not one.
     *
     * `amount` and `fee` are denominated in `currency`; `net_amount` and
     * `payable_amount` are denominated in `payable_currency`. On a BTC wallet
     * funded from a USD base those are different scales, and formatting the
     * settlement side at the base's two decimals is what once made every
     * crypto figure on this document read `0.00`.
     */
    $settleCurrency = $transaction->payable_currency ?: $currency;
    $mixedUnits     = $settleCurrency !== $currency;

    $showPayable = $mixedUnits
        || Money::quantize($transaction->payable_amount, $settleCurrency) !== Money::quantize($transaction->net_amount, $settleCurrency);

    /* The other side of the transaction, named for what it actually is. */
    $counterparty = match (true) {
        $transaction->trx_type === TrxType::RECEIVE_PAYMENT => [
            'label' => __('Paying customer'),
            'name'  => $trxData['customer_name'] ?? $transaction->provider,
            'sub'   => $trxData['merchant_name'] ?? '',
        ],
        $transaction->trx_type === TrxType::WITHDRAW => [
            'label' => __('Payout destination'),
            'name'  => $trxData['beneficiary_name'] ?? $transaction->provider,
            'sub'   => $trxData['account_number'] ?? '',
        ],
        $transaction->trx_type === TrxType::DEPOSIT => [
            'label' => __('Payment source'),
            'name'  => $transaction->provider,
            'sub'   => $trxData['account_number'] ?? ($transaction->gateway_code ?? ''),
        ],
        /*
         * The account the bill was paid against, never the token. A prepaid
         * token is shown only on the owner's receipt screen, because this
         * document is downloaded by transaction id alone.
         */
        $transaction->trx_type === TrxType::BILL_PAYMENT => [
            'label' => __('Biller'),
            'name'  => $trxData['biller_name'] ?? $transaction->provider,
            'sub'   => filled($trxData['customer_number'] ?? null)
                ? (($trxData['customer_number_label'] ?? '') ?: __('Account number')).': '.$trxData['customer_number']
                : '',
        ],
        default => [
            'label' => __('Provider'),
            'name'  => $transaction->provider,
            'sub'   => $transaction->gateway_code ?? '',
        ],
    };

    $totalLabel = $isInbound ? __('Total credited') : __('Total debited');
    $netLabel   = $isInbound ? __('Net credited') : __('Net debited');

    /*
     * The total has to be what the column above it adds up to.
     *
     * It used to print `net_amount`, which on a partially refunded payment
     * contradicted the Refunded line two rows above: the items read
     * 120.00 − 2.50 − 20.00 and the total said 117.50. Caught by reading a
     * rendered PDF, not the template.
     *
     * The refund is only subtracted when it is denominated in the same unit as
     * the settlement — refunds carry the transaction's `currency`, and taking
     * one off a `payable_currency` net would be subtracting dollars from
     * bitcoin. Where the units differ the Refunded row stands on its own and
     * the total remains the settled figure.
     *
     * bcmath, not float: this is the number a customer reconciles against.
     */
    /*
     * What came out of the amount and what was paid on top of it, read from
     * the row's own figures, so each charge carries the sign it really had.
     * A fee may be deducted or charged on top (the fee mode); GF6 tax is
     * charged on top, except a detected deposit, where it came out of what
     * arrived. Printing every charge as "−" made an on-top send read
     * 100 − 2 − 5 above a total of 100, when 107 had left the wallet.
     *
     * Only worked out when everything is in one unit; across two the column
     * keeps its old shape.
     */
    $taxTotal  = DecimalMoney::normalize((string) ($transaction->wallet_tax ?? '0'));
    $onTop     = $mixedUnits ? '0' : DecimalMoney::subtract((string) $transaction->payable_amount, (string) $transaction->amount);
    $taxOnTop  = ! $mixedUnits && DecimalMoney::compare($taxTotal, '0') > 0 && DecimalMoney::compare($onTop, $taxTotal) >= 0;
    $feeOnTop  = ! $mixedUnits && $feeAmount > 0
        && DecimalMoney::compare(DecimalMoney::subtract($onTop, $taxOnTop ? $taxTotal : '0'), (string) $transaction->fee) >= 0;

    /*
     * An outbound total is what left the wallet - the payable, charges on top
     * included - not the net that reached the other side.
     */
    $settledTotal = match (true) {
        $mixedUnits => (string) $transaction->net_amount,
        $isInbound  => DecimalMoney::subtract((string) $transaction->net_amount, (string) $refunded),
        default     => DecimalMoney::subtract((string) $transaction->payable_amount, (string) $refunded),
    };

    $total = $isVoid
        ? Money::format(0, $settleCurrency)
        : Money::format($settledTotal, $settleCurrency);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ language_direction() }}">
<head>
    <meta charset="UTF-8">
    {{--
        dompdf ignores this, but the print path serves the same file to a
        browser: without it a phone falls back to a 981px layout viewport and
        scales the whole receipt down to something nobody can read.
    --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Transaction receipt') }} · {{ strtoupper($transaction->trx_id) }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }

        /*
         * DejaVu is the only family dompdf ships with full Unicode coverage,
         * and it is what keeps an Arabic customer name from rendering as a row
         * of empty boxes. Inter is not available to dompdf, so it is not named.
         */
        body {
            margin: 0;
            padding: 56px 64px 48px;
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 13px;
            line-height: 1.5;
            color: #111827;
        }

        .bar { height: 6px; background: #4f46e5; margin: -56px -64px 34px; }

        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }

        .eyebrow {
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #6b7280;
        }

        .muted { color: #6b7280; }
        .small { font-size: 11.5px; }
        .end   { text-align: right; }

        .brand { font-size: 22px; font-weight: bold; }

        .hero {
            margin-top: 34px;
            padding: 20px 22px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
        }

        .hero-amount {
            font-size: 32px;
            font-weight: bold;
            line-height: 1;
        }

        .stamp {
            display: inline-block;
            padding: 7px 15px;
            border: 2px solid #6b7280;
            border-radius: 8px;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .party-head {
            padding-bottom: 7px;
            border-bottom: 1px solid #e5e7eb;
        }

        .lines { margin-top: 26px; }
        .lines th {
            padding: 8px 0;
            border-bottom: 2px solid #111827;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #6b7280;
        }
        .lines td { padding: 10px 0; border-bottom: 1px solid #f0f1f4; }
        .lines .net td { background: #f9fafb; font-weight: bold; }
        .lines tfoot td { padding-top: 13px; font-weight: bold; font-size: 15px; }

        code { font-family: 'DejaVu Sans Mono', monospace; font-size: 11.5px; }

        .foot {
            margin-top: 34px;
            padding-top: 18px;
            border-top: 1px solid #e5e7eb;
        }

        /*
         * Only the print path sends this to a screen. dompdf ignores both
         * blocks — it has no viewport and no print media — so the PDF is
         * byte-for-byte what it was.
         */
        @media screen {
            body {
                /* 794px is A4 at 96dpi, and the padding has to live inside it
                   or the sheet on screen is 128px wider than the paper. This
                   page carries no app stylesheet, so nothing else sets it. */
                box-sizing: border-box;
                max-width: 794px;
                margin: 24px auto;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.12);
            }
        }

        /* A4 margins on a 375px screen leave ~247px of usable width, which
           wraps every line in the table. Only the screen needs this; print
           keeps the paper margins from the base rule. */
        @media screen and (max-width: 575.98px) {
            body {
                margin: 0;
                padding: 24px 18px 32px;
                box-shadow: none;
            }
        }

        @media print {
            body { margin: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
<div class="bar"></div>

<table>
    <tr>
        <td style="width: 58%;">
            @if($siteLogo)
                <img src="{{ $siteLogo }}" alt="{{ setting('site_title', config('app.name')) }}" style="max-height: 34px;">
            @else
                <div class="brand">{{ setting('site_title', config('app.name')) }}</div>
            @endif
            <div class="small muted" style="margin-top: 4px;">
                @if(setting('support_email'))
                    {{ setting('support_email') }}@if(setting('support_phone')) · {{ setting('support_phone') }}@endif<br>
                @endif
                {{ parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.url') }}
            </div>
        </td>
        <td class="end">
            <div class="eyebrow">{{ __('Transaction receipt') }}</div>
            <code style="font-size: 15px; font-weight: bold;">{{ strtoupper($transaction->trx_id) }}</code>
            <div class="small muted" style="margin-top: 4px;">
                {{ __('Issued :date · :tz', ['date' => $transaction->created_at->format('d M Y, H:i'), 'tz' => $timezone]) }}
            </div>
        </td>
    </tr>
</table>

<div class="hero">
    <table>
        <tr>
            <td style="width: 64%;">
                <div class="eyebrow">{{ $transaction->trx_type->label() }}</div>
                <div class="hero-amount" style="margin-top: 6px; color: {{ $amountColor }};">
                    {{ $transaction->amount_flow?->sign($transaction->status) }}{{ Money::format($transaction->amount, $currency) }}
                    <span style="font-size: 15px; color: #6b7280;">{{ $currency }}</span>
                </div>
                @if($transaction->description)
                    <div class="small" style="margin-top: 7px; color: #4b5563;">{{ $transaction->description }}</div>
                @endif
            </td>
            <td class="end">
                <span class="stamp" style="border-color: {{ $stampColor }}; color: {{ $stampColor }};">{{ $transaction->status->label() }}</span>
                <div class="small muted" style="margin-top: 7px;">
                    @if($isSettled)
                        {{ __('Settled :date', ['date' => $transaction->updated_at->format('d M Y')]) }}
                    @else
                        {{ __('Not yet settled') }}
                    @endif
                </div>
            </td>
        </tr>
    </table>
</div>

<table style="margin-top: 26px;">
    <tr>
        <td style="width: 50%; padding-inline-end: 12px;">
            <div class="eyebrow party-head">{{ __('Account holder') }}</div>
            <div style="margin-top: 9px; font-weight: bold; font-size: 14px;">{{ $transaction->user?->name }}</div>
            <div class="small" style="color: #4b5563;">
                @if($transaction->user)&#64;{{ $transaction->user->username }}<br>{{ $transaction->user->email }}<br>@endif
                @if($transaction->wallet_reference){{ __('Wallet') }} <code>{{ $transaction->wallet_reference }}</code>@endif
            </div>
        </td>
        <td style="width: 50%; padding-inline-start: 12px;">
            <div class="eyebrow party-head">{{ $counterparty['label'] }}</div>
            <div style="margin-top: 9px; font-weight: bold; font-size: 14px;">{{ $counterparty['name'] }}</div>
            <div class="small" style="color: #4b5563;">
                @if($counterparty['sub']){{ $counterparty['sub'] }}<br>@endif
                {{ __(':type processing', ['type' => $transaction->processing_type?->label()]) }}<br>
                @if($transaction->trx_reference){{ __('Reference :ref', ['ref' => $transaction->trx_reference]) }}@endif
            </div>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="text-align: start;">{{ __('Description') }}</th>
            <th class="end">{{ __('Amount') }}</th>
        </tr>
    </thead>
    <tbody>
        {{--
            Every row names the unit it is counted in whenever the document
            carries more than one. A column of bare figures in two currencies
            is worse than no figures at all.
        --}}
        <tr>
            <td>{{ $transaction->trx_type->label() }}</td>
            <td class="end">{{ Money::format($transaction->amount, $currency) }}@if($mixedUnits) {{ $currency }}@endif</td>
        </tr>
        <tr>
            <td>
                {{ __('Platform fee') }}
                @if($feeAmount <= 0)
                    <div class="small muted" style="font-weight: normal;">{{ __('No fee') }}</div>
                @endif
            </td>
            <td class="end" style="{{ $feeAmount > 0 ? 'color: #b91c1c;' : '' }}">
                {{ $feeAmount > 0 ? ($feeOnTop ? '+ ' : '− ') : '' }}{{ Money::format($feeAmount, $currency) }}@if($mixedUnits) {{ $currency }}@endif
            </td>
        </tr>
        {{-- GF6: a line per tax rule, in the settlement currency, apart from the fee. --}}
        @foreach ((array) $transaction->tax_breakdown as $taxLine)
            <tr data-tax-line>
                <td>
                    {{ $taxLine['name'] ?? __('Tax') }}
                    <div class="small muted" style="font-weight: normal;">{{ __('Tax') }}</div>
                </td>
                <td class="end" style="color: #b91c1c;">
                    {{ $taxOnTop ? '+' : '−' }} {{ Money::format($taxLine['amount'] ?? 0, $settleCurrency) }}@if($mixedUnits) {{ $settleCurrency }}@endif
                </td>
            </tr>
        @endforeach
        {{--
            Net first, then what has since been taken back out of it. The other
            order made the column stop reading as a running sum — a refund
            subtracted before the net it was subtracted from.
        --}}
        <tr class="net">
            <td>{{ $netLabel }}</td>
            <td class="end">{{ Money::format($transaction->net_amount, $settleCurrency) }}@if($mixedUnits) {{ $settleCurrency }}@endif</td>
        </tr>
        @if($refunded > 0)
            <tr>
                <td>{{ __('Refunded') }}</td>
                <td class="end" style="color: #b45309;">− {{ Money::format($refunded, $currency) }}@if($mixedUnits) {{ $currency }}@endif</td>
            </tr>
        @endif
        @if($showPayable)
            <tr>
                <td>{{ __('Payable (:currency)', ['currency' => $settleCurrency]) }}</td>
                <td class="end">{{ Money::format($transaction->payable_amount, $settleCurrency) }}</td>
            </tr>
        @endif
    </tbody>
    <tfoot>
        <tr>
            <td>{{ $totalLabel }}</td>
            <td class="end" style="font-size: 18px;">{{ $total }}@if($mixedUnits) {{ $settleCurrency }}@endif</td>
        </tr>
    </tfoot>
</table>

@if(filled($transaction->exchange_rate) && (float) $transaction->exchange_rate > 0)
    <div class="small muted" style="margin-top: 13px;">
        {{ __('Exchange rate :rate applied at the time of the transaction. Reporting value :amount (:currency).', [
            'rate'     => Money::formatRate($transaction->exchange_rate),
            'amount'   => Money::format($transaction->base_amount, $transaction->base_currency),
            'currency' => $transaction->base_currency,
        ]) }}
    </div>
@endif

@if($transaction->status === TrxStatus::PENDING)
    <div class="small" style="margin-top: 13px; color: #b45309;">{{ __('This receipt is provisional until the transaction settles.') }}</div>
@elseif($isVoid)
    <div class="small" style="margin-top: 13px; color: #b91c1c;">{{ __('No funds moved.') }}</div>
@endif

<div class="foot small muted">
    {{ __('This receipt was generated electronically by :site and is valid without a signature.', ['site' => setting('site_title', config('app.name'))]) }}
    {{ __('Verify it any time at :url using the transaction ID.', ['url' => config('app.url')]) }}
    @if(setting('support_email'))
        {{ setting('support_email') }}
    @endif
</div>

@if($autoPrint ?? false)
    {{--
        The page exists to be printed, so it asks for the dialog itself rather
        than leaving the operator to find Ctrl+P. `load` rather than
        `DOMContentLoaded`: printing before the logo has decoded prints a gap
        where the wordmark should be.
    --}}
    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
@endif
</body>
</html>
