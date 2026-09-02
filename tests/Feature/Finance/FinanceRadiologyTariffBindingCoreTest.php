<?php

namespace Tests\Feature\Finance;

use App\Models\Encounter;
use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceRadiologyTariffBinding;
use App\Models\FinanceRadiologyTariffBindingVersion;
use App\Models\FinanceRadiologyTariffOperationReceipt;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItem;
use App\Models\Patient;
use App\Models\RadiologyExaminationMaster;
use App\Models\RadiologyExaminationMasterVersion;
use App\Models\RadiologyOrder;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceDenied;
use App\Support\Finance\FinanceRadiologyTariffActorPolicy;
use App\Support\Finance\FinanceRadiologyTariffBindingService;
use App\Support\Finance\FinanceRadiologyTariffProjection;
use App\Support\Finance\FinanceTariffDenied;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Radiology\RadiologyMasterService;
use App\Support\Radiology\RadiologyMutationScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FinanceRadiologyTariffBindingCoreTest extends TestCase
{
    use RefreshDatabase;

    private User $steward;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-02 10:00:00');
        $this->seed(RbacSeeder::class);
        $this->steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_migration_creates_typed_binding_and_source_schema(): void
    {
        foreach ([
            'finance_radiology_tariff_bindings',
            'finance_radiology_tariff_binding_versions',
            'finance_radiology_tariff_operation_receipts',
            'finance_radiology_source_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasColumns('finance_charge_events', [
            'pharmacy_financial_source_event_id', 'finance_radiology_source_event_id',
        ]));
        $columns = collect(DB::select('PRAGMA table_info(finance_charge_events)'))->keyBy('name');
        $this->assertSame(0, (int) $columns['pharmacy_financial_source_event_id']->notnull);
        $this->assertSame(0, (int) $columns['finance_radiology_source_event_id']->notnull);
        $this->assertContains('finance.radiology-tariff.view', Capability::all());
        $this->assertContains('finance.radiology-tariff.manage', Capability::all());
    }

    public function test_binding_versions_replay_and_terminal_retirement_preserve_half_open_history(): void
    {
        [$masterVersion, $tariff] = $this->upstreams();
        $service = $this->bindingService();
        $created = $service->create(
            $this->steward, $masterVersion->public_id, 'outpatient', $tariff->public_id,
            '2026-09-02', 'Pemetaan awal', 'radio-binding-create-0001',
        )->record;

        $this->assertSame(1, $created->version);
        $this->assertSame('OUTPATIENT', $created->care_setting);
        $this->assertDatabaseCount('finance_radiology_tariff_binding_versions', 1);
        $replay = $service->create(
            $this->steward, $masterVersion->public_id, 'outpatient', $tariff->public_id,
            '2026-09-02', 'Pemetaan awal', 'radio-binding-create-0001',
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame(1, $replay->record->version);

        $appended = $service->appendVersion(
            $this->steward, $created->public_id, $tariff->public_id, '2026-09-03',
            1, $created->current_content_digest, 'Konfirmasi lanjutan', 'radio-binding-append-0001',
        )->record;
        $retired = $service->retire(
            $this->steward, $created->public_id, '2026-09-04', 2,
            $appended->current_content_digest, 'Pemetaan dihentikan', 'radio-binding-retire-0001',
        )->record;

        $this->assertSame(FinanceRadiologyTariffBinding::RETIRED, $retired->state);
        $this->assertSame(3, FinanceRadiologyTariffBindingVersion::query()->count());
        $this->assertSame(3, FinanceRadiologyTariffOperationReceipt::query()->count());
        $history = app(FinanceRadiologyTariffProjection::class)->history($this->steward, $created->public_id);
        $this->assertSame([1, 2, 3], array_column($history['versions'], 'version'));
        $this->assertSame(['ACTIVE', 'ACTIVE', 'RETIRED'], array_column($history['versions'], 'state'));
        $this->assertSame('ACTIVE', app(FinanceRadiologyTariffProjection::class)->overview($this->steward, '2026-09-03')[0]['state']);
        $this->assertSame('RETIRED', app(FinanceRadiologyTariffProjection::class)->overview($this->steward, '2026-09-04')[0]['state']);
    }

    public function test_resolver_uses_exact_order_snapshot_and_performed_service_date(): void
    {
        [$masterVersion, $tariff] = $this->upstreams(true);
        $binding = $this->bindingService()->create(
            $this->steward, $masterVersion->public_id, 'OUTPATIENT', $tariff->public_id,
            '2026-09-02', 'Pemetaan awal', 'radio-resolve-create-0001',
        )->record;
        $order = $this->order($masterVersion, 'OUTPATIENT');
        $projection = app(FinanceRadiologyTariffProjection::class);

        $first = $projection->resolve($order, Carbon::parse('2026-09-02 23:00:00', 'Asia/Jakarta'));
        $future = $projection->resolve($order, Carbon::parse('2026-09-03 08:00:00', 'Asia/Jakarta'));

        $this->assertSame($binding->public_id, $first->binding->public_id);
        $this->assertSame(10000, $first->amountRupiah);
        $this->assertSame(12000, $future->amountRupiah);
        $this->assertSame('2026-09-02', $first->serviceDate);
        $this->assertSame($first->tariffVersion->component_content_digest, $first->componentVersion->content_digest);
        $this->assertSame($first->component->group_id, $first->componentGroup->id);
    }

    public function test_resolver_classifies_missing_mapping_without_materializing_source_rows(): void
    {
        [$masterVersion] = $this->upstreams();
        $order = $this->order($masterVersion, 'OUTPATIENT');

        try {
            app(FinanceRadiologyTariffProjection::class)->resolve($order, Carbon::parse('2026-09-02 12:00:00'));
            $this->fail('Missing mapping should remain unresolved.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('unresolved_radiology_source', $denied->reason);
        }
        $this->assertDatabaseCount('finance_radiology_source_events', 0);
    }

    public function test_resolver_distinguishes_future_binding_and_missing_master_relation(): void
    {
        [$masterVersion, $tariff] = $this->upstreams();
        $this->bindingService()->create(
            $this->steward, $masterVersion->public_id, 'OUTPATIENT', $tariff->public_id,
            '2026-09-03', 'Pemetaan mendatang', 'radio-resolve-future-0001',
        );
        $order = $this->order($masterVersion, 'OUTPATIENT');
        $projection = app(FinanceRadiologyTariffProjection::class);

        try {
            $projection->resolve($order, Carbon::parse('2026-09-02 12:00:00'));
            $this->fail('A future binding must not resolve for an earlier service date.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('tariff_not_effective', $denied->reason);
        }

        $order->setRelation('master', null);
        try {
            $projection->resolve($order, Carbon::parse('2026-09-03 12:00:00'));
            $this->fail('A missing order master relation must fail closed.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('source_binding_invalid', $denied->reason);
        }
        $this->assertDatabaseCount('finance_radiology_source_events', 0);
    }

    public function test_exact_roles_are_non_bypass_and_cashier_is_view_only(): void
    {
        $policy = app(FinanceRadiologyTariffActorPolicy::class);
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $technologist = $this->actor(RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST);

        $this->assertTrue($policy->canView($cashier));
        $this->assertFalse($policy->canManage($cashier));
        $this->assertTrue($policy->canView($this->steward));
        $this->assertTrue($policy->canManage($this->steward));
        $this->assertFalse($policy->canView($admin));
        $this->assertFalse($policy->canView($technologist));

        $this->steward->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_CASHIER)->sole()->id);
        $this->assertFalse($policy->canView($this->steward->fresh()));
        $this->assertFalse($policy->canManage($this->steward->fresh()));

        $admin->forceFill(['is_system_administrator' => true])->save();
        $this->assertFalse($policy->canView($admin->fresh()));
    }

    public function test_changed_idempotency_payload_and_retroactive_or_stale_versions_fail_closed(): void
    {
        [$masterVersion, $tariff] = $this->upstreams();
        $service = $this->bindingService();
        $binding = $service->create(
            $this->steward, $masterVersion->public_id, 'OUTPATIENT', $tariff->public_id,
            '2026-09-02', 'Pemetaan awal', 'radio-closed-create-0001',
        )->record;

        try {
            $service->create(
                $this->steward, $masterVersion->public_id, 'EMERGENCY', $tariff->public_id,
                '2026-09-02', 'Pemetaan berbeda', 'radio-closed-create-0001',
            );
            $this->fail('Changed idempotency payload should fail.');
        } catch (FinanceTariffDenied $denied) {
            $this->assertSame('idempotency_key_conflict', $denied->reason);
        }
        try {
            $service->appendVersion(
                $this->steward, $binding->public_id, $tariff->public_id, '2026-09-01',
                1, $binding->current_content_digest, 'Tanggal lampau', 'radio-closed-past-0001',
            );
            $this->fail('Retroactive binding should fail.');
        } catch (FinanceTariffDenied $denied) {
            $this->assertSame('retroactive_effective_date', $denied->reason);
        }
        try {
            $service->appendVersion(
                $this->steward, $binding->public_id, $tariff->public_id, '2026-09-03',
                2, $binding->current_content_digest, 'Versi basi', 'radio-closed-stale-0001',
            );
            $this->fail('Stale expected version should fail.');
        } catch (FinanceTariffDenied $denied) {
            $this->assertSame('stale_version', $denied->reason);
        }
    }

    public function test_service_authorizes_before_binding_lookup(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $this->bindingService();

        $this->expectException(AuthorizationException::class);
        app(FinanceRadiologyTariffBindingService::class)->retire(
            $cashier, '01J99999999999999999999999', '2026-09-03', 1, str_repeat('a', 64),
            'Tidak diizinkan', 'radio-role-denied-0001',
        );
    }

    /** @return array{RadiologyExaminationMasterVersion,FinanceTariffItem} */
    private function upstreams(bool $futureTariff = false): array
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $master = app(RadiologyMasterService::class)->create(
            $admin, 'RAD-'.str()->upper(str()->random(8)), 'Radiologi tarif', null,
            'radio-master-'.str()->lower(str()->random(12)),
        )->record;
        if (! $master instanceof RadiologyExaminationMaster) {
            throw new \LogicException('Radiology master service returned an unexpected model.');
        }
        $masterVersion = RadiologyExaminationMasterVersion::query()
            ->where('radiology_examination_master_id', $master->id)->where('version', 1)->sole();

        $service = app(FinanceTariffMasterService::class);
        $suffix = str()->upper(str()->random(6));
        $group = $service->createGroup($this->steward, 'G'.$suffix, 'Radiologi', 'Penyiapan awal', 'radio-group-'.str()->lower(str()->random(12)))->record;
        if (! $group instanceof FinanceCostComponentGroup) {
            throw new \LogicException('Tariff group service returned an unexpected model.');
        }
        $component = $service->createComponent($this->steward, $group->public_id, 'C'.$suffix, 'Komponen radiologi', null, null, 'Penyiapan awal', 'radio-component-'.str()->lower(str()->random(12)))->record;
        if (! $component instanceof FinanceCostComponent) {
            throw new \LogicException('Tariff component service returned an unexpected model.');
        }
        $catalogue = $service->createCatalogue($this->steward, 'K'.$suffix, 'Katalog radiologi', 'Penyiapan awal', 'radio-catalogue-'.str()->lower(str()->random(12)))->record;
        if (! $catalogue instanceof FinanceTariffCatalogue) {
            throw new \LogicException('Tariff catalogue service returned an unexpected model.');
        }
        $tariff = $service->createTariffItem(
            $this->steward, $catalogue->public_id, $component->public_id, 'T'.$suffix,
            'Pemeriksaan radiologi', 'OUTPATIENT', 'RADIOLOGY', null, null, 10000,
            '2026-09-02', 'Penyiapan awal', 'radio-tariff-'.str()->lower(str()->random(12)),
        )->record;
        if (! $tariff instanceof FinanceTariffItem) {
            throw new \LogicException('Tariff item service returned an unexpected model.');
        }
        if ($futureTariff) {
            $service->appendTariffItemVersion(
                $this->steward, $tariff->public_id, 'Pemeriksaan radiologi', 'OUTPATIENT',
                'RADIOLOGY', null, null, 12000, '2026-09-03', 1,
                $tariff->current_content_digest, 'Penyesuaian tarif', 'radio-tariff-next-'.str()->lower(str()->random(12)),
            );
        }

        $freshTariff = $tariff->fresh();
        if (! $freshTariff instanceof FinanceTariffItem) {
            throw new \LogicException('Tariff item disappeared during fixture creation.');
        }

        return [$masterVersion, $freshTariff];
    }

    private function bindingService(): FinanceRadiologyTariffBindingService
    {
        $audit = new class extends AuditRecorder
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
        };
        $this->app->instance(AuditRecorder::class, $audit);

        return app(FinanceRadiologyTariffBindingService::class);
    }

    private function order(RadiologyExaminationMasterVersion $masterVersion, string $careSetting): RadiologyOrder
    {
        $master = RadiologyExaminationMaster::query()->whereKey($masterVersion->radiology_examination_master_id)->firstOrFail();
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'care_setting' => $careSetting,
            'status' => Encounter::STATUS_IN_EXAMINATION,
        ]);

        return RadiologyMutationScope::run(fn (): RadiologyOrder => RadiologyOrder::query()->create([
            'encounter_id' => $encounter->id,
            'master_id' => $master->id,
            'ordered_by_user_id' => $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN)->id,
            'master_version' => $masterVersion->version,
            'master_version_public_id' => $masterVersion->public_id,
            'master_content_digest' => $masterVersion->content_digest,
            'master_code' => $master->examination_code,
            'master_display_name' => $masterVersion->display_name,
            'master_preparation_instruction' => $masterVersion->preparation_instruction,
            'care_setting' => $careSetting,
            'encounter_status_snapshot' => Encounter::STATUS_IN_EXAMINATION,
            'encounter_number_snapshot' => $encounter->public_id,
            'care_location_label_snapshot' => 'Lokasi uji',
            'clinical_indication' => 'Indikasi klinis uji.',
            'status' => RadiologyOrder::PERFORMED,
            'version' => 2,
            'ordered_at' => now(),
        ]));
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
