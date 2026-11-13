<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Media\MediaSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Replying to one's own support ticket, on the website and in the app.
 *
 * Whose ticket it is is not this request's question - the controllers resolve
 * the ticket through Ticket::ownedBy, so a stranger's never reaches here.
 */
class ReplyTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The same limit as the ticket's opening message. messages.message
            // was a VARCHAR(255) until 2026_09_24_194715 widened it to TEXT.
            'message'    => ['required', 'string', 'max:10000'],
            'attachment' => ['nullable', 'file', 'mimes:'.implode(',', MediaSettings::customerSafe(MediaSettings::fileGuardExtensions())), 'max:'.MediaSettings::effectiveMaxUploadSizeKb()],
        ];
    }
}
