<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Services\Media\MediaSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening a support ticket, on the website and in the app.
 *
 * The website checked only that a title and a message were there. priority
 * went into a NOT NULL column that is read back through the TicketPriority
 * cast, so an absent one was a database error and an invented one saved
 * happily and then threw on every read - the ticket could no longer be opened
 * by the customer or by support. category_id was stored as sent.
 */
class StoreTicketRequest extends FormRequest
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
            'title'       => ['required', 'string', 'max:255'],
            'message'     => ['required', 'string', 'max:10000'],
            'category_id' => ['nullable', 'integer', Rule::exists('support_categories', 'id')->where('status', true)],
            'priority'    => ['required', Rule::enum(TicketPriority::class)],
            // The transaction the ticket is about, from the app's receipt:
            // only one of the customer's own, so a stranger's reference
            // cannot be attached to make support look it up.
            'transaction_ref' => ['nullable', 'string', 'max:64', Rule::exists('transactions', 'trx_id')->where('user_id', (int) $this->user()?->id)],
            // The same list and size the uploader's own guard enforces, so a
            // file this accepts is never refused afterwards.
            'attachment' => ['nullable', 'file', 'mimes:'.implode(',', MediaSettings::customerSafe(MediaSettings::fileGuardExtensions())), 'max:'.MediaSettings::effectiveMaxUploadSizeKb()],
        ];
    }
}
