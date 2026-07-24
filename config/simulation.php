<?php

return [
    'mode' => env('APP_MODE', 'SIMULATION'),

    'synthetic_only' => (bool) env('APP_SYNTHETIC_ONLY', true),

    'demo_seed_enabled' => (bool) env('DEMO_SEED_ENABLED', false),

    'demo_account_password' => env('DEMO_ACCOUNT_PASSWORD'),

    'demo_account_emails' => [
        'fasilitator.simulasi@example.invalid',
        'mahasiswa.rmik@example.invalid',
        'koder.rmik@example.invalid',
        'supervisor.rmik@example.invalid',
        'mahasiswa.keperawatan@example.invalid',
        'supervisor.keperawatan@example.invalid',
        'mahasiswa.kedokteran@example.invalid',
        'supervisor.kedokteran@example.invalid',
        'mahasiswa.farmasi@example.invalid',
        'supervisor.farmasi@example.invalid',
    ],

    'banner' => 'SIMULASI — DATA SINTETIS',

    'restriction' => 'Tidak untuk pelayanan pasien nyata',

    'allowed_modes' => ['SIMULATION'],
];
