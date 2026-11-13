<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Contact;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Illuminate\Support\Collection;

/**
 * The people a customer deals with: everyone they have sent to, received
 * from or asked, merged with the rows they favourited, nicknamed, blocked
 * or matched from their phone book.
 *
 * Phone-book matching never sees a number. The phone hashes SHA-256 of the
 * E.164 form and sends the hashes; the same hash of each customer's stored
 * phone is compared here, and only the matches are answered.
 */
class ContactsService
{
    /** Ledger rows read to build the list; enough for the recents and the stats. */
    private const LEDGER_ROWS = 1000;

    public function __construct(
        private readonly CounterpartyResolver $counterparties,
        private readonly WalletService $wallets,
    ) {}

    /**
     * Every contact, A–Z by the name shown, with favourites and recents
     * derivable from the flags and last_at.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(User $user): array
    {
        $rows   = Contact::query()->where('user_id', $user->id)->get();
        $byUser = $rows->filter(fn (Contact $c) => $c->contact_user_id !== null)->keyBy('contact_user_id');

        $ledger = $this->ledgerFor($user);

        $ids   = $ledger->keys()->merge($byUser->keys())->unique()->values();
        $users = User::query()->whereIn('id', $ids)->with('kycSubmission')->get()->keyBy('id');

        $pending = $this->pendingRequestsFor($user);

        $contacts = [];
        foreach ($ids as $id) {
            $other = $users->get($id);
            if ($other === null) {
                continue;
            }
            $stats = $ledger->get($id, ['last_at' => null, 'transfers' => 0, 'sent' => '0', 'received' => '0', 'currency' => null]);

            $contacts[] = $this->describe($other, $byUser->get($id), $stats, $pending->get($id));
        }

        // Phone-book names not on the platform yet: shown so they can be invited.
        foreach ($rows as $row) {
            if ($row->contact_user_id === null && $row->display_name !== null) {
                $contacts[] = [
                    'id'          => $row->id,
                    'user_id'     => null,
                    'name'        => $row->display_name,
                    'username'    => null,
                    'email'       => null,
                    'phone'       => null,
                    'avatar'      => null,
                    'verified'    => false,
                    'on_platform' => false,
                    'favorite'    => $row->favorite,
                    'blocked'     => false,
                    'nickname'    => null,
                    'last_at'     => null,
                    'transfers'   => 0,
                    'sent'        => null,
                    'received'    => null,
                    'currency'    => null,
                    'request'     => null,
                ];
            }
        }

        usort($contacts, fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $contacts;
    }

    /**
     * Which of the phone's hashed numbers belong to customers here. Each
     * match is remembered as a phone-book contact so it survives the app
     * being reinstalled.
     *
     * @param  list<string>               $hashes
     * @return list<array<string, mixed>>
     */
    public function match(User $user, array $hashes): array
    {
        $hashes = array_values(array_unique(array_filter(
            array_map(fn ($h) => strtolower(trim((string) $h)), $hashes),
            fn ($h) => preg_match('/^[a-f0-9]{64}$/', $h) === 1,
        )));

        if ($hashes === []) {
            return [];
        }

        $matched = [];
        User::query()->whereNotNull('phone')->where('id', '!=', $user->id)->select(['id', 'phone'])->chunkById(500, function ($chunk) use ($hashes, &$matched): void {
            foreach ($chunk as $candidate) {
                $hash = self::phoneHash((string) $candidate->phone);
                if ($hash !== null && in_array($hash, $hashes, true)) {
                    $matched[(int) $candidate->id] = $hash;
                }
            }
        });

        foreach ($matched as $id => $hash) {
            Contact::query()->firstOrCreate(
                ['user_id' => $user->id, 'contact_user_id' => $id],
                ['source' => Contact::SOURCE_PHONEBOOK, 'phone_hash' => $hash],
            );
        }

        return User::query()->whereIn('id', array_keys($matched))->with('kycSubmission')->get()
            ->map(fn (User $u) => CounterpartyResolver::describe($u) + ['phone_hash' => $matched[(int) $u->id]])
            ->values()
            ->all();
    }

    /**
     * Add somebody by email, phone, username or wallet id.
     */
    public function add(User $user, string $identifier): ?Contact
    {
        $other = $this->findUser($identifier);

        if ($other === null || (int) $other->id === (int) $user->id) {
            return null;
        }

        return Contact::query()->firstOrCreate(
            ['user_id' => $user->id, 'contact_user_id' => $other->id],
            ['source' => Contact::SOURCE_MANUAL],
        );
    }

    /**
     * A row for [$otherId], created from the ledger the first time a flag
     * is set on somebody the customer only ever transacted with.
     */
    public function rowFor(User $user, int $otherId): Contact
    {
        return Contact::query()->firstOrCreate(
            ['user_id' => $user->id, 'contact_user_id' => $otherId],
            ['source' => Contact::SOURCE_TRANSACTIONS],
        );
    }

    /**
     * The shared history: totals each way and the rows, newest first.
     *
     * @return array{transfers: int, sent: string, received: string, currency: string|null, transactions: list<Transaction>}
     */
    public function between(User $user, int $otherId): array
    {
        $rows = $this->ledgerRows($user)->filter(fn (Transaction $trx) => $this->counterparties->userIdFor($trx) === $otherId)->values();

        return [
            'transfers'    => $rows->count(),
            'sent'         => $this->total($rows, AmountFlow::MINUS),
            'received'     => $this->total($rows, AmountFlow::PLUS),
            'currency'     => $rows->first()?->payable_currency,
            'transactions' => $rows->all(),
        ];
    }

