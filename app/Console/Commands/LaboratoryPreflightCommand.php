<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Coding\Enums\TerminologyProvenanceStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\TerminologyRelease;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\AdministrativeSex;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Enums\IdentifierStatus;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Patient\Enums\PatientRecordStatus;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Patient\Models\PatientIdentifier;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\ScenarioStatus;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use App\Modules\Teaching\Services\ReservedDemoAccountRoster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

class LaboratoryPreflightCommand extends Command
{
    /** @var array<string, string> */
    private const EXPECTED_TASK_STATES = [
        WorkTaskType::Registration->value => WorkTaskStatus::Ready->value,
        WorkTaskType::SessionOrientation->value => WorkTaskStatus::Complete->value,
        WorkTaskType::NursingIntake->value => WorkTaskStatus::Waiting->value,
        WorkTaskType::MedicalAssessment->value => WorkTaskStatus::Waiting->value,
    ];

    /** @var list<string> */
    private const PROGRESSED_ENCOUNTER_TABLES = [
        'queue_events',
        'clinical_entries',
        'service_requests',
        'medication_requests',
        'pharmacy_reviews',
        'medication_dispenses',
        'encounter_closures',
        'record_quality_reviews',
        'coding_assignments',
        'outpatient_safety_dispositions',
        'outpatient_early_departures',
        'debrief_notes',
    ];

    /** @var list<string> */
    private const PRODUCTION_INTEGRATION_CONFIG_KEYS = [
        'services.satusehat.base_url',
        'services.satusehat.client_id',
        'services.satusehat.client_secret',
        'services.bpjs.base_url',
        'services.bpjs.consumer_id',
        'services.bpjs.consumer_secret',
        'integrations.satusehat.endpoint',
        'integrations.bpjs.endpoint',
    ];

    protected $signature = 'simulation:lab-preflight
        {--source=SIM-RJ-UEU-001 : Pristine synthetic reference-session code}
        {--json : Emit a machine-readable identity-minimized report}';

    protected $description = 'Run the read-only readiness gate for a synthetic outpatient laboratory rehearsal';

    public function handle(): int
    {
        $checks = $this->runtimeChecks();
        $sourceCode = trim((string) $this->option('source'));
        $validSourceCode = preg_match('/^[A-Z0-9][A-Z0-9-]{2,63}$/', $sourceCode) === 1;
        $checks[] = $this->check(
            'source.session_code',
            $validSourceCode,
            $validSourceCode
                ? 'The reference-session code has the required bounded uppercase format.'
                : 'The reference-session code must contain 3–64 uppercase letters, numbers, or hyphens.',
        );

        $databaseReady = false;

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $databaseReady = true;
        } catch (Throwable $exception) {
            $databaseFailure = $exception::class;
        }

        $checks[] = $this->check(
            'database.connectivity',
            $databaseReady,
            $databaseReady
                ? 'The configured database accepted a read-only probe.'
                : 'The database read-only probe failed with '.$databaseFailure.'.',
        );

        if ($databaseReady && $validSourceCode) {
            $checks = [...$checks, ...$this->databaseChecks($sourceCode)];
        } else {
            foreach ([
                'source.reference_fixture',
                'source.pristine_graph',
                'accounts.demo_roster',
                'assignments.reference_roster',
                'tasks.initial_state',
                'terminology.icd10',
                'terminology.icd9cm',
            ] as $id) {
                $checks[] = $this->check(
                    $id,
                    false,
                    'This check could not run until the database and source-session code are valid.',
                );
            }
        }

        $failed = count(array_filter(
            $checks,
            fn (array $check): bool => $check['status'] === 'FAIL',
        ));
        $report = [
            'schemaVersion' => 1,
            'readOnly' => true,
            'status' => $failed === 0 ? 'READY' : 'BLOCKED',
            'source' => $validSourceCode ? $sourceCode : 'INVALID',
            'summary' => [
                'passed' => count($checks) - $failed,
                'failed' => $failed,
            ],
            'checks' => $checks,
        ];

