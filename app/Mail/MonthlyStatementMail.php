<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Last month's statement for each wallet, as PDF attachments, to a customer
 * who asked for it in the app.
 */
class MonthlyStatementMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Queued mail travels as JSON, and raw PDF bytes are not valid UTF-8, so
     * pushing them onto a database or redis queue throws before anything is
     * sent. The attachments ride as base64 and are decoded when the mail is
     * built in the worker.
     *
     * @var array<string, string> filename => base64 PDF
     */
    private readonly array $encodedPdfs;

    /**
     * @param array<string, string> $pdfs filename => PDF bytes
     */
    public function __construct(public readonly string $period, array $pdfs)
    {
        $this->encodedPdfs = array_map('base64_encode', $pdfs);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':app statement · :period', ['app' => config('app.name'), 'period' => $this->period]));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e(__('Your :period statements are attached. They carry your name and wallet ids - handle them like a bank statement.', ['period' => $this->period])).'</p>');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return collect($this->encodedPdfs)
            ->map(fn (string $encoded, string $name): Attachment => Attachment::fromData(fn () => (string) base64_decode($encoded, true), $name)->withMime('application/pdf'))
            ->values()
            ->all();
    }
}
