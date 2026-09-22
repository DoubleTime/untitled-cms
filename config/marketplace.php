<?php

return [

    // Private disk holding Revision files. Not the Vault: Revision files are .h5
    // weights and .zip bundles, routinely larger than the Vault's 50 MB cap, and
    // must only be reachable through the authenticated, logged download endpoints
    // (docs/adr/0003). Preview Images still use the Vault.
    'disk' => env('MARKETPLACE_DISK', 'marketplace'),

    // Upload cap for a Revision file, in kilobytes. Default 1 GB.
    'max_upload_kb' => env('MARKETPLACE_MAX_UPLOAD_KB', 1048576),

    // Allowed extensions per catalogue entry type.
    'allowed_extensions' => [
        'ai_model' => ['h5'],
        'flowchart_script' => ['zip'],
    ],

    // Lifetime of a Sanctum token issued to an AI Box, in days.
    'token_ttl_days' => env('MARKETPLACE_TOKEN_TTL_DAYS', 30),

];
