<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceAccommodationTariffBinding;
use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItem;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Inpatient\InpatientMasterService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class FinanceAccommodationTariffHttpWorkflowTest extends TestCase
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

    public function test_finance_steward_reads_an_empty_honest_accommodation_mapping_workspace(): void
    {
        $steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        [$bed, $bedVersion, $tariff] = $this->upstreams($steward);

        $this->actingAs($steward)
            ->get(route('finance.accommodation-tariff.index', ['as_of_date' => '2026-09-02']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi/index')
                ->where('as_of_date', '2026-09-02')
                ->where('source_master_version', 'MANAGED_INPATIENT_WARD_BED_MASTER_V1')
                ->where('source_trigger.code', 'CLOSED_OCCUPANCY_DAY_V1')
                ->where('permissions.can_manage', true)
                ->where('commands.create_url', route('finance.accommodation-tariff.create', absolute: false))
                ->where('sources.0.public_id', $bed->public_id)
                ->where('sources.0.bed_version_public_id', $bedVersion->public_id)
                ->where('sources.0.bed_content_digest', $bedVersion->after_digest)
                ->where('tariff_options.0.public_id', $tariff->public_id)
                ->where('tariff_options.0.amount_rupiah', 10000)
                ->where('mappings', [])
                ->has('gaps', 1)
                ->where('gaps.0.reason_code', 'TARIF_BELUM_DIPETAKAN'));
    }

    public function test_exact_finance_steward_can_create_and_cashier_can_only_read_the_mapping(): void
    {
        $steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        [$bed, $bedVersion, $tariff] = $this->upstreams($steward);
        $payload = [
            'inpatient_bed_public_id' => $bed->public_id,
            'inpatient_bed_version_public_id' => $bedVersion->public_id,
            'inpatient_bed_version' => $bedVersion->version,
            'inpatient_bed_content_digest' => $bedVersion->after_digest,
            'tariff_item_public_id' => $tariff->public_id,
            'effective_from' => '2026-09-02',
            'reason' => 'Pemetaan akomodasi awal untuk pembelajaran.',
            'confirm' => true,
            'idempotency_key' => 'accommodation-http-create-0001',
        ];

        $this->actingAs($steward)
            ->from(route('finance.accommodation-tariff.index'))
            ->post(route('finance.accommodation-tariff.create'), $payload)
            ->assertRedirect(route('finance.accommodation-tariff.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Pemetaan tarif akomodasi dibuat.');

        $binding = FinanceAccommodationTariffBinding::query()->sole();
        $this->actingAs($cashier)
            ->get(route('finance.accommodation-tariff.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_manage', false)
                ->where('commands.create_url', null)
                ->where('mappings.0.public_id', $binding->public_id)
                ->where('mappings.0.source.bed_version_public_id', $bedVersion->public_id)
                ->where('mappings.0.actions.revise_url', null)
                ->where('mappings.0.actions.retire_url', null)
                ->where('mappings.0.actions.history_url', route('finance.accommodation-tariff.history', ['binding' => $binding->public_id], false)));

        $this->actingAs($cashier)
            ->get(route('finance.accommodation-tariff.history', ['binding' => $binding->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_manage', false)
                ->where('history.binding_public_id', $binding->public_id)
                ->where('history.source.bed_version_public_id', $bedVersion->public_id)
                ->has('history.versions', 1));

        $this->actingAs($cashier)
            ->post(route('finance.accommodation-tariff.create'), [])
            ->assertForbidden();
        $this->actingAs($cashier)
            ->patch(route('finance.accommodation-tariff.revise', ['binding' => $binding->public_id]), [])
            ->assertForbidden();
        $this->assertDatabaseCount('finance_accommodation_tariff_binding_versions', 1);
    }

    public function test_rejects_an_altered_bed_version_digest_and_unauthorized_resource_lookup(): void
    {
        $steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        [$bed, $bedVersion, $tariff] = $this->upstreams($steward);

        $this->actingAs($steward)
            ->from(route('finance.accommodation-tariff.index'))
            ->post(route('finance.accommodation-tariff.create'), [
                'inpatient_bed_public_id' => $bed->public_id,
                'inpatient_bed_version_public_id' => $bedVersion->public_id,
                'inpatient_bed_version' => $bedVersion->version,
                'inpatient_bed_content_digest' => str_repeat('0', 64),
                'tariff_item_public_id' => $tariff->public_id,
                'effective_from' => '2026-09-02',
                'reason' => 'Pemetaan dengan bukti yang tidak utuh.',
                'confirm' => true,
                'idempotency_key' => 'accommodation-http-invalid-0001',
            ])
            ->assertRedirect(route('finance.accommodation-tariff.index'))
            ->assertSessionHasErrors('master');
        $this->assertDatabaseCount('finance_accommodation_tariff_bindings', 0);

        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $mixed = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $mixed->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_CASHIER)->sole()->id);
        $unknown = '01J99999999999999999999999';
        foreach ([$admin, $mixed] as $actor) {
            $this->actingAs($actor)
                ->get(route('finance.accommodation-tariff.history', ['binding' => $unknown]))
                ->assertForbidden();
            $this->actingAs($actor)
                ->patch(route('finance.accommodation-tariff.revise', ['binding' => $unknown]), [])
                ->assertForbidden();
        }
    }

    /** @return array{InpatientBed,InpatientBedVersion,FinanceTariffItem} */
    private function upstreams(User $steward): array
    {
        $administrator = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $masters = app(InpatientMasterService::class);
        $ward = $masters->createWard(
            $administrator,
            'RI-HTTP',
            'Bangsal HTTP',
            InpatientMasterService::REASON_INITIAL_SETUP,
            'accommodation-http-ward-0001',
            null,
        )->master;
        $bed = $masters->createBed(
            $administrator,
            $ward->public_id,
            'HTTP-01',
            'Tempat Tidur HTTP-01',
            'Ruang HTTP',
            'Kelas II',
            InpatientMasterService::REASON_INITIAL_SETUP,
            'accommodation-http-bed-0001',
            null,
        )->master;
        $this->assertInstanceOf(InpatientBed::class, $bed);
        $bedVersion = InpatientBedVersion::query()->where('bed_id', $bed->id)->where('version', 1)->sole();

        $tariffs = app(FinanceTariffMasterService::class);
        $group = $tariffs->createGroup($steward, 'AKM_HTTP', 'Akomodasi', 'Penyiapan komponen akomodasi.', 'accommodation-http-group-0001')->record;
        $this->assertInstanceOf(FinanceCostComponentGroup::class, $group);
        $component = $tariffs->createComponent($steward, $group->public_id, 'AKM_HTTP_SERVICE', 'Komponen akomodasi', null, null, 'Penyiapan komponen akomodasi.', 'accommodation-http-component-0001')->record;
        $this->assertInstanceOf(FinanceCostComponent::class, $component);
        $catalogue = $tariffs->createCatalogue($steward, 'AKM_HTTP_CATALOGUE', 'Katalog akomodasi', 'Penyiapan katalog akomodasi.', 'accommodation-http-catalogue-0001')->record;
        $this->assertInstanceOf(FinanceTariffCatalogue::class, $catalogue);
        $tariff = $tariffs->createTariffItem(
            $steward,
            $catalogue->public_id,
            $component->public_id,
            'AKM_HTTP_TARIFF',
            'Tarif akomodasi HTTP',
            'INPATIENT',
            'ACCOMMODATION',
            null,
            null,
            10000,
            '2026-09-02',
            'Penyiapan tarif akomodasi.',
            'accommodation-http-tariff-0001',
        )->record;
        $this->assertInstanceOf(FinanceTariffItem::class, $tariff);

        return [$bed, $bedVersion, $tariff];
    }

    private function actor(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $roleSlug)->sole()->id]);

        return $user->fresh();
    }
}
