<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Demo-mode admin guard.
 *
 * When APP_DEMO=true the admin panel is the public sandbox — any
 * visitor with the published admin credentials can log in. We must
 * keep the panel browsable for buyers/evaluators, but stop a
 * curious visitor from breaking the install for the next person.
 *
 * Guard rails:
 *
 *   1. Login-as-User (GET admin/user/login/{id}) is REFUSED — the
 *      evaluator should sign in directly with the published user
 *      credentials instead of hijacking a session from the admin
 *      panel. A notifyEvs() toast tells them what just happened.
 *
 *   2. Every write method (POST/PUT/PATCH/DELETE) is refused so
 *      settings, users, plans, currencies, etc. cannot be mutated
 *      by an anonymous evaluator. The per-controller demo guards
 *      (isDemoProtectedAccount() on credentials, etc.) cover the
 *      few write paths that need finer rules.
 *
 *   3. The recovery-backup download is refused even though the
 *      rest of the Project Updater is exempt — see
 *      BLOCKED_UPDATER_WRITES. Every other guard rail here stops a
 *      visitor changing the install; that one stops them taking it
 *      home.
 *
 *   4. Exception to (2): the work an evaluator is here to try — the
 *      decisions behind the dashboard's "Needs your action" queues
 *      (REVIEW_QUEUE_DECISIONS), clearing an issue on the System
 *      Health screen (ISSUE_TRIAGE), and the Background Tasks screen
 *      (BACKGROUND_TASK_ACTIONS). Each is a list of
 *      route names, so the exception can never widen to a whole
 *      module by accident; allowedWrites() is the union the guard
 *      checks.
 */
class DemoMode
{
    /**
     * The review decisions an evaluator may actually take in demo mode.
     *
     * One route per queue the dashboard's "Needs your action" panel
     * lists (DashboardQueueService::KEYS), plus the two ways a P2P
     * dispute ends, the two ways a bill payment ends (paid, or failed
     * and refunded), the three ways a held mass payment is settled
     * (resume, cancel the remaining rows, close after review) and the
     * reply that closes a ticket. Claiming a bill is not a decision, so
     * it stays blocked. The customer's own mass payment routes are not
     * here because this guard never sees them: it sits on the admin
     * route group only. Approving a
     * deposit, verifying a KYC document or resolving a dispute is the
     * thing a buyer opens the demo to see; a panel whose every Review
     * button dead-ends in "This action is disabled in demo mode"
     * demonstrates nothing.
     *
     * Deliberately the decision endpoints only, never the modules
     * around them — an evaluator can approve a manual deposit but
     * still cannot edit the deposit method, change a currency or
     * touch settings, so the install stays usable for the next
     * visitor. Pending rows regenerate; configuration does not.
     *
     * @var list<string>
     */
    public const REVIEW_QUEUE_DECISIONS = [
        'admin.deposit.request-action',
        'admin.withdraw.request-action',
        'admin.kyc.request-action',
        'admin.merchant.request-action',
        'admin.support-ticket.status-update',
        'admin.support-ticket.reply',
        'admin.virtual-card.requests.review',
        'admin.virtual-card.cardholders.action',
        'admin.p2p.disputes.resolve-release',
        'admin.p2p.disputes.resolve-refund',
        'admin.bill-payment.mark-paid',
        'admin.bill-payment.mark-failed',
        'admin.mass-payment.resume',
        'admin.mass-payment.cancel-remaining',
        'admin.mass-payment.close-after-review',
    ];

    /**
     * Clearing an issue on the System Health screen.
     *
     * Same reasoning as the review queues: a bug tracker whose Resolve
     * button refuses is a screenshot, not a demonstration. Resolving,
     * ignoring, assigning and the bulk form all rewrite issue triage
     * state — rows the application recreates the next time the error
     * happens, which is exactly why they are safe to hand over.
     *
     * The channel routes in the same group are NOT here. Connecting or
     * disconnecting a Slack/webhook destination is site configuration:
     * an evaluator who unhooks it breaks alerting for everyone after
     * them, and nothing regenerates it.
     *
     * @var list<string>
     */
    public const ISSUE_TRIAGE = [
        'admin.observability.issues.resolve',
        'admin.observability.issues.ignore',
        'admin.observability.issues.assign',
        'admin.observability.issues.bulk',
    ];

