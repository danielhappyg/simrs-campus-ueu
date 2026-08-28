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

    'recap_csv_max_rows' => (int) env('RECAP_CSV_MAX_ROWS', 5000),

    'recap_csv_user_attempts_per_minute' => (int) env('RECAP_CSV_USER_ATTEMPTS_PER_MINUTE', 3),

    'recap_csv_global_attempts_per_minute' => (int) env('RECAP_CSV_GLOBAL_ATTEMPTS_PER_MINUTE', 30),

    'recap_csv_lock_seconds' => (int) env('RECAP_CSV_LOCK_SECONDS', 120),

    'rebuild_admin_email' => 'admin.rebuild@example.invalid',

    'banner' => 'SIMULASI — DATA SINTETIS',

    'restriction' => '',

    'allowed_modes' => ['SIMULATION'],
];
