<?php

namespace App\Http\Controllers\Backend;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\Gender;
use App\Enums\KycStatus;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Enums\UserStatus;
use App\Exceptions\NotifyErrorException;
use App\Http\Middleware\CheckUserStatus;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Backend\User\ResetUserPasswordRequest;
use App\Http\Requests\Backend\User\SuspendUserRequest;
use App\Mail\GenericNotification;
use App\Models\KycSubmission;
use App\Models\Referral;
use App\Models\Ticket;
use App\Models\Transaction as TransactionModel;
use App\Models\User;
use App\Models\UserFeature;
use App\Notifications\TemplateNotification;
use App\Services\FeatureManager;
use App\Services\UserAccountSummaryService;
use App\Support\AccountPhone;
use App\Support\IdentityRules;
use Carbon\Carbon;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Throwable;
use Transaction;
use Wallet;

class UserManageController extends BaseController
{
    /** @var array<string, list<TrxType>> the Transactions tab's type segments */
    public const TRANSACTION_GROUPS = [
        'deposit'   => [TrxType::DEPOSIT, TrxType::ADD_BALANCE],
        'withdraw'  => [TrxType::WITHDRAW, TrxType::SUBTRACT_BALANCE],
        'transfers' => [TrxType::SEND_MONEY, TrxType::RECEIVE_MONEY, TrxType::REQUEST_MONEY, TrxType::PAYMENT, TrxType::RECEIVE_PAYMENT],
    ];

    public function __construct(
        private readonly UserAccountSummaryService $summary,
        private readonly FeatureManager $features,
    ) {}

    public static function permissions(): array
    {
        return [
            'manageUser|infoUpdate|passwordUpdate|notifyUser|handleTransactions|suspend|liftSuspension|resetPassword|signOutAll' => 'user-manage',
            'updateBalance'                                                                                                      => 'user-balance-manage',
            'loginAsUser'                                                                                                        => 'user-login-as',
            'handleActivities'                                                                                                   => 'user-activity-log',
            'updateFeatureStatus'                                                                                                => 'user-features-manage',
            'notifyUser'                                                                                                         => 'custom-notify-users',
            'handleTickets'                                                                                                      => 'support-list',
        ];
    }

    /**
     * Manage user profile, features, settings, permissions, transactions and more
     *
     * This method will show a page with all the user's information and features,
     * and allow the admin to update the user's profile, toggle features on/off,
     * update the user's settings, and assign permissions, etc.
     *
     * This is the primary page for managing a user's entire profile and settings.
     * It is a central hub for managing a user from the admin side.
     */
    public function manageUser(Request $request, string $username, ?string $param = 'statistics')
    {
        $user = User::with([
            'latestLoginActivity',
            'features',
            'kycSubmission.kycTemplate',
        ])->whereNull('system_account_type')->where('username', $username)->firstOrFail();

        $view = match ($param) {
            'transactions' => $this->handleTransactions($request, $user),
            'referrals'    => $this->handleReferrals($user),
            'tickets'      => $this->handleTickets($user),
            'activities'   => $this->handleActivities($request, $user),
            'info'         => view('backend.user.manage.info', compact('user')),
            'merchants'    => $user->isMerchant() ? view('backend.user.manage.merchant', compact('user')) : abort(404),
            'vaults'       => $this->vaultsVisible($user) ? $this->handleVaults($user) : abort(404),
            'statistics'   => $this->handleDashboard($request, $user),
            // An unknown tab is a missing page, not an UnhandledMatchError.
            default => abort(404),
        };

        if (! $view instanceof View) {
            return $view;
        }

        return $view->with($this->pageData($user, (string) $param));
    }

