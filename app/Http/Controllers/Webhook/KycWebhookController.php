<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ApplyKycWebhookResult;
use App\Services\Kyc\KycProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound provider callbacks. Mirrors IPNController: resolve the driver from the
 * URL, let it verify its own signature and normalize the payload, then apply the
 * verdict on the queue so we acknowledge the provider quickly.
 */
class KycWebhookController extends Controller
{
    public function __construct(private readonly KycProviderRegistry $registry) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $driver = $this->registry->byCode($provider);
        $result = $driver->handleWebhook($request);

        if (filled($result->providerReference)) {
            ApplyKycWebhookResult::dispatch($result->providerReference, $result);
        }

        return response()->json(['status' => 'ok']);
    }
}