        if ($this->option('json')) {
            try {
                $this->line(json_encode(
                    $report,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ));
            } catch (JsonException) {
                $this->error('The sanitized laboratory preflight report could not be encoded.');

                return self::FAILURE;
            }

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->line('Synthetic outpatient laboratory readiness: '.$report['status']);
        $this->table(
            ['Check', 'Status', 'Sanitized detail'],
            array_map(
                fn (array $check): array => [$check['id'], $check['status'], $check['detail']],
                $checks,
            ),
        );
        $this->line(sprintf(
            'Summary: %d passed; %d failed.',
            $report['summary']['passed'],
            $report['summary']['failed'],
        ));

        if ($failed === 0) {
            $this->info('The reference environment is ready to clone for a guided synthetic rehearsal.');
            $this->warn('This result does not authorize real data, clinical use, merge, deployment, or a faculty pilot.');

            return self::SUCCESS;
        }

        $this->error('Do not invite participants or clone a laboratory session until every failed check is resolved.');

        return self::FAILURE;
    }

    /** @return list<array{id: string, status: 'PASS'|'FAIL', detail: string}> */
    private function runtimeChecks(): array
    {
        $keyPresent = is_string(config('app.key')) && trim((string) config('app.key')) !== '';
        $buildManifestPresent = is_file(public_path('build/manifest.json'));
        $configuredProductionKeys = array_values(array_filter(
            self::PRODUCTION_INTEGRATION_CONFIG_KEYS,
            fn (string $key): bool => $this->hasConfiguredValue(config($key)),
        ));

        return [
            $this->check(
                'environment.non_production',
                ! app()->environment('production'),
                ! app()->environment('production')
                    ? 'The application environment is not production.'
                    : 'Laboratory rehearsal is prohibited in the production application environment.',
            ),
            $this->check(
                'simulation.mode',
                config('simulation.mode') === EnvironmentMode::Simulation->value,
                config('simulation.mode') === EnvironmentMode::Simulation->value
                    ? 'APP_MODE is SIMULATION.'
                    : 'APP_MODE must be SIMULATION.',
            ),
            $this->check(
                'simulation.synthetic_only',
                config('simulation.synthetic_only') === true,
                config('simulation.synthetic_only') === true
                    ? 'Synthetic-only enforcement is enabled.'
                    : 'APP_SYNTHETIC_ONLY must be true.',
            ),
            $this->check(
                'simulation.demo_seed_enabled',
                config('simulation.demo_seed_enabled') === true,
                config('simulation.demo_seed_enabled') === true
                    ? 'The explicit demo-fixture opt-in is enabled.'
                    : 'DEMO_SEED_ENABLED must be true for this isolated reference environment.',
            ),
            $this->check(
                'session.revocable_backend',
                config('session.driver') === 'database' && config('session.table') === 'sessions',
                config('session.driver') === 'database' && config('session.table') === 'sessions'
                    ? 'Reserved-account browser sessions use the revocable database session table.'
                    : 'SESSION_DRIVER must be database and SESSION_TABLE must be sessions.',
            ),
            $this->check(
                'app.key_present',
                $keyPresent,
                $keyPresent ? 'APP_KEY is configured.' : 'APP_KEY is missing.',
            ),
            $this->check(
                'public.build_manifest',
                $buildManifestPresent,
                $buildManifestPresent
                    ? 'The compiled frontend asset manifest is present.'
                    : 'Build production frontend assets before the laboratory rehearsal.',
            ),
            $this->check(
                'integrations.production_endpoints_absent',
                $configuredProductionKeys === [],
                $configuredProductionKeys === []
                    ? 'Known production clinical integration endpoint and credential keys are not configured.'
                    : 'One or more production clinical integration keys are configured; values were not displayed.',
            ),
        ];
    }