    /**
     * What every tab of the manage page draws around its own content: the
     * header strip, the tab counters, the three rail cards and the figures
     * the modals quote (session count, pending withdrawals, auto-lift times).
     *
     * @return array<string, mixed>
     */
    private function pageData(User $user, string $tab): array
    {
        $timezone = setting('site_timezone', config('app.timezone', 'UTC'));
        $now      = now($timezone);

        $liftTimes = [];
        foreach (SuspendUserRequest::DURATIONS as $key => $hours) {
            $liftTimes[$key] = $hours === null ? null : $now->copy()->addHours($hours)->format('M d, Y · H:i');
        }

        return [
            'activeTab'          => $tab,
            'headerStats'        => $this->summary->headerStats($user),
            'tabCounts'          => $this->summary->counts($user),
            'walletsRail'        => $this->summary->wallets($user),
            'vaultsVisible'      => $this->vaultsVisible($user),
            'sessionCount'       => $this->summary->sessionCount($user),
            'pendingWithdrawals' => $this->summary->pendingWithdrawals($user),
            'suspendReasons'     => SuspendUserRequest::reasonLabels(),
            'suspendDurations'   => SuspendUserRequest::durationLabels(),
            'suspendLiftTimes'   => $liftTimes,
        ];
    }

    /**
     * Suspend the account for a fixed window or until an admin lifts it.
     *
     * Sign-in is refused by {@see CheckUserStatus} and
     * {@see LoginRequest} for as long as the window
     * is open; every live session and API token ends now. Balances are not
     * touched and pending withdrawals stay pending for the admin to decide.
     */
    public function suspend(SuspendUserRequest $request, int $id): RedirectResponse
    {
        $user = User::whereNull('system_account_type')->findOrFail($id);

        /*
         * The published demo logins are shared property: every visitor to a
         * demo install has the credentials, so suspending one takes the
         * sandbox away from everyone who comes after. The demo write-block
         * already turns this route away, and this second refusal is what
         * makes the rule hold for a console command, a queued job or
         * anything else that reaches the model without passing the
         * middleware.
         */
        if ($user->isDemoProtected()) {
            notifyEvs('error', __('Demo accounts cannot be suspended. Block the address instead, from Security → IP deny list.'));

            return redirect()->back();
        }

        $hours = SuspendUserRequest::DURATIONS[$request->input('duration')];
        $until = $hours === null ? null : now()->addHours($hours);

        $user->forceFill([
            'suspended_at'      => now(),
            'suspended_until'   => $until,
            'suspension_reason' => $request->input('reason'),
            'suspension_note'   => $request->input('note') ?: null,
        ])->save();

        $revoked = $this->summary->revokeSessions($user);

        Log::info('User suspended by admin', [
            'user_id'          => $user->id,
            'admin_id'         => auth('admin')->id(),
            'reason'           => $request->input('reason'),
            'duration'         => $request->input('duration'),
            'suspended_until'  => $until?->toDateTimeString(),
            'sessions_revoked' => $revoked,
            'notified'         => $request->boolean('notify'),
        ]);

        if ($request->boolean('notify')) {
            $this->emailUser(
                $user,
                __('Your account has been suspended'),
                $until === null
                    ? __("Hi :name,\n\nYour account has been temporarily suspended and you cannot sign in or transact until it is lifted. If you believe this is a mistake, please contact support to appeal.", ['name' => $user->first_name ?: $user->username])
                    : __("Hi :name,\n\nYour account has been temporarily suspended until :date. You cannot sign in or transact until then. If you believe this is a mistake, please contact support to appeal.", ['name' => $user->first_name ?: $user->username, 'date' => $until->timezone(setting('site_timezone', config('app.timezone', 'UTC')))->format('M d, Y H:i')])
            );
        }

        notifyEvs('success', $until === null
            ? __('Account suspended until an admin lifts it.')
            : __('Account suspended for :duration.', ['duration' => SuspendUserRequest::durationLabels()[$request->input('duration')]]));

        return redirect()->back();
    }

    public function liftSuspension(int $id): RedirectResponse
    {
        $user = User::whereNull('system_account_type')->findOrFail($id);

        if (! $user->isSuspended() && $user->suspended_at === null) {
            notifyEvs('error', __('This account is not suspended.'));

            return redirect()->back();
        }

        Log::info('User suspension lifted by admin', [
            'user_id'  => $user->id,
            'admin_id' => auth('admin')->id(),
            'reason'   => $user->suspension_reason,
        ]);

        $user->liftSuspension();

        notifyEvs('success', __('Suspension lifted. The user can sign in again.'));

        return redirect()->back();
    }

