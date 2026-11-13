<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\AgentCashInRequest;
use App\Http\Requests\Agent\AgentCashOutOtpRequest;
use App\Http\Requests\Agent\AgentCashOutRequest;
use App\Http\Resources\Api\V1\Agent\AgentOperationResource;
use App\Models\AgentOperation;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\TemplateNotification;
use App\Services\AgentOperationService;
use App\Services\Api\ContactsService;
use App\Services\Money\WalletPinGuard;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Money over the counter.
 *
 * The service owns the whole operation - it locks the agent and both wallets,
 * checks the agent is approved, that the wallet currency is one this agent
 * supports, that the customer has a wallet in it, and that agent and customer
 * are not the same person, then writes all three ledger rows inside one
 * transaction. None of that is restated here, and none of it should be: a
 * second copy of those checks is a second thing to keep in step.
 *
 * The web request class is reused as it stands. Its rules already bind
 * agent_id and wallet_id to the caller through Rule::exists, which is what
 * stops a token being pointed at another agent's till, and its authorize()
 * asks the same gate the panel does.
 *
 * One thing the phone asks that the panel does not: the agent's wallet PIN,
 * when they have set one. A phone at a counter is lost or picked up far more
 * easily than a signed-in desk, and a cash-in spends the till's float. It is
 * the same counted check every other money flow in this API uses, through
 * WalletPinGuard, so guesses here share the one lockout.
 */
class AgentOperationController extends Controller
{
    public function __construct(
        private readonly AgentOperationService $operations,
        private readonly WalletPinGuard $pins,
    ) {}

    /**
     * Take cash from a customer and credit their wallet.
     *
     * A refusal from the service carries its own sentence - not approved, wrong
     * currency, no customer wallet, insufficient float - and reaches the app
     * through NotifyErrorException, which renders the envelope for routes that
     * opted into it. Catching each case here to reword it would put a second
     * vocabulary in front of the first.
     */
    /*
     * The `customer` field is an email address or a wallet uuid, which is
     * what WalletService::getWalletByUserEmailOrWalletUid accepts. A username
     * is NOT one of them, so the app must scan the wallet QR or take the
     * address - offering a username field would fail at the counter.
     */
    public function cashIn(AgentCashInRequest $request): JsonResponse
    {
        $this->checkPin($request, 'agent-cash-in');

        $operation = $this->operations->cashIn($request->user(), $request->validated());

        return ApiResponse::created(
            new AgentOperationResource($this->loaded($operation))
        );
    }

    /**
     * Text the customer a code before taking their money.
     *
     * No idempotency key: asking again is a legitimate action - the customer
     * did not get the message, or read it too late - and requiring a fresh key
     * for each resend would make the obvious button awkward to build. The
     * money rate limit is what bounds it.
     *
     * The service binds the code to the agent, the till wallet, the customer
     * wallet AND the amount, so a code issued for one figure cannot be spent
     * on another.
     */
    public function sendCashOutOtp(AgentCashOutOtpRequest $request): JsonResponse
    {
        $customer = $this->operations->sendCashOutOtp($request->user(), $request->validated());
        $minutes  = $this->operations->cashOutOtpExpiresMinutes();
        $channels = $this->otpChannels($customer);

        return ApiResponse::ok([
            // Whether the code could go anywhere at all: a template switched
            // off, or a customer with every channel muted, leaves the agent
            // waiting for a code that will never come.
            'sent'            => $channels !== [],
            'channels'        => $channels,
            'expires_minutes' => $minutes,
            'expires_at'      => now()->addMinutes($minutes)->toIso8601String(),
            // Only when it goes by SMS, masked the way contacts are, so the
            // agent can say where to look without reading the number out.
            'phone' => in_array('sms', $channels, true) && $customer->phone
                ? ContactsService::maskPhone((string) $customer->phone)
                : null,
        ]);
    }

    /**
     * Give the customer their cash and debit their wallet.
     */
    public function cashOut(AgentCashOutRequest $request): JsonResponse
    {
        $this->checkPin($request, 'agent-cash-out');

        $operation = $this->operations->cashOut($request->user(), $request->validated());

        return ApiResponse::created(
            new AgentOperationResource($this->loaded($operation))
        );
    }

    /**
     * The agent's wallet PIN, when they have set one. Validated on its own and
     * never into validated(), so it cannot reach the service or a log line
     * built from the request's data. WalletPinGuard's refusals carry their own
     * codes - WALLET_PIN_REQUIRED, WALLET_PIN_INVALID - so the app can ask for
     * the PIN or shake the dots.
     */
    private function checkPin(Request $request, string $flow): void
    {
        $request->validate(['wallet_pin' => ['nullable', 'string', 'digits:6']]);

        $this->pins->assert($request->user(), $request->input('wallet_pin'), context: ['flow' => $flow]);
    }

    /**
     * Where the cash-out code goes, as the notification itself decides it:
     * sms, email, app - in that order.
     *
     * @return list<string>
     */
    private function otpChannels(User $customer): array
    {
        $via = (new TemplateNotification('agent_assisted_cash_out_otp'))->via($customer);

        return array_values(array_filter([
            in_array(TwilioChannel::class, $via, true) ? 'sms' : null,
            in_array('mail', $via, true) ? 'email' : null,
            array_intersect(['database', 'broadcast', FcmChannel::class, WebPushChannel::class], $via) !== [] ? 'app' : null,
        ]));
    }

    private function loaded(AgentOperation $operation): AgentOperation
    {
        return $operation->load(['customer', 'currency']);
    }
}
