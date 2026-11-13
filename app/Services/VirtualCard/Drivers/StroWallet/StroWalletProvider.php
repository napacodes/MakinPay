<?php

namespace App\Services\VirtualCard\Drivers\StroWallet;

use App\Enums\VirtualCard\CardholderType;
use App\Enums\VirtualCard\VirtualCardNetwork;
use App\Enums\VirtualCard\VirtualCardStatus;
use App\Exceptions\NotifyErrorException;
use App\Models\Cardholders;
use App\Models\PaymentGateway;
use App\Models\VirtualCard;
use App\Models\VirtualCardRequest;
use App\Services\Payment\StroWallet\StroWalletEndpoints;
use App\Services\VirtualCard\Drivers\AbstractVirtualCardProvider;
use App\Services\VirtualCard\Drivers\SupportsCardActivity;
use App\Services\VirtualCard\VirtualCardActivityService;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

class StroWalletProvider extends AbstractVirtualCardProvider implements SupportsCardActivity
{
    /**
     * Base for every StroWallet endpoint, resolved once per instance.
     *
     * Sandbox vs live is switched via the `mode` body parameter, not the
     * URL, so one base serves both. The host now comes from
     * `StroWalletEndpoints` so an installation can be moved without a code
     * change — see that class for why the default is still strowallet.com
     * and not the ziiropay.com that StroWallet's newer NFC docs show.
     *
     * Defaulted rather than left bare so an instance built without the
     * constructor still has a usable base instead of raising an
     * uninitialized-property Error; the constructor overwrites it with the
     * resolved host.
     */
    private string $apiBase = StroWalletEndpoints::DEFAULT_HOST.'/api';

