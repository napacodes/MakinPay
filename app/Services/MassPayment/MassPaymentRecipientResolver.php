<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Enums\MassPayment\MassPaymentIssue;
use App\Enums\MassPayment\MassPaymentIssueHint;
use App\Enums\UserStatus;
use App\Exceptions\NotifyErrorException;
use App\Models\FeatureAccessRule;
use App\Models\User;
use App\Models\Wallet;
use App\Services\FeatureManager;
use App\Services\WalletService;
use App\Support\HomeCountry;
use App\Support\PhoneIdentifier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The strict recipient lookup behind mass payments.
 *
 * Send money's lookup guesses: findUserByPhone() takes the first account that
 * matches either reading of a number, getWalletByIdentifier() falls back to a
 * wallet id for anything that is not a valid email, and nothing there
 * excludes system accounts. One guess in a 500-line batch pays a stranger, so
 * this resolver refuses anything other than exactly one match at every step:
 *
 *  - an identifier is typed by its shape, and a mistyped email is invalid,
 *    never re-read as a wallet id; `@username` is refused outright;
 *  - a mobile number matching two accounts across its readings is ambiguous;
 *  - a wallet id whose stored spellings match two wallets is ambiguous;
 *  - a recipient with two wallets in the batch currency is ambiguous, and one
 *    with none is refused, never given a wallet;
 *  - system accounts are invisible on every path, reported as unknown so
 *    their existence is not revealed.
 *
 * It never calls findUserByPhone(), resolveRecipientWallet(),
 * getWalletByIdentifier(), expandRecipientAlias() or isSelfTransaction().
 *
 * Which identifiers are allowed, and whether they must be verified, comes
 * from the `mass_payment` access rule for the owner's panel. A missing rule
 * refuses every line: falling back to "everything allowed" would make the
 * admin's switches decorative on any install whose rule row is absent.
 *
 * The lookups are bulk: one query per identifier type for the whole batch,
 * never one per line.
 */
class MassPaymentRecipientResolver
{
    public const FEATURE_KEY = 'mass_payment';

    private const PHONE_PATTERN = '/^\+?[0-9\s().\-]{6,20}$/';

