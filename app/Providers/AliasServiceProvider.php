<?php

namespace App\Providers;

use App\Facades\CurrencyFacade;
use App\Facades\IpInfoFacade;
use App\Facades\PaymentFacade;
use App\Facades\SystemHealthFacade;
use App\Facades\TransactionFacade;
use App\Facades\WalletFacade;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;

class AliasServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Get the AliasLoader instance
        $loader = AliasLoader::getInstance();

        // Register aliases
        $loader->alias('Currency', CurrencyFacade::class);
        $loader->alias('Wallet', WalletFacade::class);
        $loader->alias('Transaction', TransactionFacade::class);
        $loader->alias('Payment', PaymentFacade::class);
        $loader->alias('IpInfo', IpInfoFacade::class);
        $loader->alias('SystemHealth', SystemHealthFacade::class);

    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
