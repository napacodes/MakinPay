<?php

namespace Database\Seeders;

use App\Enums\NotificationActionType;
use App\Enums\UserType;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

class PaymentNotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'identifier'  => 'payment_admin_notify_received',
                'name'        => 'Payment Received',
                'icon'        => 'money-plus',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Admins holding the payment notification permission are alerted whenever a user payment settles into a receiving wallet.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['payer', 'receiver', 'amount', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Payment received: {amount}',
                        'message'   => '{payer} paid {amount} to {receiver}. Transaction reference: {trx}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Payment Received',
                        'message'   => '{payer} paid {amount} to {receiver}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Payment received: {payer} paid {amount} to {receiver} ({trx}).',
                        'is_active' => false,
                    ],
                ],
            ],
        ];

        foreach ($templates as $data) {
            $template = NotificationTemplate::updateOrCreate(
                ['identifier' => $data['identifier']],
                collect($data)->except('channels')->toArray()
            );

            $template->channels()->delete();
            $template->channels()->createMany($data['channels']);
        }
    }
}
