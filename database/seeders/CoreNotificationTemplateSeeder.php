<?php

namespace Database\Seeders;

use App\Enums\NotificationActionType;
use App\Enums\UserType;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * The notification copy every core money and account flow depends on.
 *
 * These rows only ever shipped inside `DB/digikash.sql`, so any installation
 * that did not take the MySQL dump path — SQLite, or a manual `migrate` —
 * came up with an empty `notification_templates` table and no deposit,
 * withdraw, transfer, ticket, KYC or merchant notification at all.
 *
 * Unlike the feature seeders alongside it, this one never rewrites a template
 * that is already present: administrators edit this copy from the admin panel,
 * and an upgrade must not silently discard their wording. Missing templates are
 * created, and a template that exists but lost its channels is repaired.
 */
class CoreNotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'identifier'  => 'kyc_admin_notify_submission',
                'name'        => 'KYC Verification Requested',
                'icon'        => 'kyc-alert',
                'action_type' => NotificationActionType::REQUESTED,
                'info'        => 'Admin alert when a user submits a KYC verification request.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['user', 'kyc_type'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'New KYC Submission',
                        'message'   => 'User {user} submitted a KYC verification request using {kyc_type}. Please review it.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'KYC Submission Alert',
                        'message'   => '{user} submitted KYC ({kyc_type}).',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => '{user} submitted KYC type: {kyc_type}.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'kyc_user_notify_approved',
                'name'        => 'KYC Approved',
                'icon'        => 'kyc-approved',
                'action_type' => NotificationActionType::APPROVED,
                'info'        => 'Notifies user when their KYC is approved by admin.',
                'user_type'   => UserType::USER,
                'variables'   => ['kyc_type'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Your KYC is Approved',
                        'message'   => 'Your KYC verification using {kyc_type} has been approved. You can now access all features.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'KYC Approved',
                        'message'   => 'Your KYC ({kyc_type}) is approved.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Your KYC ({kyc_type}) is approved.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'kyc_user_notify_rejected',
                'name'        => 'KYC Rejected',
                'icon'        => 'kyc-rejected',
                'action_type' => NotificationActionType::REJECTED,
                'info'        => 'Notifies user when their KYC verification is rejected by admin.',
                'user_type'   => UserType::USER,
                'variables'   => ['kyc_type', 'rejection_reason'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Your KYC Was Rejected',
                        'message'   => 'We’re sorry, your KYC verification using {kyc_type} has been rejected. Reason: {rejection_reason}. Please re-submit with valid documents.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'KYC Rejected',
                        'message'   => 'Your KYC ({kyc_type}) was rejected. Reason: {rejection_reason}',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'KYC ({kyc_type}) rejected. Reason: {rejection_reason}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'deposit_user_submitted',
                'name'        => 'Manual Deposit Submitted',
                'icon'        => 'deposit-request',
                'action_type' => NotificationActionType::REQUESTED,
                'info'        => 'Triggered when user submits a manual deposit request.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'method', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Deposit Request Received',
                        'message'   => 'We have received your deposit request of {amount} via {method}. Transaction ID: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Request Submitted',
                        'message'   => 'You submitted a deposit request for {amount}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Deposit {amount} via {method} submitted. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'deposit_admin_notify_submission',
                'name'        => 'Manual Deposit Requested',
                'icon'        => 'deposit-alert',
                'action_type' => NotificationActionType::REQUESTED,
                'info'        => 'Admin alert when user submits manual deposit.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['user', 'amount', 'method', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Deposit Request Submitted',
                        'message'   => 'User {user} submitted a deposit request of {amount} via {method}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Deposit Request Alert',
                        'message'   => '{user} requested deposit of {amount}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => '{user} requested deposit {amount}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'deposit_user_approved',
                'name'        => 'Deposit Approval Notification',
                'icon'        => 'deposit-success',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Sent to user after admin approves manual deposit.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'method'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Deposit Approved',
                        'message'   => 'Your deposit of {amount} via {method} has been approved and added to your wallet.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Deposit Approved',
                        'message'   => '{amount} added to your balance.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Deposit {amount} approved.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'deposit_user_rejected',
                'name'        => 'Deposit Rejected Notification',
                'icon'        => 'deposit-failed',
                'action_type' => NotificationActionType::REJECTED,
                'info'        => 'Sent when admin rejects user deposit request.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'method', 'reason'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Deposit Rejected',
                        'message'   => 'Your deposit of {amount} via {method} was rejected. Reason: {reason}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Deposit Declined',
                        'message'   => 'Deposit {amount} rejected. Reason: {reason}',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Deposit {amount} rejected. Reason: {reason}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'send_money_user_sent',
                'name'        => 'Money Transfer Confirmation',
                'icon'        => 'send-money',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify user after sending money successfully.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'recipient', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Money Sent',
                        'message'   => 'You sent {amount} to {recipient}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Money Sent',
                        'message'   => '{amount} sent to {recipient}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Sent {amount} to {recipient}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'send_money_user_received',
                'name'        => 'Money Received Notification',
                'icon'        => 'receive-money',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify user when they receive money from another wallet.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'sender', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Money Received',
                        'message'   => 'You received {amount} from {sender}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Money Received',
                        'message'   => '{amount} received from {sender}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Received {amount} from {sender}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'request_money_user_requested',
                'name'        => 'Money Request Submitted',
                'icon'        => 'request-money',
                'action_type' => NotificationActionType::REQUESTED,
                'info'        => 'Notify requestor when they send a money request.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'recipient', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Request Sent',
                        'message'   => 'You requested {amount} from {recipient}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Request Sent',
                        'message'   => '{amount} requested from {recipient}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Requested {amount} from {recipient}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'request_money_user_received',
                'name'        => 'Money Request Received',
                'icon'        => 'request-received',
                'action_type' => NotificationActionType::REQUESTED,
                'info'        => 'Notify user of incoming money request.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'sender', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Request Received',
                        'message'   => 'You have a request of {amount} from {sender}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Request Received',
                        'message'   => 'Incoming request {amount} from {sender}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Request of {amount} from {sender}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'request_money_user_approved',
                'name'        => 'Money Request Approved',
                'icon'        => 'request-approved',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify requestor when their money request is approved by the recipient.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'receiver', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Request Approved',
                        'message'   => 'Your money request of {amount} has been approved by {receiver}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Request Approved',
                        'message'   => 'Request of {amount} approved by {receiver}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Approved: {amount} by {receiver}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'request_money_user_rejected',
                'name'        => 'Money Request Rejected',
                'icon'        => 'request-rejected',
                'action_type' => NotificationActionType::REJECTED,
                'info'        => 'Notify requestor when their money request is rejected by the recipient.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'receiver', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Request Rejected',
                        'message'   => 'Your money request of {amount} was rejected by {receiver}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Request Rejected',
                        'message'   => 'Request of {amount} rejected by {receiver}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Rejected: {amount} by {receiver}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'exchange_money_user_exchanged',
                'name'        => 'Currency Exchange Completed',
                'icon'        => 'exchange-money',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify user after successful currency exchange.',
                'user_type'   => UserType::USER,
                'variables'   => ['from_amount', 'from_currency', 'to_amount', 'to_currency', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Exchange Completed',
                        'message'   => 'You exchanged {from_amount} {from_currency} to {to_amount} {to_currency}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Exchange Successful',
                        'message'   => 'Exchanged {from_amount} {from_currency} → {to_amount} {to_currency}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Exchanged {from_amount} to {to_amount}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'voucher_user_redeemed',
                'name'        => 'Voucher Redemption Confirmed',
                'icon'        => 'voucher',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify user after redeeming voucher successfully.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'voucher_code', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Voucher Redeemed',
                        'message'   => 'You redeemed voucher {voucher_code} worth {amount}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Voucher Redeemed',
                        'message'   => '{amount} added from voucher.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Voucher {voucher_code} redeemed. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'payment_user_made',
                'name'        => 'Wallet Payment Completed',
                'icon'        => 'wallet-payment',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify user when they pay via wallet.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'merchant', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Payment Successful',
                        'message'   => 'Paid {amount} to {merchant}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Payment Completed',
                        'message'   => 'Payment of {amount} successful.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Paid {amount} to {merchant}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                // Sent only when the business asks for it (the business
                // app's "Send e-receipt to customer"), by email and SMS;
                // there is deliberately no push - the payment already had one.
                'identifier'  => 'payment_receipt_customer',
                'name'        => 'Payment Receipt to Customer',
                'icon'        => 'receipt',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'The receipt a business sends its customer for a payment.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'merchant', 'trx', 'date'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Your receipt from {merchant}',
                        'message'   => 'You paid {amount} to {merchant} on {date}. Reference: {trx}. Keep this email as your receipt.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Receipt from {merchant}',
                        'message'   => 'You paid {amount} to {merchant}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Receipt: you paid {amount} to {merchant} on {date}. Ref {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'payment_user_received',
                'name'        => 'Payment Received from User',
                'icon'        => 'wallet-receive',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify receiver when wallet payment is received.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Payment Received',
                        'message'   => 'Received {amount} from payer. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'New Payment',
                        'message'   => '{payer} paid you {amount}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Payment of {amount} by {payer}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'admin_balance_added',
                'name'        => 'Balance Added by Admin',
                'icon'        => 'balance-add',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify user when admin adds wallet balance.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'admin', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Balance Credited',
                        'message'   => 'Admin {admin} added {amount}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Balance Added',
                        'message'   => '{amount} credited by admin.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Added {amount} by admin {admin}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'admin_balance_subtracted',
                'name'        => 'Balance Deducted by Admin',
                'icon'        => 'balance-subtract',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify user when admin deducts wallet balance.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'admin', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Balance Deducted',
                        'message'   => 'Admin {admin} deducted {amount}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Balance Deducted',
                        'message'   => '{amount} deducted by admin.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Deducted {amount} by {admin}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'withdraw_admin_manual_submitted',
                'name'        => 'Manual Withdraw Requested',
                'icon'        => 'withdraw-alert',
                'action_type' => NotificationActionType::REQUESTED,
                'info'        => 'Notify admin when user requests withdrawal.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['user', 'amount', 'method', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Withdraw Request',
                        'message'   => 'User {user} requested withdrawal of {amount} via {method}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Withdraw Request',
                        'message'   => '{user} requested {amount} withdraw.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => '{user} requested withdraw {amount}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'withdraw_admin_auto_processed',
                'name'        => 'Auto Withdraw Processed',
                'icon'        => 'withdraw-log',
                'action_type' => NotificationActionType::LOGGED,
                'info'        => 'Notify admin when auto withdrawal completes.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['user', 'amount', 'method', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Auto Withdraw Logged',
                        'message'   => 'User {user} completed auto withdrawal of {amount}. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Auto Withdraw Completed',
                        'message'   => '{user} auto withdrew {amount}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => '{user} auto withdraw {amount}. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'withdraw_user_requested',
                'name'        => 'Withdraw Request Submitted',
                'icon'        => 'withdraw-request',
                'action_type' => NotificationActionType::REQUESTED,
                'info'        => 'Notify user after submitting withdrawal request.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'method', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Withdraw Request Submitted',
                        'message'   => 'Your withdrawal request of {amount} via {method} has been submitted. Trx: {trx}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Withdraw Requested',
                        'message'   => 'You requested withdraw of {amount}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Withdraw {amount} requested. Trx: {trx}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'withdraw_user_approved',
                'name'        => 'Withdraw Approved',
                'icon'        => 'withdraw-success',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify user when admin approves withdrawal.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'method'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Withdraw Approved',
                        'message'   => 'Your withdrawal of {amount} via {method} has been approved.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Withdraw Approved',
                        'message'   => '{amount} approved for withdrawal.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Withdrawal {amount} approved.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'withdraw_user_rejected',
                'name'        => 'Withdraw Rejected',
                'icon'        => 'withdraw-failed',
                'action_type' => NotificationActionType::REJECTED,
                'info'        => 'Notify user if withdrawal is rejected.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'method', 'reason'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Withdraw Rejected',
                        'message'   => 'Your withdrawal of {amount} via {method} was rejected. Reason: {reason}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Withdraw Rejected',
                        'message'   => 'Withdraw {amount} rejected.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Withdrawal {amount} rejected. Reason: {reason}',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'reward_user_referral',
                'name'        => 'Referral Reward Earned',
                'icon'        => 'reward-referral',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify user when they earn referral reward.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount', 'referred_user'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Referral Reward',
                        'message'   => 'You earned {amount} for referring {referred_user}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Referral Reward',
                        'message'   => 'Earned {amount} reward.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Referral reward {amount} earned.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'reward_user_system',
                'name'        => 'Achievement Reward Granted',
                'icon'        => 'reward-achievement',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify user when system reward is granted.',
                'user_type'   => UserType::USER,
                'variables'   => ['amount'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Reward Granted',
                        'message'   => 'You received {amount}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Reward Received',
                        'message'   => '{amount} reward credited for achieving a new rank..',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Reward {amount} received.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'support_user_created',
                'name'        => 'Support Ticket Created',
                'icon'        => 'support-open',
                'action_type' => NotificationActionType::CREATED,
                'info'        => 'Notify admin when a user opens support ticket.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['user', 'ticket_number', 'subject'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'New Ticket Submitted',
                        'message'   => 'User {user} opened ticket #{ticket_number}: {subject}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'New Support Ticket',
                        'message'   => 'Ticket {ticket_number} created by {user}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'New ticket #{ticket_number} from {user}.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'support_admin_replied',
                'name'        => 'Support Reply Notification',
                'icon'        => 'support-reply-admin',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify user when admin replies to ticket.',
                'user_type'   => UserType::USER,
                'variables'   => ['ticket_number', 'subject'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Support Reply',
                        'message'   => 'We replied to ticket {ticket_number}: {subject}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Ticket Reply',
                        'message'   => 'Reply to ticket #{ticket_number}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Reply for ticket #{ticket_number}.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'support_user_replied',
                'name'        => 'Support Ticket Reply from User',
                'icon'        => 'support-reply',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify admin when a user replies to a support ticket.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['user', 'ticket_number', 'subject'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Reply on Ticket #{ticket_number}',
                        'message'   => 'User {user} replied to ticket #{ticket_number}: {subject}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Ticket #{ticket_number} Reply',
                        'message'   => '{user} replied to support ticket #{ticket_number}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'User {user} replied on ticket #{ticket_number}.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'support_user_closed',
                'name'        => 'Support Ticket Closed',
                'icon'        => 'support-closed',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify user when ticket is closed.',
                'user_type'   => UserType::USER,
                'variables'   => ['ticket_number'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Ticket Closed',
                        'message'   => 'Your ticket #{ticket_number} is now closed.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Ticket Closed',
                        'message'   => 'Ticket {ticket_number} closed.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Ticket #{ticket_number} closed.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'merchant_admin_notify_shop_request',
                'name'        => 'Merchant Shop Request',
                'icon'        => 'merchant-alert',
                'action_type' => NotificationActionType::REQUESTED,
                'info'        => 'Admin alert when a merchant submits a new shop/business for approval.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['user', 'business_name', 'business_email', 'site_url'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'New Merchant Shop Request',
                        'message'   => 'Merchant {user} requested a new shop named "{business_name}" with website: {site_url} and contact: {business_email}. Please review.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'New Merchant Request',
                        'message'   => '{user} submitted new shop: {business_name}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Shop "{business_name}" requested by {user}.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'merchant_user_notify_shop_approved',
                'name'        => 'Merchant Shop Approved',
                'icon'        => 'merchant-approved',
                'action_type' => NotificationActionType::APPROVED,
                'info'        => 'Notifies merchant when their shop is approved by admin.',
                'user_type'   => UserType::USER,
                'variables'   => ['business_name'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Shop Approved',
                        'message'   => 'Good news! Your shop "{business_name}" has been approved. You can now start accepting payments.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Shop Approved',
                        'message'   => 'Shop "{business_name}" is approved.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Shop "{business_name}" approved. Start using it.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'merchant_user_notify_shop_rejected',
                'name'        => 'Merchant Shop Rejected',
                'icon'        => 'merchant-rejected',
                'action_type' => NotificationActionType::REJECTED,
                'info'        => 'Notifies merchant when their shop is rejected by admin.',
                'user_type'   => UserType::USER,
                'variables'   => ['business_name', 'rejection_reason'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Shop Request Rejected',
                        'message'   => 'Sorry, your shop "{business_name}" was rejected. Reason: {rejection_reason}. Please update and resubmit.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Shop Rejected',
                        'message'   => 'Your shop "{business_name}" was rejected.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Shop "{business_name}" rejected. Reason: {rejection_reason}',
                        'is_active' => false,
                    ],
                ],
            ],
            /*
             * Bill payments. None of these takes a `token` variable: queued
             * notification data is stored in plain text in `jobs` and the push
             * copy is persisted, so a prepaid token is only ever shown on the
             * receipt. The completed copy points the customer there instead.
             */
            [
                'identifier'  => 'bill_payment_user_submitted',
                'name'        => 'Bill Payment Submitted',
                'icon'        => 'receipt',
                'action_type' => NotificationActionType::REQUESTED,
                'info'        => 'Notify the user after a bill payment is submitted and the wallet has been debited.',
                'user_type'   => UserType::USER,
                'variables'   => ['biller', 'customer_number', 'amount', 'bill_reference', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Bill Payment Submitted',
                        'message'   => 'Your {biller} payment of {amount} for {customer_number} has been submitted and is being processed. Reference: {bill_reference}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Bill payment submitted',
                        'message'   => 'Your {biller} payment of {amount} is being processed.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => '{biller} payment of {amount} submitted. Ref {bill_reference}.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'bill_payment_user_completed',
                'name'        => 'Bill Payment Completed',
                'icon'        => 'receipt',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify the user when a bill payment has been paid to the biller.',
                'user_type'   => UserType::USER,
                'variables'   => ['biller', 'customer_number', 'amount', 'bill_reference', 'receipt_reference', 'token_label', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Bill Payment Completed',
                        'message'   => 'Your {biller} payment of {amount} for {customer_number} is complete. Receipt: {receipt_reference}. Open the receipt for your {token_label}. Reference: {bill_reference}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Bill paid',
                        'message'   => 'Your {biller} payment of {amount} is complete. Open the receipt for your {token_label}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => '{biller} payment of {amount} is complete. Receipt {receipt_reference}.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'bill_payment_user_failed',
                'name'        => 'Bill Payment Failed',
                'icon'        => 'receipt',
                'action_type' => NotificationActionType::FAILED,
                'info'        => 'Notify the user when a bill payment was not completed and the money was returned to the wallet.',
                'user_type'   => UserType::USER,
                'variables'   => ['biller', 'amount', 'reason', 'bill_reference', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Bill Payment Not Completed',
                        'message'   => 'Your {biller} payment was not completed. Reason: {reason}. {amount} was returned to your wallet. Reference: {bill_reference}.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Bill payment not completed',
                        'message'   => 'Your {biller} payment was not completed. {amount} was returned to your wallet.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => '{biller} payment not completed. {amount} returned to your wallet. Ref {bill_reference}.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'bill_payment_user_reminder',
                'name'        => 'Saved Bill Reminder',
                'icon'        => 'receipt',
                'action_type' => NotificationActionType::REQUESTED,
                'info'        => 'Remind the user to pay one of their saved bills on the day of the month they chose. Sent once a month per bill, never when it was already paid that month. Nothing is paid by itself.',
                'user_type'   => UserType::USER,
                'variables'   => ['title', 'biller', 'customer_number', 'day'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Time to pay {title}',
                        'message'   => 'It is the {day}: time to pay {title} ({biller}, {customer_number}). Open My bills to pay it in one tap. Nothing is paid until you do.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Pay {title}',
                        'message'   => 'It is the {day}: time to pay {biller} for {customer_number}. Tap to pay it.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Time to pay {title} ({biller}, {customer_number}).',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'bill_payment_admin_requested',
                'name'        => 'Bill Payment Requested',
                'icon'        => 'receipt',
                'action_type' => NotificationActionType::REQUESTED,
                'info'        => 'Admin alert when a customer submits a bill payment.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['user', 'biller', 'amount', 'bill_reference', 'trx'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'New Bill Payment',
                        'message'   => '{user} submitted a {biller} payment of {amount}. Reference: {bill_reference}. Review it in the bill payment queue.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'New bill payment',
                        'message'   => '{user} submitted a {biller} payment of {amount}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => '{biller} payment of {amount} by {user}. Ref {bill_reference}.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'bill_payment_admin_provider_paused',
                'name'        => 'Bill Payment Provider Paused',
                'icon'        => 'receipt',
                'action_type' => NotificationActionType::REVIEW,
                'info'        => 'Admin alert when a bill payment provider is paused because it refused a payment for its own account (an empty balance or refused credentials). It resumes after a passing connection test.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['provider', 'reason', 'message'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Bill payment provider paused',
                        'message'   => '{provider} has been paused: {reason}. The provider said: {message}. Customers see its billers as temporarily unavailable until a connection test on the provider screen passes.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Bill payment provider paused',
                        'message'   => '{provider} paused: {reason}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => '{provider} paused: {reason}.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'bill_payment_admin_billers_synced',
                'name'        => 'Bill Payment Catalogue Synced',
                'icon'        => 'receipt',
                'action_type' => NotificationActionType::REVIEW,
                'info'        => 'Admin alert after the daily biller catalogue sync of a bill payment provider changed something or a country failed. A sync that found nothing to change sends nothing.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['provider', 'countries', 'summary'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Bill payment catalogue synced',
                        'message'   => 'The daily sync of {provider} for {countries}: {summary} Each country\'s report is on the provider screen.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Bill payment catalogue synced',
                        'message'   => '{provider} ({countries}): {summary}',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => '{provider} ({countries}): {summary}',
                        'is_active' => false,
                    ],
                ],
            ],
            /*
             * Mass payments. The owner hears once, when the batch reaches a
             * terminal state, never per row; each recipient gets the existing
             * send_money_user_received. Admins hear when a batch is held.
             */
            [
                'identifier'  => 'mass_payment_user_completed',
                'name'        => 'Mass Payment Finished',
                'icon'        => 'users',
                'action_type' => NotificationActionType::COMPLETED,
                'info'        => 'Notify the owner once when a mass payment batch has finished, with how many rows were paid and what was returned to the wallet.',
                'user_type'   => UserType::USER,
                'variables'   => ['reference', 'paid_count', 'failed_count', 'paid_total', 'released', 'link'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Mass Payment Finished',
                        'message'   => 'Your mass payment {reference} has finished. {paid_count} paid, totalling {paid_total}. {failed_count} not paid; {released} was returned to your wallet. Report: {link}',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Mass payment finished',
                        'message'   => '{reference}: {paid_count} paid ({paid_total}), {failed_count} not paid. {released} returned to your wallet.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Mass payment {reference} finished: {paid_count} paid, {failed_count} not paid. {released} returned.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'mass_payment_admin_needs_review',
                'name'        => 'Mass Payment Needs Review',
                'icon'        => 'users',
                'action_type' => NotificationActionType::REVIEW,
                'info'        => 'Admin alert when a mass payment batch is held for review. Nothing more is paid until an admin resumes it or cancels the remaining rows.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['user', 'reference', 'reason', 'amount'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Mass Payment Held for Review',
                        'message'   => 'Mass payment {reference} by {user} ({amount}) was held for review. Reason: {reason}. Nothing more is paid until it is resumed or its remaining rows are cancelled.',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Mass payment needs review',
                        'message'   => '{reference} by {user} was held: {reason}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Mass payment {reference} by {user} held for review: {reason}.',
                        'is_active' => false,
                    ],
                ],
            ],
            /*
             * Mass payouts to external accounts. Approvers hear when a
             * confirmed batch waits for them; operators hear once per batch
             * when its first row waits to be paid by hand. There is no
             * per-row and no recipient notice.
             */
            [
                'identifier'  => 'mass_payout_admin_approval_required',
                'name'        => 'Mass Payout Awaiting Approval',
                'icon'        => 'users',
                'action_type' => NotificationActionType::REVIEW,
                'info'        => 'Admin alert when a confirmed mass payout batch waits for approval. Its money is reserved and nothing is sent until an admin approves or rejects it.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['user', 'reference', 'amount', 'rows', 'method', 'link'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Mass Payout Awaiting Approval',
                        'message'   => '{user} confirmed mass payout {reference}: {rows} payouts totalling {amount} by {method}. The money is reserved and nothing is sent until it is approved or rejected. Review: {link}',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Mass payout awaiting approval',
                        'message'   => '{reference} by {user}: {rows} payouts, {amount} by {method}.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Mass payout {reference} by {user} ({amount}) awaits approval.',
                        'is_active' => false,
                    ],
                ],
            ],
            [
                'identifier'  => 'mass_payout_admin_operator_required',
                'name'        => 'Mass Payout Needs an Operator',
                'icon'        => 'users',
                'action_type' => NotificationActionType::REVIEW,
                'info'        => 'Admin alert, once per batch, when a mass payout batch has payouts waiting to be sent by hand and recorded.',
                'user_type'   => UserType::ADMIN,
                'variables'   => ['user', 'reference', 'amount', 'rows', 'method', 'link'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'Mass Payout Needs an Operator',
                        'message'   => 'Mass payout {reference} by {user} has {rows} payouts by {method} ({amount}) waiting to be sent by hand. Record each one once it is sent. Open: {link}',
                        'is_active' => false,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Mass payout needs an operator',
                        'message'   => '{reference}: {rows} payouts by {method} ({amount}) to send by hand.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Mass payout {reference}: {rows} payouts by {method} to send by hand.',
                        'is_active' => false,
                    ],
                ],
            ],
        ];

        foreach ($templates as $data) {
            $template = NotificationTemplate::query()
                ->where('identifier', $data['identifier'])
                ->first();

            if (! $template) {
                $template = NotificationTemplate::query()->create(
                    collect($data)->except('channels')->toArray()
                );
            }

            if ($template->channels()->exists()) {
                continue;
            }

            $template->channels()->createMany($data['channels']);
        }
    }
}
