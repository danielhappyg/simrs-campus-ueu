<?php

namespace Tests\Feature\Inpatient;

use App\Models\Encounter;
use App\Support\Registration\InpatientBedClaimGuard;
use App\Support\Registration\InpatientBedUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use Tests\TestCase;

class InpatientBedClaimGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_requires_an_active_database_transaction(): void
    {
        $database = DB::getFacadeRoot();
        $connection = Mockery::mock();
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);

        DB::shouldReceive('connection')->once()->andReturn($connection);

        try {
            app(InpatientBedClaimGuard::class)->assertAvailable('RI-MELATI-01');
            $this->fail('A bed claim outside a database transaction must be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('active database transaction', $exception->getMessage());
        } finally {
            DB::swap($database);
        }
    }

    public function test_claim_locks_only_the_selected_bed_mutex(): void
    {
        DB::transaction(function (): void {
            $guard = app(InpatientBedClaimGuard::class);
            $guard->assertAvailable('RI-MELATI-01');
            $guard->assertAvailable('RI-MELATI-02');
        });

        $this->assertDatabaseHas('inpatient_bed_claim_mutexes', ['bed_code' => 'RI-MELATI-01']);
        $this->assertDatabaseHas('inpatient_bed_claim_mutexes', ['bed_code' => 'RI-MELATI-02']);
        $this->assertDatabaseCount('inpatient_bed_claim_mutexes', 2);
        $this->assertDatabaseCount('daily_queue_counters', 0);
    }

    public function test_claim_rejects_an_open_inpatient_encounter_for_the_same_bed(): void
    {
        Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
            'bed_code' => 'RI-MELATI-01',
        ]);

        try {
            DB::transaction(function (): void {
                app(InpatientBedClaimGuard::class)->assertAvailable('RI-MELATI-01');
            });
            $this->fail('An open inpatient encounter must keep its bed unavailable.');
        } catch (InpatientBedUnavailable $exception) {
            $this->assertStringContainsString('sudah dipakai', $exception->getMessage());
        }

        $this->assertDatabaseCount('inpatient_bed_claim_mutexes', 0);
    }

    public function test_claim_allows_a_different_bed_and_a_bed_from_a_closed_encounter(): void
    {
        Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
            'bed_code' => 'RI-MELATI-01',
        ]);
        Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_CLOSED,
            'bed_code' => 'RI-MELATI-02',
        ]);

        DB::transaction(function (): void {
            $guard = app(InpatientBedClaimGuard::class);
            $guard->assertAvailable('RI-MELATI-02');
            $guard->assertAvailable('RI-MELATI-03');
        });

        $this->assertDatabaseHas('inpatient_bed_claim_mutexes', ['bed_code' => 'RI-MELATI-02']);
        $this->assertDatabaseHas('inpatient_bed_claim_mutexes', ['bed_code' => 'RI-MELATI-03']);
        $this->assertDatabaseCount('inpatient_bed_claim_mutexes', 2);
    }
}