    public static function phoneHash(string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        return $digits === '' ? null : hash('sha256', '+'.$digits);
    }

    /**
     * @param  array{last_at: mixed, transfers: int, sent: string, received: string, currency: string|null} $stats
     * @param  array<string, mixed>|null                                                                    $request
     * @return array<string, mixed>
     */
    private function describe(User $other, ?Contact $row, array $stats, ?array $request): array
    {
        return [
            'id'          => $row?->id,
            'user_id'     => (int) $other->id,
            'name'        => $row?->nickname ?: (string) $other->name,
            'username'    => $other->username,
            'email'       => self::maskEmail((string) $other->email),
            'phone'       => self::maskPhone((string) $other->phone),
            'avatar'      => asset($other->avatar_alt),
            'verified'    => $other->isKycVerified(),
            'on_platform' => true,
            'favorite'    => (bool) ($row?->favorite ?? false),
            'blocked'     => (bool) ($row?->blocked ?? false),
            'nickname'    => $row?->nickname,
            'last_at'     => $stats['last_at']?->toIso8601String(),
            'transfers'   => $stats['transfers'],
            'sent'        => $stats['currency'] === null ? null : ApiMoney::amount($stats['sent'], $stats['currency']),
            'received'    => $stats['currency'] === null ? null : ApiMoney::amount($stats['received'], $stats['currency']),
            'currency'    => $stats['currency'],
            'request'     => $request,
        ];
    }

    /**
     * Per counterparty: when last, how many, how much each way.
     *
     * @return Collection<int, array{last_at: mixed, transfers: int, sent: string, received: string, currency: string|null}>
     */
    private function ledgerFor(User $user): Collection
    {
        $stats = collect();
        foreach ($this->ledgerRows($user) as $trx) {
            $other = $this->counterparties->userIdFor($trx);
            if ($other === null) {
                continue;
            }
            $entry = $stats->get($other, ['last_at' => null, 'transfers' => 0, 'sent' => '0', 'received' => '0', 'currency' => null]);
            $entry['transfers']++;
            if ($entry['last_at'] === null || ($trx->created_at !== null && $trx->created_at->greaterThan($entry['last_at']))) {
                $entry['last_at'] = $trx->created_at;
            }
            if ($trx->status === TrxStatus::COMPLETED && $trx->payable_currency !== null && ($entry['currency'] === null || $entry['currency'] === $trx->payable_currency)) {
                $entry['currency'] = $trx->payable_currency;
                $side              = $trx->amount_flow === AmountFlow::MINUS ? 'sent' : 'received';
                $entry[$side]      = DecimalMoney::add($entry[$side], (string) $trx->payable_amount, DecimalMoney::CALCULATION_SCALE);
            }
            $stats->put($other, $entry);
        }

        return $stats;
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function ledgerRows(User $user): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('trx_type', CounterpartyResolver::TYPES)
            ->latest('id')
            ->limit(self::LEDGER_ROWS)
            ->get();
    }

    /**
     * Requests waiting on this customer, by who asked.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function pendingRequestsFor(User $user): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('trx_type', TrxType::REQUEST_MONEY)
            ->where('amount_flow', AmountFlow::MINUS)
            ->where('status', TrxStatus::PENDING)
            ->latest('id')
            ->get()
            ->mapWithKeys(function (Transaction $trx) {
                $other = $this->counterparties->userIdFor($trx);

                return $other === null ? [] : [$other => [
                    'reference' => $trx->trx_id,
                    'amount'    => ApiMoney::amount($trx->payable_amount, $trx->payable_currency),
                    'currency'  => $trx->payable_currency,
                    'note'      => $trx->remarks,
                    'due_at'    => data_get($trx->trx_data, 'due_at'),
                ]];
            });
    }

    /**
     * @param Collection<int, Transaction> $rows
     */
    private function total(Collection $rows, AmountFlow $flow): string
    {
        return $rows
            ->filter(fn (Transaction $trx) => $trx->status === TrxStatus::COMPLETED && $trx->amount_flow === $flow)
            ->reduce(fn (string $carry, Transaction $trx): string => DecimalMoney::add($carry, (string) $trx->payable_amount, DecimalMoney::CALCULATION_SCALE), '0');
    }

    private function findUser(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        if (str_starts_with($identifier, '@')) {
            return User::query()->where('username', ltrim($identifier, '@'))->first();
        }

        // PH3: a phone is read the way sign-in and send money read it, and a
        // number that could mean two accounts finds neither.
        return match ($this->wallets->detectIdentifierType($identifier)) {
            WalletService::IDENTIFIER_EMAIL  => User::query()->where('email', strtolower($identifier))->first(),
            WalletService::IDENTIFIER_MOBILE => $this->wallets->lookupUserByPhone($identifier) ?: null,
            default                          => Wallet::query()->where('uuid', $this->wallets->normalizeWalletId($identifier))->first()?->user
                ?? User::query()->where('username', $identifier)->first(),
        };
    }

    public static function maskEmail(string $email): ?string
    {
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 2).'••@'.$domain;
    }

    public static function maskPhone(string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        // A + only when the number was stored with one: a local 01712345678
        // is not the country code +017.
        $plus = str_starts_with(ltrim($phone), '+') ? '+' : '';

        return strlen($digits) < 6 ? null : $plus.substr($digits, 0, 3).' •• '.substr($digits, -3);
    }
}