    /**
     * Everything the Background Tasks screen does.
     *
     * Run and the two retries are the safe half, because neither is
     * really a new action. `task_key` is validated against
     * BackgroundTaskRegistry, so Run can only fire a command the
     * scheduler was going to fire on its own a minute later; a retry
     * re-dispatches a job the application already queued once, and if
     * it fails again the row comes straight back. Whether the panel
     * actually reaches the queue is the question the screen exists to
     * answer, and every run is recorded in Run history either way.
     *
     * `forget` and `flush` are the exception on this list: they delete
     * failed-job rows and nothing brings them back, so an evaluator
     * who flushes erases the record of a real failure before the
     * operator has read it. They are here because the screen was asked
     * for whole — a Failed jobs tab with two live buttons and two dead
     * ones is a worse demo than either extreme. On a demo install that
     * is a fair trade; on a site where those rows are evidence, the
     * answer is not to run it in demo mode.
     *
     * @var list<string>
     */
    public const BACKGROUND_TASK_ACTIONS = [
        'admin.background-tasks.run',
        'admin.queue.retry',
        'admin.queue.retry-all',
        'admin.queue.forget',
        'admin.queue.flush',
    ];

    /**
     * Updater writes that stay blocked while demo mode is on.
     *
     * The Project Updater is otherwise exempt from the write-block
     * (see handle()), but the recovery backup is not an update
     * action — it builds a zip holding a full database dump and the
     * whole storage folder, then streams it to whoever pressed the
     * button. On a public demo install that is every visitor who read
     * the published admin credentials, and what they walk away with is
     * the demo database (every seeded account, every hash, every
     * setting row including gateway configuration) plus every uploaded
     * file. The archive also stays on disk under the backups path
     * afterwards, so each press leaves another copy behind.
     *
     * Blocked at the guard AND refused again in
     * ProjectUpdaterService::createDownloadableRecoveryBackup(), so no
     * second entry point can produce the archive.
     *
     * @var list<string>
     */
    public const BLOCKED_UPDATER_WRITES = [
        'admin.app.updater.backup.download',
    ];

    /**
     * Every write an evaluator may fire while demo mode is on.
     *
     * @return list<string>
     */
    public static function allowedWrites(): array
    {
        return [
            ...self::REVIEW_QUEUE_DECISIONS,
            ...self::ISSUE_TRIAGE,
            ...self::BACKGROUND_TASK_ACTIONS,
        ];
    }

    public function handle(Request $request, Closure $next)
    {
        if (! config('app.demo')) {
            return $next($request);
        }

        $route     = $request->route();
        $routeName = $route?->getName() ?? '';

        if ($routeName === 'admin.user.login') {
            notifyEvs('error', __('Login as User is disabled while demo mode is on. Sign in with the published user credentials instead.'));

            return redirect()->back();
        }

        // The Project Updater is an admin-only maintenance tool that must remain
        // fully functional in demo mode — only its REMOTE license verification is
        // skipped (handled in ProjectUpdateServerClient), while activation,
        // refresh, checks, installs, and on-disk install backups still run. So its
        // write routes are exempt from the demo write-block, except the ones in
        // BLOCKED_UPDATER_WRITES: those hand a copy of the installation to the
        // browser rather than changing it, which is the one thing an anonymous
        // evaluator must not be able to do.
        if (str_starts_with($routeName, 'admin.app.updater.')) {
            if (in_array($routeName, self::BLOCKED_UPDATER_WRITES, true)) {
                return $this->refuse($request, __('Downloading a recovery backup is disabled in demo mode.'));
            }

            return $next($request);
        }

        if (in_array($routeName, self::allowedWrites(), true)) {
            return $next($request);
        }

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $this->refuse($request, __('This action is disabled in demo mode.'));
        }

        return $next($request);
    }

    /**
     * Turn a refused write away in the shape the caller asked for.
     */
    private function refuse(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'status'  => 'demo',
                'type'    => 'warning',
                'message' => $message,
            ], 423);
        }

        notifyEvs('error', $message);

        return redirect()->back();
    }
}
