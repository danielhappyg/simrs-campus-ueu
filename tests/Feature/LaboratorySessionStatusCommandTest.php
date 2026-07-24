<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coding\Enums\TerminologyProvenanceStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\TerminologyConcept;
use App\Modules\Coding\Models\TerminologyRelease;
use App\Modules\Coding\Support\TerminologyNormalizer;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class LaboratorySessionStatusCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_read_only_session_monitor_is_registered(): void
    {
        $exitCode = Artisan::call('list', ['--raw' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('simulation:lab-session-status', Artisan::output());
    }

    public function test_pristine_clone_reports_ready_to_start_without_identity_or_state_mutation(): void
    {
        $this->seedReferenceOutpatient();
        $this->assertSame(0, Artisan::call('simulation:clone-reference-session', [
            'code' => 'LAB-PILOT-STATUS-001',
        ]));
        $before = $this->applicationTableCounts();

        $exitCode = Artisan::call('simulation:lab-session-status', [
            'session' => 'LAB-PILOT-STATUS-001',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, $output);
        $this->assertSame('OK', $report['status']);
        $this->assertSame('READY_TO_START', $report['phase']);
        $this->assertTrue($report['readOnly']);
        $this->assertSame('LAB-PILOT-STATUS-001', $report['session']['code']);
        $this->assertSame('SIM-RJ-UEU-001', $report['session']['source']);
        $this->assertSame('PLANNED', $report['encounter']['status']);
        $this->assertTrue($report['encounter']['synthetic']);
        $this->assertSame(10, $report['summary']['activeAssignments']);
        $this->assertSame(4, $report['summary']['totalTasks']);
        $this->assertSame(3, $report['summary']['openTasks']);
        $this->assertSame(1, $report['summary']['byStatus']['READY']);
        $this->assertSame(2, $report['summary']['byStatus']['WAITING']);
        $this->assertSame(1, $report['summary']['byStatus']['COMPLETE']);
        $this->assertSame([[
            'type' => 'REGISTRATION',
            'program' => 'RMIK',
            'role' => 'REGISTRAR',
            'priority' => 1,
        ]], $report['readyTasks']);
        $this->assertSame([], $report['attention']);
        $this->assertSame('/work?session=LAB-PILOT-STATUS-001', $report['workQueuePath']);
        $this->assertSame($before, $this->applicationTableCounts());

        foreach ([
            'Pasien Sintetis Arunika',
            'MR-SIM-000001',
            'SYN-NIK-000001',
            'mahasiswa.rmik@example.invalid',
            'local-demo-password-only',
        ] as $protectedValue) {
            $this->assertStringNotContainsString($protectedValue, $output);
        }
    }

    public function test_monitor_reports_finalized_phase_and_debrief_task_after_guarded_completion(): void
    {
        $this->seedReferenceOutpatient();
        $this->activateReferenceTerminology();
        $this->assertSame(0, Artisan::call('simulation:clone-reference-session', [
            'code' => 'LAB-PILOT-STATUS-002',
        ]));
        $this->assertSame(0, Artisan::call('simulation:complete-reference-journey', [
            '--session' => 'LAB-PILOT-STATUS-002',
        ]));

        $exitCode = Artisan::call('simulation:lab-session-status', [
            'session' => 'LAB-PILOT-STATUS-002',
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('FINALIZED', $report['phase']);
        $this->assertSame('FINALIZED', $report['encounter']['status']);
        $this->assertSame(27, $report['summary']['totalTasks']);
        $this->assertSame(10, $report['summary']['openTasks']);
        $this->assertCount(10, $report['readyTasks']);
        $this->assertContains([
            'type' => 'DEBRIEF',
            'program' => 'FACILITATION',
            'role' => 'FACILITATOR',
            'priority' => 2,
        ], $report['readyTasks']);
        $this->assertSame([], $report['attention']);
    }

    public function test_monitor_fails_closed_for_unsafe_and_invalid_requests(): void
    {
        config(['simulation.synthetic_only' => false]);
        $exitCode = Artisan::call('simulation:lab-session-status', [
            'session' => 'LAB-UNSAFE-001',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"id":"simulation.synthetic_only"', $output);

        config(['simulation.synthetic_only' => true]);
        $exitCode = Artisan::call('simulation:lab-session-status', [
            'session' => 'invalid session value',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"session":"INVALID"', $output);
        $this->assertStringNotContainsString('invalid session value', $output);
    }

    public function test_monitor_fails_closed_for_a_missing_session_and_the_retained_source(): void
    {
        $this->seedReferenceOutpatient();

        $exitCode = Artisan::call('simulation:lab-session-status', [
            'session' => 'LAB-NOT-FOUND-001',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"session":"UNAVAILABLE"', $output);
        $this->assertStringContainsString('"id":"session.not_found"', $output);
        $this->assertStringNotContainsString('LAB-NOT-FOUND-001', $output);

        $exitCode = Artisan::call('simulation:lab-session-status', [
            'session' => 'SIM-RJ-UEU-001',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"id":"session.disposable_clone"', $output);
    }

    public function test_monitor_fails_closed_for_a_corrupt_disposable_clone(): void
    {
        $this->seedReferenceOutpatient();

        $this->assertSame(0, Artisan::call('simulation:clone-reference-session', [
            'code' => 'LAB-CORRUPT-001',
        ]));
        $session = SimulationSession::query()->where('code', 'LAB-CORRUPT-001')->sole();
        DB::table('assignments')
            ->where('session_id', $session->getKey())
            ->limit(1)
            ->update(['revoked_at' => now()]);
        $patient = SyntheticPatient::query()->where('session_id', $session->getKey())->sole();
        SyntheticPatient::query()->create([
            'session_id' => $session->getKey(),
            'synthetic_flag' => true,
            'fixture_source' => 'OPD-REF-001-v1-monitor-corruption',
            'full_name' => 'Pasien Sintetis Cadangan',
            'birth_date' => $patient->birth_date,
            'administrative_sex' => $patient->administrative_sex,
            'record_status' => $patient->record_status,
        ]);

        $exitCode = Artisan::call('simulation:lab-session-status', [
            'session' => 'LAB-CORRUPT-001',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status":"BLOCKED"', $output);
        $this->assertStringContainsString('"id":"session.one_synthetic_case"', $output);
        $this->assertStringContainsString('"id":"session.assignment_roster"', $output);
    }

    private function activateReferenceTerminology(): void
    {
        $this->activateRelease(TerminologySystem::Icd10, 'R42', 'Dizziness and giddiness');
        $this->activateRelease(TerminologySystem::Icd9Cm, '38.99', 'Other puncture of vein');
    }

    private function activateRelease(TerminologySystem $system, string $code, string $display): void
    {
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->sole();
        $actor = Assignment::query()
            ->where('user_id', $facilitator->getKey())
            ->where('application_role', ApplicationRole::Facilitator)
            ->sole();
        $release = TerminologyRelease::query()->create([
            'request_key' => (string) Str::ulid(),
            'classification_system' => $system,
            'logical_version' => $system->logicalVersion(),
            'status' => TerminologyReleaseStatus::Imported,
            'source_filename' => 'synthetic-reference-'.$system->value.'.xlsx',
            'source_sha256' => hash('sha256', "{$system->value}|{$code}|{$display}"),
            'sheet_name' => $system->expectedSheetName(),
            'source_provenance_status' => TerminologyProvenanceStatus::SyntheticFixture,
            'imported_by_user_id' => $actor->user_id,
            'imported_by_assignment_id' => $actor->getKey(),
            'row_count' => 1,
            'ignored_blank_rows' => 0,
            'validation_report' => [
                'schema' => 'terminology-import-validation.v1',
                'classificationSystem' => $system->value,
                'sourceHashVerified' => true,
                'syntheticFixture' => true,
            ],
            'imported_at' => now(),
        ]);
        $normalizer = app(TerminologyNormalizer::class);
        $normalizedDisplay = $normalizer->text($display);
        TerminologyConcept::query()->create([
            'terminology_release_id' => $release->getKey(),
            'code' => $code,
            'display' => $display,
            'normalized_code' => $normalizer->code($code),
            'normalized_display' => $normalizedDisplay,
            'search_tokens' => implode(' ', $normalizer->tokens($normalizedDisplay)),
            'active' => true,
        ]);
        $release->persistActivation($actor, null);
    }

    /** @return array<string, int> */
    private function applicationTableCounts(): array
    {
        return collect(
            DB::connection()->getSchemaBuilder()->getTableListing(schemaQualified: false),
        )->mapWithKeys(
            fn (string $table): array => [
                $table => DB::table($table)->count(),
            ],
        )->sortKeys()->all();
    }
}
