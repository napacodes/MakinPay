<?php

namespace App\Http\Controllers\Backend;

use App\Enums\AgentStatus;
use App\Enums\KycStatus;
use App\Enums\TrxStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\TransactionUpdated;
use App\Http\Requests\Backend\User\ConvertUserToMerchantRequest;
use App\Http\Requests\Backend\User\DeleteUserRequest;
use App\Mail\GenericNotification;
use App\Models\Agent;
use App\Models\Currency;
use App\Models\KycSubmission;
use App\Models\LoginActivity;
use App\Models\Merchant;
use App\Models\User;
use App\Models\UserFeature;
use App\Services\AgentService;
use App\Services\Auth\AccountDeletionGuard;
use App\Support\IdentityRules;
use App\Traits\FileManageTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class UserController extends BaseController
{
    use FileManageTrait;

    public static function permissions(): array
    {
        return [
            'index|activeUser|suspendedUser|unverifiedUser' => 'user-list',
            'store'                                         => 'user-create',
            'destroy'                                       => 'user-delete',
            'transactionStats'                              => 'user-list',
            'convertToMerchant'                             => 'user-manage',
            'convertToAgent'                                => 'user-manage',
        ];
    }

    /**
     * Display a listing of the users with filters.
     */
    public function index(Request $request)
    {

        $title   = __('User Management');
        $users   = User::query()->filter($request)->latest()->paginate(10)->withQueryString();
        $summary = $this->headerSummary();

        return view('backend.user.index', compact('users', 'title', 'summary'));
    }

    /**
     * The four figures the page header's strip shows.
     *
     * Install-wide, not page-wide. The strip used to count the ten rows the
     * paginator happened to hand back, so "Active on page" meant something
     * different on every page and the numbers moved when you turned one.
     *
     * Two of the four have a history behind them and carry a sparkline;
     * the other two are counts and carry a tonal tile instead, which is the
     * handoff's rule — a static figure never gets a drawn trend.
     *
     * @return array<string, mixed>
     */
    private function headerSummary(): array
    {
        $since = now()->subDays(6)->startOfDay();

        /** @var array<string, int> Sign-ups per day, keyed Y-m-d. */
        $signupsByDay = User::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->all();

        /** @var array<string, int> Distinct people who signed in that day. */
        $activeByDay = LoginActivity::query()
            ->succeeded()
            ->whereNotNull('user_id')
            ->where('login_at', '>=', $since)
            ->selectRaw('DATE(login_at) as day, COUNT(DISTINCT user_id) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->all();

        $days  = collect(range(6, 0))->map(static fn (int $back): string => now()->subDays($back)->toDateString());
        $total = User::query()->count();

        /*
         * The headcount's shape is where it has been, not how many arrived:
         * each bar is the total at the end of that day, walked backwards
         * from today by subtracting the day's sign-ups. A quiet week reads
         * as a flat line rather than as seven bars of near-zero arrivals.
         */
        $running    = $total;
        $totalSpark = [];

        foreach ($days->reverse() as $day) {
            $totalSpark[] = $running;
            $running -= (int) ($signupsByDay[$day] ?? 0);
        }

        return [
            'total'           => $total,
            'newThisWeek'     => array_sum($signupsByDay),
            'totalSpark'      => array_reverse($totalSpark),
            'activeToday'     => (int) ($activeByDay[now()->toDateString()] ?? 0),
            'activeYesterday' => (int) ($activeByDay[now()->subDay()->toDateString()] ?? 0),
            'activeSpark'     => $days->map(static fn (string $day): int => (int) ($activeByDay[$day] ?? 0))->all(),
            'kycPending'      => KycSubmission::query()->where('status', KycStatus::PENDING)->count(),
            'suspended'       => User::query()->where('status', UserStatus::INACTIVE)->count(),
        ];
    }

    /**
     * Show active users.
     */
    public function activeUser(Request $request)
    {
        $title   = __('Active Users');
        $users   = User::query()->filter($request)->where('status', UserStatus::ACTIVE)->latest()->paginate(10)->withQueryString();
        $summary = $this->headerSummary();

        $statusFilter = false;

        return view('backend.user.index', compact('users', 'title', 'statusFilter', 'summary'));
    }

    public function suspendedUser(Request $request)
    {
        $title = __('Suspended Users');

        $users   = User::query()->filter($request)->where('status', UserStatus::INACTIVE)->latest()->paginate(10)->withQueryString();
        $summary = $this->headerSummary();

        return view('backend.user.index', compact('users', 'title', 'summary'));
    }

    public function unverifiedUser(Request $request)
    {
        $title   = __('Unverified Users');
        $users   = User::query()->whereNull('email_verified_at')->filter($request)->latest()->paginate(10)->withQueryString();
        $summary = $this->headerSummary();

        return view('backend.user.index', compact('users', 'title', 'summary'));
    }

    public function kycUnverifiedUser(Request $request)
    {
        $title   = __('KYC Unverified Users');
        $users   = User::query()->kycUnverified()->filter($request)->latest()->paginate(10)->withQueryString();
        $summary = $this->headerSummary();

        return view('backend.user.index', compact('users', 'title', 'summary'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Step 1: Validate incoming request
        $validated = $request->validate([
            'avatar'     => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'first_name' => IdentityRules::name(),
            'last_name'  => IdentityRules::name(),
            'username'   => IdentityRules::username('users'),
            'country'    => 'required|string|size:2',
            'phone'      => 'required|string',
            'email'      => IdentityRules::email('users'),
            'password'   => 'required|same:confirm_password',
            'status'     => 'nullable|boolean',
            'role'       => 'required|string',
        ]);

        // Step 2: Process phone number
        $countryCode = strtoupper((string) $validated['country']);
        $countryData = getCountryByCode($countryCode);
        if (! $countryData) {
            return back()
                ->withErrors(['country' => __('The selected country is invalid.')])
                ->withInput();
        }
        $formattedPhone = formatPhone((string) ($countryData['dial_code'] ?? ''), $validated['phone']);

        // Step 3: Handle avatar upload if provided
        $avatarPath = $request->hasFile('avatar')
            ? $this->uploadImage($request->file('avatar'))
            : null;

        // Step 4: Create user with transformed data
        $user = User::create([
            'avatar'     => $avatarPath,
            'first_name' => $validated['first_name'],
            'last_name'  => $validated['last_name'],
            'username'   => $validated['username'],
            'country'    => $countryCode,
            'phone'      => $formattedPhone,
            'email'      => $validated['email'],
            'password'   => Hash::make($validated['password']),
            'role'       => $validated['role'],
            'status'     => $request->boolean('status') ? UserStatus::ACTIVE : UserStatus::INACTIVE,
        ]);

        // Step 5: Sync features and fire event
        UserFeature::syncWithConfigForUser($user->id);
        event(new TransactionUpdated($user));

        // Step 6: Notify and redirect
        notifyEvs('success', __('User created successfully'));

        return redirect()->route('admin.user.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DeleteUserRequest $request, AccountDeletionGuard $guard, string $id)
    {
        $filesToDelete = [];
        $userSnapshot  = null;
        $subject       = User::findOrFail($id);

        // Money a module still holds outside the wallets (bill payments,
        // recharges, mass payments) must be resolved first; the forfeit
        // acknowledgement does not cover it.
        if (($refusal = $guard->refusal($subject)) !== null) {
            notifyEvs('error', $refusal);

            return redirect()->back();
        }

        /*
         * Money left in a wallet or a savings vault is destroyed with the
         * row, so the admin has to say so explicitly. The check is
         * server-side because the modal's switch is only a reminder; a stale
         * page or a scripted request must not delete funds by omission.
         */
        if ($guard->holdsBalance($subject) && ! $request->boolean('forfeit')) {
            notifyEvs('error', __('This account still holds a balance. Zero the wallets first, or confirm the funds are forfeited.'));

            return redirect()->back();
        }

        try {
            DB::transaction(function () use ($id, $guard, &$filesToDelete, &$userSnapshot) {
                $user         = User::findOrFail($id);
                $userSnapshot = [
                    'name'      => $user->name,
                    'username'  => $user->username,
                    'email'     => $user->email,
                    'user_type' => $user->user_type ?? 'user',
                ];

                // Collect uploaded-file paths from related rows BEFORE we delete
                // anything. DB-level cascades don't fire Eloquent events, so
                // file paths inside cascaded rows would otherwise be lost
                // and the files would orphan on disk.
                $filesToDelete = $this->collectUserFilePaths($user);

                // ── Manual cleanup for tables WITHOUT cascade FKs ──────────

                // Polymorphic: Laravel notifications
                DB::table('notifications')
                    ->where('notifiable_type', User::class)
                    ->where('notifiable_id', $id)
                    ->delete();

                // Polymorphic: per-user notification channel preferences
                DB::table('notification_preferences')
                    ->where('notifiable_type', User::class)
                    ->where('notifiable_id', $id)
                    ->delete();

                // Polymorphic: Sanctum API tokens — leaving these behind is a
                // security hole, since a leaked token would still authenticate
                // after the account is gone.
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->where('tokenable_id', $id)
                    ->delete();

                // Auth sessions for the deleted user
                DB::table('sessions')->where('user_id', $id)->delete();

                // login_activities (nullable user_id, no FK constraint)
                DB::table('login_activities')->where('user_id', $id)->delete();

                // Support tickets + messages (FK is NO ACTION, not cascade)
                $ticketIds = DB::table('tickets')->where('user_id', $id)->pluck('id');
                if ($ticketIds->isNotEmpty()) {
                    DB::table('messages')->whereIn('ticket_id', $ticketIds)->delete();
                    DB::table('tickets')->where('user_id', $id)->delete();
                }

                // Module rows whose wallet_id is RESTRICT and that hold no
                // money (bill payments, mass payments, mobile recharges).
                $guard->purgeResolved((int) $id);

                // Wallets (FK is NO ACTION, not cascade)
                DB::table('wallets')->where('user_id', $id)->delete();

                // Trigger DB cascades for all other related tables.
                $user->delete();
            });

            // ── Delete files AFTER the transaction commits ─────────────────
            // Doing this outside the transaction means a storage failure
            // can't roll back a successful row deletion (the files are
            // already orphaned anyway at that point).
            $fileResult = $this->deleteCollectedFiles($filesToDelete);

            Log::info('User deleted successfully', [
                'deleted_user_id'  => $id,
                'deleted_by_admin' => auth()->id(),
                'deleted_at'       => now(),
                'user_data'        => $userSnapshot,
                'files_attempted'  => $fileResult['attempted'],
                'files_deleted'    => $fileResult['deleted'],
            ]);

            notifyEvs('success', __('User deleted successfully'));

            return redirect()->route('admin.user.index');
        } catch (\Exception $e) {
            Log::error('Failed to delete user', [
                'user_id'  => $id,
                'admin_id' => auth()->id(),
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            notifyEvs('error', __('Failed to delete user. Please try again.'));

            return redirect()->back();
        }
    }

    /**
     * Collect every storage-path string attached to the user across the
     * tables that cascade-delete with the user row.
     *
     * @return list<string>
     */
    private function collectUserFilePaths(User $user): array
    {
        $paths = [];

        // 1. Avatar on the user row itself
        if (! empty($user->avatar)) {
            $paths[] = $user->avatar;
        }

        // 2. KYC submissions — submission_data is a JSON map of label → value
        DB::table('kyc_submissions')
            ->where('user_id', $user->id)
            ->pluck('submission_data')
            ->each(function ($json) use (&$paths) {
                $paths = array_merge($paths, $this->extractStoragePaths($json));
            });

        // 3. Agent logo
        DB::table('agents')
            ->where('user_id', $user->id)
            ->pluck('logo')
            ->filter()
            ->each(function ($logo) use (&$paths) {
                $paths[] = $logo;
            });

        // 4. Merchant business logo
        DB::table('merchants')
            ->where('user_id', $user->id)
            ->pluck('business_logo')
            ->filter()
            ->each(function ($logo) use (&$paths) {
                $paths[] = $logo;
            });

        // 5. Cardholder KYC documents (JSON map)
        DB::table('cardholders')
            ->where('user_id', $user->id)
            ->pluck('kyc_documents')
            ->each(function ($json) use (&$paths) {
                $paths = array_merge($paths, $this->extractStoragePaths($json));
            });

        // 6. Business documents (JSON map)
        DB::table('businesses')
            ->where('user_id', $user->id)
            ->pluck('documents')
            ->each(function ($json) use (&$paths) {
                $paths = array_merge($paths, $this->extractStoragePaths($json));
            });

        // 7. Ticket + message attachments
        $ticketIds = DB::table('tickets')->where('user_id', $user->id)->pluck('id');
        if ($ticketIds->isNotEmpty()) {
            DB::table('tickets')
                ->whereIn('id', $ticketIds)
                ->pluck('attachment')
                ->filter()
                ->each(function ($path) use (&$paths) {
                    $paths[] = $path;
                });

            DB::table('messages')
                ->whereIn('ticket_id', $ticketIds)
                ->pluck('attachment')
                ->filter()
                ->each(function ($path) use (&$paths) {
                    $paths[] = $path;
                });
        }

        // De-dupe + drop blanks.
        return array_values(array_filter(array_unique($paths), fn (string $p): bool => $this->isUserUploadPath($p)));
    }

    /**
     * Run the FileManageTrait deleter over each path and return counts.
     *
     * @param  list<string>                     $paths
     * @return array{attempted:int,deleted:int}
     */
    private function deleteCollectedFiles(array $paths): array
    {
        $attempted = count($paths);
        $deleted   = 0;

        foreach ($paths as $path) {
            try {
                $this->delete($path);
                $deleted++;
            } catch (\Throwable $e) {
                Log::warning('Failed to delete user file', [
                    'path'  => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['attempted' => $attempted, 'deleted' => $deleted];
    }

    /**
     * Pull every string value that looks like an upload path out of a JSON
     * blob (decoded recursively).
     *
     * @return list<string>
     */
    private function extractStoragePaths(mixed $json): array
    {
        if (blank($json)) {
            return [];
        }

        $decoded = is_array($json) ? $json : json_decode((string) $json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $paths = [];
        array_walk_recursive($decoded, function ($value) use (&$paths) {
            if (is_string($value) && $this->isUserUploadPath($value)) {
                $paths[] = $value;
            }
        });

        return $paths;
    }

    /**
     * Only delete files that match the FileManageTrait upload convention
     * (`images/YYYY/MM/DD/...` or `files/YYYY/MM/DD/...`). Demo / seeded
     * assets under `general/`, `demo/`, etc. are shared across users and
     * must be left alone.
     */
    private function isUserUploadPath(string $path): bool
    {
        if (blank($path)) {
            return false;
        }

        $normalised = ltrim($path, '/');
        if (str_starts_with($normalised, 'storage/')) {
            $normalised = substr($normalised, 8);
        }

        return str_starts_with($normalised, 'images/')
            || str_starts_with($normalised, 'files/');
    }

    /**
     * Get transaction statistics for a user (used in delete confirmation modal)
     */
    public function transactionStats(string $id)
    {
        try {
            $user = User::findOrFail($id);

            // Get all transactions for this user
            $allTransactions = $user->transactions();

            // Get successful transactions only
            $successfulTransactions = $user->transactions()->where('status', TrxStatus::COMPLETED);

            // Calculate statistics
            $totalTransactions = $allTransactions->count();
            $totalAmount       = $allTransactions->sum('amount');
            $successfulCount   = $successfulTransactions->count();
            $successfulAmount  = $successfulTransactions->sum('amount');

            // Get default currency for formatting
            $currencySymbol = siteCurrency('symbol');

            $decimals = (int) (setting('site_decimal', 2));

            return response()->json([
                'success' => true,
                'data'    => [
                    'total_transactions'      => number_format($totalTransactions),
                    'total_amount'            => $currencySymbol.number_format($totalAmount, $decimals),
                    'successful_transactions' => number_format($successfulCount),
                    'successful_amount'       => $currencySymbol.number_format($successfulAmount, $decimals),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => __('Failed to fetch transaction statistics'),
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Convert a regular user to merchant.
     */
    public function convertToMerchant(ConvertUserToMerchantRequest $request, $id)
    {
        try {
            DB::beginTransaction();

            $user = User::findOrFail($id);

            // Check if user is already a merchant
            if ($user->isMerchant()) {
                notifyEvs('error', __('User is already a merchant'));

                return redirect()->back();
            }

            // Check if user already has a merchant record
            if (Merchant::where('user_id', $user->id)->exists()) {
                notifyEvs('error', __('User already has a merchant account'));

                return redirect()->back();
            }

            // Update user role to merchant, under the name the shop will trade as
            $user->update([
                'role'          => UserRole::MERCHANT,
                'business_name' => $request->input('business_name'),
            ]);

            DB::commit();

            // Log the conversion
            Log::info('User converted to merchant', [
                'user_id'            => $user->id,
                'business_name'      => $request->input('business_name'),
                'converted_by_admin' => auth()->id(),
                'converted_at'       => now(),
                'notified'           => $request->boolean('notify'),
            ]);

            if ($request->boolean('notify')) {
                try {
                    Mail::to($user->email)->send(new GenericNotification(
                        __('Your account is now a merchant account'),
                        __("Hi :name,\n\nYour account has been switched to a merchant account under the business name :business. Sign in to open the merchant dashboard, create your shop and start accepting payments.", [
                            'name'     => $user->first_name ?: $user->username,
                            'business' => $request->input('business_name'),
                        ])
                    ));
                } catch (\Throwable $e) {
                    Log::error('Merchant conversion email could not be sent', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                    notifyEvs('warning', __('The account was converted, but the email to the user could not be sent.'));
                }
            }

            notifyEvs('success', __('User successfully converted to merchant'));

            return redirect()->back();

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to convert user to merchant', [
                'user_id'  => $id,
                'admin_id' => auth()->id(),
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            notifyEvs('error', __('Failed to convert user to merchant. Please try again.'));

            return redirect()->back();
        }
    }

    /**
     * Convert a regular user to agent.
     */
    public function convertToAgent($id)
    {
        try {
            DB::beginTransaction();

            $user = User::findOrFail($id);

            if ($user->isAgent()) {
                notifyEvs('error', __('User is already an agent'));

                return redirect()->back();
            }

            if (Agent::where('user_id', $user->id)->exists()) {
                notifyEvs('error', __('User already has an agent account'));

                return redirect()->back();
            }

            $user->update(['role' => UserRole::AGENT]);
            app(AgentService::class)->createDefaultForUser($user, AgentStatus::APPROVED->value);

            DB::commit();

            Log::info('User converted to agent', [
                'user_id'            => $user->id,
                'converted_by_admin' => auth()->id(),
                'converted_at'       => now(),
            ]);

            notifyEvs('success', __('User successfully converted to agent'));

            return redirect()->back();

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to convert user to agent', [
                'user_id'  => $id,
                'admin_id' => auth()->id(),
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            notifyEvs('error', __('Failed to convert user to agent. Please try again.'));

            return redirect()->back();
        }
    }
}
