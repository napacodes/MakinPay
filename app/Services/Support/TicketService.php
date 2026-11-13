<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Exceptions\NotifyErrorException;
use App\Models\Admin;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TemplateNotification;
use App\Traits\FileManageTrait;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A customer's side of a support ticket: opening it, replying, closing it.
 *
 * Lifted out of the website's TicketController so the app does exactly the
 * same, and so the one thing that made both fragile is fixed once: telling
 * the support desk. That looked the notification permission up by name, and
 * Spatie throws when the permission does not exist - AFTER the ticket had been
 * saved. The customer saw an error page for a ticket that existed, and the
 * natural next step was to send it again. Telling support is now best effort,
 * as it already was for KYC.
 *
 * Whose ticket it is is decided before anything reaches here: callers resolve
 * the ticket through Ticket::ownedBy.
 */
final class TicketService
{
    use FileManageTrait;

    /**
     * @param array{title: string, message: string, priority: string, category_id?: int|string|null} $validated
     */
    public function open(User $user, array $validated, ?UploadedFile $attachment): Ticket
    {
        $ticket = Ticket::create([
            'uuid'            => $this->freshNumber(),
            'title'           => $validated['title'],
            'message'         => $validated['message'],
            'attachment'      => $attachment ? $this->uploadFile($attachment) : null,
            'user_id'         => $user->getKey(),
            'category_id'     => $validated['category_id']     ?? null,
            'transaction_ref' => $validated['transaction_ref'] ?? null,
            'priority'        => TicketPriority::from((string) $validated['priority']),
            'status'          => TicketStatus::PENDING,
        ]);

        $this->tellSupport('support_user_created', $user, $ticket);

        return $ticket;
    }

    /**
     * A reply from the ticket's owner.
     *
     * A message carries no author of its own - one with no admin_id is read as
     * the owner's - which is exactly why the caller must have resolved the
     * ticket as this customer's before calling.
     */
    public function reply(Ticket $ticket, User $user, string $message, ?UploadedFile $attachment): Message
    {
        $reply = $ticket->messages()->create([
            'message'    => $message,
            'attachment' => $attachment ? $this->uploadFile($attachment) : null,
        ]);

        // A reply from the customer puts the ticket back in support's queue.
        $ticket->status = TicketStatus::OPEN;
        $ticket->save();

        $this->tellSupport('support_user_replied', $user, $ticket);

        return $reply;
    }

    public function close(Ticket $ticket): void
    {
        $ticket->status = TicketStatus::CLOSED;
        $ticket->save();
    }

    /**
     * The customer takes a closed ticket back up - the problem came back,
     * or "solved" was clicked too soon. It returns to support's queue as a
     * reply would; only a closed ticket can be reopened.
     *
     * @throws NotifyErrorException
     */
    public function reopen(Ticket $ticket, User $user): void
    {
        if ($ticket->status !== TicketStatus::CLOSED) {
            throw new NotifyErrorException(__('Only a closed ticket can be reopened.'));
        }

        $ticket->status      = TicketStatus::OPEN;
        $ticket->is_resolved = false;
        $ticket->save();

        $this->tellSupport('support_user_replied', $user, $ticket);
    }

    /**
     * The customer-facing ticket number, in the format it has always had.
     *
     * The column is unique, so a collision was a database error. Seven random
     * characters make one unlikely; this makes it impossible to reach the
     * customer.
     */
    private function freshNumber(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = strtoupper('#'.Str::random(7));

            if (! Ticket::query()->where('uuid', $number)->exists()) {
                return $number;
            }
        }

        throw new RuntimeException('Could not find a free ticket number.');
    }

    /**
     * Best effort. The ticket is already saved; nothing here may undo it or
     * turn it into an error page.
     */
    private function tellSupport(string $template, User $user, Ticket $ticket): void
    {
        try {
            Notification::send(Admin::permission('support-ticket-notification')->get(), new TemplateNotification(
                identifier: $template,
                data: [
                    'user'          => $user->name,
                    'ticket_number' => $ticket->uuid,
                    'subject'       => $ticket->title,
                ],
                sender: $user,
                action: route('admin.support-ticket.show', $ticket->id),
            ));
        } catch (Throwable $e) {
            Log::warning('Support desk could not be told about a ticket', [
                'ticket_id' => $ticket->id,
                'template'  => $template,
                'exception' => $e::class,
            ]);
        }
    }
}
