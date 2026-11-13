<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Api\ApiErrorCode;
use App\Enums\EnvironmentMode;
use App\Models\Merchant;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a Sanctum token stand in for the merchant API's key headers.
 *
 * The subscription controller reads its merchant and environment from request
 * attributes that MerchantApiAuth sets from X-Merchant-Key and X-API-Key.
 * Those keys belong on a server the merchant controls, not in an app - so this
 * puts the same two attributes there, resolved from the token's owner
 * instead, and the controller is reused exactly as it stands.
 *
 * The attributes are written with merge(), like MerchantApiAuth does, which
 * overwrites anything a client sent under the same names. A caller cannot
 * name its own merchant in the body and have the controller believe it.
 *
 * The environment is the merchant's own current_mode rather than a header.
 * Which environment a merchant is working in is a setting they already keep
 * in the panel, and letting a phone say otherwise would mean two places
 * disagreeing about it.
 */
class ResolveMerchantFromToken
{
    /**
     * Names a specific shop when the caller owns more than one.
     */
    public const HEADER = 'X-Merchant-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error(ApiErrorCode::Unauthenticated);
        }

        $merchants = Merchant::query()
            ->where('user_id', $user->id)
            ->with(['user', 'currency', 'supportedCurrencies'])
            ->get();

        if ($merchants->isEmpty()) {
            return ApiResponse::error(
                ApiErrorCode::ResourceNotFound,
                __('This account has no merchant profile.'),
            );
        }

        $named = trim((string) $request->header(self::HEADER, ''));

        $merchant = $named === ''
            // One shop is the common case, and making the app name it anyway
            // would be ceremony. More than one, and it has to choose.
            ? ($merchants->count() === 1 ? $merchants->first() : null)
            : $merchants->firstWhere('id', (int) $named);

        if ($merchant === null) {
            return ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                __('Name which merchant shop this request is for.'),
                [self::HEADER => [__('Name which merchant shop this request is for.')]],
            );
        }

        if ($refusal = $this->refuseUnusable($merchant)) {
            return $refusal;
        }

        $environment                   = $this->environmentFor($merchant);
        $merchant->current_environment = $environment;

        /*
         * Written to the ATTRIBUTE bag, not merged into the input.
         *
         * Request::get() reads attributes, then the query string, then the
         * body - and merge() writes to the body. So a merged value loses to a
         * query parameter of the same name, which would let a caller pick its
         * own environment by appending ?environment=sandbox. Attributes win
         * over both and cannot be forged from outside.
         *
         * merge() follows as well, so anything reading input() rather than
         * get() still sees the resolved values.
         */
        $resolved = [
            'merchant'    => $merchant,
            'environment' => $environment->value,
            'is_sandbox'  => $environment->isSandbox(),
        ];

        foreach ($resolved as $key => $value) {
            $request->attributes->set($key, $value);
        }

        $request->merge($resolved);

        return $next($request);
    }

    /**
     * The same three states MerchantApiAuth refuses, for the same reasons.
     */
    private function refuseUnusable(Merchant $merchant): ?Response
    {
        if ($merchant->isActionLocked()) {
            return ApiResponse::error(
                ApiErrorCode::Forbidden,
                __('This merchant is currently disabled or rejected.'),
            );
        }

        $environment = $this->environmentFor($merchant);

        if ($environment->isProduction() && ! $merchant->isApproved()) {
            return ApiResponse::error(
                ApiErrorCode::Forbidden,
                __('Production access is available after merchant approval.'),
            );
        }

        if ($environment->isSandbox() && ! $merchant->sandbox_enabled) {
            return ApiResponse::error(
                ApiErrorCode::Forbidden,
                __('Sandbox mode is disabled for this merchant.'),
            );
        }

        return null;
    }

    private function environmentFor(Merchant $merchant): EnvironmentMode
    {
        $mode = $merchant->current_mode;

        if ($mode instanceof EnvironmentMode) {
            return $mode;
        }

        return EnvironmentMode::tryFrom((string) $mode) ?? EnvironmentMode::PRODUCTION;
    }
}
