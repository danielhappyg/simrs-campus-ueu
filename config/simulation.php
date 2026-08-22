<?php

return [
    'mode' => env('APP_MODE', 'SIMULATION'),

    'synthetic_only' => (bool) env('APP_SYNTHETIC_ONLY', true),

    'demo_seed_enabled' => (bool) env('DEMO_SEED_ENABLED', false),

    'demo_account_password' => env('DEMO_ACCOUNT_PASSWORD'),

    'rebuild_admin_email' => 'admin.rebuild@example.invalid',

    'banner' => 'SIMULASI — DATA SINTETIS',

    'restriction' => '',

    'allowed_modes' => ['SIMULATION'],
];
