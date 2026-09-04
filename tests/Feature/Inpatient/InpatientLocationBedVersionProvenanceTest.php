<?php

namespace Tests\Feature\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\InpatientLocationEvent;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Database\SchemaQualifier;
use App\Support\Inpatient\InpatientAdmissionService;
use App\Support\Inpatient\InpatientBedTransferService;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Inpatient\InpatientMasterService;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class InpatientLocationBedVersionProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $registrar;

    private InpatientWard $ward;

    private InpatientBed $source;

    private InpatientBed $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = $this->userWithRole(RoleCapabilityMatrix::ROLE_ADMIN);
        $this->registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $masters = app(InpatientMasterService::class);
        $ward = $masters->createWard(
            $this->admin,
            'RI-PROVENANCE',
            'Bangsal Provenance',
            InpatientMasterService::REASON_INITIAL_SETUP,
            'provenance-ward-0001',
            null,
        )->master;
        if (! $ward instanceof InpatientWard) {
            throw new LogicException('Expected inpatient ward.');
        }
        $this->ward = $ward;
        $this->source = $this->createBed($masters, 'PROV-01', 'Bed Provenance 01', 'provenance-bed-0001');
        $this->target = $this->createBed($masters, 'PROV-02', 'Bed Provenance 02', 'provenance-bed-0002');
    }

    public function test_admission_and_transfer_retain_exact_versions_and_prior_destination_continuity_after_bed_revision(): void
    {
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $admission = app(InpatientAdmissionService::class)->admitDirect(
            patient: $patient,
            actor: $this->registrar,
            bedPublicId: $this->source->public_id,
            payerType: Encounter::PAYER_UMUM,
            insuranceNumber: null,
            continueFrom: Encounter::CONTINUE_LANGSUNG,
            chiefComplaint: 'Observasi provenance sintetis',
            admissionAuthorityType: Encounter::AUTHORITY_PLANNED_ORDER,
            admissionAuthorityReference: 'ORDER-BED-PROVENANCE-0001',
        );
        $sourceVersionOne = InpatientBedVersion::query()
            ->where('bed_id', $this->source->id)
            ->where('version', 1)
            ->sole();

        $this->assertExactToVersion($admission->location, $sourceVersionOne);
        $this->assertNull($admission->location->from_inpatient_bed_version_id);
        $this->assertTrue($admission->location->hasRequiredBedVersionProvenance());
        $this->assertTrue($admission->location->toBedVersion->is($sourceVersionOne));
        $this->assertNull($admission->location->fromBedVersion);

        app(InpatientMasterService::class)->updateBed(
            $this->admin,
            $this->source->public_id,
            'Bed Provenance 01 Revisi',
            'Ruang Provenance Revisi',
            'Kelas 1',
            1,
            InpatientMasterService::REASON_OPERATIONAL_CHANGE,
            'provenance-bed-revision-0001',
            null,
        );
        $this->assertSame(2, $this->source->fresh()?->version);

        $transfer = app(InpatientBedTransferService::class)->transfer(
            $admission->encounter->public_id,
            $this->registrar,
            1,
            $this->source->public_id,
            $this->target->public_id,
            'Pindah setelah revisi master',
            'provenance-transfer-0001',
        )->event;
        $targetVersion = InpatientBedVersion::query()
            ->where('bed_id', $this->target->id)
            ->where('version', 1)
            ->sole();

        foreach ($this->snapshotSuffixes() as $suffix) {
            $this->assertSame(
                $admission->location->getAttribute('to_'.$suffix),
                $transfer->getAttribute('from_'.$suffix),
                'Transfer source must remain byte-for-byte equal to the prior destination for '.$suffix.'.',
            );
        }
        $this->assertSame($sourceVersionOne->id, $transfer->from_inpatient_bed_version_id);
        $this->assertNotSame($this->source->fresh()?->version, $transfer->from_inpatient_bed_version);
        $this->assertTrue($transfer->fromBedVersion->is($sourceVersionOne));
        $this->assertExactToVersion($transfer, $targetVersion);
        $this->assertTrue($transfer->toBedVersion->is($targetVersion));
        $this->assertTrue($transfer->hasRequiredBedVersionProvenance());
    }

    public function test_nullable_migration_preserves_legacy_nulls_without_inference_for_finance_consumers(): void
    {
        $migration = $this->migration();
        $migration->down();

        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = InpatientLocationMutationScope::run(fn (): Encounter => Encounter::query()->create([
            'patient_id' => $patient->id,
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
            'clinic_name' => $this->ward->display_name,
            'ward_name' => $this->ward->display_name,
            'ward_class' => $this->source->service_class,
            'bed_code' => $this->source->code,
            'inpatient_bed_id' => $this->source->id,
            'continue_from' => Encounter::CONTINUE_LANGSUNG,
            'visit_date' => now()->toDateString(),
            'payer_type' => Encounter::PAYER_UMUM,
            'queue_date' => now()->toDateString(),
            'queue_number' => 1,
            'registered_at' => now(),
            'registered_by_user_id' => $this->registrar->id,
        ]));
        $legacy = InpatientLocationMutationScope::run(fn (): InpatientLocationEvent => InpatientLocationEvent::query()->create([
            'encounter_id' => $encounter->id,
            'encounter_public_id' => $encounter->public_id,
            'actor_user_id' => $this->registrar->id,
            'event_type' => InpatientLocationEvent::TYPE_ADMISSION,
            'sequence' => 1,
            ...$this->plainSnapshot('to', $this->source),
            'reason' => null,
            'payload_digest' => str_repeat('a', 64),
            'occurred_at' => $encounter->registered_at,
        ]));

        $migration->up();
        $legacy = $legacy->fresh();
        $this->assertInstanceOf(InpatientLocationEvent::class, $legacy);
        $this->assertNull($legacy->to_inpatient_bed_version_id);
        $this->assertNull($legacy->to_inpatient_bed_version_public_id);
        $this->assertNull($legacy->to_inpatient_bed_version);
        $this->assertNull($legacy->to_inpatient_bed_after_digest);
        $this->assertFalse($legacy->hasRequiredBedVersionProvenance());
    }

    public function test_database_guard_rejects_digest_drift_and_reset_still_deletes_valid_provenance(): void
    {
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $admission = app(InpatientAdmissionService::class)->admitDirect(
            $patient,
            $this->registrar,
            $this->source->public_id,
            Encounter::PAYER_UMUM,
            null,
            Encounter::CONTINUE_LANGSUNG,
            null,
            admissionAuthorityType: Encounter::AUTHORITY_PLANNED_ORDER,
            admissionAuthorityReference: 'ORDER-BED-PROVENANCE-0002',
        );
        $targetVersion = InpatientBedVersion::query()
            ->where('bed_id', $this->target->id)
            ->where('version', 1)
            ->sole();
        $invalid = [
            'public_id' => (string) Str::ulid(),
            'encounter_id' => $admission->encounter->id,
            'encounter_public_id' => $admission->encounter->public_id,
            'actor_user_id' => $this->registrar->id,
            'event_type' => InpatientLocationEvent::TYPE_TRANSFER,
            'sequence' => 2,
            ...$this->priorToSnapshot($admission->location),
            ...$this->plainSnapshot('to', $this->target),
            'to_inpatient_bed_version_id' => $targetVersion->id,
            'to_inpatient_bed_version_public_id' => $targetVersion->public_id,
            'to_inpatient_bed_version' => $targetVersion->version,
            'to_inpatient_bed_after_digest' => str_repeat('f', 64),
            'reason' => 'Bukti digest sengaja salah',
            'request_correlation_id' => null,
            'payload_digest' => str_repeat('b', 64),
            'occurred_at' => now()->addMinute(),
            'created_at' => now(),
        ];

        try {
            DB::transaction(fn (): bool => InpatientLocationMutationScope::run(
                fn (): bool => DB::table(SchemaQualifier::table('inpatient_location_events'))->insert($invalid),
            ));
            $this->fail('The database guard must reject a drifted exact-version digest.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('destination bed-version provenance does not resolve exactly', $exception->getMessage());
        }
        $this->assertDatabaseCount('inpatient_location_events', 1);

        app(SyntheticResetService::class)->reset([
            'actor' => $this->registrar,
            'reason' => 'bed_version_provenance_reset_test',
        ]);
        $this->assertDatabaseCount('inpatient_location_events', 0);
    }

    public function test_schema_is_nullable_fk_backed_and_declares_postgres_mysql_safe_guards(): void
    {
        $events = SchemaQualifier::table('inpatient_location_events');
        foreach ([
            'from_inpatient_bed_version_id',
            'from_inpatient_bed_version_public_id',
            'from_inpatient_bed_version',
            'from_inpatient_bed_after_digest',
            'to_inpatient_bed_version_id',
            'to_inpatient_bed_version_public_id',
            'to_inpatient_bed_version',
            'to_inpatient_bed_after_digest',
        ] as $name) {
            $column = collect(Schema::getColumns($events))->firstWhere('name', $name);
            $this->assertIsArray($column);
            $this->assertTrue($column['nullable'], $name.' must remain nullable for retained legacy evidence.');
        }

        $foreignKeys = collect(Schema::getForeignKeys($events));
        foreach (['from_inpatient_bed_version_id', 'to_inpatient_bed_version_id'] as $column) {
            $foreign = $foreignKeys->firstWhere('columns', [$column]);
            $this->assertIsArray($foreign);
            $this->assertSame('inpatient_bed_versions', $foreign['foreign_table']);
            $this->assertSame('restrict', $foreign['on_delete']);
        }

        $source = file_get_contents(database_path('migrations/2026_09_02_000500_add_bed_version_provenance_to_inpatient_location_events.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString("'pgsql' => \$this->createPostgresInsertGuard()", $source);
        $this->assertStringContainsString("'mysql' => \$this->createMysqlInsertGuard()", $source);
        $this->assertStringContainsString('IS NOT DISTINCT FROM', $source);
        $this->assertStringContainsString('<=>', $source);
        $this->assertStringContainsString('ile_bed_version_shape_ck', $source);
    }

    /** @return list<string> */
    private function snapshotSuffixes(): array
    {
        return [
            'ward_public_id', 'ward_code', 'ward_display_name',
            'bed_public_id', 'bed_code', 'bed_display_name', 'room_label', 'service_class',
            'inpatient_bed_version_id', 'inpatient_bed_version_public_id',
            'inpatient_bed_version', 'inpatient_bed_after_digest',
        ];
    }

    private function assertExactToVersion(InpatientLocationEvent $event, InpatientBedVersion $version): void
    {
        $this->assertSame($version->id, $event->to_inpatient_bed_version_id);
        $this->assertSame($version->public_id, $event->to_inpatient_bed_version_public_id);
        $this->assertSame($version->version, $event->to_inpatient_bed_version);
        $this->assertSame($version->after_digest, $event->to_inpatient_bed_after_digest);
    }

    /** @return array<string, string> */
    private function plainSnapshot(string $prefix, InpatientBed $bed): array
    {
        return [
            $prefix.'_ward_public_id' => $this->ward->public_id,
            $prefix.'_ward_code' => $this->ward->code,
            $prefix.'_ward_display_name' => $this->ward->display_name,
            $prefix.'_bed_public_id' => $bed->public_id,
            $prefix.'_bed_code' => $bed->code,
            $prefix.'_bed_display_name' => $bed->display_name,
            $prefix.'_room_label' => $bed->room_label,
            $prefix.'_service_class' => $bed->service_class,
        ];
    }

    /** @return array<string, int|string|null> */
    private function priorToSnapshot(InpatientLocationEvent $event): array
    {
        $attributes = [];
        foreach ($this->snapshotSuffixes() as $suffix) {
            $attributes['from_'.$suffix] = $event->getAttribute('to_'.$suffix);
        }

        return $attributes;
    }

    private function createBed(InpatientMasterService $service, string $code, string $name, string $key): InpatientBed
    {
        $bed = $service->createBed(
            $this->admin,
            $this->ward->public_id,
            $code,
            $name,
            'Ruang Provenance',
            'Kelas 1',
            InpatientMasterService::REASON_INITIAL_SETUP,
            $key,
            null,
        )->master;
        if (! $bed instanceof InpatientBed) {
            throw new LogicException('Expected inpatient bed.');
        }

        return $bed;
    }

    private function userWithRole(string $slug): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $slug)->sole()->id]);

        return $user;
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_02_000500_add_bed_version_provenance_to_inpatient_location_events.php');

        return $migration;
    }
}