    /**
     * Email a reset link through the users password broker, or set a
     * temporary password the user must replace at their next sign-in.
     */
    public function resetPassword(ResetUserPasswordRequest $request, int $id): RedirectResponse
    {
        $user = User::whereNull('system_account_type')->findOrFail($id);

        if ($request->input('method') === 'link') {
            ResetPassword::createUrlUsing(fn ($notifiable, string $token): string => route('user.password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]));

            try {
                $status = Password::broker('users')->sendResetLink(['email' => $user->email]);
            } catch (Throwable $e) {
                Log::error('Admin-triggered password reset email could not be sent', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);

                notifyEvs('error', __('The reset email could not be sent. Check Mail Settings and run the SMTP test.'));

                return redirect()->back();
            }

            if ($status !== Password::RESET_LINK_SENT) {
                notifyEvs('error', __($status));

                return redirect()->back();
            }

            Log::info('Password reset link sent by admin', ['user_id' => $user->id, 'admin_id' => auth('admin')->id()]);

            notifyEvs('success', __('Reset link sent to :email.', ['email' => $user->email]));

            return redirect()->back();
        }

        $user->forceFill([
            'password'             => Hash::make($request->input('password')),
            'must_change_password' => $request->boolean('force_change'),
        ])->save();

        $revoked = $request->boolean('sign_out_all') ? $this->summary->revokeSessions($user) : 0;

        Log::info('Temporary password set by admin', [
            'user_id'          => $user->id,
            'admin_id'         => auth('admin')->id(),
            'force_change'     => $request->boolean('force_change'),
            'sessions_revoked' => $revoked,
        ]);

        notifyEvs('success', $request->boolean('force_change')
            ? __('Temporary password set. The user must change it at their next sign-in.')
            : __('Password updated successfully'));

        return redirect()->back();
    }

    public function signOutAll(int $id): RedirectResponse
    {
        $user    = User::whereNull('system_account_type')->findOrFail($id);
        $revoked = $this->summary->revokeSessions($user);

        Log::info('User sessions revoked by admin', [
            'user_id'  => $user->id,
            'admin_id' => auth('admin')->id(),
            'revoked'  => $revoked,
        ]);

        notifyEvs('success', __('Signed the user out of every device and revoked :count session(s).', ['count' => $revoked]));

        return redirect()->back();
    }

    /**
     * One message to one address, sent in this request so the admin learns
     * what the mail server said rather than what the queue promised.
     */
    private function emailUser(User $user, string $subject, string $message): void
    {
        try {
            Mail::to($user->email)->send(new GenericNotification($subject, $message));
        } catch (Throwable $e) {
            Log::error('Admin account email could not be sent', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            notifyEvs('warning', __('The action was applied, but the email to the user could not be sent.'));
        }
    }

    public function infoUpdate(Request $request, $id)
    {
        $validated = $request->validate([
            'first_name'       => 'nullable|string|max:50',
            'last_name'        => 'nullable|string|max:50',
            'business_name'    => 'nullable|string|max:255',
            'business_address' => 'nullable|string|max:255',
            'phone'            => 'nullable|string|max:20|regex:/^[\d\-\+\(\) ]+$/',
            'gender'           => ['required', new Enum(Gender::class)],
            'birthday'         => 'nullable|date|before:today',
            // PH7: the registered country, which tax rules read local or
            // international from. Only an admin may correct it, and only to
            // a country of the list a customer may sign up with.
            'country'     => ['sometimes', ...IdentityRules::country()],
            'state'       => 'nullable',
            'city'        => 'nullable',
            'postal_code' => 'nullable',
            'address'     => 'nullable|string|max:255',
        ], [
            'phone.regex'     => __('Invalid phone number format.'),
            'birthday.before' => __('Birthday must be a past date.'),
            'country.size'    => __('Choose a country from the list.'),
        ]);

        $user = User::findOrFail($id);

        if (array_key_exists('country', $validated)) {
            $validated['country'] = strtoupper(trim((string) $validated['country']));
        }

        /*
         * A corrected country is stamped on the account - from what, by whom,
         * when - so the change can be read back later, and logged. Posting
         * the country the account already has is not a correction.
         */
        $correction = [];

        if (array_key_exists('country', $validated) && strtoupper((string) $user->country) !== $validated['country']) {
            $correction = [
                'country_corrected_from' => strtoupper(trim((string) $user->country)) ?: null,
                'country_corrected_at'   => now(),
                'country_corrected_by'   => auth('admin')->id(),
            ];

            Log::info('User country corrected by admin', [
                'user_id'  => $user->id,
                'admin_id' => auth('admin')->id(),
                'from'     => $user->country,
                'to'       => $validated['country'],
            ]);
        }

        // Stored as sign-up stores it, against the user's registered country,
        // so phone sign-in and recipient lookup still find the number.
        if (array_key_exists('phone', $validated)) {
            if (AccountPhone::isHeldByAnotherAccount($user, $validated['phone'])) {
                return back()
                    ->withErrors(['phone' => __('This phone number is already registered.')])
                    ->withInput();
            }

            $validated['phone'] = AccountPhone::forAccount($user, $validated['phone']);

            // A number nobody has proved is not verified, whoever typed it.
            if ((string) $user->phone !== (string) $validated['phone']) {
                $validated['phone_verified_at']          = null;
                $validated['phone_verification_enabled'] = false;
            }
        }

        $user->fill($validated)->forceFill($correction)->save();

        notifyEvs('success', $correction !== []
            ? __('User information updated. The country is now :country; tax rules read local or international from it.', ['country' => $validated['country']])
            : __('User information updated successfully'));

        return redirect()->back();
    }

    public function passwordUpdate($id, Request $request)
    {
        // Validate the request data
        $validated = $request->validate([
            'password' => 'required|confirmed',
        ]);

        // Fetch the user by ID
        $user = User::findOrFail($id);

        // Update the user's password
        $user->password = Hash::make($validated['password']);
        $user->save();

        notifyEvs('success', __('Password updated successfully'));

        return redirect()->back();
    }

    /**
     * Login as a user.
     *
     * This method will allow the admin to login as a user.
     * It will be used to troubleshoot issues, view the user's dashboard,
     * and perform actions on behalf of the user.
     */
    public function loginAsUser(Request $request, int $id): RedirectResponse
    {
        $user = User::findOrFail($id);

        if ($user->isSystemAccount()) {
            notifyEvs('error', __('Protected system accounts cannot be impersonated.'));

            return redirect()->back();
        }

        $guard = Auth::guard('web');

        $request->session()->forget([
            $guard->getName(),
            'password_hash_web',
        ]);

        Cookie::queue(Cookie::forget($guard->getRecallerName()));

        $request->session()->put($guard->getName(), $user->getAuthIdentifier());
        $request->session()->put('password_hash_web', $user->getAuthPassword());
        $request->session()->put('admin_impersonator_id', Auth::guard('admin')->id());

        $guard->setUser($user);
        event(new Login('web', $user, false));

        $request->session()->save();

        return redirect()->route('user.dashboard');
    }

    /**
     * Update the user balance.
     *
     * This method will update the user's wallet balance.
     * It will be used to add or subtract money from the user's wallet.
     *
     * @throws NotifyErrorException|Throwable
     */
    public function updateBalance(Request $request)
    {
        // Validate request data
        $validated = $request->validate([
            'user_id'          => 'required|exists:users,id',
            'wallet_id'        => 'required|exists:wallets,id',
            'transaction_type' => 'required|in:add,subtract',
            'amount'           => 'required|numeric|min:0.01',
            'note'             => 'nullable|string|max:255',
        ]);

        // Fetch user and wallet
        $user = User::findOrFail($validated['user_id']);

        if ($user->isSystemAccount()) {
            notifyEvs('error', __('Protected system account balances cannot be adjusted manually.'));

            return redirect()->back();
        }

        $wallet = $user->wallets()->where('id', $validated['wallet_id'])->first();

        if (! $wallet) {
            throw new NotifyErrorException(__('Wallet not found'));
        }

        $amount   = abs($validated['amount']);
        $isAdding = $validated['transaction_type'] === 'add';

        // Determine transaction description (Use note if provided)
        $description = $validated['note'] ?? ($isAdding
            ? __('Added balance From Admin')
            : __('Subtract balance From Admin'));

        // Start a database transaction
        DB::beginTransaction();

        try {

            // Handle balance operation
            if (! $isAdding && $wallet->balance < $amount) {
                throw new NotifyErrorException(__('Insufficient balance in the wallet.'));
            }

            // Define transaction data
            $trxData = new TransactionData(
                user_id: $user->id,
                trx_type: $isAdding ? TrxType::ADD_BALANCE : TrxType::SUBTRACT_BALANCE,
                amount: $amount,
                amount_flow: $isAdding ? AmountFlow::PLUS : AmountFlow::MINUS,
                currency: $wallet->currency->code,
                net_amount: $amount,
                payable_amount: $amount,
                payable_currency: $wallet->currency->code,
                wallet_reference: $wallet->uuid,
                description: $description,
                status: TrxStatus::COMPLETED,
                exchange_rate: Wallet::fxRate($wallet),
            );

            // Create transaction log
            $trx = Transaction::create($trxData);

            // Perform balance update
            $isAdding ? Wallet::addMoney($wallet, $amount) : Wallet::subtractMoney($wallet, $amount);

            if ($isAdding) {
                $user->notify(new TemplateNotification(
                    identifier: 'admin_balance_added',
                    data: [
                        'amount' => $amount.' '.$wallet->currency->code,
                        'admin'  => auth()->guard('admin')->user()->name,
                        'trx'    => $trx->trx_id,
                    ],
                    action: route('user.transaction.index')
                ));
            }

            if (! $isAdding) {
                $user->notify(new TemplateNotification(
                    identifier: 'admin_balance_subtracted',
                    data: [
                        'amount' => $amount.' '.$wallet->currency->code,
                        'admin'  => auth()->guard('admin')->user()->name,
                        'trx'    => $trx->trx_id,
                    ],
                    action: route('user.transaction.index')
                ));
            }

            // Commit transaction
            DB::commit();

            notifyEvs('success', __('User balance updated successfully'));

            return redirect()->back();

        } catch (\Exception $e) {
            DB::rollBack();
            throw new NotifyErrorException(__('An error occurred while updating balance.'));
        }
    }

    /**
     * Update the user feature status.
     * This method will update the status of a user feature.
     * It will be used to toggle features on/off for a user.
     */
    public function updateFeatureStatus(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'feature' => 'required|string|exists:user_features,feature',
            'status'  => 'required|boolean',
        ]);

        // Update the user feature status
        UserFeature::where('user_id', $request->user_id)
            ->where('feature', $request->feature)
            ->update(['status' => $request->status]);

        // Fetch the user
        $user = User::findOrFail($request->user_id);

        // Check if account status is toggled
        if ($request->feature === 'account_status') {
            $user->status = $request->status ? UserStatus::ACTIVE : UserStatus::INACTIVE;
        }

        // Check if email verification is toggled
        if ($request->feature === 'email_verification') {
            $user->email_verified_at = $request->status ? now() : null;
        }

        // Check if KYC verification is toggled
        if ($request->feature === 'kyc_verification') {
            $kycSubmission = KycSubmission::where('user_id', $request->user_id)->first();

            if (! $kycSubmission) {
                return response()->json([
                    'type'    => 'error',
                    'message' => __('KYC submission not found'),
                ]);
            }

            $kycSubmission->status = $request->status ? KycStatus::APPROVED : KycStatus::REJECTED;
            $kycSubmission->save();
        }

        // Save changes to the user table
        $user->save();

        return response()->json([
            'type' => 'success', 'message' => __('Feature status updated successfully'),
        ]);
    }

    public function getUserTransactionSummaryChart(Request $request, $userId)
    {

        $start = Carbon::parse($request->start_date)->startOfDay();
        $end   = Carbon::parse($request->end_date)->endOfDay();

        $dates = collect();
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dates->push($date->format('Y-m-d'));
        }

        $transactions = TransactionModel::selectRaw('DATE(created_at) as date, status, COUNT(*) as total')
            ->where('user_id', $userId)
            ->withoutSandbox()
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date', 'status')
            ->orderBy('date')
            ->get()
            ->groupBy('status');

        $statuses = [
            TrxStatus::COMPLETED->value => 'Completed',
            TrxStatus::PENDING->value   => 'Pending',
            TrxStatus::FAILED->value    => 'Failed',
        ];

        $series = [];

        foreach ($statuses as $key => $label) {
            $group  = $transactions[$key] ?? collect();
            $counts = $dates->map(fn ($d) => (int) optional($group->firstWhere('date', $d))->total ?? 0);

            $series[] = [
                'name' => $label,
                'data' => $counts,
            ];
        }

        return response()->json([
            'series'     => $series,
            'categories' => $dates,
        ]);
    }

