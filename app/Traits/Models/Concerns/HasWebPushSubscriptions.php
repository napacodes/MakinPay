<?php

namespace App\Traits\Models\Concerns;

use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * First-party mirror of the web push package's `HasPushSubscriptions` trait.
 *
 * Composing the package trait directly makes the whole User model — and with it
 * every request and Artisan command — fatal with "Trait not found" whenever a
 * customer applies a source update before running Composer. Push notifications
 * are a convenience feature and must never be able to take the wallet, deposit
 * or withdrawal flows offline, so the dependency is resolved at call time.
 */
trait HasWebPushSubscriptions
{
    /**
     * @return MorphMany<Model, $this>
     */
    public function pushSubscriptions(): MorphMany
    {
        return $this->morphMany($this->pushSubscriptionModel(), 'subscribable');
    }

    /**
     * Whether the web push package is installed and able to deliver.
     */
    public function webPushSubscriptionsSupported(): bool
    {
        return class_exists((string) config('webpush.model'))
            && class_exists(WebPushChannel::class);
    }

    public function updatePushSubscription(string $endpoint, ?string $key = null, ?string $token = null, ?string $contentEncoding = null): Model
    {
        $model = $this->pushSubscriptionModel();

        /** @var Model|null $subscription */
        $subscription = $model::query()->firstWhere('endpoint', $endpoint);

        if ($subscription && $this->ownsPushSubscription($subscription)) {
            $subscription->public_key       = $key;
            $subscription->auth_token       = $token;
            $subscription->content_encoding = $contentEncoding;
            $subscription->save();

            return $subscription;
        }

        if ($subscription && ! $this->ownsPushSubscription($subscription)) {
            $subscription->delete();
        }

        return $this->pushSubscriptions()->create([
            'endpoint'         => $endpoint,
            'public_key'       => $key,
            'auth_token'       => $token,
            'content_encoding' => $contentEncoding,
        ]);
    }

    public function ownsPushSubscription(Model $subscription): bool
    {
        return (string) $subscription->subscribable_id === (string) $this->getKey()
            && $subscription->subscribable_type        === $this->getMorphClass();
    }

    public function deletePushSubscription(string $endpoint): void
    {
        $this->pushSubscriptions()
            ->where('endpoint', $endpoint)
            ->delete();
    }

    /**
     * @return Collection<array-key, Model>
     */
    public function routeNotificationForWebPush(): Collection
    {
        return $this->pushSubscriptions;
    }

    /**
     * @return class-string<Model>
     */
    protected function pushSubscriptionModel(): string
    {
        $model = (string) config('webpush.model');

        return class_exists($model) ? $model : PushSubscription::class;
    }
}
