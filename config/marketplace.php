<?php

use App\Support\CatalogueEntryType;

return [

    // Private disk holding Revision files. Not the Vault: Revision files are .h5
    // weights and .zip bundles, routinely larger than the Vault's 50 MB cap, and
    // must only be reachable through the authenticated, logged download endpoints
    // (docs/adr/0003). Preview Images still use the Vault.
    'disk' => env('MARKETPLACE_DISK', 'marketplace'),

    // Upload cap for a Revision file, in kilobytes. Default 1 GB.
    'max_upload_kb' => env('MARKETPLACE_MAX_UPLOAD_KB', 1048576),

    // Allowed extensions per catalogue entry type. The keys are the morph aliases
    // that `revisable_type` holds, so they come from CatalogueEntryType rather than
    // being spelled out again here.
    'allowed_extensions' => [
        CatalogueEntryType::AI_MODEL => ['h5'],
        CatalogueEntryType::SCRIPT => ['zip'],
    ],

    // Lifetime of a Sanctum token issued to an UNYSIS Box, in days.
    'token_ttl_days' => env('MARKETPLACE_TOKEN_TTL_DAYS', 30),

];