    /** @return list<array{id: string, status: 'PASS'|'FAIL', detail: string}> */
    private function databaseChecks(string $sourceCode): array
    {
        try {
            $source = SimulationSession::query()
                ->with('scenario')
                ->where('code', $sourceCode)
                ->first();

            if (! $source) {
                return [
                    $this->check('source.reference_fixture', false, 'The requested reference session was not found.'),
                    $this->check('source.pristine_graph', false, 'No source graph was available for inspection.'),
                    $this->accountsCheck(),
                    $this->check('assignments.reference_roster', false, 'No source session was available for assignment inspection.'),
                    $this->check('tasks.initial_state', false, 'No source session was available for work-queue inspection.'),
                    $this->terminologyCheck(TerminologySystem::Icd10),
                    $this->terminologyCheck(TerminologySystem::Icd9Cm),
                ];
            }

            $referenceFixtureReady = $source->environment_mode === EnvironmentMode::Simulation
                && $source->status === SessionStatus::Active
                && $source->scenario->code === 'OPD-REF-001'
                && $source->scenario->version === 1
                && $source->scenario->status === ScenarioStatus::Published
                && data_get($source->scenario->fixture_spec, 'synthetic_only') === true
                && data_get($source->scenario->fixture_spec, 'fixture_set') === 'OPD-REF-001-v1';

            return [
                $this->check(
                    'source.reference_fixture',
                    $referenceFixtureReady,
                    $referenceFixtureReady
                        ? 'The source is the active published OPD-REF-001-v1 synthetic reference fixture.'
                        : 'The source does not match the active published OPD-REF-001-v1 synthetic reference contract.',
                ),
                $this->sourceGraphCheck($source),
                $this->accountsCheck(),
                $this->assignmentsCheck($source),
                $this->tasksCheck($source),
                $this->terminologyCheck(TerminologySystem::Icd10),
                $this->terminologyCheck(TerminologySystem::Icd9Cm),
            ];
        } catch (Throwable $exception) {
            $failedChecks = [];

            foreach ([
                'source.reference_fixture',
                'source.pristine_graph',
                'accounts.demo_roster',
                'assignments.reference_roster',
                'tasks.initial_state',
                'terminology.icd10',
                'terminology.icd9cm',
            ] as $id) {
                $failedChecks[] = $this->check(
                    $id,
                    false,
                    'The read-only inspection failed with '.$exception::class.'; no protected values were displayed.',
                );
            }

            return $failedChecks;
        }
    }

    /** @return array{id: string, status: 'PASS'|'FAIL', detail: string} */
    private function sourceGraphCheck(SimulationSession $source): array
    {
        $patients = SyntheticPatient::query()->where('session_id', $source->getKey())->get();
        $appointments = AppointmentRegistration::query()->where('session_id', $source->getKey())->get();
        $encounters = Encounter::query()->where('session_id', $source->getKey())->with('location')->get();

        if ($patients->count() !== 1 || $appointments->count() !== 1 || $encounters->count() !== 1) {
            return $this->check(
                'source.pristine_graph',
                false,
                'The source must contain exactly one synthetic patient, one appointment, and one encounter.',
            );
        }

        $patient = $patients->sole();
        $appointment = $appointments->sole();
        $encounter = $encounters->sole();
        $identifiers = $patient->identifiers()->get();
        $identifierTypes = $identifiers
            ->map(fn (PatientIdentifier $identifier): string => $identifier->type->value)
            ->sort()
            ->values()
            ->all();
        $identifiersReady = $identifiers->count() === 2
            && $identifierTypes === [
                IdentifierType::MedicalRecordNumber->value,
                IdentifierType::SyntheticNationalId->value,
            ]
            && $identifiers->every(
                fn (PatientIdentifier $identifier): bool => $identifier->synthetic_flag
                    && $identifier->status === IdentifierStatus::Active
                    && str_starts_with($identifier->system, PatientIdentifier::SYNTHETIC_SYSTEM_PREFIX),
            );
        $transitions = DB::table('encounter_transitions')
            ->where('encounter_id', $encounter->getKey())
            ->get();
        $initialTransition = $transitions->count() === 1 ? $transitions->first() : null;
        $initialTransitionReady = $initialTransition !== null
            && $initialTransition->from_status === null
            && $initialTransition->to_status === EncounterStatus::Planned->value
            && Assignment::query()
                ->where('session_id', $source->getKey())
                ->whereKey($initialTransition->actor_assignment_id)
                ->exists();
        $progressed = collect(self::PROGRESSED_ENCOUNTER_TABLES)->contains(
            fn (string $table): bool => DB::table($table)
                ->where('encounter_id', $encounter->getKey())
                ->exists(),
        );
        $stocks = DB::table('medication_stocks')
            ->where('session_id', $source->getKey())
            ->get();
        $stockReady = $stocks->isNotEmpty()
            && $stocks->every(
                fn (object $stock): bool => (bool) $stock->synthetic_flag
                    && (float) $stock->quantity_on_hand >= 0,
            );
        $stockProgressed = DB::table('medication_stock_movements')
            ->where('session_id', $source->getKey())
            ->exists();

        $ready = $patient->synthetic_flag
            && $patient->fixture_source === 'OPD-REF-001-v1'
            && $patient->record_status === PatientRecordStatus::Active
            && $patient->full_name === 'Pasien Sintetis Arunika'
            && $patient->birth_date->toDateString() === '1992-04-18'
            && $patient->administrative_sex === AdministrativeSex::Female
            && $patient->address === null
            && $patient->phone === null
            && $patient->email === null
            && $patient->religion === null
            && $patient->occupation === null
            && $patient->education === null
            && $patient->marital_status === null
            && ! $patient->deceased_flag
            && $identifiersReady
            && $appointment->patient_id === $patient->getKey()
            && $appointment->status === AppointmentStatus::Booked
            && $appointment->checked_in_at === null
            && $encounter->patient_id === $patient->getKey()
            && $encounter->appointment_registration_id === $appointment->getKey()
            && $encounter->status === EncounterStatus::Planned
            && $encounter->environment_mode === EnvironmentMode::Simulation
            && $encounter->period_start === null
            && $encounter->period_end === null
            && $encounter->closure_requested_at === null
            && $encounter->clinically_closed_at === null
            && $encounter->finalized_at === null
            && $encounter->location->is_active
            && $initialTransitionReady
            && ! $progressed
            && $stockReady
            && ! $stockProgressed;

        return $this->check(
            'source.pristine_graph',
            $ready,
            $ready
                ? 'The one-case source graph is synthetic, PLANNED, untouched, and has pristine stock provenance.'
                : 'The source graph has progressed or no longer matches the one-case pristine fixture contract.',
        );
    }

