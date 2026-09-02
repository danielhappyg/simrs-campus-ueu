<?php

namespace Tests\Feature\Finance;

use App\Models\Encounter;
use App\Models\EncounterCancellation;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\InpatientLocationEvent;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceAccommodationOccupancyDayAllocator;
use App\Support\Finance\FinanceAccommodationSourceAdapter;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Inpatient\InpatientMasterService;
use Carbon\CarbonInterface;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class FinanceAccommodationOccupancyEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private InpatientWard $ward;

    private InpatientBed $bed;

    private InpatientBedVersion $bedVersion;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-02 10:00:00');
        $this->seed(RbacSeeder::class);
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $this->actor = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $masters = app(InpatientMasterService::class);
        $ward = $masters->createWard($admin, 'AKO-EVID', 'Bangsal Evidence', InpatientMasterService::REASON_INITIAL_SETUP, 'accommodation-evidence-ward-0001', null)->master;
        if (! $ward instanceof InpatientWard) {
            throw new \LogicException('Expected ward.');
        }
        $this->ward = $ward;
        $bed = $masters->createBed($admin, $ward->public_id, 'AKO-E01', 'Bed Evidence', 'Ruang A', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'accommodation-evidence-bed-0001', null)->master;
        if (! $bed instanceof InpatientBed) {
            throw new \LogicException('Expected bed.');
        }
        $this->bed = $bed;
        $this->bedVersion = InpatientBedVersion::query()->where('bed_id', $bed->id)->where('version', 1)->sole();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_registration_timestamp_mismatch_and_state_fact_mismatch_refuse(): void
    {
        $encounter = $this->encounter(Encounter::STATUS_REGISTERED);
        $this->location($encounter, now()->addMinute());
        $plan = app(FinanceAccommodationOccupancyDayAllocator::class)->allocate($encounter);
        $this->assertSame(FinanceAccommodationOccupancyDayAllocator::CORRUPT_EVIDENCE, $plan->blockingState);
        $this->assertSame([], $plan->days);
        $this->assertSame('BUKTI_TIDAK_KONSISTEN', app(FinanceAccommodationSourceAdapter::class)->readiness($encounter)[0]['state']);

        $cancelledWithoutFact = $this->encounter(Encounter::STATUS_CANCELLED);
        $plan = app(FinanceAccommodationOccupancyDayAllocator::class)->allocate($cancelledWithoutFact);
        $this->assertSame(FinanceAccommodationOccupancyDayAllocator::CORRUPT_EVIDENCE, $plan->blockingState);
        $this->assertSame([], $plan->days);
    }

    public function test_consistent_retained_cancellation_has_no_occupancy_days(): void
    {
        $encounter = $this->encounter(Encounter::STATUS_CANCELLED);
        EncounterCancellation::query()->create([
            'encounter_id' => $encounter->id,
            'cancelled_by_user_id' => $this->actor->id,
            'reason_code' => EncounterCancellation::REASON_PLAN_CHANGED_BEFORE_SERVICE,
            'note' => null,
            'idempotency_key' => 'accommodation-cancel-0001',
            'payload_digest' => str_repeat('a', 64),
            'request_correlation_id' => null,
            'cancelled_at' => now(),
        ]);

        $plan = app(FinanceAccommodationOccupancyDayAllocator::class)->allocate($encounter);
        $this->assertNull($plan->blockingState);
        $this->assertSame([], $plan->days);
        $this->assertSame([], app(FinanceAccommodationSourceAdapter::class)->readiness($encounter));
    }

    public function test_open_admission_is_reported_without_materializing_a_source(): void
    {
        $encounter = $this->encounter(Encounter::STATUS_REGISTERED);
        $this->location($encounter, $encounter->registered_at);

        $plan = app(FinanceAccommodationOccupancyDayAllocator::class)->allocate($encounter);
        $this->assertSame(FinanceAccommodationOccupancyDayAllocator::OPEN_INTERVAL, $plan->blockingState);
        $this->assertSame([], $plan->days);
        $this->assertSame('INTERVAL_MASIH_TERBUKA', app(FinanceAccommodationSourceAdapter::class)->readiness($encounter)[0]['state']);
        $this->assertDatabaseCount('finance_accommodation_source_events', 0);
    }

    public function test_legacy_location_without_exact_version_is_refused_without_inference(): void
    {
        $migration = require database_path('migrations/2026_09_02_000500_add_bed_version_provenance_to_inpatient_location_events.php');
        $migration->down();
        $encounter = $this->encounter(Encounter::STATUS_REGISTERED);
        InpatientLocationMutationScope::run(fn (): InpatientLocationEvent => InpatientLocationEvent::query()->create([
            'encounter_id' => $encounter->id,
            'encounter_public_id' => $encounter->public_id,
            'actor_user_id' => $this->actor->id,
            'event_type' => InpatientLocationEvent::TYPE_ADMISSION,
            'sequence' => 1,
            'to_ward_public_id' => $this->ward->public_id,
            'to_ward_code' => $this->ward->code,
            'to_ward_display_name' => $this->ward->display_name,
            'to_bed_public_id' => $this->bed->public_id,
            'to_bed_code' => $this->bed->code,
            'to_bed_display_name' => $this->bedVersion->display_name,
            'to_room_label' => $this->bedVersion->room_label,
            'to_service_class' => $this->bedVersion->service_class,
            'payload_digest' => str_repeat('c', 64),
            'occurred_at' => $encounter->registered_at,
        ]));
        $migration->up();

        $plan = app(FinanceAccommodationOccupancyDayAllocator::class)->allocate($encounter);
        $this->assertSame(FinanceAccommodationOccupancyDayAllocator::INCOMPLETE_HISTORY, $plan->blockingState);
        $this->assertSame([], $plan->days);
        $this->assertSame('RIWAYAT_LOKASI_TIDAK_LENGKAP', app(FinanceAccommodationSourceAdapter::class)->readiness($encounter)[0]['state']);
        $this->assertDatabaseCount('finance_accommodation_source_events', 0);
    }

    private function encounter(string $status): Encounter
    {
        $patient = Patient::factory()->create(['is_synthetic' => true]);

        return InpatientLocationMutationScope::run(fn (): Encounter => Encounter::factory()->for($patient)->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => $status,
            'registered_at' => now(),
            'queue_date' => now()->toDateString(),
            'registered_by_user_id' => $this->actor->id,
            'clinic_name' => $this->ward->display_name,
            'ward_name' => $this->ward->display_name,
            'ward_class' => $this->bedVersion->service_class,
            'bed_code' => $this->bed->code,
            'inpatient_bed_id' => $this->bed->id,
        ]));
    }

    private function location(Encounter $encounter, CarbonInterface $occurredAt): InpatientLocationEvent
    {
        return InpatientLocationMutationScope::run(fn (): InpatientLocationEvent => InpatientLocationEvent::query()->create([
            'encounter_id' => $encounter->id,
            'encounter_public_id' => $encounter->public_id,
            'actor_user_id' => $this->actor->id,
            'event_type' => InpatientLocationEvent::TYPE_ADMISSION,
            'sequence' => 1,
            'to_ward_public_id' => $this->ward->public_id,
            'to_ward_code' => $this->ward->code,
            'to_ward_display_name' => $this->ward->display_name,
            'to_bed_public_id' => $this->bed->public_id,
            'to_bed_code' => $this->bed->code,
            'to_bed_display_name' => $this->bedVersion->display_name,
            'to_room_label' => $this->bedVersion->room_label,
            'to_service_class' => $this->bedVersion->service_class,
            'to_inpatient_bed_version_id' => $this->bedVersion->id,
            'to_inpatient_bed_version_public_id' => $this->bedVersion->public_id,
            'to_inpatient_bed_version' => $this->bedVersion->version,
            'to_inpatient_bed_after_digest' => $this->bedVersion->after_digest,
            'reason' => null,
            'request_correlation_id' => null,
            'payload_digest' => str_repeat('b', 64),
            'occurred_at' => $occurredAt,
        ]));
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user;
    }
}
