<?php

declare(strict_types=1);

namespace App\Services\Payment\Flutterwave;

use App\Models\BillPaymentProvider;
use App\Models\PaymentGateway;
use App\Services\BillPayment\BillPaymentNoticeIntake;
use App\Services\BillPayment\BillPaymentProviderManager;
use App\Services\BillPayment\Drivers\Flutterwave\FlutterwaveBillPaymentProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Hands a Flutterwave bill payment notice that reached the deposit webhook
 * over to the bill payment module.
 *
 * A Flutterwave account has one dashboard webhook URL, so an operator who
 * points it at /ipn/flutterwave receives bill events there too. The deposit
 * gateway authenticates the request with its own `verif-hash` check before
 * calling this; the notice is then bound only to payments of bill providers
 * on the Flutterwave driver, through the same BillPaymentNoticeIntake the
 * bill webhook endpoint uses. A provider holding a different webhook secret
 * belongs to another Flutterwave account and is skipped.
 *
 * Nothing here moves money or touches a deposit: the notice only triggers a
 * status check, and only the provider's status answer is applied. Polling
 * settles the payment just the same when this hand-off never runs.
 */
class FlutterwaveBillNoticeForwarder
{
    public const EVENT = FlutterwaveBillPaymentProvider::WEBHOOK_EVENT;

    /** Recorded on the notice event, to tell a hand-off from a direct webhook. */
    public const SOURCE = 'flutterwave_deposit_webhook';

    public const STATUS_IGNORED = 'ignored';

    public function __construct(
        private readonly BillPaymentProviderManager $providers,
        private readonly BillPaymentNoticeIntake $intake,
    ) {}

    /**
     * The deposit webhook address (/ipn/flutterwave) when this site takes
     * Flutterwave deposits: the deposit gateway is switched on and has its
     * secret key. Null otherwise. Read fresh, never from the gateway cache,
     * so the provider screen follows the gateway screen at once. Only the
     * URL leaves here, never a credential.
     */
    public static function depositWebhookUrl(): ?string
    {
        $gateway = PaymentGateway::query()->where('code', 'flutterwave')->where('status', true)->first();

        if ($gateway === null || trim((string) ($gateway->credentials['secret_key'] ?? '')) === '') {
            return null;
        }

        return route('ipn.handle', ['gateway' => 'flutterwave']);
    }

    /** Whether the request is a bill payment event this forwarder takes. */
    public static function handles(Request $request): bool
    {
        return $request->input('event') === self::EVENT;
    }

    /**
     * Forward an already authenticated bill notice and answer with the status
     * word to reply with: the intake's word for the first Flutterwave bill
     * provider that knows the payment, STATUS_UNKNOWN_REFERENCE when none
     * does, or STATUS_IGNORED when the body names no payment at all.
     */
    public function forward(Request $request): string
    {
        $notice = FlutterwaveBillPaymentProvider::noticeFrom($request);

        if ($notice === null) {
            return self::STATUS_IGNORED;
        }

        foreach ($this->flutterwaveProviders() as $provider) {
            $driver = $this->driverFor($provider);

            if ($driver === null || $driver->disagreesWithWebhookSignature($request)) {
                continue;
            }

            $status = $this->intake->accept($provider, $notice, self::SOURCE);

            if ($status !== BillPaymentNoticeIntake::STATUS_UNKNOWN_REFERENCE) {
                return $status;
            }
        }

        return BillPaymentNoticeIntake::STATUS_UNKNOWN_REFERENCE;
    }

    /**
     * Bill providers whose driver is Flutterwave Bills, whatever their status,
     * so payments in flight still finish after an admin disables one.
     *
     * @return Collection<int, BillPaymentProvider>
     */
    private function flutterwaveProviders(): Collection
    {
        $driverKeys = collect((array) config('bill_payments.drivers', []))
            ->filter(static fn (mixed $class): bool => is_string($class) && is_a($class, FlutterwaveBillPaymentProvider::class, true))
            ->keys()
            ->all();

        if ($driverKeys === []) {
            return collect();
        }

        return BillPaymentProvider::query()->whereIn('driver', $driverKeys)->orderBy('id')->get();
    }

    private function driverFor(BillPaymentProvider $provider): ?FlutterwaveBillPaymentProvider
    {
        try {
            $driver = $this->providers->driverFor($provider);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $driver instanceof FlutterwaveBillPaymentProvider ? $driver : null;
    }
}
