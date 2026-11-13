<?php

declare(strict_types=1);

namespace App\Services\VirtualCard\Drivers\BsiCards;

use App\Exceptions\NotifyErrorException;
use App\Exceptions\ProviderOutcomeUnknownException;

/**
 * One answer from the BSICARDS API, read the same way for every program.
 *
 * BSICARDS is inconsistent from one endpoint to the next: `code` arrives as
 * 200, "200" or 201; `status` as "success" or `true`; the payload sits under
 * `data`, sometimes under a second `data` inside it; and some failures come
 * back as HTTP 200 with an error body. Every driver call goes through this
 * class so those differences are decided once.
 *
 * The one distinction that matters for money is kept explicit:
 *
 *  - a refusal — BSICARDS answered and said no — is a NotifyErrorException,
 *    which is the only outcome the virtual-card manager refunds on;
 *  - no usable answer — a 5xx, a 408, an empty or non-JSON body, or a 2xx
 *    that does not say it worked ("pending", no status and no code) — is a
 *    ProviderOutcomeUnknownException, because the card may have been issued
 *    or funded before the edge fell over, and refunding then would pay twice.
 */
final class BsiCardsResponse
{
    private const SUCCESS_WORDS = ['success', 'successful', 'ok'];

    private const FAILURE_WORDS = ['error', 'failed', 'fail', 'failure', 'declined', 'rejected', 'invalid', 'unauthorized', 'unauthorised', 'denied', 'forbidden'];

    /**
     * @param array<string, mixed>|null $json the decoded body, null when it was not a JSON object
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly int $httpStatus,
        public readonly ?array $json,
    ) {}

    public static function fromRaw(string $endpoint, int $httpStatus, string $body): self
    {
        $decoded = json_decode($body, true);

        return new self($endpoint, $httpStatus, is_array($decoded) ? $decoded : null);
    }

    /**
     * Whether BSICARDS answered at all in a form that can be judged.
     */
    public function isUsable(): bool
    {
        if ($this->json === null || $this->json === []) {
            return false;
        }

        if ($this->httpStatus >= 500 || $this->httpStatus === 408) {
            return false;
        }

        return $this->bodyCode() === null || $this->bodyCode() < 500;
    }

    /**
     * Success needs a positive signal: a success `status`, or a 2xx `code`
     * where BSICARDS sent no status at all. A 2xx carrying neither, or a
     * status word not known to mean success ("pending", "processing"), is
     * not success — and not a refusal either; see isRefusal().
     */
    public function isSuccessful(): bool
    {
        if (! $this->isUsable() || $this->httpStatus < 200 || $this->httpStatus >= 300) {
            return false;
        }

        $code = $this->bodyCode();
        if ($code !== null && ($code < 200 || $code >= 300)) {
            return false;
        }

        $status = $this->json['status'] ?? null;

        if ($status === null) {
            return $code !== null;
        }

        if ($status === true) {
            return true;
        }

        return is_string($status) && in_array(strtolower(trim($status)), self::SUCCESS_WORDS, true);
    }

    /**
     * Whether BSICARDS answered and said no: a 4xx, a 4xx `code`, a `false`
     * status, or a status word that means failure. Only a refusal may be
     * refunded on — anything BSICARDS did not clearly refuse may have
     * happened.
     */
    public function isRefusal(): bool
    {
        if (! $this->isUsable() || $this->isSuccessful()) {
            return false;
        }

        if ($this->httpStatus >= 400 && $this->httpStatus < 500) {
            return true;
        }

        $code = $this->bodyCode();
        if ($code !== null && $code >= 400 && $code < 500) {
            return true;
        }

        $status = $this->json['status'] ?? null;

        return $status === false
            || (is_string($status) && in_array(strtolower(trim($status)), self::FAILURE_WORDS, true));
    }

    /**
     * Return this response if it succeeded, or throw the exception its
     * failure calls for.
     *
     * @throws NotifyErrorException            BSICARDS answered and refused
     * @throws ProviderOutcomeUnknownException BSICARDS gave no answer that can be judged either way
     */
    public function orFail(string $fallbackMessage): self
    {
        if ($this->isSuccessful()) {
            return $this;
        }

        if ($this->isRefusal()) {
            throw new NotifyErrorException($this->message($fallbackMessage));
        }

        throw new ProviderOutcomeUnknownException(__('BSICARDS did not give a usable answer (HTTP :status) for :endpoint.', [
            'status'   => $this->httpStatus,
            'endpoint' => $this->endpoint,
        ]));
    }

    /**
     * The payload, with BSICARDS's repeated envelope removed.
     *
     * `{"data": {"status": "success", "data": {...}}}` is unwrapped twice;
     * `{"data": {...}}` once; a body with no `data` is returned whole.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $json = $this->json   ?? [];
        $data = $json['data'] ?? null;

        if (! is_array($data)) {
            return $json;
        }

        if (array_key_exists('status', $data) && is_array($data['data'] ?? null)) {
            return $data['data'];
        }

        return $data;
    }

    /**
     * The human-readable reason in the body, or the fallback.
     *
     * Validation failures arrive either as a field => messages map under
     * `message`/`errors`, or as a plain string; both read as one line.
     */
    public function message(string $fallback = ''): string
    {
        $json  = $this->json ?? [];
        $parts = [];

        foreach ([$json['message'] ?? null, $json['errors'] ?? null, $json['error'] ?? null] as $value) {
            if (is_array($value)) {
                foreach ($value as $field => $messages) {
                    $text = is_array($messages)
                        ? implode(' ', array_map(static fn (mixed $line): string => is_scalar($line) ? (string) $line : '', $messages))
                        : (is_scalar($messages) ? (string) $messages : '');

                    if (trim($text) !== '') {
                        $parts[] = is_string($field) ? $field.': '.trim($text) : trim($text);
                    }
                }

                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            }
        }

        $parts = array_values(array_unique($parts));

        return $parts !== [] ? implode(' | ', $parts) : $fallback;
    }

    /**
     * The `code` field as an integer, or null when absent or not numeric.
     */
    public function bodyCode(): ?int
    {
        $code = $this->json['code'] ?? null;

        return is_numeric($code) ? (int) $code : null;
    }
}
