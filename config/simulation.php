<?php

return [
    'mode' => env('APP_MODE', 'SIMULATION'),

    'synthetic_only' => (bool) env('APP_SYNTHETIC_ONLY', true),

    'demo_seed_enabled' => (bool) env('DEMO_SEED_ENABLED', false),

    'demo_account_password' => env('DEMO_ACCOUNT_PASSWORD'),

    'banner' => 'SIMULASI — DATA SINTETIS',

    'restriction' => 'Tidak untuk pelayanan pasien nyata',

    'allowed_modes' => ['SIMULATION'],
];
