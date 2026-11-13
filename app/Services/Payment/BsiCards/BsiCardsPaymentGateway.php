<?php

declare(strict_types=1);

namespace App\Services\Payment\BsiCards;

use App\Exceptions\NotifyErrorException;
use App\Models\VirtualCard;
use App\Notifications\User\CustomMessageNotify;
use App\Services\Payment\PaymentGateway as PaymentGatewayInterface;
use App\Services\VirtualCard\Drivers\BsiCards\BsiCardsClient;
use App\Services\VirtualCard\VirtualCardActivityService;
use App\Services\VirtualCard\VirtualCardManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receiver for BSICARDS card webhooks at /ipn/bsicards.
 *
 * BSICARDS signs nothing. Its only proof of origin is the account's secret
 * key, echoed in a request header, so that is checked first and in constant
 * time. Even then the body is treated as a prompt, never as a record: no
 * balance, status or amount is written from it. The card it names is read
 * back from the BSICARDS API through the same verified sync the scheduler
 * and the admin refresh use, so a forged or replayed delivery can at worst
 * make the platform ask BSICARDS a question it already knows the answer to.
 *
 * Every authenticated delivery is answered 200, including unknown events
 * and cards this installation does not hold; anything else is retried every
 * ten minutes for a day.
 *
 * BSICARDS is a card provider, not a way to pay in, so there is no deposit.
 */
class BsiCardsPaymentGateway implements PaymentGatewayInterface
{
    /**
     * Header names the secret key may arrive under. BSICARDS documents only
     * that "the header contains your secret key"; `secretkey` is the name
     * its own API uses, and the others are its common spellings.
     */
    private const SECRET_HEADERS = ['secretkey', 'secret-key', 'x-secretkey', 'x-secret-key'];

    /**
     * Events that carry a one-time code the cardholder has to type: adding
     * the card to Apple Pay or Google Pay, or a purchase challenge.
     */
    private const CODE_EVENTS = ['digitalcard.xpayactivation', 'otp.code', 'card.otp'];

    public function deposit($amount, $currency, $trxId)
    {
        throw new NotifyErrorException(__('BSICARDS issues virtual cards; it is not a deposit method.'));
    }

    public function handleIPN(Request $request): JsonResponse
    {
        if (! $this->isFromBsiCards($request)) {
            // Header names only: their values may hold the key itself.
            Log::warning('BSICARDS webhook rejected: secret key missing or wrong', [
                'ip'      => $request->ip(),
                'headers' => array_keys($request->headers->all()),
            ]);

            return response()->json(['ok' => false], 401);
        }

        $payload = $request->all();
        $event   = strtolower(trim((string) ($payload['event'] ?? $payload['type'] ?? '')));
        $card    = $this->findCard($payload, $event);

        if (! $card) {
            Log::info('BSICARDS webhook for a card this installation does not hold', ['event' => $event]);

            return response()->json(['ok' => true, 'ignored' => 'unknown card']);
        }

        if (in_array($event, self::CODE_EVENTS, true)) {
            $this->notifyCode($card, $payload);
        }

        $this->syncCard($card, $event);

        return response()->json(['ok' => true]);
    }

    private function isFromBsiCards(Request $request): bool
    {
        try {
            $client = BsiCardsClient::fromGateway();
        } catch (Throwable) {
            return false;
        }

        foreach (self::SECRET_HEADERS as $header) {
            if ($client->secretKeyMatches($request->header($header))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The local BSICARDS card the delivery is about, if this installation
     * holds it. Only a card issued through BSICARDS can match.
     *
     * @param array<string, mixed> $payload
     */
    private function findCard(array $payload, string $event): ?VirtualCard
    {
        $data       = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $candidates = [
            $payload['cardid']  ?? null,
            $payload['cardId']  ?? null,
            $payload['card_id'] ?? null,
            $data['cardid']     ?? null,
            $data['cardId']     ?? null,
            $data['card_id']    ?? null,
            // A creation event names the card by its own id; on every other
            // event `data.id` is the transaction's.
            str_contains($event, 'created') ? ($data['id'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate) || trim((string) $candidate) === '') {
                continue;
            }

            $card = VirtualCard::query()
                ->where('provider_card_id', trim((string) $candidate))
                ->whereHas('provider', fn ($query) => $query->where('code', BsiCardsClient::GATEWAY_CODE))
                ->first();

            if ($card) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Ask BSICARDS about the card and record what it says. A failure here is
     * logged and left for the scheduled sync; it never turns the delivery
     * into an error BSICARDS would retry for a day.
     */
    private function syncCard(VirtualCard $card, string $event): void
    {
        try {
            app(VirtualCardManager::class)->syncCardFromProvider($card);
        } catch (Throwable $e) {
            Log::warning('BSICARDS webhook: card could not be verified with BSICARDS yet', [
                'event'           => $event,
                'virtual_card_id' => $card->id,
                'error'           => $e->getMessage(),
            ]);
        }

        try {
            app(VirtualCardActivityService::class)->sync($card->refresh(), force: true);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Send the cardholder the code BSICARDS relayed — once, however many
     * times the delivery is retried. `code` is deliberately not read: on a
     * BSICARDS body that is the status code, and "200" is not a passcode.
     *
     * @param array<string, mixed> $payload
     */
    private function notifyCode(VirtualCard $card, array $payload): void
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $code = trim((string) ($payload['activationCode'] ?? $payload['otp'] ?? $data['activationCode'] ?? $data['otp'] ?? ''));

        if ($code === '' || ! $card->user) {
            return;
        }

        $delivery = (string) ($payload['event_id'] ?? $card->id.':'.$code);

        if (! Cache::add('bsicards-code-sent:'.hash('sha256', $delivery), true, now()->addDay())) {
            return;
        }

        $wallet = strtolower((string) ($payload['digitalWalletName'] ?? ''));
        $last4  = (string) $card->last4;

        $card->user->notify(new CustomMessageNotify(
            match ($wallet) {
                'applepay'  => __('Your Apple Pay activation code for card ****:last4 is :code. If you did not add this card to Apple Pay, freeze it now.', ['last4' => $last4 ?: '••••', 'code' => $code]),
                'googlepay' => __('Your Google Pay activation code for card ****:last4 is :code. If you did not add this card to Google Pay, freeze it now.', ['last4' => $last4 ?: '••••', 'code' => $code]),
                default     => __('Your one-time code for card ****:last4 is :code. If you did not start this, freeze the card now.', ['last4' => $last4 ?: '••••', 'code' => $code]),
            },
            ['database', 'mail'],
            __('Card verification code'),
        ));
    }
}
