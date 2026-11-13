<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\User;
use App\Models\WithdrawAccount;
use App\Models\WithdrawMethod;
use App\Support\WithdrawFieldNormalizer;
use App\Traits\FileManageTrait;
use Illuminate\Http\UploadedFile;

/**
 * Saving where a customer's money goes.
 *
 * Lifted out of WithdrawAccountController so the mobile API stores a payout
 * destination in exactly the shape the web form does. That shape is not a
 * detail: it is what the processor is eventually handed, and the builder is
 * what decides that a field the client invented is not part of it.
 *
 * The credentials are always walked from the METHOD's schema, never from the
 * request. A request can only supply a value for a field the administrator
 * declared; anything else it sends is dropped on the floor without comment,
 * which is the behaviour WithdrawAccountWebCharacterizationTest pins.
 *
 * Values and files are taken as plain arrays rather than as a Request, so a
 * caller that has neither a form nor a session - a phone - can use this
 * without pretending to be a browser.
 */
final class WithdrawAccountService
{
    use FileManageTrait;

    /**
     * @param array<string, mixed>        $values
     * @param array<string, UploadedFile> $files
     */
    public function create(User $user, WithdrawMethod $method, string $name, array $values, array $files = []): WithdrawAccount
    {
        return WithdrawAccount::create([
            'user_id'            => $user->id,
            'withdraw_method_id' => $method->id,
            'name'               => $name,
            'credentials'        => $this->buildCredentials($method->fields, $values, $files),
        ]);
    }

    /**
     * Editing keeps the schema it was created with - the method's own may have
     * changed since - and keeps an existing upload when no replacement is
     * sent, which is why this is not `create()` with a different target.
     *
     * @param array<string, mixed>        $values
     * @param array<string, UploadedFile> $files
     */
    public function update(WithdrawAccount $account, string $name, array $values, array $files = []): WithdrawAccount
    {
        $account->update([
            'name'        => $name,
            'credentials' => $this->buildCredentials($account->credentials, $values, $files, true),
        ]);

        return $account;
    }

    /**
     * Short preview of the payout destination (e.g. a crypto wallet address)
     * so the customer can double-check where funds are going before
     * submitting.
     *
     * Masked rather than shown in full: the value is enough to recognise an
     * account and not enough to be worth intercepting.
     */
    public function maskedDestination(WithdrawAccount $account): ?string
    {
        $values  = WithdrawFieldNormalizer::values($account->credentials);
        $address = $values['wallet_address'] ?? $values['account_number'] ?? null;

        if (! is_string($address) || trim($address) === '') {
            return null;
        }

        $address = trim($address);

        return strlen($address) > 16
            ? substr($address, 0, 6).'…'.substr($address, -6)
            : $address;
    }

    /**
     * @param  array<string, mixed>             $values
     * @param  array<string, UploadedFile>      $files
     * @return array<int, array<string, mixed>>
     */
    public function buildCredentials(mixed $fieldSchema, array $values, array $files = [], bool $preserveExistingFiles = false): array
    {
        return collect(WithdrawFieldNormalizer::normalize($fieldSchema))
            ->map(function (array $field) use ($values, $files, $preserveExistingFiles) {
                $name = (string) $field['name'];

                if (($field['type'] ?? null) === 'file' && isset($files[$name])) {
                    $field['value'] = $this->uploadCustomerImage(
                        $files[$name],
                        $preserveExistingFiles ? ($field['value'] ?? null) : null
                    );
                } elseif (array_key_exists($name, $values)) {
                    $field['value'] = $values[$name];
                } elseif (! array_key_exists('value', $field)) {
                    $field['value'] = null;
                }

                return $field;
            })
            ->toArray();
    }
}
