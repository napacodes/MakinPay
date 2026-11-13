<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Currency;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'currency_id' => Currency::factory(),
            'user_id'     => User::factory(),
            'uuid'        => $this->walletUuid('WLT'),
            'balance'     => 0,
            'status'      => true,
        ];
    }

    /**
     * Attach the wallet to a currency and mirror its code into the uuid,
     * matching the DK-{CODE}-{CHUNK}-{CHUNK} shape WalletService generates.
     */
    public function forCurrency(Currency $currency): static
    {
        return $this->state(fn (): array => [
            'currency_id' => $currency->id,
            'uuid'        => $this->walletUuid((string) $currency->code),
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }

    /**
     * Accepts a string so crypto balances such as '0.00094118' are not
     * rounded by a float literal before they reach the database.
     */
    public function balance(string|int|float $balance): static
    {
        return $this->state(fn (): array => ['balance' => $balance]);
    }

    public function uuid(string $uuid): static
    {
        return $this->state(fn (): array => ['uuid' => $uuid]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => false]);
    }

    /**
     * Mirrors the DK-{CODE}-{CHUNK}-{CHUNK} shape WalletService generates, but
     * seeds one chunk from a monotonic counter rather than leaving both to
     * chance — two random 16-bit chunks collide often enough across a full
     * suite run to make tests intermittently fail.
     */
    private function walletUuid(string $code): string
    {
        $code = preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) ?: 'WLT';

        return sprintf('DK-%s-%s-%s', $code, $this->sequentialChunk(), $this->chunk());
    }

    private function sequentialChunk(): string
    {
        static $sequence = 0;

        return strtoupper(str_pad(dechex(++$sequence % 65536), 4, '0', STR_PAD_LEFT));
    }

    private function chunk(): string
    {
        return strtoupper(str_pad(dechex(random_int(0, 65535)), 4, '0', STR_PAD_LEFT));
    }
}
