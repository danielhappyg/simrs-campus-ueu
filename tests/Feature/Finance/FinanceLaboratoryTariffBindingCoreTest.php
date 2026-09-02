<?php

namespace Tests\Feature\Finance;

use App\Models\Encounter;
use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceLaboratoryTariffBinding;
use App\Models\FinanceLaboratoryTariffBindingVersion;
use App\Models\FinanceLaboratoryTariffOperationReceipt;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItem;
use App\Models\LaboratoryExaminationMaster;
use App\Models\LaboratoryExaminationMasterVersion;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceDenied;
use App\Support\Finance\FinanceLaboratoryTariffActorPolicy;
use App\Support\Finance\FinanceLaboratoryTariffBindingService;
use App\Support\Finance\FinanceLaboratoryTariffProjection;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Laboratory\LaboratoryMasterService;
use App\Support\Laboratory\LaboratoryMutationScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FinanceLaboratoryTariffBindingCoreTest extends TestCase
{
    use RefreshDatabase;

    private User $steward;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-02 10:00:00');
        $this->seed(RbacSeeder::class);
        $this->steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $this->app->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            /** @param array<string, mixed> $metadata */
            public function record(string $action, string $resourceType, ?string $resourceId = null, ?User $actor = null, string $outcome = 'SUCCESS', ?string $reason = null, array $metadata = [], ?Request $request = null, bool $includeRequestFingerprint = true): AuditEvent
            {
                return new AuditEvent;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_migration_creates_verified_result_typed_schema_and_capabilities(): void
    {
        foreach ([
            'finance_laboratory_tariff_bindings',
            'finance_laboratory_tariff_binding_versions',
            'finance_laboratory_tariff_operation_receipts',
            'finance_laboratory_source_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasColumns('finance_laboratory_source_events', [
            'laboratory_result_version_id', 'laboratory_order_id', 'laboratory_specimen_attempt_id',
            'laboratory_critical_communication_id', 'verified_at', 'completion_evidence_digest',
        ]));
        $this->assertTrue(Schema::hasColumn('finance_charge_events', 'finance_laboratory_source_event_id'));
        $this->assertFalse(Schema::hasTable('laboratory_performances'));
        $this->assertContains('finance.laboratory-tariff.view', Capability::all());
        $this->assertContains('finance.laboratory-tariff.manage', Capability::all());

        $columns = collect(DB::select('PRAGMA table_info(finance_charge_events)'))->keyBy('name');
        $this->assertSame(0, (int) $columns['finance_laboratory_source_event_id']->notnull);
    }

    public function test_binding_replay_half_open_versions_and_terminal_retirement(): void
    {
        [$masterVersion, $tariff] = $this->upstreams();
        $service = app(FinanceLaboratoryTariffBindingService::class);
        $created = $service->create(
            $this->steward, $masterVersion->public_id, 'outpatient', $tariff->public_id,
            '2026-09-02', 'Pemetaan awal', 'lab-tariff-create-0001',
        )->record;
        $replay = $service->create(
            $this->steward, $masterVersion->public_id, 'outpatient', $tariff->public_id,
            '2026-09-02', 'Pemetaan awal', 'lab-tariff-create-0001',
        );
        $this->assertTrue($replay->replayed);

        $second = $service->appendVersion(
            $this->steward, $created->public_id, $tariff->public_id, '2026-09-03',
            1, $created->current_content_digest, 'Versi lanjutan', 'lab-tariff-append-0001',
        )->record;
        $retired = $service->retire(
            $this->steward, $created->public_id, '2026-09-04', 2,
            $second->current_content_digest, 'Pemetaan dihentikan', 'lab-tariff-retire-0001',
        )->record;

        $this->assertSame(FinanceLaboratoryTariffBinding::RETIRED, $retired->state);
        $this->assertSame(3, FinanceLaboratoryTariffBindingVersion::query()->count());
        $this->assertSame(3, FinanceLaboratoryTariffOperationReceipt::query()->count());
        $projection = app(FinanceLaboratoryTariffProjection::class);
        $this->assertSame('ACTIVE', $projection->overview($this->steward, '2026-09-03')[0]['state']);
        $this->assertSame('RETIRED', $projection->overview($this->steward, '2026-09-04')[0]['state']);
    }

    public function test_resolver_uses_exact_master_snapshot_and_verified_service_date_without_writing_source(): void
    {
        [$masterVersion, $tariff] = $this->upstreams(true);
        $binding = app(FinanceLaboratoryTariffBindingService::class)->create(
            $this->steward, $masterVersion->public_id, 'OUTPATIENT', $tariff->public_id,
            '2026-09-02', 'Pemetaan awal', 'lab-resolve-create-0001',
        )->record;
        $order = $this->order($masterVersion);
        $projection = app(FinanceLaboratoryTariffProjection::class);

        $first = $projection->resolve($order, Carbon::parse('2026-09-02 23:00:00', 'Asia/Jakarta'));
        $future = $projection->resolve($order, Carbon::parse('2026-09-03 08:00:00', 'Asia/Jakarta'));

        $this->assertSame($binding->public_id, $first->binding->public_id);
        $this->assertSame(10000, $first->amountRupiah);
        $this->assertSame(12000, $future->amountRupiah);
        $this->assertSame('2026-09-02', $first->serviceDate);
        $this->assertDatabaseCount('finance_laboratory_source_events', 0);
    }

    public function test_resolver_classifies_missing_and_future_bindings_closed(): void
    {
        [$masterVersion, $tariff] = $this->upstreams();
        $order = $this->order($masterVersion);
        $projection = app(FinanceLaboratoryTariffProjection::class);
        try {
            $projection->resolve($order, now());
            $this->fail('Missing mapping must remain unresolved.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('unresolved_laboratory_source', $denied->reason);
        }

        app(FinanceLaboratoryTariffBindingService::class)->create(
            $this->steward, $masterVersion->public_id, 'OUTPATIENT', $tariff->public_id,
            '2026-09-03', 'Pemetaan mendatang', 'lab-resolve-future-0001',
        );
        try {
            $projection->resolve($order, now());
            $this->fail('Future binding must not resolve.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('tariff_not_effective', $denied->reason);
        }
    }

    public function test_exact_roles_are_non_bypass_and_cashier_is_view_only(): void
    {
        $policy = app(FinanceLaboratoryTariffActorPolicy::class);
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $technologist = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST);
        $this->assertTrue($policy->canView($cashier));
        $this->assertFalse($policy->canManage($cashier));
        $this->assertTrue($policy->canManage($this->steward));
        $this->assertFalse($policy->canView($admin));
        $this->assertFalse($policy->canView($technologist));

        $this->steward->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_CASHIER)->sole()->id);
        $this->assertFalse($policy->canManage($this->steward->fresh()));
        $admin->forceFill(['is_system_administrator' => true])->save();
        $this->assertFalse($policy->canView($admin->fresh()));
    }

    /** @return array{LaboratoryExaminationMasterVersion,FinanceTariffItem} */
    private function upstreams(bool $futureTariff = false): array
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $master = app(LaboratoryMasterService::class)->create(
            $admin, 'LAB-'.str()->upper(str()->random(8)), 'Darah lengkap tarif', 'Darah EDTA',
            'Koleksi sesuai prosedur.', $this->components(), 'lab-master-'.str()->lower(str()->random(12)),
        )->record;
        $this->assertInstanceOf(LaboratoryExaminationMaster::class, $master);
        $masterVersion = LaboratoryExaminationMasterVersion::query()
            ->where('laboratory_examination_master_id', $master->id)->sole();

        $service = app(FinanceTariffMasterService::class);
        $suffix = str()->upper(str()->random(6));
        $group = $service->createGroup($this->steward, 'G'.$suffix, 'Laboratorium', 'Penyiapan awal', 'lab-group-'.str()->lower(str()->random(12)))->record;
        $this->assertInstanceOf(FinanceCostComponentGroup::class, $group);
        $component = $service->createComponent($this->steward, $group->public_id, 'C'.$suffix, 'Komponen laboratorium', null, null, 'Penyiapan awal', 'lab-component-'.str()->lower(str()->random(12)))->record;
        $this->assertInstanceOf(FinanceCostComponent::class, $component);
        $catalogue = $service->createCatalogue($this->steward, 'K'.$suffix, 'Katalog laboratorium', 'Penyiapan awal', 'lab-catalogue-'.str()->lower(str()->random(12)))->record;
        $this->assertInstanceOf(FinanceTariffCatalogue::class, $catalogue);
        $tariff = $service->createTariffItem(
            $this->steward, $catalogue->public_id, $component->public_id, 'T'.$suffix,
            'Pemeriksaan laboratorium', 'OUTPATIENT', 'LABORATORY', null, null, 10000,
            '2026-09-02', 'Penyiapan awal', 'lab-item-'.str()->lower(str()->random(12)),
        )->record;
        $this->assertInstanceOf(FinanceTariffItem::class, $tariff);
        if ($futureTariff) {
            $service->appendTariffItemVersion(
                $this->steward, $tariff->public_id, 'Pemeriksaan laboratorium', 'OUTPATIENT',
                'LABORATORY', null, null, 12000, '2026-09-03', 1,
                $tariff->current_content_digest, 'Penyesuaian tarif', 'lab-item-next-'.str()->lower(str()->random(12)),
            );
        }

        return [$masterVersion, $tariff->fresh()];
    }

    private function order(LaboratoryExaminationMasterVersion $version): LaboratoryOrder
    {
        $master = LaboratoryExaminationMaster::query()->findOrFail($version->laboratory_examination_master_id);
        $encounter = Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['is_synthetic' => true])->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
        ]);

        return LaboratoryMutationScope::run(fn () => LaboratoryOrder::query()->create([
            'encounter_id' => $encounter->id,
            'master_id' => $master->id,
            'ordered_by_user_id' => $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN)->id,
            'master_version' => $version->version,
            'master_version_public_id' => $version->public_id,
            'master_content_digest' => $version->content_digest,
            'master_code' => $master->examination_code,
            'master_display_name' => $version->display_name,
            'specimen_type_snapshot' => $version->specimen_type,
            'collection_instruction_snapshot' => $version->collection_instruction,
            'components_snapshot' => $version->components,
            'care_setting' => $encounter->care_setting,
            'encounter_status_snapshot' => $encounter->status,
            'encounter_number_snapshot' => $encounter->public_id,
            'care_location_label_snapshot' => 'Poliklinik uji',
            'priority' => 'ROUTINE',
            'clinical_question' => 'Pemeriksaan laboratorium uji.',
            'status' => LaboratoryOrder::REPORTED_VERIFIED,
            'version' => 3,
            'ordered_at' => now(),
        ]));
    }

    /** @return list<array<string, mixed>> */
    private function components(): array
    {
        return [[
            'code' => 'HGB', 'display_name' => 'Hemoglobin', 'value_kind' => 'NUMERIC',
            'unit_text' => 'g/dL', 'reference_text' => 'Rujukan unit.', 'critical_allowed' => true,
        ]];
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