    protected function handleTransactions(Request $request, User $user)
    {
        $group = (string) $request->input('type', 'all');
        $types = self::TRANSACTION_GROUPS[$group] ?? null;

        $transactions = Transaction::getTransactions(
            user_id: $user->id,
            trx_type: $types === null ? null : array_map(fn (TrxType $type): string => $type->value, $types),
            status: $request->status,
            search: $request->search,
            dateRange: $request->daterange
        );

        return view('backend.user.manage.transactions', [
            'user'         => $user,
            'transactions' => $transactions,
            'typeGroup'    => $types === null ? 'all' : $group,
        ]);
    }

    protected function handleReferrals(User $user)
    {
        $referrals = Referral::with(['referredUser.kycSubmission', 'childReferrals.referredUser.kycSubmission'])
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        return view('backend.user.manage.referrals', compact('user', 'referrals'));
    }

    /**
     * The customer's savings vaults, read-only. Vault money left the
     * wallets, so the wallets rail does not show it.
     */
    protected function handleVaults(User $user): View
    {
        return view('backend.user.manage.vaults', [
            'user'   => $user,
            'vaults' => $this->summary->vaultRows($user),
        ]);
    }

    /**
     * Whether the Vaults tab is shown: the module is on, or this customer
     * already has vaults - switching the module off must never hide money
     * they still hold from the admin.
     */
    private function vaultsVisible(User $user): bool
    {
        if (! Schema::hasTable('vaults')) {
            return false;
        }

        return $this->features->isEnabled('vaults') || $user->vaults()->exists();
    }

    protected function handleTickets(User $user)
    {
        $tickets = Ticket::where('user_id', $user->id)
            ->latest()
            ->paginate(10);

        return view('backend.user.manage.tickets', compact('user', 'tickets'));
    }

    protected function handleActivities(Request $request, User $user)
    {
        $activities = $user->loginActivities()
            ->filter($request)
            ->paginate(10);

        return view('backend.user.manage.activities_log', compact('user', 'activities'));
    }

    /**
     * The Overview tab: the transaction summary chart for the chosen range
     * and the five most recent transactions.
     */
    protected function handleDashboard(Request $request, User $user)
    {
        if ($request->ajax() && $request->has('start_date') && $request->has('end_date')) {
            return $this->getUserTransactionSummaryChart($request, $user->id);
        }

        $overview = $this->summary->overview($user, (string) $request->input('range', '7d'));

        /* The range segments repaint the KPI strip and chart from this. */
        if ($request->expectsJson()) {
            return response()->json($overview);
        }

        return view('backend.user.manage.statistics', [
            'user'         => $user,
            'overview'     => $overview,
            'recent'       => $this->summary->recentTransactions($user),
            'securityRail' => $this->summary->security($user),
        ]);
    }
}
