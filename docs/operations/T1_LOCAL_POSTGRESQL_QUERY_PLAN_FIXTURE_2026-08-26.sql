-- Synthetic-only, disposable PostgreSQL 17 query-plan fixture.
-- This file is executed only by scripts/rehearse-local-postgres17-query-plans.rb
-- after its local-server, database-name, simulation-mode and explicit-confirmation gates.

SET TIME ZONE 'Asia/Jakarta';

BEGIN;

WITH actor AS (
    SELECT id
    FROM laravel.users
    ORDER BY id
    LIMIT 1
)
INSERT INTO laravel.patients (
    public_id,
    medical_record_number,
    full_name,
    date_of_birth,
    sex,
    is_synthetic,
    created_by_user_id,
    created_at,
    updated_at
)
SELECT
    'PERF-PAT-' || lpad(series::text, 12, '0'),
    'SYNTH-PERF-' || lpad(series::text, 12, '0'),
    'Pasien Sintetis Kinerja ' || lpad(series::text, 12, '0'),
    DATE '1990-01-01' + (series % 9000),
    CASE WHEN series % 2 = 0 THEN 'LAKI_LAKI' ELSE 'PEREMPUAN' END,
    TRUE,
    actor.id,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM generate_series(1, 30000) AS series
CROSS JOIN actor;

WITH actor AS (
    SELECT id
    FROM laravel.users
    ORDER BY id
    LIMIT 1
), perf_patients AS (
    SELECT
        id,
        row_number() OVER (ORDER BY id) AS series
    FROM laravel.patients
    WHERE medical_record_number LIKE 'SYNTH-PERF-%'
), numbered_encounters AS (
    SELECT
        perf_patients.*,
        CURRENT_DATE - (((perf_patients.series - 1) % 90)::integer) AS queue_date,
        row_number() OVER (
            PARTITION BY ((perf_patients.series - 1) % 90)
            ORDER BY perf_patients.series
        ) AS daily_sequence
    FROM perf_patients
), queue_assignments AS (
    SELECT
        numbered_encounters.*,
        coalesce(daily_queue_counters.last_number, 0)
            + numbered_encounters.daily_sequence::integer AS queue_number
    FROM numbered_encounters
    LEFT JOIN laravel.daily_queue_counters
        ON daily_queue_counters.queue_date = numbered_encounters.queue_date
)
INSERT INTO laravel.encounters (
    public_id,
    patient_id,
    care_setting,
    status,
    clinic_name,
    payer_type,
    queue_number,
    queue_date,
    registered_at,
    registered_by_user_id,
    visit_date,
    booking_code,
    created_at,
    updated_at
)
SELECT
    'PERF-ENC-' || lpad(queue_assignments.series::text, 12, '0'),
    queue_assignments.id,
    CASE
        WHEN queue_assignments.series % 10 < 7 THEN 'OUTPATIENT'
        WHEN queue_assignments.series % 10 < 9 THEN 'EMERGENCY'
        ELSE 'INPATIENT'
    END,
    CASE queue_assignments.series % 4
        WHEN 0 THEN 'REGISTERED'
        WHEN 1 THEN 'IN_EXAMINATION'
        WHEN 2 THEN 'READY_FOR_RM'
        ELSE 'CLOSED'
    END,
    CASE queue_assignments.series % 4
        WHEN 0 THEN 'Poli Umum'
        WHEN 1 THEN 'Poli Gigi'
        WHEN 2 THEN 'Poli Anak'
        ELSE 'Poli Penyakit Dalam'
    END,
    CASE queue_assignments.series % 3
        WHEN 0 THEN 'UMUM'
        WHEN 1 THEN 'BPJS'
        ELSE 'LAINNYA'
    END,
    queue_assignments.queue_number,
    queue_assignments.queue_date,
    queue_assignments.queue_date
        + (queue_assignments.series % 43200) * INTERVAL '1 second',
    actor.id,
    queue_assignments.queue_date,
    CASE WHEN queue_assignments.series % 5 = 0
        THEN 'SYNTH-BOOK-' || lpad(queue_assignments.series::text, 12, '0')
        ELSE NULL
    END,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM queue_assignments
CROSS JOIN actor;

INSERT INTO laravel.daily_queue_counters (
    queue_date,
    last_number,
    created_at,
    updated_at
)
SELECT
    queue_date,
    max(queue_number),
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM laravel.encounters
WHERE public_id LIKE 'PERF-ENC-%'
GROUP BY queue_date
ON CONFLICT (queue_date) DO UPDATE
SET
    last_number = greatest(daily_queue_counters.last_number, EXCLUDED.last_number),
    updated_at = EXCLUDED.updated_at;

WITH actor AS (
    SELECT id
    FROM laravel.users
    ORDER BY id
    LIMIT 1
), perf_encounters AS (
    SELECT
        id,
        registered_at,
        row_number() OVER (ORDER BY id) AS series
    FROM laravel.encounters
    WHERE public_id LIKE 'PERF-ENC-%'
    ORDER BY id
    LIMIT 12000
)
INSERT INTO laravel.lab_service_requests (
    public_id,
    encounter_id,
    requested_by_user_id,
    test_code,
    test_label,
    clinical_question,
    status,
    requested_at,
    created_at,
    updated_at
)
SELECT
    'PERF-LAB-' || lpad(perf_encounters.series::text, 12, '0'),
    perf_encounters.id,
    actor.id,
    CASE perf_encounters.series % 4
        WHEN 0 THEN 'HB'
        WHEN 1 THEN 'GLU'
        WHEN 2 THEN 'URE'
        ELSE 'CR'
    END,
    CASE perf_encounters.series % 4
        WHEN 0 THEN 'Hemoglobin'
        WHEN 1 THEN 'Glukosa'
        WHEN 2 THEN 'Ureum'
        ELSE 'Kreatinin'
    END,
    'Pertanyaan klinis sintetis untuk pengujian rencana kueri.',
    CASE WHEN perf_encounters.series % 10 = 0 THEN 'ACTIVE' ELSE 'COMPLETED' END,
    perf_encounters.registered_at + INTERVAL '15 minutes',
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM perf_encounters
CROSS JOIN actor;

COMMIT;

ANALYZE laravel.patients;
ANALYZE laravel.encounters;
ANALYZE laravel.lab_service_requests;
