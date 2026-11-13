<?php

use App\Models\DepositMethod;
use Database\Seeders\BitnobStablecoinMethodSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Sandbox verification showed Bitnob's address API accepts ethereum,
     * polygon, base and solana, while tron (TRC20) and bsc (BEP20) are
     * rejected with "chain is not supported for address generation" unless
     * Bitnob enables them for the account.
     *
     * Seed the working networks and disable the TRC20/BEP20 automatic methods
     * so users are not offered a network that cannot produce an address. An
     * admin can re-enable them from Deposit Methods once Bitnob turns those
     * chains on for the account.
     */
    private const UNSUPPORTED_METHOD_CODES = [
        'bitnob-usdt-trc20',
        'bitnob-usdt-bep20',
        'bitnob-usdc-trc20',
        'bitnob-usdc-bep20',
    ];

    public function up(): void
    {
        (new BitnobStablecoinMethodSeeder)->run();

        DepositMethod::query()
            ->whereIn('method_code', self::UNSUPPORTED_METHOD_CODES)
            ->update(['status' => false]);

        // The legacy `bitnob_usdt` code used to mean TRC20; it now maps to
        // Ethereum, so relabel it to match the chain it actually generates.
        DepositMethod::query()
            ->where('method_code', 'bitnob_usdt')
            ->update(['name' => 'USDT (ERC20) Instant']);

        // `bitnob_usdt` is the USDT/ERC20 method the deposit UI resolves, so
        // an earlier `bitnob-usdt-erc20` row would only duplicate it.
        DepositMethod::query()
            ->where('method_code', 'bitnob-usdt-erc20')
            ->update(['status' => false]);
    }

    public function down(): void
    {
        DepositMethod::query()
            ->whereIn('method_code', self::UNSUPPORTED_METHOD_CODES)
            ->update(['status' => true]);
    }
};
