<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Transaction;
use App\Notifications\TemplateNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class TransactionNotifierService
{
    /**
     * Notify a user using a specific template.
     */
    public function toUser(Transaction $trx, string $identifier, array $data, $action = null): void
    {
        $trx->user->notify(new TemplateNotification(
            identifier: $identifier,
            data: $data,
            action: $action
        ));
    }

    /**
     * Notify all admins who have specific permission using a template.
     *
     * Handlers call this from inside the `DB::transaction()` that moves the
     * money, so a permission an upgraded installation has not seeded yet must
     * not be allowed to roll the settlement back.
     */
    public function toAdmins(string $permission, string $identifier, array $data, $sender = null, ?string $action = null): void
    {
        try {
            $admins = Admin::permission($permission)->get();
        } catch (PermissionDoesNotExist) {
            Log::warning('Admin notification skipped: permission is not registered.', [
                'permission' => $permission,
                'identifier' => $identifier,
            ]);

            return;
        }

        Notification::send($admins, new TemplateNotification(
            identifier: $identifier,
            data: $data,
            sender: $sender,
            action: $action
        ));
    }
}
