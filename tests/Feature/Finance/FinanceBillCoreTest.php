<?php

namespace Tests\Feature\Finance;

use App\Models\Encounter;
use App\Models\FinanceBill;
use App\Models\FinanceBillVersion;
use App\Models\FinanceChargeEvent;
use App\Models\Patient;
use App\Models\PharmacyDepot;
use App\Models\PharmacyFinancialSourceEvent;
use App\Models\PharmacyHandover;
use App\Models\PharmacyHandoverItem;
use App\Models\PharmacyMedicine;
use App\Models\PharmacyPreparation;
use App\Models\PharmacyPrescription;
use App\Models\PharmacyPrescriptionItem;
use App\Models\PharmacyReturn;
use App\Models\PharmacyReturnItem;
use App\Models\PharmacyStockLot;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceBillService;
use App\Support\Finance\FinanceDenied;
use App\Support\Finance\FinanceMutationScope;
use App\Support\Finance\FinanceProjection;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Pharmacy\PharmacyAppendOnlyGuard;
use App\Support\Pharmacy\PharmacyCanonicalJson;
use App\Support\Pharmacy\PharmacyMutationScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class FinanceBillCoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_rj_and_igd_issue_exact_versions_while_legacy_ri_without_location_provenance_fails_closed(): void
    {
        foreach (Encounter::CARE_SETTINGS as $index => $careSetting) {
            $fixture = $this->fixture($careSetting, 2, 1500, 'X'.$index);
            $service = app(FinanceBillService::class);
            if ($careSetting === Encounter::CARE_SETTING_INPATIENT) {
                $this->assertDenied(
                    'unresolved_accommodation_source',
                    fn () => $service->synchronize($fixture['encounter']->public_id, $fixture['cashier'], "finance-sync-{$index}"),
                );
                $this->assertDatabaseMissing('finance_bills', ['encounter_id' => $fixture['encounter']->id]);

                continue;
            }
            $sync = $service->synchronize($fixture['encounter']->public_id, $fixture['cashier'], "finance-sync-{$index}");
            $this->assertFalse($sync->replayed);
            $this->assertSame(FinanceBill::OPEN_NO_VERSION, $sync->record->state);

            $fingerprint = app(FinanceProjection::class)->fingerprint($sync->record);
            $issued = $service->issue($sync->record->public_id, $fixture['cashier'], $fingerprint, 'Penerbitan awal tagihan obat.', "finance-issue-{$index}");
            $this->assertFalse($issued->replayed);
            $this->assertInstanceOf(FinanceBillVersion::class, $issued->record);
            $this->assertSame(3000, $issued->record->gross_amount);
            $this->assertSame(0, $issued->record->reversal_amount);
            $this->assertSame(3000, $issued->record->net_amount);
            $this->assertSame($careSetting, $issued->record->care_setting);
            $this->assertSame(FinanceBillVersion::COVERAGE_PHARMACY_V1, $issued->record->coverage_profile);
            $this->assertCount(1, $issued->record->lines);

            $replay = $service->issue($sync->record->public_id, $fixture['cashier'], $fingerprint, 'Penerbitan awal tagihan obat.', "finance-issue-{$index}");
            $this->assertTrue($replay->replayed);
            $this->assertSame($issued->record->public_id, $replay->record->public_id);
            $this->assertSame(1, FinanceBillVersion::query()->where('bill_id', $sync->record->id)->count());
        }
    }

    public function test_later_return_is_discoverable_then_creates_new_version_without_rewriting_history(): void
    {
        $fixture = $this->fixture(Encounter::CARE_SETTING_OUTPATIENT, 2, 1250, 'LATER');
        $service = app(FinanceBillService::class);
        $bill = $service->synchronize($fixture['encounter']->public_id, $fixture['cashier'], 'finance-later-sync-1')->record;
        $versionOne = $service->issue($bill->public_id, $fixture['cashier'], app(FinanceProjection::class)->fingerprint($bill), 'Versi awal obat diserahkan.', 'finance-later-issue-1')->record;
        $lineDigest = $versionOne->lines->sole()->content_digest;

        $this->appendReversal($fixture, 1);
        $worklist = app(FinanceProjection::class)->worklist($fixture['cashier']);
        $summary = collect($worklist['bills'])->firstWhere('public_id', $bill->public_id);
        $this->assertSame(1, $summary['pending_source_count']);
        $this->assertTrue($summary['synchronization_available']);
        $this->assertSame(FinanceBill::ISSUED_CURRENT, $summary['state']);

        $bill = $service->synchronize($fixture['encounter']->public_id, $fixture['cashier'], 'finance-later-sync-2')->record;
        $this->assertSame(FinanceBill::NEW_SOURCE_PENDING, $bill->state);
        $versionTwo = $service->issue($bill->public_id, $fixture['cashier'], app(FinanceProjection::class)->fingerprint($bill), 'Versi baru setelah retur obat.', 'finance-later-issue-2')->record;
        $this->assertSame(2, $versionTwo->version);
        $this->assertSame(2500, $versionTwo->gross_amount);
        $this->assertSame(-1250, $versionTwo->reversal_amount);
        $this->assertSame(1250, $versionTwo->net_amount);
        $this->assertCount(2, $versionTwo->lines);
        $this->assertSame($lineDigest, $versionOne->fresh()->lines()->sole()->content_digest);
        $this->assertSame(1, $versionOne->version);
    }

    public function test_worklist_exposes_read_only_first_synchronization_candidate(): void
    {
        $fixture = $this->fixture(Encounter::CARE_SETTING_EMERGENCY, 3, 1000, 'CANDIDATE');
        $before = app(FinanceProjection::class)->worklist($fixture['cashier']);
        $candidate = collect($before['synchronization_candidates'])->sole();
        $this->assertSame($fixture['encounter']->public_id, $candidate['encounter_public_id']);
        $this->assertSame(3000, $candidate['net_amount']);
        $this->assertDatabaseCount('finance_bills', 0);
        $this->assertDatabaseCount('finance_charge_events', 0);

        app(FinanceBillService::class)->synchronize($fixture['encounter']->public_id, $fixture['cashier'], 'finance-candidate-sync');
        $after = app(FinanceProjection::class)->worklist($fixture['cashier']);
        $this->assertSame([], $after['synchronization_candidates']);
        $this->assertCount(1, $after['bills']);
    }

    public function test_exact_role_cancelled_encounter_stale_and_idempotency_conflicts_fail_closed(): void
    {
        $fixture = $this->fixture(Encounter::CARE_SETTING_OUTPATIENT, 1, 1000, 'DENIAL');
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        try {
            app(FinanceBillService::class)->synchronize($fixture['encounter']->public_id, $nurse, 'finance-denied-role');
            $this->fail('Expected exact cashier denial.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('audit_events', ['action' => 'finance.workflow.mutate', 'outcome' => 'DENIED', 'reason' => 'role_not_permitted']);
        }

        $service = app(FinanceBillService::class);
        $bill = $service->synchronize($fixture['encounter']->public_id, $fixture['cashier'], 'finance-denial-sync')->record;
        $fingerprint = app(FinanceProjection::class)->fingerprint($bill);
        $service->issue($bill->public_id, $fixture['cashier'], $fingerprint, 'Penerbitan untuk uji konflik.', 'finance-denial-key-conflict');
        $this->assertDenied('idempotency_key_conflict', fn () => $service->issue($bill->public_id, $fixture['cashier'], $fingerprint, 'Muatan penerbitan berubah.', 'finance-denial-key-conflict'));
        $this->assertDenied('stale_bill', fn () => $service->issue($bill->public_id, $fixture['cashier'], str_repeat('a', 64), 'Fingerprint lama.', 'finance-denial-stale'));

        $fixture['encounter']->update(['status' => Encounter::STATUS_CANCELLED]);
        $this->assertDenied('encounter_cancelled', fn () => $service->issue($bill->public_id, $fixture['cashier'], app(FinanceProjection::class)->fingerprint($bill->fresh()), 'Encounter dibatalkan.', 'finance-denial-cancelled'));
    }

    public function test_source_drift_and_unguarded_finance_writes_are_refused(): void
    {
        $fixture = $this->fixture(Encounter::CARE_SETTING_OUTPATIENT, 1, 2000, 'GUARD');
        $bill = app(FinanceBillService::class)->synchronize($fixture['encounter']->public_id, $fixture['cashier'], 'finance-guard-sync')->record;
        $event = FinanceChargeEvent::query()->sole();

        try {
            DB::table('finance_bills')->where('id', $bill->id)->update(['state' => FinanceBill::ISSUED_CURRENT]);
            $this->fail('Expected application SQL guard refusal.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Write-capable SQL against finance tables is prohibited', $exception->getMessage());
        }
        try {
            FinanceMutationScope::run(fn () => DB::transaction(
                fn () => DB::table('finance_charge_events')->where('id', $event->id)->update(['description' => 'Tampered']),
            ));
            $this->fail('Expected append-only database refusal.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('finance append-only evidence is immutable', $exception->getMessage());
        }

        PharmacyMutationScope::run(fn () => DB::transaction(fn () => PharmacyAppendOnlyGuard::runSyntheticReset(
            fn () => DB::table('pharmacy_financial_source_events')->where('id', $fixture['source']->id)->update(['amount' => 9999]),
        )));
        $this->assertDenied('source_integrity_failure', fn () => app(FinanceBillService::class)->synchronize($fixture['encounter']->public_id, $fixture['cashier'], 'finance-guard-drift'));
    }

    /** @return array<string,mixed> */
    private function fixture(string $careSetting, int $quantity, int $unitAmount, string $suffix): array
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $pharmacist = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACIST);
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = InpatientLocationMutationScope::run(fn () => Encounter::factory()->create([
            'patient_id' => $patient->id,
            'care_setting' => $careSetting,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => $careSetting === Encounter::CARE_SETTING_INPATIENT ? 'Ruang Anggrek' : 'Unit '.$suffix,
            'ward_name' => $careSetting === Encounter::CARE_SETTING_INPATIENT ? 'Ruang Anggrek' : null,
            'ward_class' => $careSetting === Encounter::CARE_SETTING_INPATIENT ? 'KELAS_2' : null,
            'bed_code' => $careSetting === Encounter::CARE_SETTING_INPATIENT ? 'A-01' : null,
        ]));
        $digest = str_repeat('a', 64);

        return PharmacyMutationScope::run(function () use ($cashier, $pharmacist, $patient, $encounter, $careSetting, $quantity, $unitAmount, $suffix, $digest): array {
            $medicine = PharmacyMedicine::query()->create([
                'medicine_code' => 'MED-'.$suffix, 'generic_name' => 'Parasetamol '.$suffix, 'strength_text' => '500 mg',
                'dosage_form' => 'TABLET', 'base_unit' => 'TABLET', 'route_choices' => ['ORAL'],
                'acquisition_value' => 500, 'teaching_sale_value' => $unitAmount, 'state' => PharmacyMedicine::ACTIVE,
                'version' => 1, 'current_content_digest' => $digest,
            ]);
            $depot = PharmacyDepot::query()->create([
                'depot_code' => 'DEP-'.$suffix, 'display_name' => 'Depo '.$suffix,
                'eligible_care_settings' => [$careSetting], 'state' => PharmacyDepot::ACTIVE,
                'version' => 1, 'current_content_digest' => $digest,
            ]);
            $lot = PharmacyStockLot::query()->create([
                'medicine_id' => $medicine->id, 'depot_id' => $depot->id, 'opened_by_user_id' => $pharmacist->id,
                'medicine_version' => 1, 'depot_version' => 1, 'medicine_code_snapshot' => $medicine->medicine_code,
                'depot_code_snapshot' => $depot->depot_code, 'lot_code' => 'LOT-'.$suffix, 'received_at' => now(),
                'expiry_date' => now()->addYear()->toDateString(), 'available_quantity' => 100, 'quarantined_quantity' => 0,
                'acquisition_value' => 500, 'source_reference' => 'FIXTURE-'.$suffix, 'state' => PharmacyStockLot::ACTIVE,
                'version' => 1, 'content_digest' => $digest,
            ]);
            $prescription = PharmacyPrescription::query()->create([
                'encounter_id' => $encounter->id, 'patient_id' => $patient->id,
                'ordering_physician_user_id' => $pharmacist->id, 'depot_id' => $depot->id,
                'care_setting' => $careSetting, 'encounter_number_snapshot' => $encounter->public_id,
                'location_snapshot' => 'Lokasi '.$suffix, 'depot_version' => 1,
                'depot_code_snapshot' => $depot->depot_code, 'status' => PharmacyPrescription::HANDED_OVER,
                'version' => 1, 'current_content_digest' => $digest, 'ordered_at' => now(),
            ]);
            $item = PharmacyPrescriptionItem::query()->create([
                'prescription_id' => $prescription->id, 'medicine_id' => $medicine->id, 'line_number' => 1,
                'medicine_version' => 1, 'medicine_version_public_id' => $medicine->public_id,
                'medicine_content_digest' => $digest, 'medicine_code' => $medicine->medicine_code,
                'medicine_name' => $medicine->generic_name, 'strength_text' => '500 mg', 'dosage_form' => 'TABLET',
                'base_unit' => 'TABLET', 'dose_text' => '1 tablet', 'route' => 'ORAL',
                'frequency_text' => '3 kali sehari', 'duration_text' => '1 hari',
                'requested_quantity' => $quantity, 'verified_quantity' => $quantity,
                'sale_value_snapshot' => $unitAmount, 'instruction' => 'Sesudah makan',
                'content_digest' => $digest, 'created_at' => now(),
            ]);
            [$handover, $handoverItem, $source] = $this->appendChargeRows($prescription, $item, $lot, $pharmacist, $quantity, $unitAmount, 1);

            return compact('cashier', 'pharmacist', 'patient', 'encounter', 'medicine', 'depot', 'lot', 'prescription', 'item', 'handover', 'handoverItem', 'source');
        });
    }

    /** @return array{PharmacyHandover,PharmacyHandoverItem,PharmacyFinancialSourceEvent} */
    private function appendChargeRows(PharmacyPrescription $prescription, PharmacyPrescriptionItem $item, PharmacyStockLot $lot, User $actor, int $quantity, int $unitAmount, int $sequence): array
    {
        $digest = str_repeat('b', 64);
        $preparation = PharmacyPreparation::query()->create([
            'prescription_id' => $prescription->id, 'technician_user_id' => $actor->id, 'sequence' => $sequence,
            'state' => PharmacyPreparation::ACTIVE, 'prescription_fingerprint' => $digest,
            'stock_fingerprint' => $digest, 'content_digest' => $digest, 'prepared_at' => now(), 'created_at' => now(),
        ]);
        $handover = PharmacyHandover::query()->create([
            'prescription_id' => $prescription->id, 'preparation_id' => $preparation->id,
            'pharmacist_user_id' => $actor->id, 'sequence' => $sequence, 'state' => PharmacyHandover::FULL,
            'preparation_fingerprint' => $digest, 'content_digest' => $digest,
            'handed_over_at' => now()->addSeconds($sequence), 'created_at' => now()->addSeconds($sequence),
        ]);
        $handoverItem = PharmacyHandoverItem::query()->create([
            'handover_id' => $handover->id, 'prescription_item_id' => $item->id, 'stock_lot_id' => $lot->id,
            'quantity' => $quantity, 'sale_value_snapshot' => $unitAmount, 'content_digest' => $digest, 'created_at' => now(),
        ]);
        $source = PharmacyFinancialSourceEvent::query()->create([
            'prescription_id' => $prescription->id, 'prescription_item_id' => $item->id,
            'actor_user_id' => $actor->id, 'event_type' => PharmacyFinancialSourceEvent::CHARGE,
            'quantity' => $quantity, 'amount' => $quantity * $unitAmount,
            'source_type' => 'HANDOVER_ITEM', 'source_public_id' => $handoverItem->public_id,
            'content_digest' => PharmacyCanonicalJson::digest([$prescription->public_id, $item->public_id, $actor->id, PharmacyFinancialSourceEvent::CHARGE, $quantity, $quantity * $unitAmount, 'HANDOVER_ITEM', $handoverItem->public_id]),
            'occurred_at' => now()->addSeconds($sequence), 'created_at' => now()->addSeconds($sequence),
        ]);

        return [$handover, $handoverItem, $source];
    }

    /** @param array<string,mixed> $fixture */
    private function appendReversal(array $fixture, int $quantity): void
    {
        PharmacyMutationScope::run(function () use ($fixture, $quantity): void {
            $return = PharmacyReturn::query()->create([
                'handover_id' => $fixture['handover']->id, 'pharmacist_user_id' => $fixture['pharmacist']->id,
                'reason_code' => 'PATIENT_RETURN', 'handover_fingerprint' => str_repeat('c', 64),
                'content_digest' => str_repeat('c', 64), 'returned_at' => now()->addMinute(), 'created_at' => now()->addMinute(),
            ]);
            $returnItem = PharmacyReturnItem::query()->create([
                'return_id' => $return->id, 'handover_item_id' => $fixture['handoverItem']->id,
                'condition' => PharmacyReturnItem::RETURN_TO_STOCK, 'quantity' => $quantity,
                'content_digest' => str_repeat('d', 64), 'created_at' => now()->addMinute(),
            ]);
            PharmacyFinancialSourceEvent::query()->create([
                'prescription_id' => $fixture['prescription']->id, 'prescription_item_id' => $fixture['item']->id,
                'actor_user_id' => $fixture['pharmacist']->id, 'event_type' => PharmacyFinancialSourceEvent::REVERSAL,
                'quantity' => $quantity, 'amount' => -($quantity * $fixture['item']->sale_value_snapshot),
                'source_type' => 'RETURN_ITEM', 'source_public_id' => $returnItem->public_id,
                'content_digest' => PharmacyCanonicalJson::digest([$fixture['prescription']->public_id, $fixture['item']->public_id, $fixture['pharmacist']->id, PharmacyFinancialSourceEvent::REVERSAL, $quantity, -($quantity * $fixture['item']->sale_value_snapshot), 'RETURN_ITEM', $returnItem->public_id]),
                'occurred_at' => now()->addMinute(), 'created_at' => now()->addMinute(),
            ]);
        });
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create();
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $actor->fresh();
    }

    private function assertDenied(string $reason, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected finance denial {$reason}.");
        } catch (FinanceDenied $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
