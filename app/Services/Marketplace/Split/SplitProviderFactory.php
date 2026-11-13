<?php

declare(strict_types=1);

namespace App\Services\Marketplace\Split;

use Illuminate\Support\Facades\App;
use InvalidArgumentException;

class SplitProviderFactory
{
    /**
     * Resolve a split provider by its machine code. The internal `wallet`
     * driver settles between Digikash wallets; `stripe_connect` hands the
     * split to Stripe via a destination charge.
     */
    public function make(string $code): SplitProvider
    {
        return match ($code) {
            'wallet'         => App::make(WalletSplitProvider::class),
            'stripe_connect' => App::make(StripeConnectSplitProvider::class),

            default => throw new InvalidArgumentException(sprintf('Unsupported split provider: %s', $code)),
        };
    }

    /**
     * Codes an admin may select, keyed by code with a display label.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'wallet'         => __('Internal wallet'),
            'stripe_connect' => __('Stripe Connect'),
        ];
    }

    public function supports(string $code): bool
    {
        return array_key_exists($code, $this->options());
    }
}
