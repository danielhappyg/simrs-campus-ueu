<?php

return [
    'mode' => env('APP_MODE', 'SIMULATION'),

    'synthetic_only' => (bool) env('APP_SYNTHETIC_ONLY', true),

    'demo_seed_enabled' => (bool) env('DEMO_SEED_ENABLED', false),

    'demo_account_password' => env('DEMO_ACCOUNT_PASSWORD'),

    'teaching_role_access_password' => env('TEACHING_ROLE_ACCESS_PASSWORD'),

    'teaching_role_access_commitment_key' => env('TEACHING_ROLE_ACCESS_COMMITMENT_KEY'),

    'teaching_role_access_environment' => env('VERCEL_ENV', env('APP_ENV')),

    'teaching_role_access_release_sha' => env('VERCEL_GIT_COMMIT_SHA'),

    'teaching_role_access_deployment_url' => env('VERCEL_URL'),

    'teaching_role_access_canonical_host' => parse_url((string) env('APP_URL', ''), PHP_URL_HOST),

    'teaching_role_access_max_ttl_minutes' => (int) env('TEACHING_ROLE_ACCESS_MAX_TTL_MINUTES', 30),

    'rebuild_admin_email' => 'admin.rebuild@example.invalid',

    'banner' => 'SIMULASI — DATA SINTETIS',

    'restriction' => '',

    'allowed_modes' => ['SIMULATION'],
];
