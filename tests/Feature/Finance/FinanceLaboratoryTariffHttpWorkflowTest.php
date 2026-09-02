<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceLaboratoryTariffBinding;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItem;
use App\Models\LaboratoryExaminationMaster;
use App\Models\LaboratoryExaminationMasterVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Laboratory\LaboratoryMasterService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class FinanceLaboratoryTariffHttpWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-02 10:00:00');
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_finance_steward_reads_honest_manage_workspace_without_a_default_mapping(): void
    {
        $steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        [$master, $masterVersion, $tariff] = $this->upstreams($steward);

        $this->actingAs($steward)
            ->get(route('finance.laboratory-tariff.index', ['as_of_date' => '2026-09-02']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium/index')
                ->where('as_of_date', '2026-09-02')
                ->where('source_master_version', 'LABORATORY_EXAMINATION_MASTER_V1')
                ->where('source_master_content_digest', fn (mixed $digest): bool => is_string($digest) && strlen($digest) === 64)
                ->where('source_trigger.code', 'ORIGINAL_VERIFIED_RESULT_V1')
                ->where('source_trigger.label', 'Hasil asli berstatus VERIFIED pada verified_at adalah satu-satunya pemicu biaya.')
                ->where('permissions.can_manage', true)
                ->where('commands.create_url', route('finance.laboratory-tariff.create', absolute: false))
                ->where('read_error', null)
                ->has('sources', 1)
                ->where('sources.0.public_id', $master->public_id)
                ->where('sources.0.master_version_public_id', $masterVersion->public_id)
                ->where('sources.0.master_version', 1)
                ->where('sources.0.master_content_digest', $masterVersion->content_digest)
                ->where('sources.0.specimen_type', 'Darah EDTA')
                ->where('sources.0.component_count', 2)
                ->has('tariff_options', 1)
                ->where('tariff_options.0.public_id', $tariff->public_id)
                ->where('tariff_options.0.amount_rupiah', 10000)
                ->where('tariff_options.0.care_setting', 'OUTPATIENT')
                ->where('tariff_options.0.state', 'ACTIVE')
                ->where('mappings', [])
                ->has('gaps', 3)
                ->where('gaps.0.reason_code', 'TARIF_BELUM_DIPETAKAN')
                ->where('history', null));
    }

    public function test_finance_steward_creates_replays_revises_retires_and_reads_immutable_history(): void
    {
        $steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        [, $masterVersion, $tariff] = $this->upstreams($steward);
        $master = LaboratoryExaminationMaster::query()->findOrFail($masterVersion->laboratory_examination_master_id);
        $create = [
            'laboratory_master_public_id' => $master->public_id,
            'laboratory_master_version_public_id' => $masterVersion->public_id,
            'laboratory_master_version' => $masterVersion->version,
            'laboratory_master_content_digest' => $masterVersion->content_digest,
            'care_setting' => 'OUTPATIENT',
            'tariff_item_public_id' => $tariff->public_id,
            'effective_from' => '2026-09-02',
            'reason' => 'Pemetaan awal laboratorium rawat jalan.',
            'confirm' => true,
            'idempotency_key' => 'laboratory-http-create-0001',
        ];

        $this->actingAs($steward)
            ->from(route('finance.laboratory-tariff.index'))
            ->post(route('finance.laboratory-tariff.create'), $create)
            ->assertRedirect(route('finance.laboratory-tariff.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Pemetaan tarif laboratorium dibuat.');

        $binding = FinanceLaboratoryTariffBinding::query()->sole();
        $this->assertDatabaseCount('finance_laboratory_tariff_binding_versions', 1);

        $this->actingAs($steward)
            ->from(route('finance.laboratory-tariff.index'))
            ->post(route('finance.laboratory-tariff.create'), $create)
            ->assertRedirect(route('finance.laboratory-tariff.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Operasi yang sama ditampilkan kembali.');
        $this->assertDatabaseCount('finance_laboratory_tariff_bindings', 1);
        $this->assertDatabaseCount('finance_laboratory_tariff_binding_versions', 1);

        $revise = [
            'tariff_item_public_id' => $tariff->public_id,
            'effective_from' => '2026-09-03',
            'expected_version' => 1,
            'expected_digest' => $binding->current_content_digest,
            'reason' => 'Versi pemetaan terjadwal.',
            'confirm' => true,
            'idempotency_key' => 'laboratory-http-revise-0001',
        ];
        $this->actingAs($steward)
            ->from(route('finance.laboratory-tariff.index'))
            ->patch(route('finance.laboratory-tariff.revise', ['binding' => $binding->public_id]), $revise)
            ->assertRedirect(route('finance.laboratory-tariff.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Versi pemetaan tarif laboratorium ditambahkan.');
        $this->actingAs($steward)
            ->from(route('finance.laboratory-tariff.index'))
            ->patch(route('finance.laboratory-tariff.revise', ['binding' => $binding->public_id]), $revise)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Operasi yang sama ditampilkan kembali.');
        $this->assertDatabaseCount('finance_laboratory_tariff_binding_versions', 2);

        $binding->refresh();
        $retire = [
            'effective_from' => '2026-09-04',
            'expected_version' => 2,
            'expected_digest' => $binding->current_content_digest,
            'reason' => 'Pemetaan dijadwalkan nonaktif.',
            'confirm' => true,
            'idempotency_key' => 'laboratory-http-retire-0001',
        ];
        $this->actingAs($steward)
            ->from(route('finance.laboratory-tariff.index'))
            ->post(route('finance.laboratory-tariff.retire', ['binding' => $binding->public_id]), $retire)
            ->assertRedirect(route('finance.laboratory-tariff.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Pemetaan tarif laboratorium dijadwalkan nonaktif.');
        $this->actingAs($steward)
            ->from(route('finance.laboratory-tariff.index'))
            ->post(route('finance.laboratory-tariff.retire', ['binding' => $binding->public_id]), $retire)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Operasi yang sama ditampilkan kembali.');

        $binding->refresh();
        $this->assertSame(FinanceLaboratoryTariffBinding::RETIRED, $binding->state);
        $this->assertDatabaseCount('finance_laboratory_tariff_binding_versions', 3);

        $this->actingAs($steward)
            ->get(route('finance.laboratory-tariff.index', ['as_of_date' => '2026-09-02']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mappings.0.public_id', $binding->public_id)
                ->where('mappings.0.state', 'ACTIVE')
                ->where('mappings.0.version', 1)
                ->where('mappings.0.latest_head_version', 3)
                ->where('mappings.0.latest_head_state', 'RETIRED')
                ->where('mappings.0.actions.revise_url', null)
                ->where('mappings.0.actions.retire_url', null)
                ->where('mappings.0.source.master_version_public_id', $masterVersion->public_id)
                ->where('mappings.0.source.master_content_digest', $masterVersion->content_digest)
                ->where('mappings.0.tariff.public_id', $tariff->public_id)
                ->where('mappings.0.tariff.amount_rupiah', 10000));

        $this->actingAs($steward)
            ->get(route('finance.laboratory-tariff.history', [
                'binding' => $binding->public_id,
                'as_of_date' => '2026-09-02',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.binding_public_id', $binding->public_id)
                ->where('history.source.master_version_public_id', $masterVersion->public_id)
                ->where('history.care_setting', 'OUTPATIENT')
                ->has('history.versions', 3)
                ->where('history.versions.0.version', 1)
                ->where('history.versions.0.effective_from', '2026-09-02')
                ->where('history.versions.0.effective_until', '2026-09-03')
                ->where('history.versions.1.version', 2)
                ->where('history.versions.1.effective_until', '2026-09-04')
                ->where('history.versions.2.version', 3)
                ->where('history.versions.2.state', 'RETIRED')
                ->where('history.versions.2.effective_until', null));
    }

    public function test_cashier_reads_effective_mapping_and_history_without_mutation_commands(): void
    {
        $steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        [$master, $masterVersion, $tariff] = $this->upstreams($steward);
        $this->actingAs($steward)->post(route('finance.laboratory-tariff.create'), [
            'laboratory_master_public_id' => $master->public_id,
            'laboratory_master_version_public_id' => $masterVersion->public_id,
            'laboratory_master_version' => 1,
            'laboratory_master_content_digest' => $masterVersion->content_digest,
            'care_setting' => 'OUTPATIENT',
            'tariff_item_public_id' => $tariff->public_id,
            'effective_from' => '2026-09-02',
            'reason' => 'Pemetaan untuk pembacaan Kasir.',
            'confirm' => true,
            'idempotency_key' => 'laboratory-http-cashier-0001',
        ])->assertSessionHasNoErrors();
        $binding = FinanceLaboratoryTariffBinding::query()->sole();

        $this->actingAs($cashier)
            ->get(route('finance.laboratory-tariff.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_manage', false)
                ->where('commands.create_url', null)
                ->where('mappings.0.public_id', $binding->public_id)
                ->where('mappings.0.actions.revise_url', null)
                ->where('mappings.0.actions.retire_url', null)
                ->where('mappings.0.actions.history_url', route('finance.laboratory-tariff.history', ['binding' => $binding->public_id], false)));

        $this->actingAs($cashier)
            ->get(route('finance.laboratory-tariff.history', ['binding' => $binding->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_manage', false)
                ->where('history.binding_public_id', $binding->public_id)
                ->has('history.versions', 1));

        $this->actingAs($cashier)
            ->patch(route('finance.laboratory-tariff.revise', ['binding' => $binding->public_id]), [])
            ->assertForbidden();
        $this->actingAs($cashier)
            ->post(route('finance.laboratory-tariff.retire', ['binding' => $binding->public_id]), [])
            ->assertForbidden();
        $this->assertDatabaseCount('finance_laboratory_tariff_binding_versions', 1);
    }

    public function test_unauthorized_roles_are_denied_before_resource_disclosure(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $systemAdministrator = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD, true);
        $mixed = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $mixed->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_CASHIER)->sole()->id);
        $unknown = '01J99999999999999999999999';

        foreach ([$admin, $systemAdministrator, $mixed] as $actor) {
            $this->actingAs($actor)->get(route('finance.laboratory-tariff.index'))->assertForbidden();
            $this->actingAs($actor)
                ->get(route('finance.laboratory-tariff.history', ['binding' => $unknown]))
                ->assertForbidden();
            $this->actingAs($actor)
                ->post(route('finance.laboratory-tariff.create'), [])
                ->assertForbidden();
            $this->actingAs($actor)
                ->patch(route('finance.laboratory-tariff.revise', ['binding' => $unknown]), [])
                ->assertForbidden();
            $this->actingAs($actor)
                ->post(route('finance.laboratory-tariff.retire', ['binding' => $unknown]), [])
                ->assertForbidden();
        }

        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $this->actingAs($cashier)
            ->get(route('finance.laboratory-tariff.history', ['binding' => $unknown]))
            ->assertNotFound();
        $this->actingAs($cashier)
            ->post(route('finance.laboratory-tariff.create'), [])
            ->assertForbidden();
        $this->actingAs($cashier)
            ->patch(route('finance.laboratory-tariff.revise', ['binding' => $unknown]), [])
            ->assertForbidden();
    }

    public function test_read_routes_validate_as_of_date_before_projection_work(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);

        $this->actingAs($cashier)
            ->from(route('finance.laboratory-tariff.index'))
            ->get(route('finance.laboratory-tariff.index', ['as_of_date' => '02-09-2026']))
            ->assertRedirect(route('finance.laboratory-tariff.index'))
            ->assertSessionHasErrors('as_of_date');

        $this->actingAs($cashier)
            ->from(route('finance.laboratory-tariff.index'))
            ->get(route('finance.laboratory-tariff.history', [
                'binding' => '01J99999999999999999999999',
                'as_of_date' => '02-09-2026',
            ]))
            ->assertRedirect(route('finance.laboratory-tariff.index'))
            ->assertSessionHasErrors('as_of_date');
    }

    /** @return array{LaboratoryExaminationMaster,LaboratoryExaminationMasterVersion,FinanceTariffItem} */
    private function upstreams(User $steward): array
    {
        $administrator = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $master = app(LaboratoryMasterService::class)->create(
            $administrator,
            'LAB-HTTP-HEMOGRAM',
            'Hemogram lengkap',
            'Darah EDTA',
            'Koleksi sesuai prosedur.',
            [
                ['code' => 'HGB', 'display_name' => 'Hemoglobin', 'value_kind' => 'NUMERIC', 'unit_text' => 'g/dL', 'reference_text' => 'Rujukan unit.', 'critical_allowed' => true],
                ['code' => 'PLT', 'display_name' => 'Trombosit', 'value_kind' => 'NUMERIC', 'unit_text' => '10^3/uL', 'reference_text' => 'Rujukan unit.', 'critical_allowed' => true],
            ],
            'laboratory-http-master-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryExaminationMaster::class, $master);
        $masterVersion = LaboratoryExaminationMasterVersion::query()
            ->where('laboratory_examination_master_id', $master->id)
            ->where('version', 1)
            ->sole();

        $service = app(FinanceTariffMasterService::class);
        $group = $service->createGroup(
            $steward,
            'LAB_HTTP',
            'Laboratorium',
            'Penyiapan komponen laboratorium.',
            'laboratory-http-group-0001',
        )->record;
        $this->assertInstanceOf(FinanceCostComponentGroup::class, $group);
        $component = $service->createComponent(
            $steward,
            $group->public_id,
            'LAB_HTTP_SERVICE',
            'Komponen laboratorium',
            null,
            null,
            'Penyiapan komponen laboratorium.',
            'laboratory-http-component-0001',
        )->record;
        $this->assertInstanceOf(FinanceCostComponent::class, $component);
        $catalogue = $service->createCatalogue(
            $steward,
            'LAB_HTTP_CATALOGUE',
            'Katalog laboratorium',
            'Penyiapan katalog laboratorium.',
            'laboratory-http-catalogue-0001',
        )->record;
        $this->assertInstanceOf(FinanceTariffCatalogue::class, $catalogue);
        $tariff = $service->createTariffItem(
            $steward,
            $catalogue->public_id,
            $component->public_id,
            'LAB_HTTP_TARIFF',
            'Tarif hemogram lengkap',
            'OUTPATIENT',
            'LABORATORY',
            null,
            null,
            10000,
            '2026-09-02',
            'Penyiapan tarif laboratorium.',
            'laboratory-http-tariff-0001',
        )->record;
        $this->assertInstanceOf(FinanceTariffItem::class, $tariff);

        return [$master, $masterVersion, $tariff];
    }

    private function actor(string $roleSlug, bool $systemAdministrator = false): User
    {
        $user = User::factory()->create(['is_system_administrator' => $systemAdministrator]);
        $user->roles()->sync([Role::query()->where('slug', $roleSlug)->sole()->id]);

        return $user->fresh();
    }
}
