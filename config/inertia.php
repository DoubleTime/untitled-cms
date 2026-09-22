<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server-side rendering
    |--------------------------------------------------------------------------
    |
    | Off unless INERTIA_SSR_ENABLED=true and an SSR server is running. With the
    | package default (on) every page render waits ~2s on a refused connection
    | to 127.0.0.1:13714, which is enough to time the test suite out.
    |
    */

    'ssr' => [
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', false),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Inertia v3 defaults to `resources/js/pages` (lowercase). This app keeps
    | page components in `resources/js/Pages`, which only resolves by accident
    | on case-insensitive filesystems (macOS) and fails on Linux, e.g. when
    | `assertInertia()` checks that a page component file exists in CI.
    |
    | Other Inertia options fall back to the package defaults.
    |
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [
            resource_path('js/Pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'ts',
            'tsx',
        ],

    ],

];
