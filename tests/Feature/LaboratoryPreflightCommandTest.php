<?php

namespace Tests\Feature;

use App\Modules\Coding\Enums\TerminologyProvenanceStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Patient\Services\AppointmentCheckInService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class LaboratoryPreflightCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'database',
            'session.table' => 'sessions',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/laboratory-preflight-public'));

        parent::tearDown();
    }

    public function test_read_only_laboratory_preflight_command_is_registered(): void
    {
        $exitCode = Artisan::call('list', ['--raw' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('simulation:lab-preflight', Artisan::output());
    }

    public function test_preflight_fails_closed_for_an_unsafe_or_unprepared_environment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set([
            'app.key' => null,
            'simulation.mode' => 'CLINICAL',
            'simulation.synthetic_only' => false,
            'simulation.demo_seed_enabled' => false,
            'services.satusehat.client_secret' => 'DO-NOT-ECHO-INTEGRATION-SECRET',
            'session.driver' => 'file',
        ]);

        $exitCode = Artisan::call('simulation:lab-preflight', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status":"BLOCKED"', $output);

        foreach ([
            'environment.non_production',
            'simulation.mode',
            'simulation.synthetic_only',
            'simulation.demo_seed_enabled',
            'session.revocable_backend',
            'app.key_present',
            'public.build_manifest',
            'integrations.production_endpoints_absent',
            'source.reference_fixture',
            'terminology.icd10',
            'terminology.icd9cm',
        ] as $checkId) {
            $this->assertStringContainsString($checkId, $output);
        }

        $this->assertStringNotContainsString('DO-NOT-ECHO-INTEGRATION-SECRET', $output);
    }

    public function test_preflight_is_ready_identity_minimized_and_read_only_for_the_complete_fixture(): void
    {
        $this->prepareReadyFixture();
        $before = $this->applicationTableCounts();

        $exitCode = Artisan::call('simulation:lab-preflight', ['--json' => true]);
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, $output);
        $this->assertSame('READY', $report['status']);
        $this->assertTrue($report['readOnly']);
        $this->assertSame(0, $report['summary']['failed']);
        $this->assertSame('SIM-RJ-UEU-001', $report['source']);
        $this->assertSame($before, $this->applicationTableCounts());

        foreach ([
            'Pasien Sintetis Arunika',
            'MR-SIM-000001',
            'SYN-NIK-000001',
            'mahasiswa.rmik@example.invalid',
            'local-demo-password-only',
            (string) config('app.key'),
        ] as $protectedValue) {
            $this->assertStringNotContainsString($protectedValue, $output);
        }

        $this->assertStringContainsString('ICD10_2010', $output);
        $this->assertStringContainsString('ICD9CM_2010', $output);
        $this->assertStringContainsString('checksum suffix', $output);
    }

    public function test_preflight_blocks_missing_release_and_inactive_demo_account(): void
    {
        $this->prepareCompiledAssets();
        $this->seedReferenceOutpatient();

        DB::table('users')
            ->where('email', 'mahasiswa.farmasi@example.invalid')
            ->update(['status' => 'INACTIVE']);

        $exitCode = Artisan::call('simulation:lab-preflight', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status":"BLOCKED"', $output);
        $this->assertStringContainsString('"id":"accounts.demo_roster","status":"FAIL"', $output);
        $this->assertStringContainsString('"id":"assignments.reference_roster","status":"FAIL"', $output);
        $this->assertStringContainsString('"id":"terminology.icd10","status":"FAIL"', $output);
        $this->assertStringContainsString('"id":"terminology.icd9cm","status":"FAIL"', $output);
        $this->assertStringNotContainsString('mahasiswa.farmasi@example.invalid', $output);
    }

    public function test_preflight_blocks_a_progressed_reference_source(): void
    {
        $this->prepareReadyFixture();
        $source = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->sole();
        $appointment = AppointmentRegistration::query()->where('session_id', $source->getKey())->sole();
        $registrar = Assignment::query()
            ->where('session_id', $source->getKey())
            ->get()
            ->sole(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::PatientRegister));

        app(AppointmentCheckInService::class)->checkIn($appointment, $registrar);

        $exitCode = Artisan::call('simulation:lab-preflight', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status":"BLOCKED"', $output);
        $this->assertStringContainsString('"id":"source.pristine_graph","status":"FAIL"', $output);
    }

    public function test_preflight_rejects_an_invalid_source_code_without_echoing_it(): void
    {
        $exitCode = Artisan::call('simulation:lab-preflight', [
            '--source' => 'invalid source with spaces',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"source":"INVALID"', $output);
        $this->assertStringContainsString('"id":"source.session_code","status":"FAIL"', $output);
        $this->assertStringNotContainsString('invalid source with spaces', $output);
    }

    private function prepareReadyFixture(): void
    {
        $this->prepareCompiledAssets();
        $this->seedReferenceOutpatient();
        $this->seedActiveTerminologyRelease(TerminologySystem::Icd10, 'a');
        $this->seedActiveTerminologyRelease(TerminologySystem::Icd9Cm, 'b');
    }

    private function prepareCompiledAssets(): void
    {
        $publicPath = storage_path('framework/testing/laboratory-preflight-public');
        File::ensureDirectoryExists($publicPath.'/build');
        File::put($publicPath.'/build/manifest.json', '{}');
        app()->usePublicPath($publicPath);
    }

    private function seedActiveTerminologyRelease(TerminologySystem $system, string $checksumCharacter): void
    {
        $source = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->sole();
        $assignment = Assignment::query()
            ->where('session_id', $source->getKey())
            ->get()
            ->sole(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::TerminologyManage));
        $now = now();
        $releaseId = DB::table('terminology_releases')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'request_key' => (string) Str::ulid(),
            'classification_system' => $system->value,
            'logical_version' => $system->logicalVersion(),
            'status' => TerminologyReleaseStatus::Active->value,
            'source_filename' => 'synthetic-terminology-fixture.xlsx',
            'source_sha256' => str_repeat($checksumCharacter, 64),
            'sheet_name' => $system->expectedSheetName(),
            'source_provenance_status' => TerminologyProvenanceStatus::VerifiedUserSupplied->value,
            'imported_by_user_id' => $assignment->user_id,
            'imported_by_assignment_id' => $assignment->getKey(),
            'row_count' => 1,
            'ignored_blank_rows' => 0,
            'validation_report' => json_encode(['valid' => true], JSON_THROW_ON_ERROR),
            'imported_at' => $now,
            'activated_by_user_id' => $assignment->user_id,
            'activated_by_assignment_id' => $assignment->getKey(),
            'activated_at' => $now,
            'supersedes_release_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('terminology_concepts')->insert([
            'public_id' => (string) Str::ulid(),
            'terminology_release_id' => $releaseId,
            'code' => $system === TerminologySystem::Icd10 ? 'Z00.0' : '89.01',
            'display' => 'Synthetic terminology concept',
            'normalized_code' => $system === TerminologySystem::Icd10 ? 'Z000' : '8901',
            'normalized_display' => 'synthetic terminology concept',
            'search_tokens' => 'synthetic terminology concept',
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
