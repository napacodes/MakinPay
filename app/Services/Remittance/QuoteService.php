<?php

namespace App\Services\Remittance;

use App\Enums\PayoutMethod;
use App\Enums\Tax\TaxableType;
use App\Models\RemittanceCorridor;
use App\Models\RemittanceQuote;
use App\Models\User;
use App\Services\CurrencyConversionService;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use App\Services\Tax\TaxEngine;
use App\Support\DecimalMoney;
use Exception;
use InvalidArgumentException;
use Wallet;

class QuoteService
{
    public function __construct(
        protected CurrencyConversionService $conversionService,
        protected PayoutGatewayFactory $gatewayFactory,
        protected TaxEngine $taxes = new TaxEngine,
    ) {}

    /**
     * Generate (and persist) a locked remittance quote for the user.
     *
     * @throws Exception
     */
    public function quote(
        User $user,
        RemittanceCorridor $corridor,
        string|int|float $sendAmount,
        PayoutMethod $payoutMethod,
        ?int $beneficiaryId = null,
    ): RemittanceQuote {
        $this->assertCorridorAccepts($corridor, $payoutMethod, $sendAmount);

        // Each leg lands on its own currency's precision. DecimalMoney::round()
        // defaults to two decimals, which is right for USD and wrong for both
        // ends of a corridor an operator is likely to open: a KWD payout has
        // three, and a JPY or UGX one has none at all.
        $sendAmount = DecimalMoney::round($sendAmount, currencyDecimals($corridor->source_currency));
        $midRate    = $this->fetchMidMarketRate($corridor);
        $appliedFx  = DecimalMoney::subtractPercentage($midRate, $corridor->fx_spread_percent);
        $feeAmount  = $this->calculateFee($corridor, $sendAmount);
        $receiveAmt = DecimalMoney::round(
            DecimalMoney::multiply($sendAmount, $appliedFx),
            currencyDecimals($corridor->destination_currency),
        );

        // GF6: the sender's remittance tax, on top of what they pay and
        // locked with the quote; the amount sent and received are unchanged.
        $tax = $this->taxes->quote(
            TaxableType::Remittance,
            $user,
            $corridor->source_currency,
            $sendAmount,
            $feeAmount,
            (string) Wallet::fxRate($corridor->source_currency),
        );

        return RemittanceQuote::create([
            'user_id'              => $user->id,
            'corridor_id'          => $corridor->id,
            'beneficiary_id'       => $beneficiaryId,
            'source_currency'      => $corridor->source_currency,
            'destination_currency' => $corridor->destination_currency,
            'send_amount'          => $sendAmount,
            'fee_amount'           => $feeAmount,
            'tax_amount'           => $tax->isEmpty() ? null : $tax->total,
            'tax_breakdown'        => $tax->isEmpty() ? null : $tax->breakdown(),
            // What the sender pays, so it is denominated — and rounded — in
            // the currency they are debited in.
            'total_pay' => DecimalMoney::add(
                DecimalMoney::add($sendAmount, $feeAmount, DecimalMoney::CALCULATION_SCALE),
                $tax->total,
                currencyDecimals($corridor->source_currency),
            ),
            'exchange_rate'   => $appliedFx,
            'mid_market_rate' => $midRate,
            'rate_provider'   => $this->conversionService->fiatRateProvider(),
            'rate_fetched_at' => now(),
            'receive_amount'  => $receiveAmt,
            'payout_method'   => $payoutMethod,
            'expires_at'      => now()->addMinutes($corridor->quote_ttl_minutes),
            'is_consumed'     => false,
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function assertCorridorAccepts(RemittanceCorridor $corridor, PayoutMethod $method, string|int|float $amount): void
    {
        if (! $corridor->status) {
            throw new InvalidArgumentException(__('This corridor is currently disabled.'));
        }

        if (! $corridor->supportsPayoutMethod($method)) {
            throw new InvalidArgumentException(__('Selected payout method is not supported on this corridor.'));
        }

        if (! $this->gatewayFactory->supports($corridor->payout_gateway)) {
            throw new InvalidArgumentException(__('The payout provider for this corridor is unavailable.'));
        }

        if (DecimalMoney::compare($amount, $corridor->min_amount) < 0 || DecimalMoney::compare($amount, $corridor->max_amount) > 0) {
            throw new InvalidArgumentException(__('Amount must be between :min and :max.', [
                'min' => $corridor->min_amount,
                'max' => $corridor->max_amount,
            ]));
        }
    }

    /**
     * @throws Exception
     */
    protected function fetchMidMarketRate(RemittanceCorridor $corridor): string
    {
        $converted = $this->conversionService->convertCurrency(
            amount: 1,
            from: $corridor->source_currency,
            to: $corridor->destination_currency,
        );

        if ($converted === null || $converted <= 0) {
            throw new Exception(__('Could not fetch exchange rate for :from to :to.', [
                'from' => $corridor->source_currency,
                'to'   => $corridor->destination_currency,
            ]));
        }

        return DecimalMoney::round($converted, DecimalMoney::CALCULATION_SCALE);
    }

    /**
     * The fee is charged on the leg the sender pays, so it carries the source
     * currency's precision rather than a flat two decimals.
     */
    protected function calculateFee(RemittanceCorridor $corridor, string $sendAmount): string
    {
        $scale = currencyDecimals($corridor->source_currency);

        return DecimalMoney::add(
            $corridor->fixed_fee,
            DecimalMoney::percentage($sendAmount, $corridor->percent_fee, $scale),
            $scale,
        );
    }
}
