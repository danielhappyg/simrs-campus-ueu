<?php

namespace Tests\Feature\Registration;

use App\Models\Encounter;
use App\Models\MedicalRecordNumberCounter;
use App\Models\Patient;
use App\Models\User;
use App\Support\Registration\MedicalRecordNumber;
use App\Support\Registration\MedicalRecordNumberAllocator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OverflowException;
use Tests\TestCase;

class MedicalRecordNumberAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_sequence_is_shared_across_outpatient_emergency_and_inpatient(): void
    {
        $user = User::factory()->create();
        $allocated = [];

        DB::transaction(function () use ($user, &$allocated): void {
            foreach ([
                Encounter::CARE_SETTING_OUTPATIENT,
                Encounter::CARE_SETTING_EMERGENCY,
                Encounter::CARE_SETTING_INPATIENT,
            ] as $careSetting) {
                $mrn = $this->allocator()->allocate();
                $patient = Patient::factory()->create([
                    'medical_record_number' => $mrn->value,
                    'created_by_user_id' => $user->id,
                ]);
                Encounter::factory()->create([
                    'patient_id' => $patient->id,
                    'registered_by_user_id' => $user->id,
                    'care_setting' => $careSetting,
                ]);
                $allocated[] = $mrn->value;
            }
        });

        $this->assertSame(['000001', '000002', '000003'], $allocated);
        $this->assertCount(3, array_unique($allocated));
        $this->assertDatabaseHas('medical_record_number_counters', [
            'scope' => MedicalRecordNumberCounter::GLOBAL_SCOPE,
            'last_number' => 3,
        ]);
    }

    public function test_row_lock_issues_unique_numbers_inside_one_transaction(): void
    {
        $values = DB::transaction(function (): array {
            return [
                $this->allocator()->allocate()->value,
                $this->allocator()->allocate()->value,
                $this->allocator()->allocate()->value,
            ];
        });

        $this->assertSame(['000001', '000002', '000003'], $values);
        $this->assertSame($values, array_values(array_unique($values)));
    }

    public function test_database_rejects_duplicate_medical_record_numbers(): void
    {
        $user = User::factory()->create();
        Patient::factory()->create([
            'medical_record_number' => '000007',
            'created_by_user_id' => $user->id,
        ]);

        $this->expectException(QueryException::class);

        Patient::factory()->create([
            'medical_record_number' => '000007',
            'created_by_user_id' => $user->id,
        ]);
    }

    public function test_rolled_back_allocation_is_reused_by_the_next_successful_transaction(): void
    {
        try {
            DB::transaction(function (): void {
                $allocation = $this->allocator()->allocate();
                $this->assertSame('000001', $allocation->value);

                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertDatabaseCount('medical_record_number_counters', 0);

        $reused = DB::transaction(fn (): MedicalRecordNumber => $this->allocator()->allocate());
        $this->assertSame('000001', $reused->value);
        $this->assertDatabaseHas('medical_record_number_counters', [
            'scope' => MedicalRecordNumberCounter::GLOBAL_SCOPE,
            'last_number' => 1,
        ]);
    }

    public function test_high_watermark_sync_never_decrements_a_counter(): void
    {
        DB::transaction(function (): void {
            $this->allocator()->ensureHighWatermark(12);
            $this->allocator()->ensureHighWatermark(4);
        });

        $this->assertDatabaseHas('medical_record_number_counters', [
            'scope' => MedicalRecordNumberCounter::GLOBAL_SCOPE,
            'last_number' => 12,
        ]);

        $next = DB::transaction(fn (): MedicalRecordNumber => $this->allocator()->allocate());
        $this->assertSame('000013', $next->value);
    }

    public function test_explicit_high_watermark_keeps_padded_characters(): void
    {
        $mrn = DB::transaction(fn (): MedicalRecordNumber => $this->allocator()->ensureHighWatermark(212));

        $this->assertSame('000212', $mrn->value);
        $this->assertDatabaseHas('medical_record_number_counters', [
            'scope' => MedicalRecordNumberCounter::GLOBAL_SCOPE,
            'last_number' => 212,
        ]);
    }

    public function test_overflow_is_rejected(): void
    {
        MedicalRecordNumberCounter::query()->create([
            'scope' => MedicalRecordNumberCounter::GLOBAL_SCOPE,
            'last_number' => MedicalRecordNumber::MAX,
        ]);
        $this->assertSame(
            MedicalRecordNumber::MAX,
            MedicalRecordNumberCounter::query()
                ->where('scope', MedicalRecordNumberCounter::GLOBAL_SCOPE)
                ->value('last_number'),
        );

        $this->expectException(OverflowException::class);
        $this->expectExceptionMessage('capacity');

        DB::transaction(fn () => $this->allocator()->allocate());
    }

    private function allocator(): MedicalRecordNumberAllocator
    {
        return $this->app->make(MedicalRecordNumberAllocator::class);
    }
}
