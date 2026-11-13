<?php

namespace App\Services;

use App\Exceptions\NotifyErrorException;
use App\Models\Currency;
use App\Models\FeatureAccessRule;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Wallet as WalletModel;
use App\Support\DecimalMoney;
use App\Support\FeeCalculator;
use App\Support\HomeCountry;
use App\Support\Money;
use App\Support\PhoneIdentifier;
use App\Support\RateGuard;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public const IDENTIFIER_EMAIL = 'email';

    public const IDENTIFIER_MOBILE = 'mobile';

    public const IDENTIFIER_WALLET_ID = 'wallet_id';

    private const DEFAULT_WALLET_ID_PREFIX = 'DK';

    /**
     * Brand prefix stamped onto newly generated wallet ids, or an empty string
     * when the admin has switched it off. Set under General Settings → Wallet
     * IDs; the WALLET_ID_PREFIX env value is only the default for a site that
     * has not saved one. Lookup and masking stay prefix-agnostic so ids issued
     * under any previous prefix — or none — keep resolving.
     */
    private function walletIdPrefix(): string
    {
        if (! (bool) setting('wallet_id_prefix_enabled', true)) {
            return '';
        }

        $fallback = strtoupper((string) config('wallet.id_prefix', self::DEFAULT_WALLET_ID_PREFIX));
        $fallback = preg_match('/^[A-Z]{2,6}$/', $fallback) === 1 ? $fallback : self::DEFAULT_WALLET_ID_PREFIX;
        $prefix   = strtoupper(trim((string) setting('wallet_id_prefix', $fallback)));

        return preg_match('/^[A-Z]{2,6}$/', $prefix) === 1 ? $prefix : $fallback;
    }

    /**
     * @throws NotifyErrorException
     */
    public function getDefaultWallet(User $user)
    {
        $defaultCurrency = Currency::getDefault();

        if (! $defaultCurrency) {
            throw new NotifyErrorException('Default currency not found.');
        }

        return $user->wallets()->where('currency_id', $defaultCurrency->id)->first();
    }

    /**
     * Create a default wallet for a user if they don't have one with the default currency.
     */
    public function createDefaultWalletForUser(User $user): ?Wallet
    {
        // Fetch all auto wallet currencies
        $currencies = Currency::autoWallets();

        foreach ($currencies as $currency) {
            // Check if the user already has a wallet with this currency
            if (! $this->userHasWalletWithCurrency($user, $currency->id)) {
                $this->createWallet($user, $currency);
            }
        }

        return null; // Return null if no new wallet was created
    }

    /**
     * Create a wallet for a specified currency if the user doesn't already have one.
     */
    public function createWalletForCurrency(User $user, int $currencyId): ?Wallet
    {
        $currency = Currency::query()->findOrFail($currencyId);

        $this->assertCurrencyOperational($currency);

        return ! $this->userHasWalletWithCurrency($user, $currencyId)
            ? $this->createWallet($user, $currency)
            : null;
    }

    /**
     * @throws Exception
     */
    public function subtractMoneyByWalletUuid($walletUuid, $amount): WalletModel
    {
        try {
            $wallet = $this->getWalletByUniqueId($walletUuid);
        } catch (ModelNotFoundException $e) {
            throw new NotifyErrorException("Wallet with UUID {$walletUuid} not found.");
        }

        return $this->subtractMoney($wallet, $amount);
    }

    /**
     * Retrieve a wallet by its UniqueWalletId.
     *
     * @throws Exception
     */
    public function getWalletByUniqueId(string $uuid): Wallet
    {
        $candidates = $this->walletIdLookupCandidates($uuid);
        $wallet     = Wallet::whereIn('uuid', $candidates)
            ->get()
            ->sortBy(fn (Wallet $match): int|false => array_search($match->uuid, $candidates, true))
            ->first();

        if (! $wallet) {
            throw new NotifyErrorException(__("Wallet with ID $uuid not found."));
        }

        return $wallet;
    }

    /**
     * Every wallet a typed wallet id could stand for, best-ranked first.
     *
     * getWalletByUniqueId() keeps only the first of these. A caller that must
     * not guess between two stored spellings (mass payment) reads the whole
     * list and treats more than one match as ambiguous.
     *
     * @return Collection<int, Wallet>
     */
    public function walletsMatchingUniqueId(string $id): Collection
    {
        $candidates = $this->walletIdLookupCandidates($id);

        return Wallet::whereIn('uuid', $candidates)
            ->get()
            ->sortBy(fn (Wallet $match): int|false => array_search($match->uuid, $candidates, true))
            ->values();
    }

    /**
     * walletsMatchingUniqueId() for many typed ids at once, in one query.
     *
     * A mass payment batch resolves hundreds of wallet ids, and one query
     * per id is the N+1 the resolver must not have. Each id keeps its own
     * best-first list, built from the same candidates the single lookup
     * uses, so the two can never disagree about what an id matches.
     *
     * Stored ids are compared without regard to case, as MySQL's collation
     * already does, so SQLite and MySQL give the same answer.
     *
     * @param  array<int, string>                     $ids
     * @param  array<int, string>                     $with relations to eager load on the matches
     * @return array<string, Collection<int, Wallet>> keyed by the id as given
     */
    public function walletsMatchingUniqueIds(array $ids, array $with = []): array
    {
        $candidatesById = [];

        foreach ($ids as $id) {
            $candidatesById[(string) $id] ??= $this->walletIdLookupCandidates((string) $id);
        }

        if ($candidatesById === []) {
            return [];
        }

        $found = Wallet::query()
            ->with($with)
            ->whereIn('uuid', array_values(array_unique(array_merge(...array_values($candidatesById)))))
            ->get()
            ->groupBy(fn (Wallet $wallet): string => strtoupper((string) $wallet->uuid));

        $matches = [];

        foreach ($candidatesById as $id => $candidates) {
            $list = new Collection;

            foreach ($candidates as $candidate) {
                foreach ($found->get(strtoupper($candidate), []) as $wallet) {
                    if (! $list->contains('id', $wallet->id)) {
                        $list->push($wallet);
                    }
                }
            }

            $matches[(string) $id] = $list;
        }

        return $matches;
    }

    /**
     * Subtract money from a wallet.
     *
     * @throws Exception
     */
    /**
     * `string|int|float`, matching creditSettlement().
     *
     * It took a float while its credit sibling already took a string, and
     * that asymmetry was the only reason the agent path had to narrow an
     * exact decimal to a double before debiting. shiftBalance() below has
     * always accepted a string and normalises it with bcmath, so nothing
     * underneath needed changing - the signature here was the whole
     * constraint.
     */
    public function subtractMoney(Wallet $wallet, string|int|float $amount): Wallet
    {
        // Compared as a decimal, because a string amount cannot be compared
        // to zero with `<=` without being coerced back to the double this is
        // here to avoid.
        if (DecimalMoney::compare($amount, '0') <= 0) {
            throw new NotifyErrorException('Amount must be greater than zero.');
        }

        $debited = $this->shiftBalance($wallet, $amount, debit: true);

        if ($debited !== 1) {
            throw new NotifyErrorException('Insufficient balance in wallet.');
        }

        return $wallet->refresh();
    }

    /**
     * @throws Exception
     */
    public function addMoneyByWalletUuid($walletUuid, $amount): WalletModel
    {
        try {
            $wallet = $this->getWalletByUniqueId($walletUuid);
        } catch (ModelNotFoundException $e) {
            throw new NotifyErrorException("Wallet with UUID {$walletUuid} not found.");
        }

        return $this->addMoney($wallet, $amount);
    }

    /**
     * Credit a previously-created financial obligation by wallet UUID.
     *
     * Settlement credits intentionally remain available after a wallet or its
     * currency is disabled. They may only be used for money already owed, such
     * as refunds, reversals, escrow releases, or approved payouts.
     *
     * @throws Exception
     */
    public function creditSettlementByWalletUuid($walletUuid, $amount): WalletModel
    {
        try {
            $wallet = $this->getWalletByUniqueId($walletUuid);
        } catch (ModelNotFoundException $e) {
            throw new NotifyErrorException("Wallet with UUID {$walletUuid} not found.");
        }

        return $this->creditSettlement($wallet, $amount);
    }

    /**
     * Add money to a wallet.
     *
     * @throws Exception
     */
    public function addMoney(Wallet $wallet, string|int|float $amount): Wallet
    {
        $this->assertOperational($wallet);

        return $this->creditBalance($wallet, $amount);
    }

    /**
     * Credit a refund, reversal, escrow release, or other existing obligation.
     *
     * This is the only balance-credit exception to the operational wallet and
     * currency guards. Callers must establish the obligation from an existing,
     * immutable transaction or domain record before invoking it.
     *
     * Accepts a decimal string as well as a float, so a caller holding an
     * exact `decimal(24,8)` value can hand it over without a `(float)` cast on
     * the way in. That cast was lossless for everything a double represents
     * exactly, which is not the whole of the column's range. Widening only —
     * every existing float and int caller still satisfies the union, and the
     * value is normalized through bcmath either way.
     *
     * @throws NotifyErrorException
     */
    public function creditSettlement(Wallet $wallet, string|int|float $amount): Wallet
    {
        return $this->creditBalance($wallet, $amount);
    }

    /**
     * @throws NotifyErrorException
     */
    private function creditBalance(Wallet $wallet, string|int|float $amount): Wallet
    {
        if (! $wallet->exists) {
            throw new NotifyErrorException('The settlement wallet is unavailable.');
        }

        if ($amount <= 0) {
            throw new NotifyErrorException('Amount must be greater than zero.');
        }

        $this->shiftBalance($wallet, $amount, debit: false);

        return $wallet->refresh();
    }

    /**
     * Move a wallet's balance by one atomic statement.
     *
     * A debit carries its own precondition — `balance >= amount` lives in the
     * WHERE clause, so the row is read and written under the same write lock
     * and the caller learns it failed from an affected-row count of zero.
     *
     * This replaces a read-then-write pair:
     *
     *     if ($wallet->balance < $amount) { throw; }   // no lock held
     *     $wallet->decrement('balance', $amount);
     *
     * Nothing held a lock between those two lines, and under MySQL's default
     * REPEATABLE READ a plain SELECT takes none — so two requests arriving
     * together both read 100, both cleared a check for 60, and both
     * decremented, leaving the wallet at -20. `wallets.balance` is a signed
     * column, so the database stored it without complaint. Worse, callers such
     * as the gift-card flow had already read the balance *before* opening
     * their transaction, so the figure being checked could be arbitrarily
     * stale.
     *
     * Marketplace, P2P and Wallet Earn were written this way from the start;
     * this brings the shared service onto the same idiom rather than inventing
     * a second one.
     *
     * The delta is normalized through bcmath first, and interpolated rather
     * than bound because the value is on the right-hand side of an expression.
     * That is safe precisely because it came out of bcmath: a plain decimal
     * string, never exponent notation. Passing the float directly would let
     * PHP render 0.00000001 as "1.0E-8", which MySQL then reads as a double
     * and rounds — the exact loss the money layer exists to prevent. The
     * comparison on the left is a normal binding.
     *
     * @return int rows affected: 1 on success, 0 when a debit was refused
     */
    private function shiftBalance(Wallet|int|null $wallet, string|int|float $amount, bool $debit): int
    {
        $key = $wallet instanceof Wallet ? $wallet->getKey() : $wallet;

        if ($key === null) {
            return 0;
        }

        $delta = DecimalMoney::normalize($amount, DecimalMoney::CALCULATION_SCALE);

        $query = WalletModel::query()->whereKey($key);

        if ($debit) {
            $query->where('balance', '>=', $delta);
        }

        return $query->update([
            'balance' => DB::raw('balance '.($debit ? '-' : '+').' '.$delta),
        ]);
    }

    /**
     * Atomically debit a wallet, reporting the outcome instead of throwing.
     *
     * For callers that own their own rejection message — an escrow hold, a
     * vendor clawback, a marketplace purchase all fail for different reasons
     * and say so differently. {@see subtractMoney()} is the throwing variant
     * for everything else.
     *
     * The modules using this pattern already had the atomicity right; routing
     * them through here is about the delta, which they were handing to
     * `decrement()` as a float. Laravel interpolates that into raw SQL, so a
     * satoshi arrived as `balance - 1.0E-8` — an approximate literal, applied
     * to a decimal(20,8) column carrying more significant digits than a double
     * can hold.
     *
     * Accepts a wallet or its id; a null id debits nothing and reports 0,
     * which is what a caller resolving a wallet that does not exist should
     * see.
     *
     * @return int rows affected: 1 when debited, 0 when the balance was short
     */
    public function tryDebit(Wallet|int|null $wallet, string|int|float $amount): int
    {
        if (DecimalMoney::compare($amount, 0) <= 0) {
            return 0;
        }

        return $this->shiftBalance($wallet, $amount, debit: true);
    }

    public function getWalletByUserId(int $userId, string $currencyCode): ?Wallet
    {
        return Wallet::where('user_id', $userId)
            ->whereHas('currency', function ($query) use ($currencyCode) {
                $query->where('code', $currencyCode);
            })
            ->first();
    }

    public function getDefaultWalletByUserId(int $userId): ?Wallet
    {
        $currency = Currency::getDefault();

        return self::getWalletByUserId($userId, $currency->code);
    }

    /**
     * Compared as decimals: the balance is a decimal(20,8) string and the
     * amount may carry more digits than a double holds, so `>=` on the two
     * - which coerces both to doubles - could pass a short balance or refuse
     * a sufficient one at the last digit (CR1).
     */
    public function isWalletBalanceSufficient($walletUuid, $amount): bool
    {
        $myWallet = $this->getWalletByUniqueId($walletUuid);

        return DecimalMoney::compare((string) $myWallet->balance, $amount) >= 0;
    }

    /**
     * Get a wallet's balance.
     */
    public function getWalletBalance(Wallet $wallet): float
    {
        return $wallet->balance;
    }

    /**
     * Retrieves a payer's wallet, given their identifier and currency ID.
     *
     * @throws Exception
     */
    public function getWalletByUserEmailOrWalletUid($emailOrWalletUid, $currencyId): ?WalletModel
    {
        if (filter_var($emailOrWalletUid, FILTER_VALIDATE_EMAIL)) {
            $recipientUser = User::where('email', $emailOrWalletUid)->first();

            return $recipientUser ? WalletModel::where('user_id', $recipientUser->id)->where('currency_id', $currencyId)->first() : null;
        }

        try {
            return self::getWalletByUniqueId((string) $emailOrWalletUid);
        } catch (NotifyErrorException) {
            return null;
        }
    }

    /**
     * `@username` names a person rather than an address - a contact picked
     * in the app, whose email and phone the app only ever sees masked. It is
     * turned into whichever of their identifiers the rule allows before the
     * lookup, so the administrator's identifier policy still decides: their
     * wallet in this currency, else their email, else their phone. Anything
     * else passes through untouched.
     *
     * @param list<string> $allowedMethods
     */
    private function expandRecipientAlias(string $identifier, int $currencyId, array $allowedMethods): string
    {
        if (! str_starts_with($identifier, '@') || strlen($identifier) < 2) {
            return $identifier;
        }

        $user = User::query()->where('username', substr($identifier, 1))->first();

        if ($user === null) {
            return $identifier;
        }

        // A wallet ID that happens to be all digits reads as a phone number to
        // the detector, so each candidate is offered only when it would be
        // read as what it is - otherwise the lookup would run on the wrong
        // table and find nobody.
        $uuid = WalletModel::query()->where('user_id', $user->id)->where('currency_id', $currencyId)->value('uuid');

        $candidates = [
            self::IDENTIFIER_WALLET_ID => is_string($uuid) && $uuid !== '' ? $uuid : null,
            self::IDENTIFIER_EMAIL     => filled($user->email) ? (string) $user->email : null,
            self::IDENTIFIER_MOBILE    => filled($user->phone) ? (string) $user->phone : null,
        ];

        foreach ($candidates as $type => $candidate) {
            if ($candidate !== null && in_array($type, $allowedMethods, true) && $this->detectIdentifierType($candidate) === $type) {
                return $candidate;
            }
        }

        return $identifier;
    }

    /**
     * Detect whether an identifier looks like an email, mobile number, or wallet ID.
     */
    public function detectIdentifierType(string $identifier): string
    {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return self::IDENTIFIER_EMAIL;
        }

        $stripped = preg_replace('/[\s\-\(\)]/', '', $identifier);

        if (preg_match('/^\+?[0-9]{8,15}$/', (string) $stripped)) {
            return self::IDENTIFIER_MOBILE;
        }

        return self::IDENTIFIER_WALLET_ID;
    }

    /**
     * Normalize a phone number input (strip whitespace, dashes, and parentheses).
     */
    public function normalizePhone(string $phone): string
    {
        return (string) preg_replace('/[\s\-\(\)]/', '', trim($phone));
    }

    /**
     * Look up a recipient wallet by email.
     */
    public function getWalletByEmail(string $email, int $currencyId): ?WalletModel
    {
        $user = User::where('email', $email)->first();

        return $user ? WalletModel::where('user_id', $user->id)->where('currency_id', $currencyId)->first() : null;
    }

    /**
     * Find a user by phone number, through lookupUserByPhone().
     * generateUniqueWalletId() never issues an id that detectIdentifierType()
     * reads as a phone number, prefix or no prefix, so a phone input without
     * "+" is never ambiguous with a wallet ID.
     *
     * @throws NotifyErrorException when the number could mean more than one account
     */
    public function findUserByPhone(string $phone): ?User
    {
        $found = $this->lookupUserByPhone($phone);

        if ($found === false) {
            throw new NotifyErrorException(__('This number matches more than one account. Use the recipient\'s email or wallet ID instead.'));
        }

        return $found;
    }

    /**
     * The one account a typed number names, false when it could name more
     * than one, null when it names none (PH3).
     *
     * Read exactly as sign-in reads it - PhoneIdentifier, with the home
     * country - so a number that signs a customer in also finds them as a
     * recipient. It used to toggle a '+' on and off and take whichever row
     * the database returned first: a local 01712345678 then matched a
     * stranger's malformed +01712345678, in no particular order, system
     * accounts included. Now every reading is looked up, a stored number
     * without its '+' is still found, system accounts are never found, and
     * two accounts across the readings is no answer at all - there is no
     * password here to tell them apart, and guessing pays a stranger.
     */
    public function lookupUserByPhone(string $phone): User|false|null
    {
        $candidates = PhoneIdentifier::candidates($this->normalizePhone($phone), HomeCountry::code());

        if ($candidates === []) {
            return null;
        }

        $stored = array_values(array_unique([...$candidates, ...array_map(fn (string $phone): string => ltrim($phone, '+'), $candidates)]));

        $users = User::query()
            ->whereIn('phone', $stored)
            ->whereNull('system_account_type')
            ->limit(3)
            ->get()
            ->unique('id');

        return match ($users->count()) {
            0       => null,
            1       => $users->first(),
            default => false,
        };
    }

    /**
     * Look up a recipient wallet by mobile number.
     */
    public function getWalletByPhone(string $phone, int $currencyId): ?WalletModel
    {
        $user = $this->findUserByPhone($phone);

        return $user ? WalletModel::where('user_id', $user->id)->where('currency_id', $currencyId)->first() : null;
    }

    /**
     * Resolve a recipient wallet by an arbitrary identifier (email / mobile / wallet ID),
     * subject to the methods allowed by the caller (e.g. feature access rules).
     *
     * @param array<int, string> $allowedMethods
     *
     * @throws Exception
     */
    public function getWalletByIdentifier(string $identifier, int $currencyId, array $allowedMethods = [
        self::IDENTIFIER_EMAIL,
        self::IDENTIFIER_MOBILE,
        self::IDENTIFIER_WALLET_ID,
    ]): ?WalletModel
    {
        $type = $this->detectIdentifierType($identifier);

        if (! in_array($type, $allowedMethods, true)) {
            return null;
        }

        return match ($type) {
            self::IDENTIFIER_EMAIL     => $this->getWalletByEmail($identifier, $currencyId),
            self::IDENTIFIER_MOBILE    => $this->getWalletByPhone($this->normalizePhone($identifier), $currencyId),
            self::IDENTIFIER_WALLET_ID => $this->safeGetWalletByUniqueId($identifier),
            default                    => null,
        };
    }

    private function safeGetWalletByUniqueId(string $uuid): ?WalletModel
    {
        try {
            return $this->getWalletByUniqueId($uuid);
        } catch (NotifyErrorException) {
            return null;
        }
    }

    /**
     * Resolve a recipient wallet for a feature/panel, honoring the admin-configured
     * identifier methods and verification requirements on the access rule.
     *
     * @return array{wallet: WalletModel|null, error: string|null}
     *
     * @throws Exception
     */
    public function resolveRecipientWallet(string $identifier, int $currencyId, ?FeatureAccessRule $rule): array
    {
        $allowedMethods = $rule?->allowedIdentifierMethods() ?? [
            self::IDENTIFIER_EMAIL,
            self::IDENTIFIER_MOBILE,
            self::IDENTIFIER_WALLET_ID,
        ];

        if ($allowedMethods === []) {
            return ['wallet' => null, 'error' => __('No recipient identifier methods are enabled for this feature.')];
        }

        $identifier = $this->expandRecipientAlias($identifier, $currencyId, $allowedMethods);
        $type       = $this->detectIdentifierType($identifier);

        if (! in_array($type, $allowedMethods, true)) {
            return ['wallet' => null, 'error' => __('This identifier method is not allowed. Please use a permitted lookup method.')];
        }

        $wallet = $this->getWalletByIdentifier($identifier, $currencyId, $allowedMethods);

        if (! $wallet) {
            return ['wallet' => null, 'error' => __('Recipient wallet not found or invalid input provided.')];
        }

        $user = $wallet->user;

        if ($type === self::IDENTIFIER_EMAIL && $rule?->requiresVerifiedEmail() && $user?->email_verified_at === null) {
            return ['wallet' => null, 'error' => __('Recipient email is not verified.')];
        }

        if ($type === self::IDENTIFIER_MOBILE && $rule?->requiresVerifiedMobile() && ! ($user?->hasVerifiedPhone() ?? false)) {
            return ['wallet' => null, 'error' => __('Recipient mobile number is not verified.')];
        }

        return ['wallet' => $wallet, 'error' => null];
    }

    public function normalizeWalletId(string $walletId): string
    {
        $normalized = strtoupper(trim($walletId));
        $normalized = str_replace([' ', '_', '—', '–'], ['', '-', '-', '-'], $normalized);

        if (preg_match('/^([A-Z]{2,6})([A-Z0-9]{3})([A-F0-9]{4})([A-F0-9]{4})$/', $normalized, $matches)) {
            return $matches[1].'-'.$matches[2].'-'.$matches[3].'-'.$matches[4];
        }

        return $normalized;
    }

    /**
     * Every stored spelling a typed wallet id could stand for, best first.
     *
     * An id typed without its hyphens is ambiguous once prefixes can differ
     * in length or be switched off: "USDT9F2AE710" is the prefixless
     * USDT-9F2A-E710, while normalizeWalletId() alone would read "DKUSDT…"
     * as DKU-SDT-…. So a compact input is split every way an issued id could
     * have been built, and the database says which one exists. An input that
     * already carries hyphens is taken as written.
     *
     * @return list<string>
     */
    private function walletIdLookupCandidates(string $walletId): array
    {
        $normalized = $this->normalizeWalletId($walletId);
        $compact    = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $walletId));

        if (preg_match('/[-_—–]/u', $walletId) === 1
            || preg_match('/^([A-Z0-9]+)([A-F0-9]{4})([A-F0-9]{4})$/', $compact, $matches) !== 1) {
            return [$normalized];
        }

        $head       = $matches[1];
        $tail       = '-'.$matches[2].'-'.$matches[3];
        $candidates = [$normalized, $compact];

        for ($length = 2; $length <= 6 && $length < strlen($head); $length++) {
            $prefix = substr($head, 0, $length);

            if (ctype_alpha($prefix)) {
                $candidates[] = $prefix.'-'.substr($head, $length).$tail;
            }
        }

        $candidates[] = $head.$tail;

        return array_values(array_unique($candidates));
    }

    public function formatMaskedWalletId(string $walletId): string
    {
        $normalized = $this->normalizeWalletId($walletId);

        if (preg_match('/^([A-Z0-9]+(?:-[A-Z0-9]+)?)-([A-Z0-9]{4})-([A-Z0-9]{4})$/', $normalized, $matches)) {
            return "{$matches[1]}-{$matches[2]}•••{$matches[3]}";
        }

        return $normalized;
    }

    /**
     * Determines if the payer and requester wallets are the same.
     */
    public function isSelfTransaction($formWallet, $toWallet): bool
    {
        return $formWallet->user_id === auth()->id() || $formWallet->id === $toWallet->id;
    }

    /**
     * Calculates the fee for requesting money, based on the requester's wallet and amount.
     */
    /**
     * Fee for a currency role: fixed + percentage, within any configured floor
     * and ceiling.
     *
     * This is the entry point for send money, request money, exchange, voucher
     * and gift card — five controllers call it through the Wallet facade.
     *
     * Returns the fee as a decimal string at calculation scale, exact; it was
     * a float, which the ledger then bound at `precision` 14 (CR1).
     */
    public function calculateFeeByRole($wallet, $amount, $role): string
    {
        $currencyRole = $wallet->currency->roles()->where('role_name', $role)->first();

        if ($currencyRole === null) {
            return '0';
        }

        // The role's fixed fee and caps belong to this wallet's currency — the
        // roles table has one row per currency precisely so they can differ.
        // `$amount` arrives in base currency, so the two must be reconciled
        // before they are added together.
        return app(FeeCalculator::class)->totalFromBase(
            $amount,
            $wallet->currency->exchange_rate,
            $currencyRole->fee_fixed,
            $currencyRole->fee_percent,
            $currencyRole->fee_min,
            $currencyRole->fee_max,
        );
    }

    /**
     * Convert a base-currency amount into the wallet's currency.
     *
     * The rate is checked first. Multiplying by an unusable rate does not
     * fail, it just produces a wrong number and books it — which is how a
     * placeholder BTC rate of 1 turned 1000 USD into 1000 BTC.
     *
     * @throws NotifyErrorException
     */
    public function conversionAmount($wallet, $amount)
    {
        $this->assertConvertible($wallet->currency);

        // Exact decimal arithmetic, then quantized to the wallet currency's own
        // precision — this is the figure the wallet is debited and credited by,
        // and a USD wallet cannot hold a fraction of a cent. A 1.5% fee on
        // 33.33 left 0.00005 behind on every transfer, which is invisible at
        // two decimals and means the balance can never reach zero.
        //
        // Transaction::completeFxSnapshot() derives the wallet side of the FX
        // snapshot at the same precision whenever the row names its
        // `payable_currency`, so the snapshot and the figures the writer
        // computed still agree digit for digit.
        //
        // Returned as the decimal string quantize() produced, not cast to a
        // float: this is the figure the ledger stores, and a float was bound
        // at `precision` 14 on the way to the row (CR1).
        return Money::quantize(
            DecimalMoney::multiply((string) $amount, $this->fxRate($wallet)),
            $wallet->currency?->code,
        );
    }

    /**
     * Convert an amount the customer typed in the wallet's own currency into
     * the base currency the ledger is denominated in.
     *
     * The exact inverse of {@see conversionAmount()}, through the same rate and
     * the same guard, so a figure converted out and back lands where it
     * started. This is the entry point every money form now funnels through:
     * the customer works in the unit they hold, and `amount` stays
     * base-denominated for the ledger, the FX snapshot and every consumer
     * already reading it.
     *
     * @throws NotifyErrorException
     */
    public function baseAmount($wallet, $amount): string
    {
        $this->assertConvertible($wallet->currency);

        // A decimal string at calculation scale, exact, for the same reason
        // as conversionAmount() (CR1).
        return DecimalMoney::divide((string) $amount, $this->fxRate($wallet));
    }

    /**
     * The wallet's rate as an exact decimal string, for recording on a ledger
     * entry.
     *
     * Writers that convert a base amount with `conversionAmount()` only need
     * to hand this to TransactionData: `Transaction::completeFxSnapshot()`
     * derives the wallet-currency side from it, and it derives it the same way
     * `conversionAmount()` does, so the snapshot cannot disagree with the
     * figures the writer computed.
     */
    public function fxRate($wallet): string
    {
        $currency = match (true) {
            $wallet instanceof Currency => $wallet,
            is_string($wallet)          => app(CurrencyService::class)->getCurrencyByCode(strtoupper(trim($wallet))),
            default                     => $wallet?->currency,
        };

        if ($currency === null) {
            return '1';
        }

        // The cached projection is an array, a freshly loaded model is not.
        // Both carry the effective rate: the array was built by toArray(),
        // which runs the same accessor. Reading the raw column here instead
        // recorded a rate the conversion had not used whenever live rates were
        // on, leaving the snapshot unable to reproduce the row's own figures.
        $rate = is_array($currency)
            ? ($currency['exchange_rate'] ?? 1)
            : ($currency->exchange_rate ?? 1);

        return DecimalMoney::rate($rate);
    }

    /**
     * @throws NotifyErrorException
     */
    public function assertConvertible(?Currency $currency): void
    {
        $guard  = app(RateGuard::class);
        $reason = $guard->reject($currency);

        if ($reason === null) {
            return;
        }

        throw new NotifyErrorException(
            $currency === null
                ? __('The exchange rate is not available, so this amount cannot be converted.')
                : $guard->message($currency, $reason)
        );
    }

    /**
     * Ensure a currency can be used for any new or existing wallet operation.
     *
     * @throws NotifyErrorException
     */
    public function assertCurrencyOperational(?Currency $currency): void
    {
        if ($currency === null || ! $currency->exists) {
            throw new NotifyErrorException(__('The wallet currency is unavailable.'));
        }

        if (! $currency->status) {
            throw new NotifyErrorException(
                __(':currency is currently unavailable. Please choose another currency.', [
                    'currency' => $currency->code,
                ])
            );
        }
    }

    /**
     * Ensure both the user-level wallet switch and platform currency switch
     * permit an operation. A role may be supplied by transaction flows that
     * also require a specific currency capability.
     *
     * @throws NotifyErrorException
     */
    public function assertOperational(?WalletModel $wallet, ?string $role = null): void
    {
        if ($wallet === null || ! $wallet->exists) {
            throw new NotifyErrorException(__('The selected wallet is unavailable.'));
        }

        if (! $wallet->status) {
            throw new NotifyErrorException(__('The selected wallet is not active.'));
        }

        $wallet->loadMissing('currency.activeRoles');
        $this->assertCurrencyOperational($wallet->currency);

        if ($role !== null && ! $wallet->hasCurrencyRole($role)) {
            throw new NotifyErrorException(
                __('The selected wallet does not support :operation.', [
                    'operation' => title($role),
                ])
            );
        }
    }

    /**
     * Enforce the role's transaction limits on a base-currency amount.
     *
     * Both bounds are nullable, and "not configured" is not the same as zero.
     * This used to compare against the raw column either way, so a role left
     * with `max_limit = null` — which is exactly what the admin controller
     * writes for every newly created currency — rejected every amount: PHP
     * coerces null to 0 in `$payable > null`, and the operator saw
     * "Amount must be between 0 and " with nothing after the "and". A live BTC
     * exchange role was in precisely that state, which is why converting BTC
     * failed outright.
     *
     * An absent bound is now simply not enforced, and the message names only
     * the bounds that exist. Comparison goes through DecimalMoney so a
     * satoshi-scale limit is not decided by float wobble.
     *
     * A missing role row is not this method's problem: `assertOperational()`
     * is what decides whether the wallet may perform the operation at all, and
     * every caller runs it first. With no row there is no configured limit, so
     * there is nothing to enforce.
     *
     * @throws NotifyErrorException
     */
    /**
     * The base-currency window an amount has to sit inside for this role, as
     * two nullable bounds.
     *
     * Read by validateAmountLimitByRole() below and by anything that wants to
     * SHOW the window rather than enforce it - the mobile API's send-money
     * preview does - so the figure a customer is shown and the figure they are
     * held to come from one place.
     *
     * @return array{min: string|null, max: string|null}
     */
    public function amountLimitsByRole($requesterWallet, $role): array
    {
        $currencyRole = $requesterWallet->currency->roles()->where('role_name', $role)->first();

        if ($currencyRole === null) {
            return ['min' => null, 'max' => null];
        }

        return [
            'min' => $this->limitBound($currencyRole->min_limit),
            'max' => $this->limitBound($currencyRole->max_limit),
        ];
    }

    public function validateAmountLimitByRole($requesterWallet, $payableAmount, $role): void
    {
        ['min' => $min, 'max' => $max] = $this->amountLimitsByRole($requesterWallet, $role);

        $belowMin = $min !== null && DecimalMoney::compare($payableAmount, $min) < 0;
        $aboveMax = $max !== null && DecimalMoney::compare($payableAmount, $max) > 0;

        if (! $belowMin && ! $aboveMax) {
            return;
        }

        throw new NotifyErrorException($this->limitMessage($min, $max));
    }

    /**
     * A usable limit bound, or null when none is configured.
     *
     * A blank string is what an untouched admin input posts, and a negative
     * bound is not a limit anyone can satisfy; both mean "unset". Zero is kept
     * as a real bound for `max_limit` only in the sense that it is rejected
     * here too — a ceiling of zero would block every amount, which is never
     * what an operator means and is how the null default behaved before.
     */
    private function limitBound(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $normalized = DecimalMoney::normalize($value);

        return DecimalMoney::compare($normalized, 0) > 0 ? $normalized : null;
    }

    /**
     * Limits are denominated in the platform base currency — the admin form
     * labels them with it — so they are formatted at its precision.
     */
    private function limitMessage(?string $min, ?string $max): string
    {
        $base = (string) (siteCurrency() ?? '');

        if ($min !== null && $max !== null) {
            return __('Amount must be between :min and :max', [
                'min' => Money::format($min, $base),
                'max' => Money::format($max, $base),
            ]);
        }

        if ($max !== null) {
            return __('Amount must not be more than :max', ['max' => Money::format($max, $base)]);
        }

        return __('Amount must be at least :min', ['min' => Money::format($min, $base)]);
    }

    /**
     * How many wallets this customer's rank allows, or null for no limit.
     */
    public function walletLimitFor(User $user): ?int
    {
        $limit = data_get($user->rank?->features, 'wallet_create');

        return is_numeric($limit) ? (int) $limit : null;
    }

    /**
     * Refuse another wallet once the rank's limit is reached - the same rule
     * the website's WalletController::create applies.
     *
     * @throws NotifyErrorException
     */
    public function assertWithinWalletLimit(User $user): void
    {
        $limit = $this->walletLimitFor($user);

        if ($limit !== null && $user->wallets()->count() >= $limit) {
            throw new NotifyErrorException(
                __('You have reached your wallet limit for :rankName Member', ['rankName' => $user->rank?->name ?? __('Unranked')])
            );
        }
    }

    /**
     * Check if a user already has a wallet in a specific currency.
     */
    protected function userHasWalletWithCurrency(User $user, int $currencyId): bool
    {
        return $user->wallets()->where('currency_id', $currencyId)->exists();
    }

    /**
     * Create a wallet for a user with a given currency.
     */
    protected function createWallet(User $user, Currency $currency): Wallet
    {
        $this->assertCurrencyOperational($currency);

        return Wallet::create([
            'currency_id' => $currency->id,
            'user_id'     => $user->id,
            'uuid'        => $this->generateUniqueWalletId($currency),
            'balance'     => 0.0,
            'status'      => true,
        ]);
    }

    /**
     * Generate a unique wallet ID: PREFIX-CODE-XXXX-XXXX, or CODE-XXXX-XXXX
     * when the prefix is switched off.
     *
     * Without a prefix a numeric currency code and all-digit chunks would
     * read as a phone number to detectIdentifierType(), and the id could never
     * be looked up as a wallet — so such a draw is thrown back.
     */
    protected function generateUniqueWalletId(Currency $currency): string
    {
        $currencyCode = $this->walletCurrencyCode($currency);
        $prefix       = $this->walletIdPrefix();
        $head         = $prefix !== '' ? $prefix.'-'.$currencyCode : $currencyCode;

        do {
            $walletUuid = $head.'-'.$this->walletIdChunk().'-'.$this->walletIdChunk();
        } while (
            $this->detectIdentifierType($walletUuid) !== self::IDENTIFIER_WALLET_ID
            || Wallet::where('uuid', $walletUuid)->exists()
        );

        return $walletUuid;
    }

    private function walletCurrencyCode(Currency $currency): string
    {
        $currencyCode = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $currency->code));

        return $currencyCode !== '' ? $currencyCode : 'WLT';
    }

    private function walletIdChunk(): string
    {
        return strtoupper(str_pad(dechex(random_int(0, 65535)), 4, '0', STR_PAD_LEFT));
    }
}
