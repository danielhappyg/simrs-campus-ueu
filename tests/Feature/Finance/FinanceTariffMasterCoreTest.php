<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItem;
use App\Models\FinanceTariffItemVersion;
use App\Models\FinanceTariffOperationReceipt;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceTariffAuditUnavailable;
use App\Support\Finance\FinanceTariffDenied;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Finance\FinanceTariffProjection;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

final class FinanceTariffMasterCoreTest extends TestCase
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

    public function test_effective_versions_replay_and_terminal_retirement_preserve_history(): void
    {
        $service = app(FinanceTariffMasterService::class);
        $group = $service->createGroup($this->steward, ' svc ', 'Layanan', 'Penyiapan awal', 'group-create-0001')->record;
        $component = $service->createComponent($this->steward, $group->public_id, 'doctor', 'Jasa Dokter', null, null, 'Penyiapan awal', 'component-create-0001')->record;
        $catalogue = $service->createCatalogue($this->steward, 'regular', 'Tarif Reguler', 'Penyiapan awal', 'catalogue-create-0001')->record;
        $item = $service->createTariffItem(
            $this->steward, $catalogue->public_id, $component->public_id, 'consult', 'Konsultasi',
            'OUTPATIENT', 'GENERAL_SERVICE', null, null, 10000, '2026-09-02',
            'Penyiapan awal', 'tariff-create-0001',
        )->record;

        $this->assertSame(1, $item->version);
        $this->assertSame($this->steward->id, FinanceTariffItemVersion::query()->sole()->actor_user_id);
        $second = $service->appendTariffItemVersion(
            $this->steward, $item->public_id, 'Konsultasi', 'OUTPATIENT', 'GENERAL_SERVICE',
            null, null, 12000, '2026-09-03', 1, $item->current_content_digest,
            'Penyesuaian tarif', 'tariff-append-0001',
        )->record;

        $this->assertSame(10000, $service->resolveEffectiveTariff($this->steward, 'CONSULT', '2026-09-02')?->amount_rupiah);
        $this->assertSame(12000, $service->resolveEffectiveTariff($this->steward, 'CONSULT', '2026-09-03')?->amount_rupiah);

        $replay = $service->createTariffItem(
            $this->steward, $catalogue->public_id, $component->public_id, 'consult', 'Konsultasi',
            'OUTPATIENT', 'GENERAL_SERVICE', null, null, 10000, '2026-09-02',
            'Penyiapan awal', 'tariff-create-0001',
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame(1, $replay->record->version);
        $this->assertSame('2026-09-02', $replay->record->latest_effective_from->format('Y-m-d'));
        $this->assertSame(2, FinanceTariffItemVersion::query()->count());

        $retired = $service->retireTariffItem(
            $this->steward, $item->public_id, '2026-09-04', 2, $second->current_content_digest,
            'Layanan dihentikan', 'tariff-retire-0001',
        )->record;
        $this->assertSame(FinanceTariffItem::RETIRED, $retired->state);
        $this->assertSame(12000, $service->resolveEffectiveTariff($this->steward, 'CONSULT', '2026-09-03')?->amount_rupiah);
        $this->assertNull($service->resolveEffectiveTariff($this->steward, 'CONSULT', '2026-09-04'));
        $this->assertSame(3, FinanceTariffItemVersion::query()->count());

        $projection = app(FinanceTariffProjection::class);
        $history = $projection->tariffHistory($this->steward, $item->public_id);
        $this->assertSame(['2026-09-03', '2026-09-04', null], array_column($history['versions'], 'effective_until'));
        $this->assertSame([10000, 12000, 12000], array_column($history['versions'], 'amount_rupiah'));
        $this->assertCount(1, $projection->effectiveTariffs($this->steward, '2026-09-03'));
        $this->assertSame([], $projection->effectiveTariffs($this->steward, '2026-09-04'));

        $this->expectException(FinanceTariffDenied::class);
        $service->retireComponent($this->steward, $component->public_id, 1, $component->current_content_digest, 'Belum dapat pensiun', 'component-retire-early-0001');
    }

    public function test_upstreams_can_retire_only_after_dependent_tariff_retirement_is_effective(): void
    {
        $service = app(FinanceTariffMasterService::class);
        [$group, $component, $catalogue, $item] = $this->graph($service);
        $retiredItem = $service->retireTariffItem($this->steward, $item->public_id, '2026-09-03', 1, $item->current_content_digest, 'Pensiun tarif', 'retire-item-0001')->record;

        try {
            $service->retireCatalogue($this->steward, $catalogue->public_id, 1, $catalogue->current_content_digest, 'Pensiun katalog', 'retire-cat-early-0001');
            $this->fail('Future tariff retirement must still block upstream retirement.');
        } catch (FinanceTariffDenied $denied) {
            $this->assertSame('dependent_tariffs_remain', $denied->reason);
        }

        Carbon::setTestNow('2026-09-03 10:00:00');
        $component = $service->retireComponent($this->steward, $component->public_id, 1, $component->current_content_digest, 'Pensiun komponen', 'retire-component-0001')->record;
        $catalogue = $service->retireCatalogue($this->steward, $catalogue->public_id, 1, $catalogue->current_content_digest, 'Pensiun katalog', 'retire-catalogue-0001')->record;
        $group = $service->retireGroup($this->steward, $group->public_id, 1, $group->current_content_digest, 'Pensiun group', 'retire-group-0001')->record;

        $this->assertSame(FinanceCostComponent::RETIRED, $component->state);
        $this->assertSame(FinanceTariffCatalogue::RETIRED, $catalogue->state);
        $this->assertSame(FinanceCostComponentGroup::RETIRED, $group->state);
        $this->assertSame(FinanceTariffItem::RETIRED, $retiredItem->state);
    }

    public function test_overview_keeps_today_effective_version_and_future_only_items_visible(): void
    {
        $service = app(FinanceTariffMasterService::class);
        [, $component, $catalogue, $item] = $this->graph($service);
        $service->appendTariffItemVersion(
            $this->steward, $item->public_id, 'Konsultasi Baru', 'EMERGENCY', 'LABORATORY',
            null, null, 12000, '2026-09-03', 1, $item->current_content_digest,
            'Penyesuaian tarif', 'overview-append-0001',
        );
        $service->createTariffItem(
            $this->steward, $catalogue->public_id, $component->public_id, 'future-only', 'Layanan Masa Depan',
            'OUTPATIENT', 'GENERAL_SERVICE', null, null, 25000, '2026-09-10',
            'Persiapan layanan', 'overview-future-0001',
        );

        $projection = app(FinanceTariffProjection::class);
        $today = collect($projection->overview($this->steward, '2026-09-02')['tariffs'])->keyBy('code');
        $this->assertSame(10000, $today['CONSULT']['amount_rupiah']);
        $this->assertSame(1, $today['CONSULT']['version']);
        $this->assertSame(2, $today['CONSULT']['latest_head_version']);
        $this->assertSame(FinanceTariffItem::ACTIVE, $today['CONSULT']['latest_head_state']);
        $this->assertTrue($today['CONSULT']['is_effective']);
        $this->assertSame(25000, $today['FUTURE-ONLY']['amount_rupiah']);
        $this->assertFalse($today['FUTURE-ONLY']['is_effective']);

        $boundary = collect($projection->overview($this->steward, '2026-09-03')['tariffs'])->keyBy('code');
        $this->assertSame(12000, $boundary['CONSULT']['amount_rupiah']);
        $this->assertSame(2, $boundary['CONSULT']['version']);
        $this->assertSame(2, $boundary['CONSULT']['latest_head_version']);
        $this->assertTrue($boundary['CONSULT']['is_effective']);
        $this->assertSame([], $projection->effectiveTariffs($this->steward, '2026-09-03', ['care_setting' => 'OUTPATIENT']));
        $this->assertSame('CONSULT', $projection->effectiveTariffs($this->steward, '2026-09-03', ['care_setting' => 'EMERGENCY'])[0]['code']);

        $latest = FinanceTariffItem::query()->where('tariff_code', 'CONSULT')->sole();
        $service->retireTariffItem(
            $this->steward, $latest->public_id, '2026-09-04', 2, $latest->current_content_digest,
            'Pensiun terjadwal', 'overview-retire-0001',
        );
        $beforeRetirement = collect($projection->overview($this->steward, '2026-09-03')['tariffs'])->keyBy('code');
        $this->assertSame(FinanceTariffItem::ACTIVE, $beforeRetirement['CONSULT']['state']);
        $this->assertSame(FinanceTariffItem::RETIRED, $beforeRetirement['CONSULT']['latest_head_state']);
        $this->assertSame(3, $beforeRetirement['CONSULT']['latest_head_version']);
    }

    public function test_stale_and_changed_payload_are_denied_without_mutating_history(): void
    {
        $service = app(FinanceTariffMasterService::class);
        $group = $service->createGroup($this->steward, 'svc', 'Layanan', 'Penyiapan awal', 'group-create-stale-0001')->record;
        $revised = $service->reviseGroup($this->steward, $group->public_id, 'Layanan Klinis', 1, $group->current_content_digest, 'Perbaikan nama', 'group-revise-0001')->record;

        try {
            $service->reviseGroup($this->steward, $group->public_id, 'Nama basi', 1, $group->current_content_digest, 'Muatan basi', 'group-revise-stale-0001');
            $this->fail('Stale write should fail.');
        } catch (FinanceTariffDenied $denied) {
            $this->assertSame('stale_version', $denied->reason);
        }
        try {
            $service->createGroup($this->steward, 'different', 'Berbeda', 'Penyiapan awal', 'group-create-stale-0001');
            $this->fail('Changed idempotency payload should fail.');
        } catch (FinanceTariffDenied $denied) {
            $this->assertSame('idempotency_key_conflict', $denied->reason);
        }

        $this->assertSame(2, $revised->fresh()->version);
        $this->assertSame(2, $revised->versions()->count());
        $this->assertSame(2, FinanceTariffOperationReceipt::query()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'finance.tariff.mutate', 'outcome' => 'DENIED', 'reason' => 'stale_version']);
    }

    public function test_required_success_audit_failure_rolls_back_every_mutation_artifact(): void
    {
        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->andReturnNull();
        $this->app->instance(AuditRecorder::class, $audit);

        $this->expectException(FinanceTariffAuditUnavailable::class);
        try {
            app(FinanceTariffMasterService::class)->createGroup($this->steward, 'rollback', 'Rollback', 'Uji audit wajib', 'audit-rollback-0001');
        } finally {
            $this->assertDatabaseEmpty('finance_cost_component_groups');
            $this->assertDatabaseEmpty('finance_cost_component_group_versions');
            $this->assertDatabaseEmpty('finance_tariff_code_reservations');
            $this->assertDatabaseEmpty('finance_tariff_operation_receipts');
        }
    }

    public function test_service_authorizes_before_resource_lookup(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);

        $this->expectException(AuthorizationException::class);
        app(FinanceTariffMasterService::class)->retireGroup(
            $cashier, '01J99999999999999999999999', 1, str_repeat('a', 64),
            'Tidak diizinkan', 'cashier-denied-0001',
        );
    }

    /** @return array{FinanceCostComponentGroup,FinanceCostComponent,FinanceTariffCatalogue,FinanceTariffItem} */
    private function graph(FinanceTariffMasterService $service): array
    {
        $group = $service->createGroup($this->steward, 'svc', 'Layanan', 'Penyiapan awal', 'graph-group-0001')->record;
        $component = $service->createComponent($this->steward, $group->public_id, 'doctor', 'Dokter', null, null, 'Penyiapan awal', 'graph-component-0001')->record;
        $catalogue = $service->createCatalogue($this->steward, 'regular', 'Reguler', 'Penyiapan awal', 'graph-catalogue-0001')->record;
        $item = $service->createTariffItem(
            $this->steward, $catalogue->public_id, $component->public_id, 'consult', 'Konsultasi',
            'OUTPATIENT', 'GENERAL_SERVICE', null, null, 10000, '2026-09-02',
            'Penyiapan awal', 'graph-tariff-0001',
        )->record;

        return [$group, $component, $catalogue, $item];
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
