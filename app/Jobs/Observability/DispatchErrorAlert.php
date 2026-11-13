<?php

namespace App\Jobs\Observability;

use App\Models\Observability\ErrorDeliveryLog;
use App\Models\Observability\ErrorGroup;
use App\Services\Observability\AlertRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fan-out: one queued job per channel.
 *
 * This job decides and delegates; it never sends. A rate-limited Teams webhook
 * holding a worker for thirty seconds must not be able to delay the page going
 * to Teams, and the only way to guarantee that is to give every channel its
 * own job and its own failure.
 */
class DispatchErrorAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private string $groupId) {}

    public function handle(AlertRouter $router): void
    {
        $group = ErrorGroup::query()->where('group_id', $this->groupId)->first();

        if ($group === null) {
            return;
        }

        foreach ($router->plan($group) as $step) {
            /*
             * A gate that silenced this alert is written down rather than
             * discarded. Otherwise a working throttle and a broken channel
             * look identical from the delivery log.
             */
            if ($step['status'] !== 'send') {
                ErrorDeliveryLog::recordGate($group->id, $step['channel'], $step['status']);

                continue;
            }

            DeliverErrorAlert::dispatch($group->group_id, $step['channel']);
        }
    }
}
