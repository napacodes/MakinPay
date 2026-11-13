<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\GlobalSearchRequest;
use App\Support\AdminSearch\GlobalSearch;
use Illuminate\Http\JsonResponse;

/**
 * The command palette's endpoint.
 *
 * Not a BaseController: those gate whole actions on one permission, and this
 * action has none of its own. Every admin may open the palette; what each
 * scope shows them is decided per scope, inside GlobalSearch, from the same
 * permission list the sidebar reads.
 */
final class GlobalSearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request): JsonResponse
    {
        $permissions = session('admin_permissions');

        if (! is_array($permissions)) {
            $permissions = $request->user('admin')?->getAllPermissions()->pluck('name')->all() ?? [];
        }

        $scope = (string) ($request->validated('scope') ?? GlobalSearch::SCOPE_ALL);
        $limit = (int) ($request->validated('limit') ?? GlobalSearch::DEFAULT_LIMIT);

        return response()->json([
            'scope'  => $scope,
            'groups' => (new GlobalSearch($permissions))->run($scope, (string) $request->validated('q', ''), $limit),
        ]);
    }
}
