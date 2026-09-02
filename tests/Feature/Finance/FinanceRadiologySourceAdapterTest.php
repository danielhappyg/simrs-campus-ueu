<?php

namespace Tests\Feature\Finance;

use App\Models\Encounter;
use App\Models\FinanceBill;
use App\Models\FinanceBillVersion;
use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceRadiologySourceEvent;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItem;
use App\Models\Patient;
use App\Models\RadiologyExaminationMaster;
use App\Models\RadiologyExaminationMasterVersion;
use App\Models\RadiologyOrder;
use App\Models\RadiologyPerformance;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceBillService;
use App\Support\Finance\FinanceDenied;
use App\Support\Finance\FinanceProjection;
use App\Support\Finance\FinanceRadiologyTariffBindingService;
use App\Support\Finance\FinanceSourceCoordinator;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Radiology\RadiologyMasterService;
use App\Support\Radiology\RadiologyMutationScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class FinanceRadiologySourceAdapterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $physician;

    private User $technologist;

    private User $cashier;

    private User $steward;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-02 10:00:00');
        $this->seed(RbacSeeder::class);
        $this->admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $this->physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $this->technologist = $this->actor(RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST);
        $this->cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $this->steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);

        $this->app->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            /** @param array<string, mixed> $metadata */
            public function record(
                string $action,
                string $resourceType,
                ?string $resourceId = null,
                ?User $actor = null,
                string $outcome = 'SUCCESS',
                ?string $reason = null,
                array $metadata = [],
                ?Request $request = null,
                bool $includeRequestFingerprint = true,
            ): AuditEvent {
                return new AuditEvent;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_one_performance_materializes_one_typed_source_and_one_radiology_only_bill_version(): void
    {
        [$masterVersion, $order, $performance, $encounter] = $this->performedOrder('RAD-TORAKS');
        $tariff = $this->tariff('TORAKS', 125000);
        $this->bind($masterVersion, $tariff, 'bind-toraks-0001');

        $coordinator = app(FinanceSourceCoordinator::class);
        $before = $coordinator->readiness($encounter);
        $this->assertSame('SIAP_DISINKRONKAN', $before['items'][0]['state']);
        $this->assertSame(125000, $before['items'][0]['preview_signed_amount']);
        $this->assertSame($performance->performed_at->toIso8601String(), $before['items'][0]['service_at']);
        $candidate = collect(app(FinanceProjection::class)->worklist($this->cashier)['synchronization_candidates'])->sole();
        $this->assertSame(1, $candidate['source_event_count']);
        $this->assertSame(125000, $candidate['gross_amount']);
        $this->assertSame(125000, $candidate['net_amount']);
        $this->assertSame($performance->performed_at->toIso8601String(), $candidate['latest_source_at']);
        $this->assertDatabaseCount('finance_radiology_source_events', 0);
        $this->assertDatabaseCount('finance_charge_events', 0);

        $service = app(FinanceBillService::class);
        $bill = $service->synchronize($encounter->public_id, $this->cashier, 'radiology-source-sync-0001')->record;
        $this->assertInstanceOf(FinanceBill::class, $bill);
        $this->assertDatabaseHas('finance_radiology_source_events', [
            'radiology_performance_id' => $performance->id,
            'radiology_order_id' => $order->id,
            'unit_amount' => 125000,
            'signed_amount' => 125000,
        ]);
        $source = FinanceRadiologySourceEvent::query()->sole();
        $this->assertDatabaseHas('finance_charge_events', [
            'finance_radiology_source_event_id' => $source->id,
            'pharmacy_financial_source_event_id' => null,
            'source_domain' => 'RADIOLOGY',
            'signed_amount' => 125000,
        ]);
        $this->assertSame('TERSINKRONISASI', $coordinator->readiness($encounter)['items'][0]['state']);

        $service->synchronize($encounter->public_id, $this->cashier, 'radiology-source-sync-0002');
        $this->assertDatabaseCount('finance_radiology_source_events', 1);
        $this->assertDatabaseCount('finance_charge_events', 1);

        $version = $service->issue(
            $bill->public_id,
            $this->cashier,
            app(FinanceProjection::class)->fingerprint($bill->fresh()),
            'Penerbitan pemeriksaan radiologi selesai.',
            'radiology-source-issue-0001',
        )->record;
        $this->assertInstanceOf(FinanceBillVersion::class, $version);
        $this->assertSame(FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_V1, $version->coverage_profile);
        $this->assertSame(125000, $version->net_amount);
        $this->assertSame('RADIOLOGY', $version->lines->sole()->source_domain);
    }

    public function test_valid_source_synchronizes_but_unmapped_performance_blocks_issue(): void
    {
        [$mappedMaster, , , $encounter] = $this->performedOrder('RAD-MAPPED');
        $this->performedOrder('RAD-UNMAPPED', $encounter);
        $tariff = $this->tariff('MAPPED', 90000);
        $this->bind($mappedMaster, $tariff, 'bind-mapped-0001');

        $bill = app(FinanceBillService::class)->synchronize(
            $encounter->public_id,
            $this->cashier,
            'radiology-partial-sync-0001',
        )->record;
        $this->assertInstanceOf(FinanceBill::class, $bill);
        $this->assertDatabaseCount('finance_radiology_source_events', 1);
        $readiness = app(FinanceSourceCoordinator::class)->readiness($encounter);
        $this->assertSame(1, $readiness['resolved_count']);
        $this->assertSame(1, $readiness['unresolved_count']);
        $this->assertTrue($readiness['issue_blocked']);
        $this->assertContains('TARIF_BELUM_DIPETAKAN', array_column($readiness['items'], 'state'));

        try {
            app(FinanceBillService::class)->issue(
                $bill->public_id,
                $this->cashier,
                app(FinanceProjection::class)->fingerprint($bill->fresh()),
                'Tidak boleh terbit sebelum semua sumber siap.',
                'radiology-partial-issue-0001',
            );
            $this->fail('An unresolved performed radiology source must block issuance.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('unresolved_radiology_source', $denied->reason);
        }
        $this->assertDatabaseCount('finance_bill_versions', 0);
        $this->assertDatabaseCount('finance_radiology_source_events', 1);
    }

    /** @return array{RadiologyExaminationMasterVersion,RadiologyOrder,RadiologyPerformance,Encounter} */
    private function performedOrder(string $code, ?Encounter $encounter = null): array
    {
        $master = app(RadiologyMasterService::class)->create(
            $this->admin,
            $code,
            'Pemeriksaan '.$code,
            null,
            'master-'.str()->lower(str()->random(16)),
        )->record;
        $this->assertInstanceOf(RadiologyExaminationMaster::class, $master);
        $masterVersion = RadiologyExaminationMasterVersion::query()
            ->where('radiology_examination_master_id', $master->id)->sole();
        $encounter ??= Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['is_synthetic' => true])->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Poliklinik Penyakit Dalam',
        ]);

        [$order, $performance] = RadiologyMutationScope::run(function () use ($encounter, $master, $masterVersion): array {
            $order = RadiologyOrder::query()->create([
                'encounter_id' => $encounter->id,
                'master_id' => $master->id,
                'ordered_by_user_id' => $this->physician->id,
                'master_version' => $masterVersion->version,
                'master_version_public_id' => $masterVersion->public_id,
                'master_content_digest' => $masterVersion->content_digest,
                'master_code' => $master->examination_code,
                'master_display_name' => $masterVersion->display_name,
                'master_preparation_instruction' => $masterVersion->preparation_instruction,
                'care_setting' => $encounter->care_setting,
                'encounter_status_snapshot' => $encounter->status,
                'encounter_number_snapshot' => $encounter->public_id,
                'care_location_label_snapshot' => $encounter->clinic_name,
                'clinical_indication' => 'Indikasi klinis pengujian.',
                'status' => RadiologyOrder::PERFORMED,
                'version' => 2,
                'ordered_at' => now()->subHour(),
            ]);
            $performance = RadiologyPerformance::query()->create([
                'radiology_order_id' => $order->id,
                'performed_by_user_id' => $this->technologist->id,
                'performed_at' => now(),
                'created_at' => now(),
            ]);

            return [$order, $performance];
        });

        return [$masterVersion, $order, $performance, $encounter->fresh('patient')];
    }

    private function tariff(string $suffix, int $amount): FinanceTariffItem
    {
        $service = app(FinanceTariffMasterService::class);
        $key = str()->lower(str()->random(12));
        $group = $service->createGroup(
            $this->steward, 'G-'.$suffix, 'Kelompok '.$suffix, 'Pembuatan kelompok', 'group-'.$key,
        )->record;
        $this->assertInstanceOf(FinanceCostComponentGroup::class, $group);
        $component = $service->createComponent(
            $this->steward, $group->public_id, 'C-'.$suffix, 'Komponen '.$suffix,
            null, null, 'Pembuatan komponen', 'component-'.$key,
        )->record;
        $this->assertInstanceOf(FinanceCostComponent::class, $component);
        $catalogue = $service->createCatalogue(
            $this->steward, 'K-'.$suffix, 'Katalog '.$suffix, 'Pembuatan katalog', 'catalogue-'.$key,
        )->record;
        $this->assertInstanceOf(FinanceTariffCatalogue::class, $catalogue);
        $tariff = $service->createTariffItem(
            $this->steward, $catalogue->public_id, $component->public_id, 'T-'.$suffix,
            'Tarif '.$suffix, 'OUTPATIENT', 'RADIOLOGY', null, null, $amount,
            '2026-09-02', 'Pembuatan tarif', 'tariff-'.$key,
        )->record;
        $this->assertInstanceOf(FinanceTariffItem::class, $tariff);

        return $tariff->fresh();
    }

    private function bind(RadiologyExaminationMasterVersion $master, FinanceTariffItem $tariff, string $key): void
    {
        app(FinanceRadiologyTariffBindingService::class)->create(
            $this->steward,
            $master->public_id,
            'OUTPATIENT',
            $tariff->public_id,
            '2026-09-02',
            'Pemetaan tarif pemeriksaan.',
            $key,
        );
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
