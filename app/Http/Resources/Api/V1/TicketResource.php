<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One support ticket, and its conversation when loaded.
 *
 * Each message says only whether it came from the customer or from support.
 * Which admin answered - a name, an address - is not the customer's to see,
 * and a message with no admin_id is the owner's by construction.
 *
 * Attachments are linked through /file/download, which serves the public disk
 * and nothing outside it.
 *
 * @property-read Ticket $resource
 */
class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ticket = $this->resource;

        return [
            'id'             => $ticket->id,
            'number'         => $ticket->uuid,
            'title'          => $ticket->title,
            'message'        => $ticket->message,
            'status'         => $ticket->status?->value,
            'priority'       => $ticket->priority?->value,
            'category'       => $ticket->category ? ['id' => $ticket->category->id, 'name' => $ticket->category->name] : null,
            'attachment_url' => self::attachmentUrl($ticket->attachment),
            // Support wrote last: the app shows "Answered" rather than "Open".
            'answered'        => $ticket->status !== TicketStatus::CLOSED && $ticket->isReplied(),
            'transaction_ref' => $ticket->transaction_ref,
            'transaction'     => $this->transaction($ticket),
            'created_at'      => $ticket->created_at?->toIso8601String(),
            'updated_at'      => $ticket->updated_at?->toIso8601String(),
            'messages'        => $this->whenLoaded('messages', fn (): array => $ticket->messages
                ->map(fn (Message $message): array => [
                    'id'             => $message->id,
                    'from'           => $message->admin_id === null ? 'you' : 'support',
                    'message'        => $message->message,
                    'attachment_url' => self::attachmentUrl($message->attachment),
                    'created_at'     => $message->created_at?->toIso8601String(),
                ])
                ->values()
                ->all()),
        ];
    }

    /**
     * The transaction the ticket is about, as it stands now - so the
     * conversation can say "credited" beside support's last word without
     * anyone typing it. Only the ticket owner's own row is ever looked up.
     *
     * @return array{reference: string, type: string|null, status: string|null, amount: string, currency: string|null, created_at: string|null}|null
     */
    private function transaction(Ticket $ticket): ?array
    {
        if (! filled($ticket->transaction_ref)) {
            return null;
        }

        $trx = Transaction::query()
            ->where('user_id', $ticket->user_id)
            ->where('trx_id', $ticket->transaction_ref)
            ->first();

        if ($trx === null) {
            return null;
        }

        return [
            'reference'  => (string) $trx->trx_id,
            'type'       => $trx->trx_type?->value,
            'status'     => $trx->status?->value,
            'amount'     => ApiMoney::amount((string) ($trx->payable_amount ?? $trx->amount), (string) ($trx->payable_currency ?? $trx->currency)),
            'currency'   => $trx->payable_currency ?? $trx->currency,
            'created_at' => $trx->created_at?->toIso8601String(),
        ];
    }

    private static function attachmentUrl(?string $path): ?string
    {
        return filled($path) ? route('file.download', ['filePath' => $path]) : null;
    }
}
