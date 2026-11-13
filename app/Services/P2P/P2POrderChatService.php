<?php

declare(strict_types=1);

namespace App\Services\P2P;

use App\Models\P2P\Order;
use App\Models\P2P\OrderMessage;
use App\Models\User;
use App\Notifications\P2P\P2POrderStatusNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class P2POrderChatService
{
    public function store(Order $order, User $sender, ?string $body, ?UploadedFile $proof): OrderMessage
    {
        $attachmentPath = null;

        if ($proof) {
            $storedPath = $proof->store('p2p/payment-proofs/'.$order->id, 'local');

            if (! $storedPath) {
                throw ValidationException::withMessages([
                    'proof' => __('Payment proof could not be uploaded. Please try again.'),
                ]);
            }

            $attachmentPath = $storedPath;
        }

        try {
            $message = OrderMessage::create([
                'order_id'        => $order->id,
                'sender_id'       => $sender->id,
                'body'            => filled($body) ? trim((string) $body) : __('Payment proof uploaded.'),
                'attachment_path' => $attachmentPath,
                'attachment_name' => $proof ? Str::limit(basename($proof->getClientOriginalName()), 180, '') : null,
                'attachment_mime' => $proof?->getMimeType(),
                'attachment_size' => $proof?->getSize(),
            ]);
        } catch (Throwable $exception) {
            if ($attachmentPath) {
                Storage::disk('local')->delete($attachmentPath);
            }

            throw $exception;
        }

        $message->setRelation('sender', $sender);

        if ($proof) {
            $this->notifyCounterparty($order, $sender);
        }

        return $message;
    }

    private function notifyCounterparty(Order $order, User $sender): void
    {
        $counterpartyId = (int) $sender->id === (int) $order->maker_id
            ? (int) $order->taker_id
            : (int) $order->maker_id;

        $counterparty = User::find($counterpartyId);

        $counterparty?->notify(new P2POrderStatusNotification(
            orderId: (int) $order->id,
            title: __('Payment Proof Uploaded'),
            message: __('New payment proof was uploaded for Order #:id. Review it before taking action.', ['id' => $order->order_number]),
            data: [
                'status'     => $order->status->value,
                'event'      => 'payment_proof_uploaded',
                'send_email' => true,
            ],
        ));
    }
}
