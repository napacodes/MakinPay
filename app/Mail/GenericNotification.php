<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class GenericNotification extends Mailable
{
    use Queueable, SerializesModels;

    public string $title;

    public string $message;

    /**
     * Everything past the message is optional and rendered only when given.
     *
     * @param array{label: string, tone?: string}|null $badge   tone: success|warning|danger|info
     * @param array<string, string>                    $details label => value rows under the message
     */
    public function __construct(
        string $title,
        string $message,
        public readonly ?array $badge = null,
        public readonly array $details = [],
        public readonly ?string $actionText = null,
        public readonly ?string $actionUrl = null,
        public readonly ?string $greeting = null,
    ) {
        $this->title   = $title;
        $this->message = $message;
    }

    public function build(): self
    {
        return $this->subject($this->title)
            ->view('emails.generic_notification')
            ->with([
                'title'      => $this->title,
                'body'       => $this->message,
                'badge'      => $this->badge,
                'details'    => $this->details,
                'actionText' => $this->actionText,
                'actionUrl'  => $this->actionUrl,
                'greeting'   => $this->greeting,
            ]);
    }
}
