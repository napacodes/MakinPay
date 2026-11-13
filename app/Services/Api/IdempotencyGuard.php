<?php

declare(strict_types=1);

namespace App\Services\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mechanics of making a request safe to send twice.
 *
 * Two middlewares need this - the server-to-server merchant API and the mobile
 * API - against two different tables, keyed on two different things. What they
 * share is not the policy but the MECHANISM: what counts as a well-formed key,
 * what a request fingerprint is, and the careful bit in the middle where two
 * requests arrive at once and the unique index has to settle it.
 *
 * WHAT IS DELIBERATELY NOT HERE, because the two genuinely differ and
 * flattening them would change a live payment surface:
 *
 *   - whether a missing key is allowed. The merchant API treats it as opt-in,
 *     which is what its integrators have always had; the mobile API requires
 *     it, because an endpoint that moves money and lets a client opt out of
 *     replay protection is one bad connection away from a double credit.
 *   - the shape of every refusal. One answers `{error, message}`, the other
 *     the mobile envelope.
 *   - which outcomes are worth storing. The merchant one keeps 5xx so a
 *     retry cannot re-run an operation whose result nobody knows; the mobile
 *     one drops the key so a customer whose transfer crashed can try again.
 *   - what happens when the request throws, and what the replay header is
 *     called. They even spell it differently, and neither can be renamed
 *     without breaking a client that reads it.
 *
 * Each middleware keeps all of that. This holds only what is identical, which
 * is the part that is easy to get subtly wrong in a second copy.
 */
final class IdempotencyGuard
{
    public const MAX_KEY_LENGTH = 255;

    private const KEY_PATTERN = '/^[A-Za-z0-9._:-]+$/';

    public function keyFrom(Request $request): string
    {
        return trim((string) $request->header('Idempotency-Key', ''));
    }

    public function isWellFormed(string $key): bool
    {
        return $key !== ''
            && strlen($key) <= self::MAX_KEY_LENGTH
            && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * What makes two requests "the same request".
     *
     * Method, path and body. The path is in there so one key cannot be reused
     * across two different operations even by accident, and the body is in
     * there so a client that changes what it is asking for is told rather than
     * handed the earlier answer.
     *
     * A multipart body never reaches getContent(): PHP parses it into the form
     * fields and the uploaded files and leaves the raw body empty. Without the
     * part below, the same key with a corrected CSV or another wallet replayed
     * the first upload's answer. A request with a raw body hashes exactly as it
     * always has, so stored keys for JSON calls still match.
     *
     * The wallet PIN is left out of it. The fingerprint is a plain SHA-256
     * kept for a day, and the rest of a money request is guessable, so a PIN
     * inside it - six digits, 10^6 tries - could be read straight back off the
     * stored hash, around the bcrypt that guards the PIN itself. A request
     * without one hashes exactly as before.
     */
    public function fingerprint(Request $request): string
    {
        $parts = [
            strtoupper($request->method()),
            $request->getRequestUri(),
            $this->withoutPin($request->getContent()),
        ];

        if ($parts[2] === '' && ($request->request->count() > 0 || $request->allFiles() !== [])) {
            $parts[] = json_encode([
                'fields' => $this->canonical(array_diff_key($request->request->all(), ['wallet_pin' => true])),
                'files'  => $this->canonical($this->fileDigests($request->allFiles())),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * A JSON body with its wallet_pin taken out; any other body as it came.
     */
    private function withoutPin(string $body): string
    {
        if (! str_contains($body, 'wallet_pin')) {
            return $body;
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! array_key_exists('wallet_pin', $decoded)) {
            return $body;
        }

        unset($decoded['wallet_pin']);

        return json_encode($this->canonical($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Take the key, or find out who already has it.
     *
     * THE RACE IS THE WHOLE REASON THIS IS SHARED. Two identical requests
     * arriving together both look up the key, both find nothing, and both try
     * to claim it. The unique index lets exactly one through, and the loser
     * must not proceed - it has to read the winner's row and answer from
     * that. Getting this wrong in one of two copies is how the same money
     * moves twice on exactly the day the network is bad enough for a client to
     * retry.
     *
     * @param  class-string<Model>      $model    the table to claim in
     * @param  array<string, mixed>     $identity the columns that scope the key
     * @return array{0: Model, 1: bool} the record, and whether we claimed it
     */
    public function claim(string $model, array $identity, string $key, string $fingerprint, int $windowHours): array
    {
        $existing = $this->find($model, $identity, $key);

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $record = $model::query()->create($identity + [
                'idempotency_key' => $key,
                'request_hash'    => $fingerprint,
                'expires_at'      => now()->addHours($windowHours),
            ]);

            return [$record, true];
        } catch (QueryException $exception) {
            $winner = $this->find($model, $identity, $key);

            // Not the unique index, then - something else went wrong and
            // swallowing it would hide a real fault behind a replay.
            if ($winner === null) {
                throw $exception;
            }

            return [$winner, false];
        }
    }

    /**
     * Whether a stored record describes the same request as this one.
     *
     * hash_equals rather than ===, because the comparison is against a value
     * the caller supplied and a timing difference here leaks how much of a
     * fingerprint was guessed correctly.
     */
    public function matches(Model $record, string $fingerprint): bool
    {
        return hash_equals((string) $record->request_hash, $fingerprint);
    }

    /**
     * Keep this answer, so a repeat of the same request gets it back.
     */
    public function remember(Model $record, Response $response): void
    {
        $record->update([
            'response_status'       => $response->getStatusCode(),
            'response_content_type' => $response->headers->get('Content-Type', 'application/json'),
            'response_body'         => $response->getContent(),
        ]);
    }

    /**
     * Give the key back, so the same one can be used again.
     */
    public function release(Model $record): void
    {
        $record->delete();
    }

    /**
     * The value with every array's keys sorted, at any depth, so the field
     * order a client happens to send does not change the fingerprint.
     */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }

    /**
     * Each uploaded file as the sha256 of its contents, in the same shape as
     * the files array, so a different file under the same field name is a
     * different request.
     *
     * @param  array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private function fileDigests(array $files): array
    {
        return array_map(function (mixed $file): mixed {
            if (is_array($file)) {
                return $this->fileDigests($file);
            }

            if ($file instanceof UploadedFile && $file->isValid()) {
                $path = $file->getRealPath();

                return $path === false ? 'unreadable' : (hash_file('sha256', $path) ?: 'unreadable');
            }

            return 'invalid';
        }, $files);
    }

    /**
     * @param class-string<Model>  $model
     * @param array<string, mixed> $identity
     */
    private function find(string $model, array $identity, string $key): ?Model
    {
        return $model::query()
            ->where($identity)
            ->where('idempotency_key', $key)
            ->first();
    }
}
