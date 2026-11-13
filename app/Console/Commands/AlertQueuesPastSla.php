<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Admin;
use App\Notifications\QueueSlaBreachAlert;
use App\Services\Dashboard\DashboardQueueService;
use App\Services\Dashboard\DashboardSla;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;

/**
 * Tell administrators a review queue went past its SLA.
 *
 * The dashboard shows this already, but only to somebody who has it open. A
 * queue that goes late overnight is the one nobody is watching.
 *
 * Off unless an administrator turns it on: notifying people is new behaviour,
 * and an update must not start doing it on its own.
 */
class AlertQueuesPastSla extends Command
{
    protected $signature = 'dashboard:alert-sla
                            {--repeat=360 : Minutes before the same queue may alert again}';

    protected $description = 'Notify administrators about review queues that have passed their SLA target';

    /**
     * Only somebody who can act on the queue is told about it.
     */
    private const RECIPIENT_PERMISSION = 'dashboard-queue';

    public function handle(DashboardQueueService $queues): int
    {
        if (! setting('sla_alerts_enabled', false)) {
            $this->info(__('SLA alerts are switched off.'));

            return self::SUCCESS;
        }

        $repeat = max(1, min(10080, (int) $this->option('repeat')));

        /*
         * Only queues that are late AND have not been alerted inside the
         * repeat window. Without the second half, a queue nobody can clear
         * today would alert on every scheduler tick until it was.
         */
        $breached = $queues->queues()
            ->filter(fn (array $queue): bool => $queue['count'] > 0 && $queue['state'] === DashboardSla::STATE_OVER)
            ->reject(fn (array $queue): bool => Cache::has($this->alertKey($queue['key'])))
            ->sortByDesc('elapsed_hours')
            ->values();

        if ($breached->isEmpty()) {
            $this->info(__('No review queue needs an SLA alert.'));

            return self::SUCCESS;
        }

        /*
         * Admin::permission() throws when the permission row is absent, and on
         * an installation that has upgraded but not yet run install:reconcile
         * it is absent — which would make this scheduled command throw every
         * ten minutes instead of reporting the real problem.
         */
        $permissionExists = Permission::query()
            ->where('name', self::RECIPIENT_PERMISSION)
            ->where('guard_name', 'admin')
            ->exists();

        if (! $permissionExists) {
            $this->error(__('The :permission permission does not exist yet. Run php artisan install:reconcile.', [
                'permission' => self::RECIPIENT_PERMISSION,
            ]));

            return self::FAILURE;
        }

        $admins = Admin::permission(self::RECIPIENT_PERMISSION)
            ->where('status', true)
            ->get();

        if ($admins->isEmpty()) {
            $this->error(__('No active administrator holds :permission, so nobody can receive the alert.', [
                'permission' => self::RECIPIENT_PERMISSION,
            ]));

            return self::FAILURE;
        }

        $worst = $breached->first();

        Notification::sendNow($admins, new QueueSlaBreachAlert(
            queues: $breached->map(fn (array $queue): array => [
                'title'        => $queue['title'],
                'over_count'   => $queue['over_count'],
                'count'        => $queue['count'],
                'oldest_label' => (string) $queue['oldest_label'],
            ])->all(),
            worstQueue: (string) $worst['title'],
            worstAge: (string) $worst['oldest_label'],
            link: (string) $worst['link'],
        ));

        foreach ($breached as $queue) {
            Cache::put($this->alertKey($queue['key']), now()->toIso8601String(), now()->addMinutes($repeat));
        }

        $this->info(__('Alerted :admins administrator(s) about :count queue(s).', [
            'admins' => $admins->count(),
            'count'  => $breached->count(),
        ]));

        return self::SUCCESS;
    }

    private function alertKey(string $queue): string
    {
        return 'dashboard.sla_alerted.'.$queue;
    }
}
