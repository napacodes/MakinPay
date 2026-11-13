<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Contracts\BillPayment\ReceivesBillPaymentWebhooks;
use App\Exceptions\InvalidBillPaymentWebhookException;
use App\Http\Controllers\Controller;
use App\Models\BillPaymentProvider;
use App\Services\BillPayment\BillPaymentNoticeIntake;
use App\Services\BillPayment\BillPaymentProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbound bill payment provider callbacks.
 *
 * A callback never moves money. It is authenticated by the provider's
 * driver, then BillPaymentNoticeIntake binds it to exactly one payment of
 * that same provider, deduplicates it by its event id and only triggers a
 * status check after the response has gone out. Only the provider's status
 * answer is ever applied, through BillPaymentStatusChecker and
 * BillPaymentService::applyResult().
 *
 * The provider is looked up regardless of its status, so payments already in
 * flight can still finish after an admin disables it. Every reply after a
 * successful authentication is a 200 with a status word, so the provider
 * stops retrying a notice there is nothing more to do with. Logs carry the
 * provider code, ids and the caller's IP only: never the body, the
 * signature header or a secret.
 */
class BillPaymentWebhookController extends Controller
{
    public const STATUS_UNKNOWN_REFERENCE = BillPaymentNoticeIntake::STATUS_UNKNOWN_REFERENCE;

    public const STATUS_MISMATCH = BillPaymentNoticeIntake::STATUS_MISMATCH;

    public function __construct(
        private readonly BillPaymentProviderManager $providers,
        private readonly BillPaymentNoticeIntake $intake,
    ) {}

    public function __invoke(Request $request, string $providerCode): JsonResponse
    {
        $provider = BillPaymentProvider::query()->where('code', $providerCode)->first();

        if ($provider === null) {
            abort(404);
        }

        $driver = $this->webhookDriver($provider);

        if ($driver === null) {
            abort(404);
        }

        try {
            $notice = $driver->webhookNotice($request);
        } catch (InvalidBillPaymentWebhookException) {
            Log::warning('Bill payment webhook rejected', [
                'provider' => $provider->code,
                'ip'       => $request->ip(),
            ]);

            return response()->json(['status' => 'rejected'], 401);
        }

        if ($notice === null) {
            return response()->json(['status' => 'ignored']);
        }

        return response()->json(['status' => $this->intake->accept($provider, $notice)]);
    }

    /** The provider's driver when it receives webhooks, otherwise null. */
    private function webhookDriver(BillPaymentProvider $provider): ?ReceivesBillPaymentWebhooks
    {
        try {
            $driver = $this->providers->driverFor($provider);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $driver instanceof ReceivesBillPaymentWebhooks ? $driver : null;
    }
}
