<?php

use App\Support\Authorization\Capability;

return [
    // BG-01 is configuration only. No mode currently grants access.
    'mode' => env('BREAK_GLASS_MODE', 'off'),

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
