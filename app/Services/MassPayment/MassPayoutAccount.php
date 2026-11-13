<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Enums\MassPayment\MassPaymentIssue;
use App\Models\WithdrawMethod;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use App\Services\Payment\Payout\PayoutRecipient;
use App\Support\WithdrawFieldNormalizer;
use InvalidArgumentException;
use Throwable;

/**
 * The external account one mass payout line is sent to: its details read
 * against the withdraw method's own field schema, checked offline by the
 * method's driver, masked for display and hashed for duplicate detection.
 *
 * Nothing here calls a provider. The driver's validateRecipient() is the
 * offline check every driver promises; a line it refuses is
 * `account_field_invalid`, never something sent to find out.
 *
 * The details themselves are only ever held in memory here and written to the
 * row's encrypted `payout_account`. The label shown in previews, the report
 * and the admin screens is the account name with the identifier masked to its
 * last four characters, and the hash is keyed with APP_KEY, so neither can be
 * turned back into the account.
 */
class MassPayoutAccount
{
    /** The field names that hold the account holder's name, in the order the drivers read them. */
    public const NAME_FIELDS = ['account_name', 'name', 'recipient_name'];

    /** The field names that identify the destination, in the order the label prefers them. */
    public const IDENTIFIER_FIELDS = ['account_number', 'phone_number', 'mobile_number', 'iban', 'stripe_account_id', 'email'];

    private const TEXT_MAX_LENGTH = 255;

    private const TEXTAREA_MAX_LENGTH = 2000;

    private const LABEL_MAX_LENGTH = 191;

    private const VISIBLE_TAIL = 4;

    public function __construct(private readonly PayoutGatewayFactory $gateways) {}

    /**
     * The method's account fields a line can carry: every schema field but a
     * file, which an eligible method never has anyway.
     *
     * @return list<array{name: string, type: string, validation: string, label?: mixed}>
     */
    public function fields(WithdrawMethod $method): array
    {
        return array_values(array_filter(
            WithdrawFieldNormalizer::normalize($method->fields),
            fn (array $field): bool => $field['type'] !== 'file',
        ));
    }

    /**
     * @return list<string>
     */
    public function fieldNames(WithdrawMethod $method): array
    {
        return array_map(fn (array $field): string => (string) $field['name'], $this->fields($method));
    }

    /**
     * @return list<string>
     */
    public function requiredFieldNames(WithdrawMethod $method): array
    {
        return array_values(array_map(
            fn (array $field): string => (string) $field['name'],
            array_filter($this->fields($method), fn (array $field): bool => $field['validation'] === 'required'),
        ));
    }

    /**
     * The details of one line, kept to the method's own fields with blanks
     * dropped, and why they cannot be paid, if they cannot.
     *
     * A required field left blank is `account_field_missing`; a value over
     * the field's length, or one the driver refuses offline, is
     * `account_field_invalid`. Values are kept exactly as typed (trimmed);
     * nothing is reformatted into a different account. An over-long value is
     * kept one character past the limit, so checking the stored line again at
     * confirm refuses it again instead of passing a shortened account.
     *
     * @param  array<string, string|null>                                          $typed
     * @return array{account: array<string, string>, issue: MassPaymentIssue|null}
     */
    public function evaluate(WithdrawMethod $method, array $typed): array
    {
        $account = [];
        $issue   = null;

        foreach ($this->fields($method) as $field) {
            $name  = (string) $field['name'];
            $value = $typed[$name] ?? null;
            $value = $value === null ? '' : trim($value);

            if ($value === '') {
                if ($field['validation'] === 'required') {
                    $issue ??= MassPaymentIssue::AccountFieldMissing;
                }

                continue;
            }

            $max = $field['type'] === 'textarea' ? self::TEXTAREA_MAX_LENGTH : self::TEXT_MAX_LENGTH;

            if (mb_strlen($value) > $max || preg_match('/\p{Cc}/u', $value) === 1) {
                $issue ??= MassPaymentIssue::AccountFieldInvalid;
                $value = mb_substr($value, 0, $max + 1);
            }

            $account[$name] = $value;
        }

        return ['account' => $account, 'issue' => $issue ?? $this->driverIssue($method, $account)];
    }

    /**
     * The details as the driver reads them.
     *
     * @param array<string, string> $account
     */
    public function recipient(array $account): PayoutRecipient
    {
        $details = new PayoutRecipient(null, '', $account);

        return new PayoutRecipient(
            $details->detail(['country']),
            (string) $details->detail(self::NAME_FIELDS),
            $account,
        );
    }

    /**
     * What a person reads for this account: the holder's name and the
     * identifier masked to its last four characters, e.g.
     * "Ada Obi · ••••6789". Never the whole identifier.
     *
     * @param array<string, string> $account
     */
    public function label(array $account): string
    {
        $details    = new PayoutRecipient(null, '', $account);
        $name       = $details->detail(self::NAME_FIELDS);
        $identifier = $details->detail(self::IDENTIFIER_FIELDS) ?? $this->firstOtherValue($account);

        $parts = array_values(array_filter([
            $name,
            $identifier === null ? null : $this->mask($identifier),
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        $label = $parts === [] ? __('Account details missing') : implode(' · ', $parts);

        return mb_substr($label, 0, self::LABEL_MAX_LENGTH);
    }

    /**
     * A keyed hash that is equal for two lines paying the same destination.
     *
     * The holder's name is left out, since a name typed differently still
     * pays the same account, and every other value is compared lower-cased
     * with spaces and hyphens removed. Keyed with APP_KEY so the hash cannot
     * be matched against a list of guessed account numbers.
     *
     * @param array<string, string> $account
     */
    public function hash(array $account): string
    {
        $canonical = [];

        foreach ($account as $name => $value) {
            if (in_array($name, self::NAME_FIELDS, true)) {
                continue;
            }

            $canonical[$name] = (string) preg_replace('/[\s\-]+/u', '', mb_strtolower($value));
        }

        ksort($canonical);

        return hash_hmac('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (string) config('app.key'));
    }

    /**
     * The driver's offline check: details it refuses are
     * `account_field_invalid`, and a driver that cannot even be built makes
     * the method `method_unavailable` for the line.
     *
     * @param array<string, string> $account
     */
    private function driverIssue(WithdrawMethod $method, array $account): ?MassPaymentIssue
    {
        try {
            $gateway = $this->gateways->massPayoutGateway((string) $method->mass_payout_driver);
        } catch (Throwable) {
            return MassPaymentIssue::MethodUnavailable;
        }

        try {
            $gateway->validateRecipient($this->recipient($account));
        } catch (InvalidArgumentException) {
            return MassPaymentIssue::AccountFieldInvalid;
        }

        return null;
    }

    /** @param array<string, string> $account */
    private function firstOtherValue(array $account): ?string
    {
        foreach ($account as $name => $value) {
            if (! in_array($name, self::NAME_FIELDS, true) && $name !== 'country') {
                return $value;
            }
        }

        return null;
    }

    private function mask(string $identifier): string
    {
        $length = mb_strlen($identifier);

        return $length <= self::VISIBLE_TAIL
            ? str_repeat('•', 4)
            : str_repeat('•', 4).mb_substr($identifier, $length - self::VISIBLE_TAIL);
    }
}
