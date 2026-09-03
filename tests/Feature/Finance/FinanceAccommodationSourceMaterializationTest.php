<?php

namespace Tests\Feature\Finance;

use App\Models\Encounter;
use App\Models\FinanceAccommodationSourceEvent;
use App\Models\FinanceBill;
use App\Models\FinanceBillVersion;
use App\Models\FinanceChargeEvent;
use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItem;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceAccommodationSourceAdapter;
use App\Support\Finance\FinanceAccommodationTariffAppendOnlyGuard;
use App\Support\Finance\FinanceAccommodationTariffBindingService;
use App\Support\Finance\FinanceBillService;
use App\Support\Finance\FinanceDenied;
use App\Support\Finance\FinanceProjection;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Finance\FinanceTariffSchemaMutationScope;
use App\Support\Inpatient\InpatientBedTransferService;
use App\Support\Inpatient\InpatientDischargeCodingSourceService;
use App\Support\Inpatient\InpatientDischargeService;
use App\Support\Inpatient\InpatientDischargeSummaryService;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Inpatient\InpatientMasterService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class FinanceAccommodationSourceMaterializationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $registrar;

    private User $physician;

    private User $steward;

    private User $cashier;

    private InpatientWard $ward;

    private InpatientBed $sourceBed;

    private InpatientBed $targetBed;

    private InpatientBedVersion $sourceVersion;

    private FinanceTariffItem $tariff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-02 10:00:00');
        $this->seed(RbacSeeder::class);
        $this->admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $this->registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $this->physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $this->steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $this->cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        [$this->ward, $this->sourceBed, $this->targetBed] = $this->beds();
        $this->sourceVersion = InpatientBedVersion::query()->where('bed_id', $this->sourceBed->id)->where('version', 1)->sole();
        $this->tariff = $this->tariff();
        app(FinanceAccommodationTariffBindingService::class)->create(
            $this->steward, $this->sourceVersion->public_id, 'INPATIENT', $this->tariff->public_id,
            '2026-09-02', 'Pemetaan okupansi', 'accommodation-materialize-bind-0001',
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_open_final_interval_materializes_closed_days_once_with_historical_tariffs_and_tamper_refusal(): void
    {
        $encounter = $this->admission();
        Carbon::setTestNow('2026-09-04 08:00:00');
        app(InpatientBedTransferService::class)->transfer(
            $encounter->public_id, $this->registrar, 1, $this->sourceBed->public_id,
            $this->targetBed->public_id, 'Pindah ruang', 'accommodation-materialize-transfer-0001',
        );

        $adapter = app(FinanceAccommodationSourceAdapter::class);
        $ready = collect($adapter->readiness($encounter->fresh()))
            ->where('state', 'SIAP_DISINKRONKAN')->values();
        $this->assertSame([10000, 12000, 12000], $ready->pluck('preview_signed_amount')->all());
        $candidate = collect(app(FinanceProjection::class)->worklist($this->cashier)['synchronization_candidates'])->sole();
        $this->assertSame(3, $candidate['source_event_count']);
        $this->assertSame(34000, $candidate['gross_amount']);
        $this->assertSame(34000, $candidate['net_amount']);
        $this->assertSame($ready->max('service_at'), $candidate['latest_source_at']);
        $this->assertDatabaseCount('finance_accommodation_source_events', 0);
        $this->assertDatabaseCount('finance_charge_events', 0);

        $events = $adapter->synchronize($encounter->fresh('patient'), $this->cashier);
        $this->assertCount(3, $events);
        $this->assertSame(['2026-09-02', '2026-09-03', '2026-09-04'], FinanceAccommodationSourceEvent::query()->orderBy('service_date')->get()->map(fn ($source) => $source->service_date->format('Y-m-d'))->all());
        $this->assertSame([10000, 12000, 12000], FinanceAccommodationSourceEvent::query()->orderBy('service_date')->pluck('unit_amount')->all());
        $this->assertSame(3, FinanceAccommodationSourceEvent::query()->pluck('service_date')->unique()->count());
        $this->assertSame(3, FinanceChargeEvent::query()->where('source_domain', FinanceChargeEvent::SOURCE_ACCOMMODATION)->count());
        $this->assertContains('INTERVAL_MASIH_TERBUKA', array_column($adapter->readiness($encounter->fresh()), 'state'));

        $replayed = $adapter->synchronize($encounter->fresh('patient'), $this->cashier);
        $this->assertCount(3, $replayed);
        $this->assertDatabaseCount('finance_accommodation_source_events', 3);
        $this->assertDatabaseCount('finance_charge_events', 3);
        $adapter->verifyRetained($encounter->fresh(), $replayed);

        $bill = app(FinanceBillService::class)->synchronize(
            $encounter->public_id, $this->cashier, 'accommodation-open-bill-sync-0001',
        )->record;
        $this->assertInstanceOf(FinanceBill::class, $bill);
        try {
            app(FinanceBillService::class)->issue(
                $bill->public_id, $this->cashier, app(FinanceProjection::class)->fingerprint($bill->fresh()),
                'Tidak boleh terbit selama interval terbuka.', 'accommodation-open-bill-issue-0001',
            );
            $this->fail('An open accommodation interval must block bill issuance.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('unresolved_accommodation_source', $denied->reason);
        }

        $first = FinanceAccommodationSourceEvent::query()->orderBy('service_date')->firstOrFail();
        FinanceTariffSchemaMutationScope::run(fn () => FinanceAccommodationTariffAppendOnlyGuard::runSyntheticReset(
            fn () => DB::table('finance_accommodation_source_events')->where('id', $first->id)->update([
                'unit_amount' => 99999,
                'signed_amount' => 99999,
            ]),
        ));
        try {
            $adapter->verifyRetained($encounter->fresh(), $replayed);
            $this->fail('Tampered accommodation source must fail retained verification.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('source_integrity_failure', $denied->reason);
        }
    }

    public function test_governed_routine_discharge_closes_and_bill_sync_materializes_complete_daily_sources(): void
    {
        $encounter = $this->admission();
        $summaries = app(InpatientDischargeSummaryService::class);
        $draft = $summaries->saveDraft(
            $encounter->public_id, $this->physician, InpatientDischargeSummary::DEFINITION_VERSION,
            0, $this->completeFields(), 'accommodation-summary-draft-0001',
        );
        $final = $summaries->finalize(
            $encounter->public_id, $this->physician, InpatientDischargeSummary::DEFINITION_VERSION,
            $draft->summary->version, 'accommodation-summary-final-0001',
        );
        $coding = app(InpatientDischargeCodingSourceService::class);
        $codingDraft = $coding->saveDraft(
            $encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, 0,
            ['principal_diagnosis_statement' => 'Observasi sintetis', 'secondary_diagnosis_statements' => [], 'procedure_attestation' => InpatientDischargeCodingSource::ATTESTATION_NONE, 'performed_procedure_statements' => []],
            'accommodation-coding-draft-0001',
        );
        $coding->finalize(
            $encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION,
            $codingDraft->source->version, 'accommodation-coding-final-0001',
        );
        Carbon::setTestNow('2026-09-04 08:00:00');
        app(InpatientDischargeService::class)->execute(
            $encounter->public_id, $this->physician, $final->summary->version, 1,
            $this->sourceBed->public_id, 'accommodation-discharge-0001',
        );

        $bill = app(FinanceBillService::class)->synchronize(
            $encounter->public_id, $this->cashier, 'accommodation-bill-sync-0001',
        )->record;
        $this->assertInstanceOf(FinanceBill::class, $bill);
        $this->assertDatabaseCount('finance_accommodation_source_events', 3);
        $this->assertSame(34000, (int) FinanceAccommodationSourceEvent::query()->sum('signed_amount'));
        $this->assertSame(3, FinanceChargeEvent::query()->whereNotNull('finance_accommodation_source_event_id')->count());
        $this->assertNotContains('INTERVAL_MASIH_TERBUKA', array_column(app(FinanceAccommodationSourceAdapter::class)->readiness($encounter->fresh()), 'state'));
        $version = app(FinanceBillService::class)->issue(
            $bill->public_id, $this->cashier, app(FinanceProjection::class)->fingerprint($bill->fresh()),
            'Penerbitan tagihan akomodasi lengkap.', 'accommodation-bill-issue-0001',
        )->record;
        $this->assertInstanceOf(FinanceBillVersion::class, $version);
        $this->assertSame(34000, $version->net_amount);
        $this->assertSame(FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1, $version->coverage_profile);
        $this->assertSame(3, $version->lines()->where('source_domain', FinanceChargeEvent::SOURCE_ACCOMMODATION)->count());
    }

    private function admission(): Encounter
    {
        $patient = Patient::factory()->create(['created_by_user_id' => $this->registrar->id, 'is_synthetic' => true]);
        $encounter = InpatientLocationMutationScope::run(fn (): Encounter => Encounter::factory()->create([
            'patient_id' => $patient->id, 'registered_by_user_id' => $this->registrar->id,
            'care_setting' => Encounter::CARE_SETTING_INPATIENT, 'status' => Encounter::STATUS_REGISTERED,
            'registered_at' => now(), 'inpatient_bed_id' => $this->sourceBed->id,
            'ward_name' => $this->ward->display_name, 'ward_class' => $this->sourceVersion->service_class,
            'bed_code' => $this->sourceBed->code,
        ]));
        DB::transaction(fn () => app(InpatientBedTransferService::class)->recordAdmission(
            $encounter, $this->ward, $this->sourceBed, $this->registrar, null,
        ));

        return $encounter;
    }

    private function tariff(): FinanceTariffItem
    {
        $service = app(FinanceTariffMasterService::class);
        $group = $service->createGroup($this->steward, 'G-AKO-M', 'Akomodasi', 'Penyiapan', 'accommodation-materialize-group-0001')->record;
        if (! $group instanceof FinanceCostComponentGroup) {
            throw new LogicException('Expected group.');
        }
        $component = $service->createComponent($this->steward, $group->public_id, 'C-AKO-M', 'Komponen akomodasi', null, null, 'Penyiapan', 'accommodation-materialize-component-0001')->record;
        if (! $component instanceof FinanceCostComponent) {
            throw new LogicException('Expected component.');
        }
        $catalogue = $service->createCatalogue($this->steward, 'K-AKO-M', 'Katalog akomodasi', 'Penyiapan', 'accommodation-materialize-catalogue-0001')->record;
        if (! $catalogue instanceof FinanceTariffCatalogue) {
            throw new LogicException('Expected catalogue.');
        }
        $tariff = $service->createTariffItem(
            $this->steward, $catalogue->public_id, $component->public_id, 'T-AKO-M', 'Akomodasi okupansi',
            'INPATIENT', 'ACCOMMODATION', null, null, 10000, '2026-09-02', 'Penyiapan', 'accommodation-materialize-tariff-0001',
        )->record;
        if (! $tariff instanceof FinanceTariffItem) {
            throw new LogicException('Expected tariff.');
        }
        $service->appendTariffItemVersion(
            $this->steward, $tariff->public_id, 'Akomodasi okupansi', 'INPATIENT', 'ACCOMMODATION',
            null, null, 12000, '2026-09-03', 1, $tariff->current_content_digest,
            'Penyesuaian', 'accommodation-materialize-tariff-version-0001',
        );

        return $tariff->fresh();
    }

    /** @return array{InpatientWard,InpatientBed,InpatientBed} */
    private function beds(): array
    {
        $service = app(InpatientMasterService::class);
        $ward = $service->createWard($this->admin, 'AKO-MAT', 'Bangsal Materialisasi', InpatientMasterService::REASON_INITIAL_SETUP, 'accommodation-materialize-ward-0001', null)->master;
        if (! $ward instanceof InpatientWard) {
            throw new LogicException('Expected ward.');
        }
        $source = $service->createBed($this->admin, $ward->public_id, 'AKO-M01', 'Bed M01', 'Ruang M', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'accommodation-materialize-bed-0001', null)->master;
        $target = $service->createBed($this->admin, $ward->public_id, 'AKO-M02', 'Bed M02', 'Ruang M', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'accommodation-materialize-bed-0002', null)->master;
        if (! $source instanceof InpatientBed || ! $target instanceof InpatientBed) {
            throw new LogicException('Expected beds.');
        }

        return [$ward, $source, $target];
    }

    /** @return array<string, string> */
    private function completeFields(): array
    {
        return ['admission_reason' => 'Observasi', 'significant_findings' => 'Stabil', 'care_and_treatment_summary' => 'Pemantauan', 'condition_at_discharge' => 'Baik', 'follow_up_plan' => 'Kontrol'];
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
