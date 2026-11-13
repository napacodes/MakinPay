<?php

namespace App\Models;

use App\Support\GatewayCredentialLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class PaymentGateway extends Model
{
    use HasFactory;

    private const array DEFAULT_WITHDRAW_FIELDS = [
        'paypal' => [
            [
                'name'        => 'paypal_email',
                'type'        => 'text',
                'label'       => 'PayPal Email',
                'placeholder' => 'Enter PayPal account email',
                'validation'  => 'required',
            ],
        ],
        'paystack' => [
            [
                'name'       => 'recipient_type',
                'type'       => 'select',
                'label'      => 'Recipient Type',
                'validation' => 'required',
                'options'    => [
                    'nuban'        => 'NUBAN (Nigeria bank)',
                    'ghipss'       => 'GHIPSS (Ghana bank)',
                    'mobile_money' => 'Mobile Money',
                    'basa'         => 'BASA (South Africa bank)',
                ],
            ],
            [
                'name'       => 'bank_code',
                'type'       => 'text',
                'label'      => 'Bank Code',
                'validation' => 'required',
            ],
            [
                'name'       => 'account_number',
                'type'       => 'text',
                'label'      => 'Account Number',
                'validation' => 'required',
            ],
            [
                'name'       => 'account_name',
                'type'       => 'text',
                'label'      => 'Account Holder Name',
                'validation' => 'required',
            ],
        ],
        'stripe' => [
            [
                'name'        => 'connected_account_id',
                'type'        => 'text',
                'label'       => 'Connected Account ID',
                'placeholder' => 'Optional: acct_...',
                'validation'  => 'nullable',
            ],
            [
                'name'        => 'destination',
                'type'        => 'text',
                'label'       => 'Destination ID',
                'placeholder' => 'Optional: ba_... or card_...',
                'validation'  => 'nullable',
            ],
            [
                'name'       => 'method',
                'type'       => 'select',
                'label'      => 'Payout Method',
                'validation' => 'required',
                'options'    => [
                    'standard' => 'Standard',
                    'instant'  => 'Instant',
                ],
            ],
            [
                'name'       => 'source_type',
                'type'       => 'select',
                'label'      => 'Source Balance',
                'validation' => 'nullable',
                'options'    => [
                    'card'         => 'Card',
                    'bank_account' => 'Bank Account',
                    'fpx'          => 'FPX',
                ],
            ],
            [
                'name'        => 'statement_descriptor',
                'type'        => 'text',
                'label'       => 'Statement Descriptor',
                'placeholder' => 'Optional, max 22 characters',
                'validation'  => 'nullable',
            ],
        ],
        'moneroo' => [
            [
                'name'        => 'method',
                'type'        => 'text',
                'label'       => 'Payout Method',
                'placeholder' => 'Example: mobile_money, bank_transfer',
                'validation'  => 'required',
            ],
            [
                'name'        => 'recipient_key',
                'type'        => 'text',
                'label'       => 'Recipient Field',
                'placeholder' => 'Example: msisdn, account_number, email',
                'validation'  => 'required',
            ],
            [
                'name'        => 'recipient_value',
                'type'        => 'text',
                'label'       => 'Recipient Value',
                'placeholder' => 'Enter recipient account, phone, or email',
                'validation'  => 'required',
            ],
        ],
        'bitnob' => [
            [
                'name'       => 'destination_type',
                'type'       => 'select',
                'label'      => 'Destination',
                'validation' => 'required',
                'options'    => [
                    'bank'         => 'Bank Account',
                    'mobile_money' => 'Mobile Money',
                ],
            ],
            [
                'name'        => 'country',
                'type'        => 'text',
                'label'       => 'Country (ISO-2)',
                'placeholder' => 'Example: NG, KE, GH',
                'validation'  => 'required',
            ],
            [
                'name'        => 'bank_code',
                'type'        => 'text',
                'label'       => 'Bank / Mobile Money Code',
                'placeholder' => 'Enter bank or mobile money provider code',
                'validation'  => 'required',
            ],
            [
                'name'        => 'account_number',
                'type'        => 'text',
                'label'       => 'Account / Phone Number',
                'placeholder' => 'Enter destination account or phone number',
                'validation'  => 'required',
            ],
            [
                'name'        => 'account_name',
                'type'        => 'text',
                'label'       => 'Account Holder Name',
                'placeholder' => 'Enter account holder name',
                'validation'  => 'required',
            ],
        ],
    ];

    /**
     * Credential fields a gateway still ships but its provider no longer
     * issues to every account, so leaving them blank must not block a save,
     * a credential test or a payment.
     *
     * StroWallet hands out a single public key; every documented endpoint
     * (card issuance, funding, `/pay` checkout, checkout status) takes that
     * key alone. The secret only signs the legacy `/express/initiate` IPN, and
     * the gateway refuses that flow outright when no secret is stored.
     *
     * @var array<string, array<int, string>>
     */
    private const array OPTIONAL_CREDENTIALS = [
        'strowallet' => ['secret_key'],
    ];

    protected $fillable = [
        'logo',
        'name',
        'code',
        'currencies',
        'credentials',
        'withdraw_field',
        'status',
    ];

    protected $casts = [
        'currencies'     => 'array',
        'withdraw_field' => 'array',
        'status'         => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    /**
     * Scope to only include gateways that support withdrawal.
     */
    /**
     * Whether this gateway could actually take a payment right now.
     *
     * `status` alone does not answer that. Several gateways are inserted by
     * migrations already enabled and carrying placeholder credentials whose
     * value is literally the field name — `'api_key' => 'api_key'`. Counting
     * those as live tells a brand-new operator their payments are healthy on a
     * site that cannot accept a single deposit.
     *
     * A credential counts as filled when it is a non-empty string that is not
     * simply an echo of its own key. Toggles and the base URL are excluded:
     * both are shipped with real defaults and say nothing about whether the
     * operator has entered their own keys.
     */
    public function isConfigured(): bool
    {
        $credentials = $this->credentials;

        if (! is_array($credentials) || $credentials === []) {
            return false;
        }

        foreach ($credentials as $key => $value) {
            if (! is_string($key) || GatewayCredentialLabel::isToggle($key)) {
                continue;
            }

            if (in_array($key, ['base_url', 'payment_methods'], true)) {
                continue;
            }

            if (self::credentialIsFilled($key, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a stored credential holds a value the operator entered: a
     * non-empty string that is not simply an echo of its own key, which is
     * what the seeders ship as a placeholder (`'secret_key' => 'secret_key'`).
     */
    public static function credentialIsFilled(string $key, mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        return strcasecmp(trim($value), $key) !== 0;
    }

    /**
     * Whether a gateway may be saved and used with this credential left blank.
     */
    public static function isOptionalCredential(string $code, string $key): bool
    {
        return in_array($key, self::OPTIONAL_CREDENTIALS[$code] ?? [], true);
    }

    public function scopeWithdrawAvailable(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNotNull('withdraw_field')
                ->orWhereIn('code', array_keys(self::DEFAULT_WITHDRAW_FIELDS));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Credentials always leave the model with every toggle key
     * (GatewayCredentialLabel::isToggle — `sandbox`, `*_enabled`) as a real
     * PHP boolean, no matter how the row was written. Migrations seed native
     * bools while the admin form round-trips checkboxes through HTTP as the
     * strings "0"/"1"; without one normalising boundary every reader had to
     * guess the shape, and the credential edit form guessed wrong the moment
     * a gateway was saved once.
     *
     * @return array<string, mixed>
     */
    public function getCredentialsAttribute(mixed $value): array
    {
        return self::normalizeCredentialToggles($this->decodeJsonColumn($value));
    }

    public function setCredentialsAttribute(mixed $value): void
    {
        $this->attributes['credentials'] = json_encode(
            self::normalizeCredentialToggles($this->decodeJsonColumn($value))
        );
    }

    /**
     * Cast every toggle credential to a real boolean, leaving provider-issued
     * values (keys, secrets, account ids) untouched.
     *
     * @param  array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public static function normalizeCredentialToggles(array $credentials): array
    {
        foreach ($credentials as $key => $value) {
            if (is_string($key) && GatewayCredentialLabel::isToggle($key)) {
                $credentials[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $credentials;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonColumn(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Check if withdraw field is available.
     */
    public function getWithdrawAvailableAttribute(): bool
    {
        return $this->withdraw_field !== null && $this->withdraw_field !== [];
    }

    public function getWithdrawFieldAttribute(mixed $value): ?array
    {
        if (! blank($value)) {
            if (is_array($value)) {
                return $value;
            }

            $decoded = json_decode((string) $value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return self::DEFAULT_WITHDRAW_FIELDS[$this->attributes['code'] ?? ''] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | Static Fetch Methods (with optimized Caching)
    |--------------------------------------------------------------------------
    */

    /**
     * Get all payment gateways (optionally paginated).
     */
    public static function allCached()
    {
        return Cache::rememberForever('payment_gateways_all', function () {
            return self::active()->orderBy('id')->get();
        });
    }

    /**
     * Get a payment gateway by its ID.
     */
    public static function getById(int $id): ?self
    {
        return Cache::rememberForever("payment_gateway_id_{$id}", function () use ($id) {
            return self::find($id);
        });
    }

    /**
     * Get a payment gateway by its code.
     */
    public static function getByCode(string $code): ?self
    {
        return Cache::rememberForever("payment_gateway_code_{$code}", function () use ($code) {
            return self::where('code', $code)->first();
        });
    }

    /**
     * Get credentials for a specific gateway code.
     */
    public static function getCredentials(string $code): array
    {
        return self::getByCode($code)?->credentials ?? [];
    }

    /**
     * Get currencies supported by a specific gateway code.
     */
    public static function getCurrencies(string $code): array
    {
        return self::getByCode($code)?->currencies ?? [];
    }

    /*
   |--------------------------------------------------------------------------
   | Relationships
   |--------------------------------------------------------------------------
   */

    public function depositMethods(): HasMany|PaymentGateway
    {
        return $this->hasMany(DepositMethod::class, 'payment_gateway_id', 'id');
    }

    public function withdrawMethods(): HasMany|PaymentGateway
    {
        return $this->hasMany(WithdrawMethod::class, 'payment_gateway_id', 'id');
    }

    /*
    |--------------------------------------------------------------------------
    | Cache Management
    |--------------------------------------------------------------------------
    */

    /**
     * Flush related cache keys.
     */
    public static function flushCache(self $gateway): void
    {
        Cache::forget('payment_gateways_all');
        Cache::forget("payment_gateway_id_{$gateway->id}");
        Cache::forget("payment_gateway_code_{$gateway->code}");
    }

    /**
     * Auto-clear cache on update or delete.
     */
    protected static function booted()
    {
        static::saved(fn (self $gateway) => self::flushCache($gateway));
        static::deleted(fn (self $gateway) => self::flushCache($gateway));
    }
}
