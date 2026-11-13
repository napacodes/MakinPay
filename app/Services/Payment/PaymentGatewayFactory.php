<?php

namespace App\Services\Payment;

use App\Models\PaymentGateway as PaymentGatewayModel;
use App\Services\Payment\Airtel\AirtelPaymentGateway;
use App\Services\Payment\Binance\BinancePaymentGateway;
use App\Services\Payment\Bitnob\BitnobPaymentGateway;
use App\Services\Payment\Bitpayserver\BitpayserverPaymentGateway;
use App\Services\Payment\Blockchain\BlockchainPaymentGateway;
use App\Services\Payment\Blockio\BlockioPaymentGateway;
use App\Services\Payment\BsiCards\BsiCardsPaymentGateway;
use App\Services\Payment\Cashmaal\CashmaalPaymentGateway;
use App\Services\Payment\Coinbase\CoinbasePaymentGateway;
use App\Services\Payment\Coingate\CoingatePaymentGateway;
use App\Services\Payment\Coinpayments\CoinpaymentsPaymentGateway;
use App\Services\Payment\Cryptomus\CryptomusPaymentGateway;
use App\Services\Payment\Flutterwave\FlutterwavePaymentGateway;
use App\Services\Payment\Instamojo\InstamojoPaymentGateway;
use App\Services\Payment\MercadoPago\MercadoPagoPaymentGateway;
use App\Services\Payment\Mollie\MolliePaymentGateway;
use App\Services\Payment\Moneroo\MonerooPaymentGateway;
use App\Services\Payment\MTN\MTNPaymentGateway;
use App\Services\Payment\Nowpayments\NowpaymentsPaymentGateway;
use App\Services\Payment\Paymob\PaymobPaymentGateway;
use App\Services\Payment\Paypal\PaypalPaymentGateway;
use App\Services\Payment\Paystack\PaystackPaymentGateway;
use App\Services\Payment\Razorpay\RazorpayPaymentGateway;
use App\Services\Payment\Stripe\StripePaymentGateway;
use App\Services\Payment\StroWallet\StroWalletPaymentGateway;
use App\Services\Payment\Twocheckout\TwocheckoutPaymentGateway;
use App\Services\Payment\Voguepay\VoguepayPaymentGateway;
use Exception;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;

class PaymentGatewayFactory
{
    /**
     * Codes that have no `payment_gateways` row and never will.
     *
     * `manual` is reached through `?? $depositMethod->type` and is not a
     * gateway at all, so the activation check must not strand it.
     */
    private const UNMANAGED_CODES = ['manual'];

    private const EXTENSION_CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,31}$/';

    /**
     * Gateways contributed by add-ons, by code. Bound as a singleton so a
     * registration made by an add-on's provider is seen by PaymentService and
     * by the IPN controller alike.
     *
     * @var array<string, class-string>
     */
    private array $extensions = [];

    /**
     * Create an instance of a payment gateway.
     *
     * @param bool $requireActive whether the gateway must be enabled. True for
     *                            opening a new payment: one an administrator
     *                            has switched off must not take more money.
     *                            False for an inbound callback, where funds may
     *                            already sit at a gateway disabled since —
     *                            stranding them would be the worse failure, and
     *                            the binding check is what decides whether that
     *                            callback may settle anything.
     *
     * @throws Exception
     */
    public function getGateway(string $gatewayCode, bool $requireActive = true)
    {
        if ($requireActive) {
            $this->assertEnabled($gatewayCode);
        }

        $class = $this->coreGateways()[$gatewayCode] ?? $this->extensions[$gatewayCode] ?? null;

        if ($class === null) {
            throw new Exception(sprintf('Unsupported payment gateway: %s', $gatewayCode));
        }

        return App::make($class);
    }

    /**
     * Lets an add-on contribute a gateway under a code of its own.
     *
     * A core code can never be claimed, and a claimed code never changes
     * hands: an add-on must not be able to open payments, or answer the
     * callbacks, of a gateway that is not its own.
     *
     * @param class-string $class
     *
     * @throws InvalidArgumentException
     */
    public function extend(string $gatewayCode, string $class): void
    {
        if (preg_match(self::EXTENSION_CODE_PATTERN, $gatewayCode) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid payment gateway code: %s', $gatewayCode));
        }

        if (isset($this->coreGateways()[$gatewayCode]) || in_array($gatewayCode, self::UNMANAGED_CODES, true)) {
            throw new InvalidArgumentException(sprintf('Payment gateway code %s belongs to core.', $gatewayCode));
        }

        if (isset($this->extensions[$gatewayCode]) && $this->extensions[$gatewayCode] !== $class) {
            throw new InvalidArgumentException(sprintf('Payment gateway code %s is already registered.', $gatewayCode));
        }

        $this->extensions[$gatewayCode] = $class;
    }

    public function hasGateway(string $gatewayCode): bool
    {
        return isset($this->coreGateways()[$gatewayCode]) || isset($this->extensions[$gatewayCode]);
    }

    /**
     * @return array<string, class-string>
     */
    private function coreGateways(): array
    {
        return [
            'paypal'       => PaypalPaymentGateway::class,
            'stripe'       => StripePaymentGateway::class,
            'mollie'       => MolliePaymentGateway::class,
            'coinbase'     => CoinbasePaymentGateway::class,
            'paystack'     => PaystackPaymentGateway::class,
            'paymob'       => PaymobPaymentGateway::class,
            'flutterwave'  => FlutterwavePaymentGateway::class,
            'cryptomus'    => CryptomusPaymentGateway::class,
            'manual'       => ManualPaymentSystem::class,
            'mercadopago'  => MercadoPagoPaymentGateway::class,
            'moneroo'      => MonerooPaymentGateway::class,
            'strowallet'   => StroWalletPaymentGateway::class,
            'voguepay'     => VoguepayPaymentGateway::class,
            'twocheckout'  => TwocheckoutPaymentGateway::class,
            'razorpay'     => RazorpayPaymentGateway::class,
            'nowpayments'  => NowpaymentsPaymentGateway::class,
            'mtn'          => MTNPaymentGateway::class,
            'instamojo'    => InstamojoPaymentGateway::class,
            'coinpayments' => CoinpaymentsPaymentGateway::class,
            'coingate'     => CoingatePaymentGateway::class,
            'cashmaal'     => CashmaalPaymentGateway::class,
            'bitpayserver' => BitpayserverPaymentGateway::class,
            'blockio'      => BlockioPaymentGateway::class,
            'blockchain'   => BlockchainPaymentGateway::class,
            'airtel'       => AirtelPaymentGateway::class,
            'binance'      => BinancePaymentGateway::class,
            'bitnob'       => BitnobPaymentGateway::class,
            'bsicards'     => BsiCardsPaymentGateway::class,
        ];
    }

    /**
     * Refuse a gateway an administrator has disabled.
     *
     * A code with no row is left to getGateway() to reject, so an unknown
     * gateway and a disabled one stay distinguishable to the caller.
     *
     * @throws Exception
     */
    private function assertEnabled(string $gatewayCode): void
    {
        if (in_array($gatewayCode, self::UNMANAGED_CODES, true)) {
            return;
        }

        $exists = PaymentGatewayModel::query()->where('code', $gatewayCode)->exists();

        if (! $exists) {
            return;
        }

        $isActive = PaymentGatewayModel::query()->where('code', $gatewayCode)->active()->exists();

        if (! $isActive) {
            throw new Exception(sprintf('Payment gateway is disabled: %s', $gatewayCode));
        }
    }
}
