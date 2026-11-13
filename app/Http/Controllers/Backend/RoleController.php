<?php

namespace App\Http\Controllers\Backend;

use App\Http\Requests\Backend\Role\RenameRoleRequest;
use App\Http\Requests\Backend\Role\StoreRoleRequest;
use App\Http\Requests\Backend\Role\UpdateRoleRequest;
use App\Models\Admin;
use App\Support\PermissionScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends BaseController
{
    /** The one role the application cannot run without. */
    private const PROTECTED_ROLE = 'super-admin';

    /**
     * How the editor's rail groups the permission modules.
     *
     * Forty-odd modules in one alphabetical column is a list nobody can scan,
     * so the rail sorts them under four headings by what they are about.
     * Order within a section is the order here, which puts the everyday
     * modules first. A module not named below still shows — it lands under
     * "More" — so a new seeder category can never vanish from the editor.
     *
     * @var array<string, list<string>>
     */
    private const MODULE_SECTIONS = [
        'finance' => [
            'currency', 'deposit', 'withdraw', 'payment', 'gift-card', 'transaction', 'virtual-card',
            'subscription', 'wallet-earn', 'remittance', 'payment-link', 'mobile-recharge', 'bill-payment', 'mass-payment', 'marketplace', 'p2p',
        ],
        'people' => [
            'user', 'merchant', 'agent', 'kyc', 'support', 'staff', 'role', 'referral', 'ranking', 'subscriber',
        ],
        'platform' => [
            'dashboard', 'feature', 'app', 'background-tasks', 'observability', 'site-settings',
            'language', 'plugins', 'notification',
        ],
        'content' => [
            'blog', 'component', 'custom-landing', 'page', 'media', 'theme-manager', 'navigation', 'social', 'seo',
        ],
    ];

    public static function permissions(): array
    {
        return [
            'index|show|permissionEditor' => 'role-list',
            'create|store'                => 'role-create',
            'update|rename'               => 'role-edit',
            'destroy'                     => 'role-delete',
        ];
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->input('search'));
        $type   = in_array($request->input('type'), ['custom', 'protected'], true) ? (string) $request->input('type') : 'all';

        $roles = Role::query()
            ->with(['users', 'permissions:id'])
            ->withCount(['users', 'permissions'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($type === 'protected', fn ($query): mixed => $query->where('name', self::PROTECTED_ROLE))
            ->when($type === 'custom', fn ($query): mixed => $query->where('name', '!=', self::PROTECTED_ROLE))
            ->orderBy('id')
            ->get();

        return view('backend.role.index', [
            'roles'           => $roles,
            'summary'         => $this->summary(),
            'protectedRole'   => self::PROTECTED_ROLE,
            'currentRoleName' => auth('admin')->user()?->roles->first()?->name,
            'search'          => $search,
            'type'            => $type,
        ]);
    }

    /**
     * The editor for one role — its name, its description and its permissions.
     *
     * Every module is rendered server-side and the page hides all but the
     * active one, so switching modules cannot lose the admin's place. The
     * switches are the form's own checkboxes, so the page saves correctly
     * before any script has run; the script only keeps the tallies honest.
     */
    public function permissionEditor(int $id): View
    {
        $role = Role::query()->with('users')->withCount(['users', 'permissions'])->findOrFail($id);

        $granted    = $role->permissions()->pluck('permissions.name')->all();
        $categories = $this->permissionGroups($granted);

        return view('backend.role.permissions', [
            'role'        => $role,
            'categories'  => $categories,
            'sections'    => $this->moduleSections($categories),
            'granted'     => $granted,
            'isProtected' => $role->name === self::PROTECTED_ROLE,
            'summary'     => $this->summary(),
            'readOnly'    => PermissionScope::readOnly(
                Permission::query()->where('guard_name', 'admin')->pluck('name')
            ),
        ]);
    }

    /**
     * One role as JSON.
     *
     * The editor no longer switches roles in place, but the endpoint is part
     * of the admin API surface and stays for whatever still reads it.
     */
    public function show(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $role = Role::query()->with('users')->withCount(['users', 'permissions'])->findOrFail($id);

        if (! $request->expectsJson()) {
            /* A browser that followed this URL wanted the editor. */
            return redirect()->route('admin.role.permissions', $role->id);
        }

        return response()->json(['role' => $this->roleState($role)]);
    }

    /**
     * The new-role screen is the editor with nothing granted yet.
     *
     * Naming a role and choosing what it can do is one decision, so it is one
     * form — the same one that edits an existing role, rather than a second
     * layout to keep in step with it.
     */
    public function create(): View
    {
        $categories = $this->permissionGroups();

        return view('backend.role.create', [
            'role'        => null,
            'categories'  => $categories,
            'sections'    => $this->moduleSections($categories),
            'granted'     => [],
            'isProtected' => false,
            'summary'     => $this->summary(),
            'readOnly'    => PermissionScope::readOnly(
                Permission::query()->where('guard_name', 'admin')->pluck('name')
            ),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $role = Role::create([
            'name'        => $validated['role_name'],
            'description' => $validated['description'],
            'guard_name'  => 'admin',
        ]);

        $role->syncPermissions($this->resolvePermissionNames($request->selectedPermissionNames()));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        notifyEvs('success', __('Role created successfully'));

        return redirect()->route('admin.role.permissions', $role->id);
    }

    /**
     * Save a role: what it is called and what it can do.
     *
     * Names, not ids: the editor posts what the seeder calls each capability,
     * so a form held open across a reseed cannot silently grant whatever now
     * occupies those id numbers.
     */
    /**
     * A role's name and description, without touching its permissions.
     *
     * The manage screen shows the role's identity in its header rather than
     * as a pair of fields, so renaming is an action in the ⋯ menu. It has its
     * own request and its own action deliberately: `update()` syncs
     * permissions from whatever arrived, and a dialog that posts none would
     * strip the lot.
     */
    public function rename(RenameRoleRequest $request, int $id): RedirectResponse
    {
        $role = Role::findOrFail($id);

        /* The same guard update() carries — hiding a control is not a guard. */
        if ($role->name === self::PROTECTED_ROLE) {
            notifyEvs('error', __('The super-admin role cannot be edited.'));

            return redirect()->route('admin.role.index');
        }

        $validated = $request->validated();

        $role->update([
            'name'        => $validated['role_name'],
            'description' => $validated['description'],
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        notifyEvs('success', __('Role updated successfully'));

        return redirect()->route('admin.role.permissions', $role->id);
    }

    public function update(UpdateRoleRequest $request, int $id): RedirectResponse|JsonResponse
    {
        $role = Role::findOrFail($id);

        /*
         * The index has always hidden the editor's controls on this role, but
         * hiding a control is not a guard — the route accepted the id just the
         * same. Renaming super-admin or narrowing its permissions locks the
         * installation out of its own panel.
         */
        if ($role->name === self::PROTECTED_ROLE) {
            return $this->refuse($request, __('The super-admin role cannot be edited.'), 403);
        }

        $validated = $request->validated();

        $role->update([
            'name'        => $validated['role_name'],
            'description' => $validated['description'],
        ]);

        $names = $this->resolvePermissionNames($request->selectedPermissionNames());

        $role->syncPermissions($names);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Role updated successfully'),
                'granted' => $names,
                'counts'  => $this->grantedCounts($names),
                'role'    => $this->roleState($role->fresh()->loadCount(['users', 'permissions'])->load('users')),
            ]);
        }

        notifyEvs('success', __('Role updated successfully'));

        return redirect()->route('admin.role.permissions', $role->id);
    }

    /**
     * Keep only the names this installation actually has.
     *
     * Validation has already refused anything unknown; this narrows the set to
     * the admin guard so a name shared with a customer-side permission cannot
     * be granted here by spelling.
     *
     * @param  list<string> $names
     * @return list<string>
     */
    private function resolvePermissionNames(array $names): array
    {
        if ($names === []) {
            return [];
        }

        return Permission::query()
            ->where('guard_name', 'admin')
            ->whereIn('name', $names)
            ->pluck('name')
            ->all();
    }

    public function destroy(int $id): RedirectResponse
    {
        $role = Role::findOrFail($id);

        /*
         * "You cannot delete your own role" needs no guard of its own: holding
         * a role means being counted by the assigned-staff check below, which
         * already refuses. A separate check here could never fire, and a test
         * for it could never fail — `AdminRolePermissionMatrixTest` covers the
         * outcome through the path that actually enforces it.
         */
        if ($role->name === self::PROTECTED_ROLE) {
            notifyEvs('error', __('The super-admin role cannot be deleted'));
        } else {

            $assignedStaffCount = DB::table(config('permission.table_names.model_has_roles'))
                ->join('admins', config('permission.table_names.model_has_roles').'.model_id', '=', 'admins.id')
                ->where('role_id', $role->id)
                ->where('model_type', Admin::class)
                ->count();
            if ($assignedStaffCount > 0) {
                notifyEvs('error', 'This role is assigned to staff and cannot be deleted');

                return redirect()->route('admin.role.index');
            }

            DB::transaction(function () use ($role): void {
                DB::table(config('permission.table_names.role_has_permissions'))
                    ->where($this->rolePivotKey(), $role->id)
                    ->delete();

                DB::table(config('permission.table_names.model_has_roles'))
                    ->where($this->rolePivotKey(), $role->id)
                    ->where(function ($query): void {
                        $query->where('model_type', '!=', Admin::class)
                            ->orWhereNotExists(function ($subQuery): void {
                                $subQuery->selectRaw('1')
                                    ->from('admins')
                                    ->whereColumn('admins.id', config('permission.table_names.model_has_roles').'.model_id');
                            });
                    })
                    ->delete();

                $role->delete();
            });

            notifyEvs('success', 'Role deleted successfully');
        }

        return redirect()->route('admin.role.index');
    }

    private function groupedPermissions(): Collection
    {
        return Permission::query()
            ->where('guard_name', 'admin')
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->groupBy('category');
    }

    /**
     * The permission catalogue as the matrix draws it: one entry per module,
     * carrying the labels the seeder already wrote onto each row.
     *
     * @param  list<string>                          $granted
     * @return Collection<int, array<string, mixed>>
     */
    private function permissionGroups(array $granted = []): Collection
    {
        $held = array_flip($granted);

        return $this->groupedPermissions()
            ->map(function (Collection $permissions, string $category) use ($held): array {
                $first = $permissions->first();

                return [
                    'key'           => $category,
                    'label'         => $first?->category_display_name ?: title($category),
                    'icon'          => $this->groupIcon($first?->category_icon),
                    'summary'       => $first?->category_summary,
                    'description'   => $first?->category_description,
                    'permissions'   => $permissions,
                    'total'         => $permissions->count(),
                    'granted_count' => $permissions->filter(
                        static fn (mixed $permission): bool => isset($held[$permission->name])
                    )->count(),
                ];
            })
            ->values();
    }

    /**
     * The modules sorted under the rail's section headings.
     *
     * Each entry carries its label and the modules it holds, in the order
     * `MODULE_SECTIONS` names them. Sections with nothing to show are left
     * out, and modules no section names close the list under "More".
     *
     * @param  Collection<int, array<string, mixed>>                                                                 $categories
     * @return Collection<int, array{key: string, label: string, categories: Collection<int, array<string, mixed>>}>
     */
    private function moduleSections(Collection $categories): Collection
    {
        $labels = [
            'finance'  => __('Finance'),
            'people'   => __('People'),
            'platform' => __('Platform'),
            'content'  => __('Content'),
        ];

        $byKey = $categories->keyBy('key');
        $seen  = [];

        $sections = collect(self::MODULE_SECTIONS)
            ->map(function (array $modules, string $key) use ($byKey, $labels, &$seen): array {
                $members = collect($modules)
                    ->filter(static fn (string $module): bool => $byKey->has($module))
                    ->map(static fn (string $module): array => $byKey->get($module))
                    ->values();

                foreach ($members as $member) {
                    $seen[$member['key']] = true;
                }

                return ['key' => $key, 'label' => $labels[$key], 'categories' => $members];
            })
            ->values();

        $rest = $categories
            ->reject(static fn (array $category): bool => isset($seen[$category['key']]))
            ->values();

        if ($rest->isNotEmpty()) {
            $sections->push(['key' => 'more', 'label' => __('More'), 'categories' => $rest]);
        }

        return $sections->filter(static fn (array $section): bool => $section['categories']->isNotEmpty())->values();
    }

    /**
     * Per-module tallies for the JSON save response.
     *
     * @param  list<string>       $granted
     * @return array<string, int>
     */
    private function grantedCounts(array $granted): array
    {
        $held = array_flip($granted);

        return $this->groupedPermissions()
            ->map(static fn (Collection $permissions): int => $permissions->filter(
                static fn (mixed $permission): bool => isset($held[$permission->name])
            )->count())
            ->all();
    }

    /**
     * The seeder names an icon per module; four of them have never had an SVG
     * shipped (agent, observability, quick-style, feature-management), and
     * `x-icon` draws a question mark for a name it cannot find. Fall back to
     * the generic role glyph, and pick the real one up automatically the day
     * the file appears.
     */
    private function groupIcon(?string $icon): string
    {
        if ($icon === null || $icon === '') {
            return 'role';
        }

        return is_file(public_path('general/static/svg/'.$icon.'.svg')) ? $icon : 'role';
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        return [
            'totalRoles'    => Role::query()->count(),
            'assignedStaff' => DB::table(config('permission.table_names.model_has_roles'))
                ->where('model_type', Admin::class)
                ->distinct()
                ->count('model_id'),
            'totalPermissions' => Permission::query()->where('guard_name', 'admin')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function roleState(Role $role): array
    {
        return [
            'id'                => $role->id,
            'name'              => $role->name,
            'label'             => title($role->name),
            'description'       => $role->description,
            'protected'         => $role->name === self::PROTECTED_ROLE,
            'is_protected'      => $role->name === self::PROTECTED_ROLE,
            'granted'           => $role->permissions()->pluck('permissions.name')->all(),
            'permissions'       => $role->permissions()->pluck('permissions.id')->map(fn (mixed $id): int => (int) $id)->all(),
            'permissions_count' => (int) ($role->permissions_count ?? $role->permissions()->count()),
            'members_count'     => (int) ($role->users_count ?? 0),
            'members'           => $role->users->take(3)->map(fn (mixed $member): array => [
                'name'     => $member->name,
                'initials' => $this->initials($member->name),
                'avatar'   => $member->avatar ? asset($member->avatar_alt) : null,
            ])->values()->all(),
        ];
    }

    private function initials(?string $name): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim((string) $name)) ?: []));

        $letters = implode('', array_map(
            static fn (string $word): string => mb_substr($word, 0, 1),
            array_slice($words, 0, 2)
        ));

        return $letters === '' ? '?' : mb_strtoupper($letters);
    }

    private function refuse(Request $request, string $message, int $status = 422): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        notifyEvs('error', $message);

        return redirect()->route('admin.role.index');
    }

    private function rolePivotKey(): string
    {
        return config('permission.column_names.role_pivot_key') ?: 'role_id';
    }
}
