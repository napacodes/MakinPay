<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EnvironmentMode;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CancelMerchantSubscriptionRequest;
use App\Http\Requests\Api\RenewMerchantSubscriptionRequest;
use App\Http\Requests\Api\StoreMerchantSubscriptionRequest;
use App\Http\Resources\MerchantSubscriptionResource;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Services\MerchantSubscriptionService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class MerchantSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $subscriptions = MerchantSubscription::query()
            ->forMerchantEnvironment($this->merchant($request), $this->environment($request))
            ->with('latestInvoice')
            ->latest()
            ->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return MerchantSubscriptionResource::collection($subscriptions)->response();
    }

    public function store(StoreMerchantSubscriptionRequest $request, MerchantSubscriptionService $service): JsonResponse
    {
        try {
            $subscription = $service->create(
                merchant: $this->merchant($request),
                environment: $this->environment($request),
                data: $request->validated(),
            );

            return response()->json(['data' => new MerchantSubscriptionResource($subscription)], 201);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (NotifyErrorException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (QueryException $exception) {
            report($exception);

            return response()->json(['message' => __('A subscription with this external_reference already exists.')], 409);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => __('Unable to create the subscription right now.')], 503);
        }
    }

    public function show(Request $request, string $subscription): JsonResponse
    {
        $model = $this->findOwned($request, $subscription)
            ->load(['latestInvoice', 'invoices' => fn ($query) => $query->limit(20)]);

        return response()->json(['data' => new MerchantSubscriptionResource($model)]);
    }

    public function cancel(
        CancelMerchantSubscriptionRequest $request,
        string $subscription,
        MerchantSubscriptionService $service,
    ): JsonResponse {
        $model     = $this->findOwned($request, $subscription);
        $cancelled = $service->cancel($model, $request->boolean('at_period_end', true));

        return response()->json(['data' => new MerchantSubscriptionResource($cancelled)]);
    }

    public function renew(
        RenewMerchantSubscriptionRequest $request,
        string $subscription,
        MerchantSubscriptionService $service,
    ): JsonResponse {
        try {
            $model   = $this->findOwned($request, $subscription);
            $renewed = $service->renew($model, $request->boolean('force_new'));

            return response()->json(['data' => new MerchantSubscriptionResource($renewed)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (NotifyErrorException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => __('Unable to create a renewal checkout right now.')], 503);
        }
    }

    private function findOwned(Request $request, string $uuid): MerchantSubscription
    {
        return MerchantSubscription::query()
            ->forMerchantEnvironment($this->merchant($request), $this->environment($request))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function merchant(Request $request): Merchant
    {
        return $request->get('merchant');
    }

    private function environment(Request $request): EnvironmentMode
    {
        return EnvironmentMode::from((string) $request->get('environment'));
    }
}
