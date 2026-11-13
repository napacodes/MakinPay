<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Wallet ID Prefix
    |--------------------------------------------------------------------------
    | Brand prefix stamped onto every NEWLY generated wallet id, e.g. the "DK"
    | in "DK-USD-9F2A-E710". The admin sets it — or switches it off — under
    | Settings → General → Wallet IDs; this env value is only the default
    | until that setting has been saved. It only affects wallets created
    | afterwards.
    |
    | Existing wallet ids are immutable public identifiers (users share them to
    | receive money), so lookup and masking are intentionally prefix-agnostic:
    | previously issued ids keep working forever regardless of this value.
    |
    | Must be 2–6 uppercase letters. Invalid values fall back to "DK".
    */
    'id_prefix' => env('WALLET_ID_PREFIX', 'DK'),

];
