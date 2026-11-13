<?php

namespace App\Http\Controllers\Backend;

use App\Models\Admin;
use App\Support\AdminSessionRegistry;
use App\Traits\FileManageTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class StaffController extends BaseController
{
    use FileManageTrait;

    public static function permissions(): array
    {
        return [
            'index'                                         => 'staff-list',
            'create|store'                                  => 'staff-create',
            'update'                                        => 'staff-edit',
            'updateStatus|sendPasswordReset|revokeSessions' => 'staff-edit',
        ];
    }

    /**
     * Display a listing of staff.
     */
    public function index(Request $request): View
    {
        $staffs = $this->filtered($request)->paginate(10)->withQueryString();

        $sessionCounts = AdminSessionRegistry::countsByAdmin();

        $staffs->getCollection()->transform(
            fn (Admin $staff): Admin => $this->decorate($staff, $sessionCounts)
        );

        return view('backend.staff.index', [
            'staffs'     => $staffs,
            'roles'      => Role::where('name', '!=', 'super-admin')->pluck('name', 'name'),
            'allRoles'   => Role::orderBy('name')->pluck('name', 'name'),
            'roleCounts' => $this->roleCounts(),
            'summary'    => $this->summary(),
        ]);
    }

    /**
     * Store a new staff member.
     */
    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'avatar'   => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:admins,email',
            'password' => 'required|confirmed',
            'role'     => 'required|string',
            'status'   => 'boolean',
        ]);

        if ($request->hasFile('avatar')) {
            $input['avatar'] = self::uploadImage($input['avatar']);
        }

        $input['password'] = Hash::make($input['password']);
        $input['status']   = $request->boolean('status');

        $staff = Admin::create($input);
        $staff->assignRole($input['role']);

        notifyEvs('success', __('Staff created successfully'));

        return redirect()->route('admin.staff.index');
    }

    /**
     * Show the form for editing a staff member.
     */
    /**
     * Update a staff member.
     *
     * Manage is the only screen that reaches here, and it edits who somebody
     * is — name, email, role, whether the account is on. It never carried a
     * password or an avatar: a password is changed by the member from the
     * emailed reset link, never typed into a colleague's browser, and an
     * avatar is theirs to set from their own profile.
     */
    public function update(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $input = $request->validate([
            'name'   => 'required|string|max:255',
            'email'  => 'required|email|unique:admins,email,'.$id,
            'role'   => 'required|string',
            'status' => 'boolean',
        ]);

        $staff = Admin::findOrFail($id);

        if ($staff->id === 1 && $input['role'] !== 'super-admin') {
            return $this->refuse($request, __('You cannot change the super-admin role'));
        }

        $input['status'] = $request->boolean('status');

        $staff->update($input);

        DB::table('model_has_roles')->where('model_id', $id)->delete();
        $staff->assignRole($input['role']);

        if ($request->expectsJson()) {
            return response()->json(['message' => __('Staff updated successfully')]);
        }

        notifyEvs('success', __('Staff updated successfully'));

        return redirect()->route('admin.staff.index');
    }

    /**
     * Switch an account on or off.
     *
     * Two accounts can never be switched off: your own — locking yourself out
     * of the panel you are standing in — and the last active super admin,
     * which would leave the installation with nobody able to restore anything.
     */
    public function updateStatus(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $staff  = Admin::findOrFail($id);
        $active = $request->boolean('status');

        if (! $active && $staff->id === auth('admin')->id()) {
            return $this->refuse($request, __('You cannot deactivate your own account.'));
        }

        if (! $active && $this->isLastActiveSuperAdmin($staff)) {
            return $this->refuse($request, __('The last active super admin cannot be deactivated.'));
        }

        $staff->forceFill(['status' => $active])->save();

        if (! $active) {
            /* A blocked account holding a live session is still signed in. */
            AdminSessionRegistry::revoke($staff);
        }

        $message = $active ? __('Account activated.') : __('Account deactivated.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'status' => $active]);
        }

        notifyEvs('success', $message);

        return redirect()->route('admin.staff.index');
    }

    /**
     * Mail this member a password reset link.
     *
     * The link goes to the address on file rather than appearing here. An
     * admin screen must not become a way to set somebody else's password.
     */
    public function sendPasswordReset(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $staff = Admin::findOrFail($id);

        $status = Password::broker('admins')->sendResetLink(['email' => $staff->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            return $this->refuse($request, __('The password reset link could not be sent.'));
        }

        $message = __('Password reset link sent to :email.', ['email' => $staff->email]);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        notifyEvs('success', $message);

        return redirect()->route('admin.staff.index');
    }

    public function revokeSessions(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $staff = Admin::findOrFail($id);

        $revoked = AdminSessionRegistry::revoke($staff);

        $message = AdminSessionRegistry::driverIsDatabase()
            ? trans_choice('{0} No open sessions to revoke; the remember-me token was rotated.|{1} One session revoked.|[2,*] :count sessions revoked.', $revoked, ['count' => $revoked])
            : __('The remember-me token was rotated. Open sessions cannot be listed on this session driver.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'revoked' => $revoked]);
        }

        notifyEvs('success', $message);

        return redirect()->route('admin.staff.index');
    }

    /**
     * @return Builder<Admin>
     */
    private function filtered(Request $request): Builder
    {
        return Admin::query()
            ->with(['roles', 'latestLoginActivity'])
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = (string) $request->input('search');

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('role'), function (Builder $query) use ($request): void {
                $role = (string) $request->input('role');

                $query->whereHas('roles', fn (Builder $roleQuery): Builder => $roleQuery->where('name', $role));
            })
            ->when($request->input('status') === 'active', fn (Builder $query): Builder => $query->where('status', true))
            ->when($request->input('status') === 'inactive', fn (Builder $query): Builder => $query->where('status', false))
            ->latest();
    }

    /**
     * Everything a row shows that is not a column of its own.
     *
     * @param array<int, int> $sessionCounts
     */
    private function decorate(Admin $staff, array $sessionCounts): Admin
    {
        $lastLogin = $staff->latestLoginActivity;

        $staff->setAttribute('isYou', $staff->id === auth('admin')->id());
        $staff->setAttribute('roleName', $staff->roles->first()?->name);
        $staff->setAttribute('lastActiveAt', $lastLogin?->login_at);
        $staff->setAttribute('lastIp', $lastLogin?->ip_address);
        $staff->setAttribute('isOnline', $staff->isOnline());
        $staff->setAttribute('sessionsCount', $sessionCounts[$staff->id] ?? 0);

        return $staff;
    }

    /**
     * Members per role, counting administrators only.
     *
     * @return array<string, int>
     */
    private function roleCounts(): array
    {
        $pivot = config('permission.table_names.model_has_roles');
        $roles = config('permission.table_names.roles');

        return DB::table($pivot)
            ->join($roles, $pivot.'.role_id', '=', $roles.'.id')
            ->join('admins', $pivot.'.model_id', '=', 'admins.id')
            ->where($pivot.'.model_type', Admin::class)
            ->groupBy($roles.'.name')
            ->selectRaw($roles.'.name as role_name, COUNT(*) as member_count')
            ->pluck('member_count', 'role_name')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        $total     = Admin::query()->count();
        $active    = Admin::query()->where('status', true)->count();
        $twoFactor = Admin::query()->where('two_factor_enabled', true)->count();

        return [
            'total'            => $total,
            'active'           => $active,
            'inactive'         => $total - $active,
            'rolesInUse'       => count($this->roleCounts()),
            'rolesTotal'       => Role::query()->count(),
            'twoFactor'        => $twoFactor,
            'twoFactorMissing' => $total - $twoFactor,
            'twoFactorPercent' => $total > 0 ? (int) round(($twoFactor / $total) * 100) : 0,
            'joinedThisMonth'  => Admin::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            'onlineNow'        => Admin::query()
                ->whereHas('loginActivities', fn (Builder $query): Builder => $query->where('login_at', '>=', now()->subMinutes(5)))
                ->count(),
        ];
    }

    private function isLastActiveSuperAdmin(Admin $staff): bool
    {
        if (! $staff->hasRole('super-admin')) {
            return false;
        }

        return Admin::query()
            ->where('status', true)
            ->whereKeyNot($staff->id)
            ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', 'super-admin'))
            ->doesntExist();
    }

    private function refuse(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        notifyEvs('error', $message);

        return redirect()->route('admin.staff.index');
    }
}