    /**
     * Map our cardholder `id_type` vocabulary to the values StroWallet's
     * `/bitvcard/create-user` endpoint documents:
     *
     *     "type of ID e.g BVN, NIN, PASSPORT"
     *
     * Strowallet's KYC pipeline is Nigerian-first so it understands
     * BVN (Bank Verification Number) and NIN (National ID), then falls
     * back to PASSPORT for non-Nigerian residents. Uppercase values are
     * the documented form.
     *
     * @var array<string, string>
     */
    private const ID_TYPE_MAP = [
        'passport'         => 'PASSPORT',
        'national_id'      => 'NIN',
        'drivers_license'  => 'DRIVERS_LICENSE',
        'residence_permit' => 'PASSPORT', // Strowallet has no permit slot — passport is the safe equivalent for foreign residents
        'voter_id'         => 'NIN',      // Closest national-ID analogue
        'bvn'              => 'BVN',      // Allow Nigerian operators to opt in explicitly
        'nin'              => 'NIN',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $credentials;

    private Client $httpClient;

    private string $mode;

    public function __construct()
    {
        $this->credentials = PaymentGateway::getCredentials('strowallet');
        $this->httpClient  = new Client;
        $this->apiBase     = StroWalletEndpoints::api($this->credentials);

        $this->mode = filter_var($this->credentials['sandbox'] ?? false, FILTER_VALIDATE_BOOL)
            ? 'sandbox'
            : 'live';

        if (! PaymentGateway::credentialIsFilled('public_key', $this->credentials['public_key'] ?? null)) {
            throw new Exception('StroWallet API credentials are not configured.');
        }
    }

    /**
     * The card id sent on the credential probe. It never exists, so the
     * probe cannot read or change a real card; the only thing StroWallet can
     * do with it is accept or reject the key.
     */
    private const HEALTH_PROBE_CARD_ID = 'DK_HEALTH_CHECK';

    /**
     * StroWallet health probe.
     *
     * Asks the documented card-transactions endpoint for a card that does not
     * exist. StroWallet validates the public key before it looks at the card,
     * and it does so in sandbox as well as live — unlike the card-detail and
     * funding endpoints, which answer sandbox calls with canned data whatever
     * key they are given, so a probe against those would call any key valid.
     *
     * The customer-list URL this used to call is not part of StroWallet's API:
     * the host answers it with the marketing site's HTML, so the previous probe
     * reported every key — valid or not — as failed.
     */
    public function testConnection(): array
    {
        $started = microtime(true);
        try {
            $response = $this->httpClient->post($this->apiBase.'/bitvcard/card-transactions/', [
                'json' => [
                    'public_key' => $this->credentials['public_key'],
                    'card_id'    => self::HEALTH_PROBE_CARD_ID,
                    'mode'       => $this->mode,
                ],
                'headers' => [
                    'accept'       => 'application/json',
                    'content-type' => 'application/json',
                ],
                'timeout'     => 10,
                'http_errors' => false,
            ]);
            $latency = (int) ((microtime(true) - $started) * 1000);
            $body    = json_decode($response->getBody()->getContents(), true);
            $ok      = self::probeAcceptedKey($response->getStatusCode(), $body);

            return [
                'ok'      => $ok,
                'mode'    => $this->mode,
                'message' => $ok
                    ? __('StroWallet credentials accepted. Reachable in :ms ms.', ['ms' => $latency])
                    : (is_array($body)
                        ? $this->providerMessage($body, __('StroWallet returned a non-success status.'))
                        : __('StroWallet did not answer with JSON; the API host may be unreachable.')),
                'latency_ms' => $latency,
                'details'    => [
                    'base_url'    => $this->apiBase,
                    'status_code' => $response->getStatusCode(),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'ok'         => false,
                'mode'       => $this->mode,
                'message'    => __('StroWallet connection failed: :err', ['err' => $e->getMessage()]),
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
                'details'    => ['exception' => get_class($e)],
            ];
        }
    }

    public function issueCard(VirtualCardRequest $request): array
    {
        $customerData = $this->resolveCustomerData($request);

        $customerResponse = $this->createCustomer($customerData);

        // The card is embossed with the CARDHOLDER's legal name, not the
        // user-account holder's. These can differ when a user creates a
        // card on behalf of a family member, employee, etc. The previous
        // `$request->user->name` would put the parent's name on a child's
        // card — wrong, and Strowallet's KYC would reject it.
        $nameOnCard = trim($customerData['firstName'].' '.($customerData['lastName'] ?? ''));

        $cardData = [
            'name_on_card'  => $nameOnCard,
            'card_type'     => $this->resolveCardType($request),
            'public_key'    => $this->credentials['public_key'],
            'amount'        => $request->providerLoadAmount(),
            'customerEmail' => $this->customerEmailForCardCreation($customerData['customerEmail']),
            'mode'          => $this->mode,
        ];

        $cardResponse = $this->createCard($cardData);

        return [
            'id'           => $cardResponse['card_id'],
            'last4'        => $cardResponse['last4'],
            'brand'        => $cardResponse['brand'],
            'expiry_month' => $cardResponse['expiry_month'],
            'expiry_year'  => $cardResponse['expiry_year'],
            'status'       => $cardResponse['status'],
            'meta'         => $cardResponse['meta'],
            // Raw API responses persisted on `virtual_card_requests.provider_response`
            // by VirtualCardManager. Invaluable when debugging "why did the issuance
            // status come back like this?" — the previous implementation always saved
            // null here because the `raw` key was never returned.
            'raw' => [
                'customer' => $customerResponse,
                'card'     => $cardResponse['raw'] ?? null,
            ],
        ];
    }

    /**
     * Resolve the StroWallet `card_type` value from the request's network.
     *
     * Falls back to `visa` when no network is set (legacy rows from before
     * the network column was required) — StroWallet's sandbox issues only
     * Visa cards regardless, so this remains the safest default.
     */
    private function resolveCardType(VirtualCardRequest $request): string
    {
        $network = $request->network instanceof VirtualCardNetwork
            ? $request->network->value
            : (string) $request->network;

        return $network !== '' ? strtolower($network) : 'visa';
    }

    /**
     * Pick the customerEmail used when calling `/bitvcard/create-card/`.
     *
     * StroWallet's sandbox historically accepts only a small set of demo
     * customer emails. Keep that override behaviour but make it
     * configurable so each environment can pin its own sandbox account.
     *
     * Live mode always uses the real cardholder email.
     */
    private function customerEmailForCardCreation(string $real): string
    {
        if ($this->mode !== 'sandbox') {
            return $real;
        }

        $sandboxEmail = trim((string) ($this->credentials['sandbox_customer_email'] ?? ''));

        return $sandboxEmail !== '' ? $sandboxEmail : 'mydemo@gmail.com';
    }

    public function topUpCard($amount, $cardID): array
    {
        return $this->moveCardFunds('fund', $amount, $cardID, 'top-up', 'StroWallet top-up failed.');
    }

    public function withdrawFromCard($amount, $cardID): array
    {
        return $this->moveCardFunds('withdraw', $amount, $cardID, 'withdraw', 'StroWallet withdraw failed.');
    }

    /**
     * Fund or unload a card, keeping "StroWallet refused" apart from
     * "StroWallet never answered".
     *
     * The documented endpoint is `POST /bitvcard/fund-withdraw-nfccard` with
     * `type` = `fund` | `withdraw`; the older `fund-card` / `card_withdraw`
     * pair is no longer in the reference but is still served, and is used
     * only when the host says the documented route does not exist. Both
     * answer `success` + a `reference`; the documented one reports the
     * movement as `pending` and confirms it through the card webhooks.
     *
     * `VirtualCardManager::dispatchTopup()` reads the refused/unanswered
     * distinction off the exception type: a `NotifyErrorException` is a
     * conclusive refusal and is the only branch that returns the customer's
     * money, while any other Throwable means the outcome is unknown and the
     * attempt is parked for an operator rather than refunded — refunding a
     * top-up that actually landed would pay the customer twice.
     *
     * Collapsing both into one generic Exception, as this used to, meant a
     * flat refusal left the wallet debited and the attempt waiting on a human.
     *
     * Reference: https://strowallet.readme.io/reference/fund-withdraw-nfc
     *
     * @param  'fund'|'withdraw'          $type
     * @param  string|int|float           $amount
     * @param  string|int                 $cardID
     * @return array<string, string|null>
     *
     * @throws NotifyErrorException when StroWallet answered and refused
     * @throws Exception            when the call never completed
     */
    private function moveCardFunds(string $type, $amount, $cardID, string $action, string $fallbackMessage): array
    {
        $parameters = [
            'card_id'    => $cardID,
            'amount'     => $amount,
            'public_key' => $this->credentials['public_key'],
            'mode'       => $this->mode,
        ];

        try {
            [$statusCode, $responseData] = $this->callDocumentedThenLegacy($action, 15, [
                ['method' => 'POST', 'path' => '/bitvcard/fund-withdraw-nfccard', 'params' => $parameters + ['type' => $type]],
                ['method' => 'POST', 'path' => $type === 'fund' ? '/bitvcard/fund-card/' : '/bitvcard/card_withdraw/', 'params' => $parameters],
            ]);
        } catch (Throwable $e) {
            Log::error('StroWallet '.$action.' transport failure', ['error' => $e->getMessage()]);

            throw new Exception('StroWallet API '.$action.' error: '.$e->getMessage());
        }

        // A gateway error page or a server error is not an answer. StroWallet
        // may well have moved the money before its edge fell over, so this is
        // reported as unfinished — never as a refusal that refunds, and never
        // as a success that settles.
        if ($statusCode >= 500 || ! is_array($responseData)) {
            Log::error('StroWallet '.$action.' answered without a usable result', ['status' => $statusCode]);

            throw new Exception('StroWallet API '.$action.' error: the provider answered HTTP '.$statusCode.' without a readable result.');
        }

        try {
            $this->assertResponseOk($responseData, $fallbackMessage);
        } catch (Throwable $e) {
            throw new NotifyErrorException($e->getMessage());
        }

        $data = is_array($responseData['response'] ?? null)
            ? $responseData['response']
            : (is_array($responseData['apiresponse']['data'] ?? null) ? $responseData['apiresponse']['data'] : []);

        return [
            'id'        => $data['id']        ?? null,
            'status'    => $data['status']    ?? null,
            'cardId'    => $data['cardId']    ?? $data['card_id'] ?? null,
            'reference' => $data['reference'] ?? null,
        ];
    }

    /**
     * Call the documented endpoint and, only if the host answers that the
     * route does not exist, the legacy one.
     *
     * strowallet.com serves both generations today and StroWallet's reference
     * lists only the new one, so the new one goes first; the legacy path is a
     * safety net for a host that has not been given it yet, not a retry. A
     * route the host does not know cannot have executed anything, which is
     * what makes trying the next path safe for a call that moves money — any
     * other answer, a refusal or a server error included, is returned as-is.
     *
     * Transport failures propagate so each caller keeps its own reading of
     * "the provider never answered".
     *
     * @param  array<int, array{method: string, path: string, params: array<string, mixed>}> $attempts
     * @return array{0: int, 1: array<string, mixed>|null}                                   status code and decoded body of the answer used
     */
    private function callDocumentedThenLegacy(string $action, int $timeout, array $attempts): array
    {
        $answer = [0, null];

        foreach ($attempts as $index => $attempt) {
            $options = [
                'headers' => [
                    'accept'       => 'application/json',
                    'content-type' => 'application/json',
                ],
                'timeout'     => $timeout,
                'http_errors' => false,
            ];

            $options[$attempt['method'] === 'GET' ? 'query' : 'json'] = $attempt['params'];

            $response = $this->httpClient->request($attempt['method'], $this->apiBase.$attempt['path'], $options);
            $body     = json_decode((string) $response->getBody(), true);
            $answer   = [$response->getStatusCode(), is_array($body) ? $body : null];

            if (! self::routeIsMissing($answer[0], $answer[1]) || $index === array_key_last($attempts)) {
                return $answer;
            }

            Log::info('StroWallet documented endpoint is not served on this host; using the legacy path.', [
                'action' => $action,
                'path'   => $attempt['path'],
                'status' => $answer[0],
            ]);
        }

        return $answer;
    }

    /**
     * Whether an answer means "no such route" rather than "no".
     *
     * StroWallet's host answers an unknown route with a bare Laravel 404
     * (`{"message": ""}`) or a 405; its application errors carry a `success`
     * or `status` key even when they borrow the 404 code (the checkout status
     * endpoint answers "Invalid Public key" as a 404). Only the former is a
     * route the host does not have.
     *
     * @param array<string, mixed>|null $body
     */
    public static function routeIsMissing(int $statusCode, ?array $body): bool
    {
        if ($statusCode === 405) {
            return true;
        }

        return $statusCode === 404
            && (! is_array($body) || (! array_key_exists('success', $body) && ! array_key_exists('status', $body)));
    }

    public function getCardDetails(VirtualCard $card)
    {
        $providerCardId = $card->meta['card_id'] ?? $card->provider_card_id;

        if (! $providerCardId) {
            throw new NotifyErrorException(__('Provider card id is missing for this card.'));
        }

        try {
            $details = $this->fetchCardDetails($providerCardId)['card_detail'] ?? null;

            if (! $details) {
                throw new NotifyErrorException(__('Failed to retrieve card details.'));
            }

            return [
                'card_name'        => $details['card_name']   ?? null,
                'card_status'      => $details['card_status'] ?? $card->status?->value,
                'card_brand'       => $details['card_brand']  ?? $card->brand,
                'card_number'      => $details['card_number'] ?? null,
                'last4'            => $details['last4']       ?? null,
                'currency'         => $card->provider?->cardCurrency(),
                'cvv'              => $details['cvv']              ?? null,
                'expiry'           => $details['expiry']           ?? null,
                'card_holder_name' => $details['card_holder_name'] ?? null,
                'card_type'        => $details['card_type']        ?? null,
                'balance'          => isset($details['balance']) && is_numeric($details['balance']) ? $details['balance'] : null,
                'created_at'       => $details['card_created_date'] ?? null,
                // The documented detail shape carries the billing address as
                // `address`, `city`, `state`, `postal`, `country`; the older
                // one as `billing_*`. Either is read.
                'billing_street'   => $details['billing_street']   ?? $details['address'] ?? null,
                'billing_city'     => $details['billing_city']     ?? $details['city'] ?? null,
                'billing_country'  => $details['billing_country']  ?? $details['country'] ?? null,
                'billing_zip_code' => $details['billing_zip_code'] ?? $details['postal'] ?? null,
                'customer_email'   => $details['customer_email']   ?? null,
                'reference'        => $details['reference']        ?? null,
            ];
        } catch (Throwable $e) {
            Log::error('StroWallet card details fetch error', ['error' => $e->getMessage()]);
            throw new NotifyErrorException(__('Card details API error: :msg', ['msg' => $e->getMessage()]));
        }
    }

    private function resolveCustomerData(VirtualCardRequest $request): array
    {
        $ch         = Cardholders::with('business')->with('user')->findOrFail($request->cardholder_id);
        $isBusiness = $ch->card_type === CardholderType::BUSINESS;

        if ($isBusiness) {
            throw new NotifyErrorException('StroWallet only supports personal accounts, not business accounts.');
        }

        // Provider-universal source of truth: structured columns first,
        // legacy KYC-template fall-through second. Existing rows that
        // were saved via the old template flow keep working.
        $rawIdType  = $ch->id_type ?: optional($ch->kycTemplate)->title;
        $idDocument = $ch->kyc_documents['id_document'] ?? null;
        if (! $idDocument && optional($ch->kycTemplate)->fields) {
            $legacyFile = collect($ch->kycTemplate->fields)->firstWhere('type', 'file');
            if ($legacyFile) {
                $idDocument = $ch->kyc_documents[$legacyFile['label']] ?? null;
            }
        }

        if (! $rawIdType || ! $idDocument) {
            throw new NotifyErrorException('Cardholder is missing the Government ID type or document image.');
        }

        if (empty($ch->id_number)) {
            throw new NotifyErrorException('Cardholder is missing the Government ID number.');
        }

        // Map our wider id_type vocabulary onto StroWallet's documented set
        // (PASSPORT, NIN, BVN, DRIVERS_LICENSE). PASSPORT is the safe
        // fallback for unknown values because every operator accepts it.
        $mappedIdType = self::ID_TYPE_MAP[strtolower($rawIdType)] ?? 'PASSPORT';

        $idImageUrl = asset($idDocument);
        // Cardholder selfie lives in `kyc_documents` (uploaded as part
        // of the cardholder form). Fall back to the user-account avatar
        // only for legacy rows that pre-date the cardholder selfie
        // upload, so user-on-behalf-of-cardholder flows put the right
        // person's face on the KYC packet.
        $cardholderSelfie = $ch->kyc_documents['selfie']
            ?? $ch->kyc_documents['user_photo']
            ?? $ch->kyc_documents['photo']
            ?? null;
        $userPhotoUrl = $cardholderSelfie ? asset($cardholderSelfie) : asset($ch->user->avatar_alt);

        // StroWallet fetches both images server-side. If APP_URL points at
        // localhost / private IPs / .test / .local then their servers
        // can't reach the files, customer creation fails with a generic
        // "could not download image" error that's painful to diagnose.
        // Catch it here with a message that names the actual problem.
        $this->assertImageUrlReachable($idImageUrl, 'Government ID image');
        $this->assertImageUrlReachable($userPhotoUrl, 'User selfie / avatar');

        return [
            'public_key'    => $this->credentials['public_key'],
            'firstName'     => $ch->first_name,
            'lastName'      => $ch->last_name,
            'customerEmail' => $ch->email,
            // StroWallet doc: "mobile number in international format
            // without +". A common shape on our side is `+8801712345678`
            // — strip the `+` and any spaces/dashes so the API sees a
            // pure digit string.
            'phoneNumber' => $this->normalizePhoneNumber($ch->mobile, $ch->phone_country_code),
            'dob'         => $ch->dob ? (new DateTime($ch->dob))->format('m/d/Y') : null,
            'idImage'     => $idImageUrl,
            'userPhoto'   => $userPhotoUrl,
            'street'      => $ch->address_line1,
            'state'       => $ch->state,
            'zipCode'     => $ch->postal_code,
            'city'        => $ch->city,
            'country'     => $ch->country,
            'idType'      => $mappedIdType,
            'idNumber'    => $ch->id_number,
        ];

    }

    /**
     * Normalize a phone number to "international format without +" as
     * required by StroWallet's `/bitvcard/create-user` endpoint.
     *
     * Cases handled:
     *   `+8801712345678`, `null`         → `8801712345678`     (just strip +)
     *   `+1 (415) 555-2671`, `null`      → `14155552671`       (strip formatting)
     *   `01712345678`, `+880`            → `88001712345678`    (prepend country)
     *   `8801712345678`, `+880`          → `8801712345678`     (don't double-prepend)
     *
     * Heuristic: a string starting with `+` is treated as international.
     * A string starting with the supplied country code (digits only) is
     * also treated as international. Everything else is treated as
     * local and the country code is prepended.
     */
    private function normalizePhoneNumber(?string $mobile, ?string $countryCode = null): string
    {
        $mobile = trim((string) $mobile);
        if ($mobile === '') {
            return '';
        }

        $countryDigits = preg_replace('/\D+/', '', (string) $countryCode);

        $isInternational = str_starts_with($mobile, '+')
            || ($countryDigits !== '' && str_starts_with(preg_replace('/\D+/', '', $mobile), $countryDigits));

        if (! $isInternational && $countryDigits !== '') {
            /*
             * THROUGH formatPhone(), so the trunk-zero rule has one
             * definition. Gluing the dial code straight on left the zero a
             * customer dials at home in place - '+880' and '01712345678'
             * became '88001712345678', one digit too long in most countries -
             * and the country that legitimately keeps its zero, Italy, was
             * handled nowhere. A second copy of the rule here would drift
             * from the one the rest of the platform uses, which is how this
             * survived the fix that corrected formatPhone() itself.
             */
            $mobile = formatPhone($countryDigits, $mobile);
        }

        // Strip every non-digit (covers `+`, spaces, dashes, parens).
        return preg_replace('/\D+/', '', $mobile) ?? '';
    }

    /**
     * Reject URLs StroWallet's servers can't fetch from the public internet.
     *
     * Detects the common dev-time mistake of running issuance from a
     * machine whose `APP_URL` is `http://localhost`, `http://127.0.0.1`,
     * a `.test`/`.local` host, or a private RFC1918 IP. In all of those
     * cases StroWallet returns a generic image-download failure and the
     * admin can't tell why.
     *
     * Only the host is checked — the scheme being http (vs https) is
     * fine for StroWallet, they accept both.
     *
     * @throws NotifyErrorException when the URL is not internet-reachable.
     */
    private function assertImageUrlReachable(?string $url, string $label): void
    {
        if (! $url) {
            throw new NotifyErrorException(__(':label is missing.', ['label' => $label]));
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            throw new NotifyErrorException(__(':label URL is malformed.', ['label' => $label]));
        }

        $host  = strtolower($host);
        $isDev = $host === 'localhost'
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.localhost');

        if (! $isDev && filter_var($host, FILTER_VALIDATE_IP)) {
            // Private / reserved IP ranges are not reachable from outside.
            $isDev = ! filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        if ($isDev) {
            throw new NotifyErrorException(__(
                ':label URL (:url) is not reachable from the public internet. '
                .'StroWallet downloads images server-side, so APP_URL must point '
                .'at a publicly-accessible domain (e.g. your tunnel or staging URL).',
                ['label' => $label, 'url' => $url]
            ));
        }
    }

    private function createCustomer(array $data): array
    {
        // The trailing slash matches the canonical path in StroWallet's
        // "Create customer" reference
        // (https://strowallet.readme.io/reference/create-customer):
        //     <host>/api/bitvcard/create-user/
        // Dropping it is not cosmetic. A slash-less request is answered
        // with a 301, and Guzzle follows that having already dropped the
        // body — which surfaces as "missing required field" rather than as
        // a redirect. Confirmed live: every legacy path on ziiropay.com
        // answers 301 today for exactly this reason.
        $response = $this->httpClient->post($this->apiBase.'/bitvcard/create-user/', [
            'json' => [
                'public_key'    => $data['public_key'],
                'mode'          => $this->mode,
                'houseNumber'   => $data['street'],
                'firstName'     => $data['firstName'],
                'lastName'      => $data['lastName'] ?? '',
                'idNumber'      => $data['idNumber'],
                'customerEmail' => $data['customerEmail'],
                'phoneNumber'   => $data['phoneNumber'],
                'dateOfBirth'   => $data['dob'] ?? '',
                'idImage'       => $data['idImage'],
                'userPhoto'     => $data['userPhoto'],
                'line1'         => $data['street'],
                'state'         => $data['state'],
                'zipCode'       => $data['zipCode'],
                'city'          => $data['city'],
                'country'       => $data['country'],
                'idType'        => $data['idType'],
            ],
            'headers'     => ['Content-Type' => 'application/json'],
            'timeout'     => 30,
            'http_errors' => false,
        ]);

        $response_data = json_decode($response->getBody(), true);

        // A cardholder who already has a StroWallet customer record — the
        // second card on the same email, or a retried approval — makes
        // `create-user` answer "customer already exists". That is not a
        // failure of the issuance: the record the card needs is there, so the
        // flow continues to `create-card` instead of aborting the approval.
        if ($this->customerAlreadyExists($response_data)) {
            Log::info('StroWallet customer already registered; reusing it for card issuance.');

            return is_array($response_data) ? $response_data : [];
        }

        $this->assertResponseOk($response_data, 'StroWallet customer creation failed.');

        return $response_data;
    }

    /**
     * Whether a failed `create-user` response only means the customer is
     * already on file at StroWallet.
     *
     * @param array<string, mixed>|null $body
     */
    private function customerAlreadyExists(?array $body): bool
    {
        if (! is_array($body) || $this->responseLooksSuccessful($body)) {
            return false;
        }

        $message = strtolower(trim((string) ($body['message'] ?? $body['error'] ?? '')));

        if ($message === '') {
            return false;
        }

        foreach (['already exist', 'already registered', 'already have', 'duplicate'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function createCard(array $data): array
    {

        $response = $this->httpClient->post($this->apiBase.'/bitvcard/create-card/', [
            'json' => [
                'name_on_card'  => $data['name_on_card'],
                'card_type'     => $data['card_type'],
                'public_key'    => $data['public_key'],
                'amount'        => $data['amount'],
                'customerEmail' => $data['customerEmail'],
                'mode'          => $data['mode'],
            ],
            'headers' => [
                'accept'       => 'application/json',
                'content-type' => 'application/json',
            ],
            'timeout'     => 30,
            'http_errors' => false,
        ]);

        $response_data = json_decode($response->getBody(), true);
        $this->assertResponseOk($response_data, 'StroWallet card issuance failed.');

        $created = is_array($response_data['response'] ?? null) ? $response_data['response'] : [];
        $cardId  = trim((string) ($created['card_id'] ?? ''));

        if ($cardId === '') {
            Log::error('StroWallet card issuance returned no card id.', ['response' => $response_data]);

            $providerMessage = trim((string) ($response_data['message'] ?? ''));

            throw new Exception($providerMessage !== '' ? $providerMessage : 'StroWallet card issuance failed.');
        }

        // Issuance is asynchronous. `create-card` allocates the id, and the
        // PAN, expiry and brand only exist once StroWallet finishes
        // provisioning — which it announces through the
        // `virtualcard.created.complete` webhook. A detail fetch fired
        // milliseconds later therefore comes back empty (or errors) on a
        // perfectly healthy issuance, so it must not abort the approval: the
        // card row is saved pending and the webhook backfills it.
        $cardDetails = $this->fetchCardDetailsQuietly($cardId);

        [$expiryMonth, $expiryYear] = $this->parseExpiry($cardDetails['expiry'] ?? null);

        $meta = array_filter([
            'card_id'      => $cardId,
            'card_user_id' => $cardDetails['card_user_id'] ?? $created['card_user_id'] ?? null,
            'customer_id'  => $cardDetails['customer_id']  ?? $created['customer_id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return [
            'card_id'      => $cardId,
            'last4'        => $cardDetails['last4']      ?? null,
            'brand'        => $cardDetails['card_brand'] ?? $created['card_brand'] ?? $data['card_type'],
            'expiry_month' => $expiryMonth,
            'expiry_year'  => $expiryYear,
            'status'       => $this->mapCardStatus(
                $cardDetails['card_status'] ?? $created['card_status'] ?? null
            ),
            'meta' => $meta,
            'raw'  => $response_data,
        ];
    }

    /**
     * Fetch card details without letting a not-yet-provisioned card abort the
     * issuance. Returns an empty detail set when StroWallet has nothing to
     * show yet.
     *
     * @return array<string, mixed>
     */
    private function fetchCardDetailsQuietly(string $cardId): array
    {
        try {
            $details = $this->fetchCardDetails($cardId)['card_detail'] ?? null;

            return is_array($details) ? $details : [];
        } catch (Throwable $e) {
            Log::warning('StroWallet card details not available yet after issuance.', [
                'card_id' => $cardId,
                'error'   => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Split StroWallet's `MM/YY` (or `MM/YYYY`) expiry into the two columns
     * the card row stores, returning nulls for anything that is not an expiry.
     *
     * The previous unguarded `explode('/', ...)` raised a TypeError whenever
     * the field was absent — which is the normal shape of a card StroWallet
     * has not finished provisioning, so a healthy async issuance surfaced to
     * the admin as a crashed approval.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parseExpiry(mixed $expiry): array
    {
        if (! is_string($expiry) || ! preg_match('#^(\d{1,2})/(\d{2}|\d{4})$#', trim($expiry), $matches)) {
            return [null, null];
        }

        $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);

        if ((int) $month < 1 || (int) $month > 12) {
            return [null, null];
        }

        return [$month, strlen($matches[2]) === 2 ? '20'.$matches[2] : $matches[2]];
    }

    /**
     * Translate StroWallet's card status vocabulary into `VirtualCardStatus`.
     *
     * Passing the provider's own wording straight through made the enum cast
     * on `virtual_cards.status` reject the row, so a card StroWallet had
     * already created was lost to a database error. Unknown wording maps to
     * `pending` because StroWallet provisions asynchronously and the
     * `virtualcard.created.complete` webhook settles the real outcome.
     */
    private function mapCardStatus(?string $status): string
    {
        return (VirtualCardStatus::fromProvider($status) ?? VirtualCardStatus::Pending)->value;
    }

    /**
     * Freeze (block) a StroWallet card.
     *
     * The documented call is `POST /bitvcard/nfc-cards/status` with
     * `status=frozen`; the older `POST /bitvcard/action/status` with
     * `action=freeze` is the fallback when the host lacks the documented
     * route. On success the controller (callsite) still flips our local DB
     * status; we just report `soft=false` so the UI doesn't show the "freeze
     * didn't propagate to the provider" hint.
     *
     * Reference: https://strowallet.readme.io/reference/freezeactivate-nfc
     */
    /**
     * The card's transactions as StroWallet lists them, read into the
     * platform's own rows. StroWallet's wording varies between hosts, so
     * every field is read from the names seen in the wild and a row that
     * carries no amount is skipped rather than guessed at.
     */
    public function listCardActivity(VirtualCard $card): array
    {
        $providerCardId = $card->meta['card_id'] ?? $card->provider_card_id;
        if (! $providerCardId) {
            return [];
        }

        $parameters = [
            'public_key' => $this->credentials['public_key'],
            'card_id'    => $providerCardId,
            'mode'       => $this->mode,
        ];

        [, $body] = $this->callDocumentedThenLegacy('card transactions', 20, [
            ['method' => 'POST', 'path' => '/bitvcard/card-transactions/', 'params' => $parameters],
        ]);

        $rows = is_array($body) ? ($body['response']['card_transactions'] ?? $body['response']['transactions'] ?? $body['response'] ?? $body['data'] ?? []) : [];
        if (! is_array($rows)) {
            return [];
        }

        $currency = $card->provider?->cardCurrency() ?? 'USD';
        $out      = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $amount = $row['amount'] ?? $row['transaction_amount'] ?? $row['card_amount'] ?? null;
            if (! is_numeric($amount)) {
                continue;
            }
            $type   = strtolower((string) ($row['type'] ?? $row['transaction_type'] ?? $row['category'] ?? 'purchase'));
            $status = strtolower((string) ($row['status'] ?? $row['transaction_status'] ?? 'success'));
            $failed = in_array($status, ['declined', 'failed', 'rejected', 'error'], true);
            $kind   = match (true) {
                $failed                                                                                          => 'decline',
                str_contains($type, 'refund')   || str_contains($type, 'reversal')                               => 'refund',
                str_contains($type, 'fund')     || str_contains($type, 'topup') || str_contains($type, 'credit') => 'topup',
                str_contains($type, 'withdraw') || str_contains($type, 'unload')                                 => 'withdraw',
                str_contains($type, 'fee')                                                                       => 'fee',
                default                                                                                          => 'purchase',
            };
            $out[] = [
                'reference'         => (string) ($row['id'] ?? $row['transaction_id'] ?? $row['reference'] ?? md5(json_encode($row))),
                'kind'              => $kind,
                'status'            => $failed ? 'declined' : (in_array($status, ['pending', 'processing', 'authorized', 'authorised'], true) ? 'pending' : 'settled'),
                'amount'            => (string) abs((float) $amount),
                'currency'          => strtoupper((string) ($row['currency'] ?? $currency)),
                'merchant'          => $row['merchant']     ?? $row['merchant_name'] ?? $row['narrative'] ?? $row['description'] ?? null,
                'category'          => $row['mcc_category'] ?? $row['merchant_category'] ?? null,
                'channel'           => isset($row['channel']) ? strtolower((string) $row['channel']) : null,
                'original_amount'   => isset($row['original_amount']) && is_numeric($row['original_amount']) ? (string) $row['original_amount'] : null,
                'original_currency' => isset($row['original_currency']) ? strtoupper((string) $row['original_currency']) : null,
                'decline_reason'    => $failed ? VirtualCardActivityService::reasonFrom((string) ($row['reason'] ?? $row['decline_reason'] ?? $row['narrative'] ?? '')) : null,
                'occurred_at'       => (string) ($row['created_at'] ?? $row['createdAt'] ?? $row['date'] ?? $row['transaction_date'] ?? now()->toIso8601String()),
                'meta'              => ['provider' => 'strowallet'],
            ];
        }

        return $out;
    }

    public function freezeCard(VirtualCard $card): array
    {
        $this->setCardStatus($card, 'freeze');

        return ['status' => 'inactive', 'soft' => false];
    }

    /**
     * Unfreeze (re-activate) a StroWallet card via the same status endpoint.
     */
    public function unfreezeCard(VirtualCard $card): array
    {
        $this->setCardStatus($card, 'unfreeze');

        return ['status' => 'active', 'soft' => false];
    }

    /**
     * @param 'freeze'|'unfreeze' $action
     */
    private function setCardStatus(VirtualCard $card, string $action): void
    {
        $providerCardId = $card->meta['card_id'] ?? $card->provider_card_id;
        if (! $providerCardId) {
            throw new NotifyErrorException(__('Provider card id is missing for this card.'));
        }

        $parameters = [
            'public_key' => $this->credentials['public_key'],
            'mode'       => $this->mode,
            'card_id'    => $providerCardId,
        ];

        [, $body] = $this->callDocumentedThenLegacy($action, 15, [
            ['method' => 'POST', 'path' => '/bitvcard/nfc-cards/status', 'params' => $parameters + ['status' => $action === 'freeze' ? 'frozen' : 'active']],
            ['method' => 'POST', 'path' => '/bitvcard/action/status/', 'params' => $parameters + ['action' => $action]],
        ]);

        $this->assertResponseOk(
            $body,
            $action === 'freeze'
                ? 'StroWallet freeze failed.'
                : 'StroWallet unfreeze failed.'
        );
    }

    /**
     * Read a card back from StroWallet.
     *
     * The documented call is `GET /bitvcard/fetch-nfccard-detail`; the older
     * `POST /bitvcard/fetch-card-detail/` is the fallback. Both answer
     * `response.card_detail` in the same shape.
     *
     * Reference: https://strowallet.readme.io/reference/nfc-card-details
     */
    private function fetchCardDetails(string $cardId): array
    {
        $parameters = [
            'public_key' => $this->credentials['public_key'],
            'mode'       => $this->mode,
            'card_id'    => $cardId,
        ];

        [, $response_data] = $this->callDocumentedThenLegacy('card details', 20, [
            ['method' => 'GET', 'path' => '/bitvcard/fetch-nfccard-detail', 'params' => $parameters],
            ['method' => 'POST', 'path' => '/bitvcard/fetch-card-detail/', 'params' => $parameters],
        ]);

        $this->assertResponseOk($response_data, 'StroWallet card details fetch failed.');

        return is_array($response_data['response'] ?? null) ? $response_data['response'] : [];
    }

    /**
     * Unified StroWallet response gate.
     *
     * Modern endpoints return `{"success": false, "message": "..."}` on
     * failure (e.g. "Cannot create card for RU user"), while legacy
     * endpoints sometimes return `{"error": "not ok", ...}`. The
     * previous code only checked the `error` shape, so the modern
     * `success: false` failures slipped past the guard and crashed
     * downstream when the caller dereferenced a missing `response` key.
     *
     * Treat the response as failed when ANY of these are true:
     *   - `success` is present and falsy
     *   - `error` is present and not "ok"
     *   - `message` is present alongside a falsy `success`
     *
     * @throws Exception with the provider's own message when available.
     */
    private function assertResponseOk(?array $body, string $fallbackMessage): void
    {
        if (! is_array($body)) {
            Log::error('StroWallet response was not JSON.', ['body' => $body]);
            throw new Exception($fallbackMessage);
        }

        if ($this->responseLooksSuccessful($body)) {
            return;
        }

        Log::error('StroWallet API returned a failure response.', ['response' => $body]);

        throw new Exception($this->providerMessage($body, $fallbackMessage));
    }

    /**
     * The human-readable reason in a StroWallet failure body.
     *
     * StroWallet reports validation failures in two shapes: `message` as a
     * field → messages map (`create-user`), or a string `message` plus an
     * `errors` map (`create-card`, `card_withdraw`). Casting either map to a
     * string, as this used to, showed the admin the word "Array" instead of
     * "The amount must be at least 3." — the one line they needed.
     *
     * @param array<string, mixed> $body
     */
    private function providerMessage(array $body, string $fallbackMessage): string
    {
        $parts = [];

        foreach ([$body['message'] ?? null, $body['errors'] ?? null, $body['error'] ?? null] as $value) {
            if (is_array($value)) {
                foreach ($value as $field => $messages) {
                    $text = is_array($messages)
                        ? implode(' ', array_map('strval', $messages))
                        : (string) $messages;

                    if (trim($text) !== '') {
                        $parts[] = is_string($field) ? $field.': '.trim($text) : trim($text);
                    }
                }

                continue;
            }

            if (is_string($value) && trim($value) !== '' && ! in_array(strtolower(trim($value)), ['ok', 'false', 'error'], true)) {
                $parts[] = trim($value);
            }
        }

        $parts = array_values(array_unique($parts));

        return $parts !== [] ? implode(' | ', $parts) : $fallbackMessage;
    }

    /**
     * Whether a credential probe proves the public key was accepted.
     *
     * StroWallet rejects a bad key with `Invalid Public Key` / `Invalid API
     * key` — under HTTP 200, 401 or 404 depending on the endpoint — and only
     * then looks at the request. Any JSON answer that is not that rejection
     * (a "card not found" for the probe's fake card included) therefore means
     * the key went through. A non-JSON body is the marketing site, i.e. the
     * wrong URL, and never counts as success.
     *
     * Shared with the payment-gateway diagnostics so both admin screens judge
     * one key by one rule.
     *
     * @param array<string, mixed>|null $body
     */
    public static function probeAcceptedKey(int $statusCode, ?array $body): bool
    {
        if (! is_array($body) || $statusCode >= 500) {
            return false;
        }

        if ($statusCode === 401 || $statusCode === 403) {
            return false;
        }

        $message = strtolower(trim((string) (is_string($body['message'] ?? null) ? $body['message'] : '')));

        return ! preg_match('/invalid\s+(public|api)\s*key|unauthori[sz]ed|authentication/i', $message);
    }

    /**
     * Decide whether a parsed StroWallet body represents success.
     *
     * Used by both `assertResponseOk()` (throwing path) and
     * `testConnection()` (non-throwing diagnostic path) so they apply
     * the same rules.
     */
    private function responseLooksSuccessful(?array $body): bool
    {
        if (! is_array($body)) {
            return false;
        }

        if (array_key_exists('success', $body) && ! $body['success']) {
            return false;
        }

        if (array_key_exists('error', $body)) {
            $err = strtolower((string) $body['error']);
            if ($err !== '' && $err !== 'ok' && $err !== 'false') {
                return false;
            }
        }

        return true;
    }
}