    private const WALLET_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9\-_ ]{5,40}$/';

    private const LABEL_MAX_LENGTH = 191;

    public function __construct(
        private readonly WalletService $wallets,
        private readonly FeatureManager $features,
    ) {}

    /**
     * Resolve every line's recipient against the source wallet's currency.
     *
     * Each input is the typed recipient, or an array with `recipient` and an
     * optional `currency` column. The result keeps the input's keys.
     *
     * @param  array<int|string, string|array{recipient?: string|null, currency?: string|null}> $inputs
     * @return array<int|string, RecipientResolution>
     */
    public function resolveMany(User $owner, Wallet $source, array $inputs): array
    {
        $source->loadMissing('currency');

        $rule        = $this->accessRule($owner);
        $homeCountry = null;
        $lines       = [];

        foreach ($inputs as $key => $input) {
            $recipient = trim((string) (is_array($input) ? ($input['recipient'] ?? '') : $input));
            $currency  = is_array($input) ? trim((string) ($input['currency'] ?? '')) : '';

            $line = $this->classify($recipient, $rule);

            if ($line['issue'] === null && $line['type'] === FeatureAccessRule::IDENTIFIER_MOBILE) {
                $homeCountry ??= ['code' => HomeCountry::code()];
                $line = $this->withPhoneCandidates($line, $recipient, $homeCountry['code']);
            }

            $lines[$key] = $line + ['recipient' => $recipient, 'currency' => $currency];
        }

        $usersByEmail = $this->usersByEmail($lines);
        $usersByPhone = $this->usersByPhone($lines);
        $walletsById  = $this->wallets->walletsMatchingUniqueIds(
            $this->lookupKeys($lines, FeatureAccessRule::IDENTIFIER_WALLET_ID),
            ['user', 'currency.activeRoles'],
        );

        $people = [];

        foreach ($lines as $key => $line) {
            if ($line['issue'] !== null) {
                continue;
            }

            $people[$key] = match ($line['type']) {
                FeatureAccessRule::IDENTIFIER_EMAIL  => $this->single($usersByEmail->get($line['lookup'], [])),
                FeatureAccessRule::IDENTIFIER_MOBILE => $this->single($this->usersForCandidates($usersByPhone, $line['candidates'])),
                default                              => null,
            };
        }

        $walletsByUser = $this->walletsInSourceCurrency($source, $people);
        $results       = [];

        foreach ($lines as $key => $line) {
            $results[$key] = $line['issue'] !== null
                ? $this->refuse($line['issue'], $line, $line['hint'])
                : $this->resolveLine($owner, $source, $rule, $line, $people[$key] ?? null, $walletsByUser, $walletsById);
        }

        return $results;
    }

    /**
     * The `mass_payment` rule for the owner's panel, or null when there is
     * none. Never send money's rule, and never another panel's.
     */
    private function accessRule(User $owner): ?FeatureAccessRule
    {
        $panel = $this->features->currentPanel($owner);

        return $this->features->find(self::FEATURE_KEY)?->accessRules->firstWhere('panel', $panel);
    }

    /**
     * Decide the identifier's type from its shape alone.
     *
     * @return array{type: string|null, issue: MassPaymentIssue|null, hint: MassPaymentIssueHint|null, lookup: string|null, candidates: list<string>}
     */
    private function classify(string $recipient, ?FeatureAccessRule $rule): array
    {
        $line = ['type' => null, 'issue' => null, 'hint' => null, 'lookup' => null, 'candidates' => []];

        if (str_contains($recipient, '@')) {
            if (! str_starts_with($recipient, '@')) {
                $line['type'] = FeatureAccessRule::IDENTIFIER_EMAIL;
            }

            if (str_starts_with($recipient, '@') || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                $line['issue'] = MassPaymentIssue::InvalidIdentifier;
            } else {
                $line['lookup'] = mb_strtolower($recipient);
            }
        } elseif (preg_match(self::PHONE_PATTERN, $recipient) === 1) {
            $line['type'] = FeatureAccessRule::IDENTIFIER_MOBILE;
        } elseif (preg_match(self::WALLET_ID_PATTERN, $recipient) === 1) {
            $line['type']   = FeatureAccessRule::IDENTIFIER_WALLET_ID;
            $line['lookup'] = $recipient;
        } else {
            $line['issue'] = MassPaymentIssue::InvalidIdentifier;
        }

        if ($rule === null) {
            $line['issue'] = MassPaymentIssue::MethodNotAllowed;
        } elseif ($line['issue'] === null && ! $rule->isIdentifierAllowed((string) $line['type'])) {
            $line['issue'] = MassPaymentIssue::MethodNotAllowed;
        }

        return $line;
    }

    /**
     * Every stored number the typed one could mean. None at all is an
     * invalid identifier; a bare local number on a site with no home country
     * is told how to type it instead.
     *
     * @param  array{type: string|null, issue: MassPaymentIssue|null, hint: MassPaymentIssueHint|null, lookup: string|null, candidates: list<string>} $line
     * @return array{type: string|null, issue: MassPaymentIssue|null, hint: MassPaymentIssueHint|null, lookup: string|null, candidates: list<string>}
     */
    private function withPhoneCandidates(array $line, string $recipient, ?string $homeCountry): array
    {
        $line['candidates'] = PhoneIdentifier::candidates($recipient, $homeCountry);

        if ($line['candidates'] === []) {
            $line['issue'] = MassPaymentIssue::InvalidIdentifier;

            if ($homeCountry === null && ! str_starts_with($recipient, '+')) {
                $line['hint'] = MassPaymentIssueHint::InternationalFormat;
            }
        }

        return $line;
    }

    /**
     * Every non-system account holding one of the typed emails, grouped by
     * the lower-cased address.
     *
     * @param  array<int|string, array<string, mixed>>   $lines
     * @return Collection<string, Collection<int, User>>
     */
    private function usersByEmail(array $lines): Collection
    {
        $emails = $this->lookupKeys($lines, FeatureAccessRule::IDENTIFIER_EMAIL);

        if ($emails === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('email', $emails)
            ->whereNull('system_account_type')
            ->get()
            ->groupBy(fn (User $user): string => mb_strtolower((string) $user->email));
    }

    /**
     * Every non-system account holding one of the candidate numbers,
     * grouped by the stored number.
     *
     * @param  array<int|string, array<string, mixed>>   $lines
     * @return Collection<string, Collection<int, User>>
     */
    private function usersByPhone(array $lines): Collection
    {
        $candidates = [];

        foreach ($lines as $line) {
            if ($line['issue'] === null && $line['type'] === FeatureAccessRule::IDENTIFIER_MOBILE) {
                array_push($candidates, ...$line['candidates']);
            }
        }

        if ($candidates === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('phone', array_values(array_unique($candidates)))
            ->whereNull('system_account_type')
            ->get()
            ->groupBy(fn (User $user): string => (string) $user->phone);
    }

    /**
     * The distinct accounts across every reading of one typed number.
     *
     * @param  Collection<string, Collection<int, User>> $usersByPhone
     * @param  list<string>                              $candidates
     * @return Collection<int, User>
     */
    private function usersForCandidates(Collection $usersByPhone, array $candidates): Collection
    {
        return (new Collection($candidates))
            ->flatMap(fn (string $phone): Collection => $usersByPhone->get($phone, new Collection))
            ->unique('id')
            ->values();
    }

    /**
     * The one account a lookup found, false for several, null for none.
     *
     * @param iterable<int, User> $users
     */
    private function single(iterable $users): User|false|null
    {
        $distinct = (new Collection($users))->unique('id')->values();

        return match ($distinct->count()) {
            0       => null,
            1       => $distinct->first(),
            default => false,
        };
    }

    /**
     * Each found recipient's wallets in the source currency, in one query.
     * Wallets are only ever looked up here, never created.
     *
     * @param  array<int|string, User|false|null>       $people
     * @return Collection<int, Collection<int, Wallet>>
     */
    private function walletsInSourceCurrency(Wallet $source, array $people): Collection
    {
        $userIds = [];

        foreach ($people as $person) {
            if ($person instanceof User) {
                $userIds[$person->id] = $person->id;
            }
        }

        if ($userIds === []) {
            return new Collection;
        }

        return Wallet::query()
            ->with('currency.activeRoles')
            ->whereIn('user_id', array_values($userIds))
            ->where('currency_id', $source->currency_id)
            ->get()
            ->groupBy(fn (Wallet $wallet): int => (int) $wallet->user_id);
    }

    /**
     * @param array<string, mixed>                           $line
     * @param Collection<int, Collection<int, Wallet>>       $walletsByUser
     * @param array<string, EloquentCollection<int, Wallet>> $walletsById
     */
    private function resolveLine(
        User $owner,
        Wallet $source,
        FeatureAccessRule $rule,
        array $line,
        User|false|null $person,
        Collection $walletsByUser,
        array $walletsById,
    ): RecipientResolution {
        if ($line['type'] === FeatureAccessRule::IDENTIFIER_WALLET_ID) {
            $matches = (new Collection($walletsById[$line['lookup']] ?? []))
                ->filter(fn (Wallet $wallet): bool => $wallet->user instanceof User && $wallet->user->system_account_type === null)
                ->values();

            if ($matches->isEmpty()) {
                return $this->refuse(MassPaymentIssue::UnknownRecipient, $line);
            }

            if ($matches->count() > 1) {
                return $this->refuse(MassPaymentIssue::AmbiguousRecipient, $line);
            }

            $wallet = $matches->first();
            $user   = $wallet->user;

            if ($this->isOwnAccount($owner, $source, $user)) {
                return $this->refuse(MassPaymentIssue::OwnWallet, $line);
            }
        } else {
            if ($person === null) {
                return $this->refuse(MassPaymentIssue::UnknownRecipient, $line);
            }

            if ($person === false) {
                return $this->refuse(MassPaymentIssue::AmbiguousRecipient, $line);
            }

            $user = $person;

            if ($this->isOwnAccount($owner, $source, $user)) {
                return $this->refuse(MassPaymentIssue::OwnWallet, $line);
            }

            $wallets = $walletsByUser->get((int) $user->id, new Collection);

            if ($wallets->isEmpty()) {
                return $this->refuse(MassPaymentIssue::NoWalletInCurrency, $line);
            }

            if ($wallets->count() > 1) {
                return $this->refuse(MassPaymentIssue::AmbiguousRecipient, $line);
            }

            $wallet = $wallets->first();
            $wallet->setRelation('user', $user);
        }

        $issue = $this->eligibilityIssue($source, $rule, $line, $user, $wallet);

        if ($issue !== null) {
            return $this->refuse($issue, $line);
        }

        return RecipientResolution::resolved($user, $wallet, (string) $line['type'], $this->resolvedLabel($user, $wallet, (string) $line['type']));
    }

    /**
     * The owner's own account, by any of its wallets in any currency. An
     * explicit id comparison, never isSelfTransaction(), which reads the
     * session user.
     */
    private function isOwnAccount(User $owner, Wallet $source, User $recipient): bool
    {
        return (int) $recipient->id === (int) $owner->id || (int) $recipient->id === (int) $source->user_id;
    }

    /**
     * The checks a found recipient and wallet must still pass, in order.
     *
     * @param array<string, mixed> $line
     */
    private function eligibilityIssue(Wallet $source, FeatureAccessRule $rule, array $line, User $user, Wallet $wallet): ?MassPaymentIssue
    {
        $currency = strtoupper((string) $line['currency']);

        if ((int) $wallet->currency_id !== (int) $source->currency_id
            || ($currency !== '' && $currency !== strtoupper((string) $source->currency->code))) {
            return MassPaymentIssue::WrongCurrency;
        }

        if ($user->status !== UserStatus::ACTIVE || $user->isSuspended()) {
            return MassPaymentIssue::RecipientUnavailable;
        }

        if ($line['type'] === FeatureAccessRule::IDENTIFIER_EMAIL && $rule->requiresVerifiedEmail() && $user->email_verified_at === null) {
            return MassPaymentIssue::RecipientUnverified;
        }

        if ($line['type'] === FeatureAccessRule::IDENTIFIER_MOBILE && $rule->requiresVerifiedMobile() && ! $user->hasVerifiedPhone()) {
            return MassPaymentIssue::RecipientUnverified;
        }

        try {
            $this->wallets->assertOperational($wallet);
        } catch (NotifyErrorException) {
            return MassPaymentIssue::WalletUnavailable;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function refuse(MassPaymentIssue $issue, array $line, ?MassPaymentIssueHint $hint = null): RecipientResolution
    {
        $label = $this->maskIdentifier((string) $line['recipient'], $line['type']);

        return RecipientResolution::refused($issue, $line['type'], $label, $hint);
    }

    /**
     * The typed lookup values of every still-valid line of one type.
     *
     * @param  array<int|string, array<string, mixed>> $lines
     * @return list<string>
     */
    private function lookupKeys(array $lines, string $type): array
    {
        $keys = [];

        foreach ($lines as $line) {
            if ($line['issue'] === null && $line['type'] === $type && $line['lookup'] !== null) {
                $keys[(string) $line['lookup']] = (string) $line['lookup'];
            }
        }

        return array_values($keys);
    }

    /**
     * "Rahim K. (ra•••@example.com)": a first name, a last initial and the
     * stored identifier masked. The full name never leaves the server.
     */
    private function resolvedLabel(User $user, Wallet $wallet, string $type): string
    {
        $identifier = match ($type) {
            FeatureAccessRule::IDENTIFIER_EMAIL  => $this->maskEmail((string) $user->email),
            FeatureAccessRule::IDENTIFIER_MOBILE => $this->maskPhone((string) $user->phone),
            default                              => $this->maskWalletId((string) $wallet->uuid),
        };

        $name  = $this->maskedName($user);
        $label = $name === '' ? $identifier : $name.' ('.$identifier.')';

        return mb_substr($label, 0, self::LABEL_MAX_LENGTH);
    }

    private function maskedName(User $user): string
    {
        $first   = trim((string) $user->first_name);
        $last    = trim((string) $user->last_name);
        $initial = $last === '' ? '' : mb_strtoupper(mb_substr($last, 0, 1)).'.';

        return trim($first.' '.$initial);
    }

    private function maskIdentifier(string $recipient, ?string $type): string
    {
        $masked = match ($type) {
            FeatureAccessRule::IDENTIFIER_EMAIL     => $this->maskEmail($recipient),
            FeatureAccessRule::IDENTIFIER_MOBILE    => $this->maskPhone($recipient),
            FeatureAccessRule::IDENTIFIER_WALLET_ID => $this->maskWalletId($recipient),
            default                                 => $this->maskOther($recipient),
        };

        return mb_substr($masked, 0, self::LABEL_MAX_LENGTH);
    }

    /** "rahim@example.com" becomes "ra•••@example.com". */
    private function maskEmail(string $email): string
    {
        $at = mb_strrpos($email, '@');

        if ($at === false) {
            return $this->maskOther($email);
        }

        $local  = mb_substr($email, 0, $at);
        $domain = mb_substr($email, $at + 1);
        $keep   = mb_strlen($local) > 2 ? 2 : 1;

        return mb_substr($local, 0, $keep).'•••@'.$domain;
    }

    /** "+8801712345678" becomes "+880•••45678". */
    private function maskPhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        $plus   = str_starts_with(trim($phone), '+') ? '+' : '';

        if (strlen($digits) >= 10) {
            return $plus.substr($digits, 0, 3).'•••'.substr($digits, -5);
        }

        if (strlen($digits) >= 6) {
            return $plus.substr($digits, 0, 2).'•••'.substr($digits, -2);
        }

        return '•••';
    }

    /** "DK-USD-9F2A-E710" becomes "DK-U…E710". */
    private function maskWalletId(string $walletId): string
    {
        $id = strtoupper(trim($walletId));

        if (mb_strlen($id) > 8) {
            return mb_substr($id, 0, 4).'…'.mb_substr($id, -4);
        }

        return mb_substr($id, 0, 2).'…';
    }

    private function maskOther(string $value): string
    {
        return mb_strlen($value) > 4 ? mb_substr($value, 0, 2).'•••' : '•••';
    }
}
