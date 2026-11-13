<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Wallet;

/**
 * Lets a request name the caller's wallet by its uuid as well as its id.
 *
 * The web forms post the numeric id from a select. The mobile API never hands
 * a numeric wallet id out - WalletResource carries the uuid, and every other
 * API endpoint takes the uuid - so an app driving a request that validates
 * `Rule::exists('wallets', 'id')` had nothing it could send.
 *
 * A uuid is swapped for the id of a wallet THE CALLER OWNS, before the rules
 * run, so the existing `exists ... where user_id` rule, the currency lookup
 * and the service all keep seeing the id they always saw. A uuid that is not
 * one of the caller's wallets is left exactly as sent, and that rule refuses
 * it - owning the wallet is still decided in one place, not two.
 */
trait ResolvesOwnWalletUuid
{
    protected function prepareForValidation(): void
    {
        $this->resolveOwnWalletUuid();
    }

    protected function resolveOwnWalletUuid(string $field = 'wallet_id'): void
    {
        $value  = $this->input($field);
        $userId = $this->user()?->getAuthIdentifier();

        // A digits-only value is not thereby an id: installations mint
        // wallet uuids like "596071003", and treating those as ids sent
        // every app cash-out to "the selected wallet id is invalid". The
        // caller's own uuid wins; only when none matches is it left as an id.
        if (! is_string($value) || $value === '' || $userId === null) {
            return;
        }

        $walletId = Wallet::query()
            ->where('user_id', $userId)
            ->where('uuid', $value)
            ->value('id');

        if ($walletId !== null) {
            $this->merge([$field => (int) $walletId]);
        }
    }
}
