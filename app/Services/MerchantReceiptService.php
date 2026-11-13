<?php

namespace App\Services;

use App\Enums\TrxStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\PaymentReceiptNotification;
use App\Services\Api\ContactsService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use NotificationChannels\Twilio\TwilioChannel;

/**
 * A business sends its customer the receipt for a payment.
 *
 * Only a completed payment, only to the DigiKash customer who paid it (the
 * payer is on the row, never an address the business types), and only once:
 * asking again answers with the first sending instead of mailing twice, so
 * a counter cannot be used to spam a customer. The row is locked while it
 * is decided, so two taps cannot both send.
 */
class MerchantReceiptService
{
    public const NOT_PAID = 'not_paid';

    public const NO_PAYER = 'no_payer';

    public const NO_CHANNEL = 'no_channel';

    /**
     * @return array{sent: bool, refused: string|null, receipt: array<string, mixed>|null}
     */
    public function send(Transaction $payment): array
    {
        return DB::transaction(function () use ($payment): array {
            $row = Transaction::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($row->status !== TrxStatus::COMPLETED) {
                return $this->refused(self::NOT_PAID);
            }

            $data  = is_array($row->trx_data) ? $row->trx_data : [];
            $payer = User::query()->find($data['payer_user_id'] ?? null);

            if ($payer === null) {
                return $this->refused(self::NO_PAYER);
            }

            if (isset($data['receipt']['sent_at'])) {
                return ['sent' => false, 'refused' => null, 'receipt' => $this->describe($row, $data['receipt'], $payer)];
            }

            $isSandbox = str_contains($row->remarks ?? '', 'SANDBOX_TRANSACTION')
                || (bool) ($data['is_sandbox'] ?? false)
                || ($data['environment'] ?? null) === 'sandbox';
            $merchant = (string) ($data['merchant_name'] ?? config('app.name'));
            $currency = (string) ($row->payable_currency ?: $row->currency);

            $notification = new PaymentReceiptNotification([
                // What the customer paid, not what reached the business.
                'amount'   => trim(Money::format($row->payable_amount ?? $row->amount, $currency ?: null, false).' '.$currency),
                'merchant' => $isSandbox ? __('SANDBOX: :merchant', ['merchant' => $merchant]) : $merchant,
                'trx'      => $row->trx_id,
                'date'     => Carbon::parse($row->updated_at)->timezone(config('app.timezone'))->format('M j, Y g:i A'),
            ]);

            $channels = $notification->via($payer);

            if ($channels === []) {
                return $this->refused(self::NO_CHANNEL);
            }

            $payer->notify($notification);

            $data['receipt'] = [
                'sent_at'  => now()->toIso8601String(),
                'channels' => array_values(array_map(
                    fn (string $channel): string => $channel === TwilioChannel::class ? 'sms' : 'email',
                    $channels,
                )),
            ];
            $row->trx_data = $data;
            $row->save();

            return ['sent' => true, 'refused' => null, 'receipt' => $this->describe($row, $data['receipt'], $payer)];
        });
    }

    /**
     * What the business is told: when, how, and where to, masked - the
     * customer's address is theirs, not the counter's.
     *
     * @param  array{sent_at: string, channels: list<string>} $receipt
     * @return array<string, mixed>
     */
    private function describe(Transaction $row, array $receipt, User $payer): array
    {
        $channels = $receipt['channels'];

        return [
            'reference' => $row->trx_id,
            'sent_at'   => $receipt['sent_at'],
            'channels'  => $channels,
            'email'     => in_array('email', $channels, true) ? ContactsService::maskEmail((string) $payer->email) : null,
            'phone'     => in_array('sms', $channels, true) ? ContactsService::maskPhone((string) $payer->phone) : null,
        ];
    }

    /**
     * @return array{sent: bool, refused: string, receipt: null}
     */
    private function refused(string $why): array
    {
        return ['sent' => false, 'refused' => $why, 'receipt' => null];
    }
}
