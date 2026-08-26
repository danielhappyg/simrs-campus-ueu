<?php

use App\Support\Authorization\Capability;

return [
    // BG-03 remains non-authoritative in every mode. `enforce` is retained as
    // a future cutover value, but the shadow resolver never grants access.
    'mode' => env('BREAK_GLASS_MODE', 'off'),

    'global_disabled' => filter_var(
        env('BREAK_GLASS_GLOBAL_DISABLED', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ),

    'environment' => env('BREAK_GLASS_ENVIRONMENT', env('APP_ENV')),

    'release_sha' => env(
        'BREAK_GLASS_RELEASE_SHA',
        env('VERCEL_GIT_COMMIT_SHA'),
    ),

    // This secret is runtime-only. It must never be logged, stored in a
    // break-glass record, or committed to the repository.
    'session_hmac_key' => env('BREAK_GLASS_SESSION_HMAC_KEY'),

    'default_ttl_minutes' => filter_var(
        env('BREAK_GLASS_DEFAULT_TTL_MINUTES', 15),
        FILTER_VALIDATE_INT,
    ),

    'maximum_ttl_minutes' => filter_var(
        env('BREAK_GLASS_MAXIMUM_TTL_MINUTES', 30),
        FILTER_VALIDATE_INT,
    ),

    'scopes' => [
        'security-containment' => [
            Capability::USER_MANAGE,
            Capability::AUDIT_VIEW,
        ],
        'identity-recovery' => [
            Capability::USER_MANAGE,
            Capability::ROLE_MANAGE,
            Capability::AUDIT_VIEW,
        ],
        'platform-recovery' => [
            Capability::USER_MANAGE,
            Capability::ROLE_MANAGE,
            Capability::AUDIT_VIEW,
            Capability::MASTER_MANAGE,
            Capability::SYNTHETIC_RESET,
        ],
    ],
];
