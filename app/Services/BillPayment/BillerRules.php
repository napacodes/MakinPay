<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Enums\BillPayment\BillerAmountType;
use App\Models\Biller;
use App\Models\BillerItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * What a biller accepts: the customer number and, for a package biller, the
 * item. Pure — it reads the biller and its items and writes nothing.
 *
 * A biller's `customer_number_pattern` is a regex *body* an admin typed, with
 * no delimiters and no anchors. wrap() is the one place it becomes a full
 * pattern, anchored at both ends so a partial match never passes. A pattern
 * that cannot be evaluated — it no longer compiles, or a number drives it into
 * the backtrack limit — refuses the number rather than waving it through.
 */
class BillerRules
{
    /** The `bill_payments.customer_number` column is string(64). */
    public const MAX_CUSTOMER_NUMBER_LENGTH = 64;

    /**
     * Control and invisible format characters, and the angle brackets, are
     * refused whatever the biller's pattern says. Everything else is left to
     * the pattern, so a biller whose numbers carry `_` or `#` can still take
     * them.
     */
    private const FORBIDDEN_CHARACTERS = '/[\p{Cc}\p{Cf}<>]/u';

    /**
     * Trim the number, then strip every whitespace character inside it —
     * "1234 5678" and " 12345678 " are the same account.
     */
    public function normalizeCustomerNumber(string $raw): string
    {
        $trimmed = trim($raw);

        // The /u pass also strips Unicode spaces (a pasted no-break space). It
        // gives up on bytes that are not UTF-8, and assertCustomerNumber()
        // refuses those anyway, so a plain ASCII pass is enough for them.
        return preg_replace('/\s+/u', '', $trimmed)
            ?? (string) preg_replace('/\s+/', '', $trimmed);
    }

    /**
     * Refuse a normalised customer number the biller would not accept.
     *
     * @throws ValidationException on `customer_number`
     */
    public function assertCustomerNumber(Biller $biller, string $number): void
    {
        $label = (string) ($biller->customer_number_label ?: __('Account number'));

        if ($number === '') {
            throw $this->invalidNumber(__('Enter the :label.', ['label' => $label]));
        }

        if (! mb_check_encoding($number, 'UTF-8') || preg_match(self::FORBIDDEN_CHARACTERS, $number) === 1) {
            throw $this->invalidNumber(__('Enter a valid :label.', ['label' => $label]));
        }

        if (mb_strlen($number) > self::MAX_CUSTOMER_NUMBER_LENGTH) {
            throw $this->invalidNumber(__('The :label may not be longer than :max characters.', [
                'label' => $label,
                'max'   => self::MAX_CUSTOMER_NUMBER_LENGTH,
            ]));
        }

        $pattern = $biller->customer_number_pattern;

        if ($pattern === null || trim($pattern) === '') {
            return;
        }

        $matched = self::compiles($pattern) ? @preg_match(self::wrap($pattern), $number) : false;

        if ($matched === false) {
            // Ids only: neither the customer's number nor the pattern belongs in a log.
            Log::warning('Bill payment customer number pattern could not be evaluated', ['biller_id' => $biller->id]);

            throw $this->invalidNumber(__('This :label cannot be checked right now. Try again later.', ['label' => $label]));
        }

        if ($matched !== 1) {
            $example = $biller->customer_number_example;

            throw $this->invalidNumber($example !== null && $example !== ''
                ? __('Enter a valid :label, e.g. :example.', ['label' => $label, 'example' => $example])
                : __('Enter a valid :label.', ['label' => $label]));
        }
    }

    /**
     * The item a payment is for. A fixed biller needs one of its own active
     * items; an open biller takes none, so any item id is ignored.
     *
     * @throws ValidationException on `biller_item_id`
     */
    public function resolveItem(Biller $biller, ?int $itemId): ?BillerItem
    {
        if ($biller->amount_type !== BillerAmountType::Fixed) {
            return null;
        }

        if ($itemId === null) {
            throw ValidationException::withMessages(['biller_item_id' => __('Choose a package.')]);
        }

        $item = $biller->items()->whereKey($itemId)->where('status', true)->first();

        if ($item === null) {
            throw ValidationException::withMessages(['biller_item_id' => __('Choose one of the packages this biller offers.')]);
        }

        return $item;
    }

    /**
     * Turn a stored pattern body into a full pattern that must match the
     * whole number.
     *
     * The body goes inside a non-capturing group so an alternation such as
     * `A\d+|B\d+` is anchored as a whole, and `~` (the delimiter) is escaped
     * unless the admin already escaped it.
     */
    public static function wrap(string $pattern): string
    {
        return '~\A(?:'.self::escapeDelimiter($pattern).')\z~u';
    }

    /**
     * Whether a pattern body can be used at all.
     *
     * The body must compile on its own as well as wrapped: `a)|(b` compiles
     * once wrapped, but only by closing the group early, which would leave
     * the second branch unanchored at the start.
     */
    public static function compiles(?string $pattern): bool
    {
        if ($pattern === null) {
            return false;
        }

        if (@preg_match('~'.self::escapeDelimiter($pattern).'~u', '') === false) {
            return false;
        }

        return @preg_match(self::wrap($pattern), '') !== false;
    }

    /**
     * Escape every `~` not already preceded by an odd run of backslashes.
     */
    private static function escapeDelimiter(string $pattern): string
    {
        return (string) preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)~/', '$1\\~', $pattern);
    }

    private function invalidNumber(string $message): ValidationException
    {
        return ValidationException::withMessages(['customer_number' => $message]);
    }
}