    /** @return array{id: string, status: 'PASS'|'FAIL', detail: string} */
    private function accountsCheck(): array
    {
        $emails = app(ReservedDemoAccountRoster::class)->emails();
        $users = User::query()->whereIn('email', $emails)->get();
        $ready = $users->count() === ReservedDemoAccountRoster::EXPECTED_ACCOUNT_COUNT
            && $users->pluck('email')->unique()->count() === ReservedDemoAccountRoster::EXPECTED_ACCOUNT_COUNT
            && $users->every(
                fn (User $user): bool => $user->status === 'ACTIVE' && $user->email_verified_at !== null,
            );

        return $this->check(
            'accounts.demo_roster',
            $ready,
            $ready
                ? 'All ten reserved demo accounts are active and verified.'
                : 'The ten-account reserved demo roster is incomplete, inactive, or unverified.',
        );
    }

    /** @return array{id: string, status: 'PASS'|'FAIL', detail: string} */
    private function assignmentsCheck(SimulationSession $source): array
    {
        $emails = app(ReservedDemoAccountRoster::class)->emails();
        $expectedUserIds = User::query()
            ->whereIn('email', $emails)
            ->pluck('id')
            ->sort()
            ->values();
        $assignments = Assignment::query()
            ->where('session_id', $source->getKey())
            ->with('user')
            ->get();
        $assignmentIds = $assignments->pluck('id');
        $facilitators = $assignments->filter(
            fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::SessionFacilitate),
        );
        $registrars = $assignments->filter(
            fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::PatientRegister),
        );
        $patient = SyntheticPatient::query()->where('session_id', $source->getKey())->first();
        $encounter = Encounter::query()->where('session_id', $source->getKey())->first();
        $now = now();
        $ready = $patient !== null
            && $encounter !== null
            && $assignments->count() === ReservedDemoAccountRoster::EXPECTED_ACCOUNT_COUNT
            && $assignments->pluck('user_id')->unique()->sort()->values()->all() === $expectedUserIds->all()
            && $facilitators->count() === 1
            && $facilitators->sole()->user_id === $source->facilitator_user_id
            && $registrars->count() === 1
            && $assignments->every(function (Assignment $assignment) use ($assignmentIds, $patient, $encounter, $now): bool {
                $active = $assignment->revoked_at === null
                    && ! $assignment->active_from->isAfter($now)
                    && ($assignment->active_until === null || ! $assignment->active_until->isBefore($now));
                $unscoped = $assignment->patient_id === null && $assignment->encounter_id === null;
                $caseScoped = $assignment->patient_id === $patient->getKey()
                    && $assignment->encounter_id === $encounter->getKey();
                $supervisorRemainsInSession = $assignment->supervisor_assignment_id === null
                    || $assignmentIds->contains($assignment->supervisor_assignment_id);

                return $active
                    && $assignment->user->status === 'ACTIVE'
                    && $assignment->capabilities !== []
                    && ($unscoped || $caseScoped)
                    && $supervisorRemainsInSession;
            });

        return $this->check(
            'assignments.reference_roster',
            $ready,
            $ready
                ? 'Ten active assignments match the reserved roster and remain within the reference session/case scope.'
                : 'The reference assignments are incomplete, inactive, or cross their permitted session/case scope.',
        );
    }

    /** @return array{id: string, status: 'PASS'|'FAIL', detail: string} */
    private function tasksCheck(SimulationSession $source): array
    {
        $tasks = WorkTask::query()->where('session_id', $source->getKey())->get();
        $encounter = Encounter::query()->where('session_id', $source->getKey())->first();
        $assignmentIds = Assignment::query()->where('session_id', $source->getKey())->pluck('id');
        $states = $tasks->mapWithKeys(
            fn (WorkTask $task): array => [$task->task_type->value => $task->status->value],
        )->sortKeys()->all();
        $ready = $encounter !== null
            && $tasks->count() === count(self::EXPECTED_TASK_STATES)
            && $states === collect(self::EXPECTED_TASK_STATES)->sortKeys()->all()
            && $tasks->every(
                fn (WorkTask $task): bool => $task->encounter_id === $encounter->getKey()
                    && $assignmentIds->contains($task->assignment_id)
                    && $task->clinical_entry_id === null
                    && $task->clinical_entry_version_id === null
                    && data_get($task->context, 'synthetic') === true
                    && data_get($task->context, 'caseLabel') === $encounter->encounter_number
                    && count($task->context ?? []) === 2,
            );

        return $this->check(
            'tasks.initial_state',
            $ready,
            $ready
                ? 'The work queue contains exactly the four expected pristine initial task states.'
                : 'The source work queue does not match the four-task pristine reference contract.',
        );
    }

    /** @return array{id: string, status: 'PASS'|'FAIL', detail: string} */
    private function terminologyCheck(TerminologySystem $system): array
    {
        $releases = TerminologyRelease::query()
            ->where('classification_system', $system)
            ->where('status', TerminologyReleaseStatus::Active)
            ->get();
        $release = $releases->count() === 1 ? $releases->sole() : null;
        $ready = $release !== null
            && $release->logical_version === $system->logicalVersion()
            && $release->sheet_name === $system->expectedSheetName()
            && $release->source_provenance_status === TerminologyProvenanceStatus::VerifiedUserSupplied
            && preg_match('/^[a-f0-9]{64}$/', $release->source_sha256) === 1
            && $release->row_count > 0
            && $release->activated_at !== null
            && $release->concepts()->count() === $release->row_count;
        $suffix = $ready ? substr($release->source_sha256, -12) : null;

        return $this->check(
            'terminology.'.strtolower(str_replace('_', '', $system->value)),
            $ready,
            $ready
                ? sprintf(
                    '%s %s is active with %d concepts; checksum suffix %s.',
                    $system->label(),
                    $system->logicalVersion(),
                    $release->row_count,
                    $suffix,
                )
                : $system->label().' must have exactly one active verified-user-supplied '.$system->logicalVersion().' release with matching sheet, checksum, activation, and concept count.',
        );
    }

    private function hasConfiguredValue(mixed $value): bool
    {
        if (is_array($value)) {
            return collect($value)->contains(fn (mixed $entry): bool => $this->hasConfiguredValue($entry));
        }

        return is_string($value) ? trim($value) !== '' : $value !== null;
    }

    /**
     * @return array{id: string, status: 'PASS'|'FAIL', detail: string}
     */
    private function check(string $id, bool $passes, string $detail): array
    {
        return [
            'id' => $id,
            'status' => $passes ? 'PASS' : 'FAIL',
            'detail' => $detail,
        ];
    }
}
