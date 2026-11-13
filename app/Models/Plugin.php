<?php

namespace App\Models;

use App\Support\PluginSecretFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;

class Plugin extends Model
{
    use HasFactory;

    public const TYPE_MOBILE_RECHARGE = 'mobile_recharge';

    public const TYPE_BILL_PAYMENT = 'bill_payment';

    public const STATE_ACTIVE = 'active';

    public const STATE_SETUP = 'setup';

    public const STATE_OFF = 'off';

    protected $fillable = [
        'code',
        'name',
        'type',
        'logo',
        'description',
        'credentials',
        'fields',
        'status',
        'last_tested_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * Whether every credential this plugin needs holds a real value.
     *
     * The seeders ship placeholders that are the key's own name —
     * `api_key => "api_key"` — so a freshly installed plugin looks configured
     * to anything that checks only for emptiness. That is the whole reason the
     * Integration Center could not tell "switched off" from "never set up":
     * both rendered as one bare Inactive pill, and an administrator had to
     * open each plugin to find out which.
     *
     * `fields` is skipped because it is the nested per-plugin form block
     * rather than a credential. Booleans and the "0"/"1" a checkbox submits
     * are values in their own right, and count as set.
     */
    public function getIsConfiguredAttribute(): bool
    {
        return $this->credentialsArray() !== [] && $this->missingCredentialKeys() === [];
    }

    /**
     * The credential keys still blank or on their seeded placeholder.
     *
     * The Integration Center card used to print the plugin's total key count
     * under "keys missing", so a Reloadly row with its Client ID and Secret
     * filled still read "4 keys missing" because one other value was empty.
     *
     * Keys the plugin's driver declares optional never count as missing.
     *
     * @return list<string>
     */
    public function missingCredentialKeys(): array
    {
        $missing  = [];
        $optional = $this->optionalCredentialKeys();

        foreach ($this->credentialsArray() as $key => $value) {
            if (! is_string($key) || $key === 'fields') {
                continue;
            }

            if (is_bool($value) || $value === '0' || $value === '1' || in_array($key, $optional, true)) {
                continue;
            }

            if (! PluginSecretFields::hasStoredValue($key, $value)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Why this plugin is or is not working, in one word.
     *
     * Three states rather than two: a plugin can be switched off deliberately
     * or be missing its keys, and only the second is something an
     * administrator can act on. `active` needs both — switched on, and
     * actually configured.
     */
    public function getStateAttribute(): string
    {
        if (! $this->is_configured) {
            return self::STATE_SETUP;
        }

        return $this->status ? self::STATE_ACTIVE : self::STATE_OFF;
    }

    public static function credentials($code): mixed
    {

        return Cache::rememberForever($code, function () use ($code) {
            $plugin = self::where('code', $code)->first();

            if (! $plugin) {
                return [];
            }

            $credentials = $plugin->credentialsArray();

            $credentials['status'] = $plugin->status;

            return $credentials;
        });
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeMobileRecharge(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_MOBILE_RECHARGE);
    }

    /**
     * Decoded credentials JSON with sealed secrets revealed. Empty array when
     * blank or invalid.
     *
     * @return array<string, mixed>
     */
    public function credentialsArray(): array
    {
        $decoded = json_decode((string) $this->credentials, true);

        return is_array($decoded) ? PluginSecretFields::reveal($decoded) : [];
    }

    public function credentialValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentialsArray(), $key, $default);
    }

    public function rechargeProvider(): HasOne
    {
        return $this->hasOne(MobileRechargeProvider::class, 'plugin_id');
    }

    /**
     * The bill payment provider whose credentials this plugin holds. Bill
     * payment drivers read credentialsArray() live, so saving the plugin
     * needs no cache flush of its own.
     */
    public function billPaymentProvider(): HasOne
    {
        return $this->hasOne(BillPaymentProvider::class, 'plugin_id');
    }

    /**
     * Credential keys this plugin works without, as its driver declares
     * them (a bill payment driver's static `optionalCredentialKeys()`), such
     * as the Flutterwave bill webhook secret, which a site taking Flutterwave
     * deposits may leave blank. Empty for every other plugin.
     *
     * @return list<string>
     */
    public function optionalCredentialKeys(): array
    {
        if ($this->type !== self::TYPE_BILL_PAYMENT) {
            return [];
        }

        $driver = $this->billPaymentProvider?->driver;
        $class  = is_string($driver) ? config('bill_payments.drivers.'.$driver) : null;

        if (! is_string($class) || ! method_exists($class, 'optionalCredentialKeys')) {
            return [];
        }

        return array_values(array_filter((array) $class::optionalCredentialKeys(), 'is_string'));
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function (self $plugin): void {
            self::flushCache($plugin->code);

            if ($plugin->type === self::TYPE_MOBILE_RECHARGE) {
                MobileRechargeProvider::flushProviderCache();
            }
        });

        static::deleted(function (self $plugin): void {
            self::flushCache($plugin->code);

            if ($plugin->type === self::TYPE_MOBILE_RECHARGE) {
                MobileRechargeProvider::flushProviderCache();
            }
        });
    }

    private static function flushCache($code): void
    {
        Cache::forget($code);
        Cache::forget('plugins_data');
    }
}
