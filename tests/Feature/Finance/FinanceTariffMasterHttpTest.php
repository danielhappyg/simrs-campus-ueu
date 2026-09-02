<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItem;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class FinanceTariffMasterHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_exact_finance_steward_receives_empty_manage_workspace_without_seeded_prices(): void
    {
        $steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);

        $this->actingAs($steward)
            ->get(route('finance.tariff.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('manajemen-data/tarif-komponen-biaya/index')
                ->where('permissions.can_manage', true)
                ->has('groups', 0)
                ->has('components', 0)
                ->has('catalogues', 0)
                ->has('tariffs', 0)
                ->where('commands.create_group_url', route('finance.tariff.groups.create', absolute: false))
                ->where('commands.create_tariff_url', route('finance.tariff.items.create', absolute: false)));
    }

    public function test_exact_cashier_receives_read_only_workspace(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);

        $this->actingAs($cashier)
            ->get(route('finance.tariff.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_manage', false)
                ->where('commands.create_group_url', null)
                ->where('commands.create_component_url', null)
                ->where('commands.create_catalogue_url', null)
                ->where('commands.create_tariff_url', null));
    }

    public function test_admin_system_administrator_and_mixed_role_are_denied(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $systemAdministrator = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD, true);
        $mixed = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $mixed->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_CASHIER)->firstOrFail());

        foreach ([$admin, $systemAdministrator, $mixed] as $actor) {
            $this->actingAs($actor)->get(route('finance.tariff.index'))->assertForbidden();
        }
    }

    public function test_capability_is_checked_before_history_resource_lookup(): void
    {
        $unknown = '01J99999999999999999999999';
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);

        $this->actingAs($admin)
            ->get(route('finance.tariff.items.history', ['tariff' => $unknown]))
            ->assertForbidden();

        $this->actingAs($cashier)
            ->get(route('finance.tariff.items.history', ['tariff' => $unknown]))
            ->assertNotFound();
    }

    public function test_cashier_cannot_mutate_even_when_target_does_not_exist(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);

        $this->actingAs($cashier)
            ->patch(route('finance.tariff.groups.revise', ['group' => '01J99999999999999999999999']), [
                'display_name' => 'Tidak boleh disimpan',
                'expected_version' => 1,
                'expected_digest' => str_repeat('a', 64),
                'reason' => 'Uji batas peran',
                'idempotency_key' => 'http-cashier-denial-0001',
            ])
            ->assertForbidden();
    }

    public function test_history_rejects_an_invalid_as_of_date_without_projection_failure(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);

        $this->actingAs($cashier)
            ->from(route('finance.tariff.index'))
            ->get(route('finance.tariff.items.history', [
                'tariff' => '01J99999999999999999999999',
                'as_of_date' => '02-09-2026',
            ]))
            ->assertRedirect(route('finance.tariff.index'))
            ->assertSessionHasErrors('as_of_date');
    }

    public function test_steward_can_build_the_empty_master_in_dependency_order_and_read_integer_rupiah(): void
    {
        $steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);

        $this->actingAs($steward)->post(route('finance.tariff.groups.create'), [
            'code' => 'JASA',
            'display_name' => 'Jasa Pelayanan',
            'reason' => 'Pembentukan group awal',
            'idempotency_key' => 'http-group-create-0001',
        ])->assertRedirect();
        $group = FinanceCostComponentGroup::query()->where('group_code', 'JASA')->firstOrFail();
        $groupPublicId = (string) $group->getAttribute('public_id');

        $this->actingAs($steward)->post(route('finance.tariff.components.create'), [
            'group_public_id' => $groupPublicId,
            'code' => 'JASA_DOKTER',
            'display_name' => 'Jasa Dokter',
            'description' => 'Komponen jasa profesional',
            'terminology_label' => null,
            'reason' => 'Pembentukan komponen awal',
            'idempotency_key' => 'http-component-create-0001',
        ])->assertRedirect();
        $component = FinanceCostComponent::query()->where('component_code', 'JASA_DOKTER')->firstOrFail();
        $componentPublicId = (string) $component->getAttribute('public_id');

        $this->actingAs($steward)->post(route('finance.tariff.catalogues.create'), [
            'code' => 'UMUM',
            'display_name' => 'Katalog Umum',
            'reason' => 'Pembentukan katalog awal',
            'idempotency_key' => 'http-catalogue-create-0001',
        ])->assertRedirect();
        $catalogue = FinanceTariffCatalogue::query()->where('catalogue_code', 'UMUM')->firstOrFail();
        $cataloguePublicId = (string) $catalogue->getAttribute('public_id');

        $this->actingAs($steward)->post(route('finance.tariff.items.create'), [
            'catalogue_public_id' => $cataloguePublicId,
            'component_public_id' => $componentPublicId,
            'code' => 'RJ_KONSUL',
            'display_name' => 'Konsultasi Rawat Jalan',
            'care_setting' => 'OUTPATIENT',
            'service_domain' => 'GENERAL_SERVICE',
            'reference_label' => null,
            'ward_class_label' => null,
            'amount_rupiah' => 150000,
            'effective_from' => now()->toDateString(),
            'reason' => 'Pembentukan tarif awal',
            'idempotency_key' => 'http-tariff-create-0001',
        ])->assertRedirect();

        $tariff = FinanceTariffItem::query()->where('tariff_code', 'RJ_KONSUL')->firstOrFail();
        $tariffPublicId = (string) $tariff->getAttribute('public_id');
        $this->actingAs($steward)
            ->get(route('finance.tariff.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tariffs.0.public_id', $tariffPublicId)
                ->where('tariffs.0.amount_rupiah', 150000)
                ->where('tariffs.0.is_effective', true)
                ->where('tariffs.0.actions.revise_url', route('finance.tariff.items.revise', ['tariff' => $tariffPublicId], false))
                ->where('groups.0.actions.revise_url', route('finance.tariff.groups.revise', ['group' => $groupPublicId], false)));

        $this->actingAs($steward)->patch(route('finance.tariff.items.revise', ['tariff' => $tariffPublicId]), [
            'display_name' => 'Konsultasi Rawat Jalan',
            'care_setting' => 'OUTPATIENT',
            'service_domain' => 'GENERAL_SERVICE',
            'reference_label' => null,
            'ward_class_label' => null,
            'amount_rupiah' => 175000,
            'effective_from' => now()->addDay()->toDateString(),
            'expected_version' => 1,
            'expected_digest' => (string) $tariff->getAttribute('current_content_digest'),
            'reason' => 'Penyesuaian tarif mendatang',
            'idempotency_key' => 'http-tariff-revise-0001',
        ])->assertRedirect();

        $this->actingAs($steward)
            ->get(route('finance.tariff.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tariffs.0.version', 1)
                ->where('tariffs.0.amount_rupiah', 150000)
                ->where('tariffs.0.latest_head_version', 2)
                ->where('tariffs.0.latest_head_state', 'ACTIVE')
                ->where('tariffs.0.actions.revise_url', route('finance.tariff.items.revise', ['tariff' => $tariffPublicId], false)));

        $tariff->refresh();
        $this->actingAs($steward)->post(route('finance.tariff.items.retire', ['tariff' => $tariffPublicId]), [
            'effective_from' => now()->addDays(2)->toDateString(),
            'expected_version' => 2,
            'expected_digest' => (string) $tariff->getAttribute('current_content_digest'),
            'reason' => 'Penutupan tarif mendatang',
            'confirm' => true,
            'idempotency_key' => 'http-tariff-retire-0001',
        ])->assertRedirect();

        $this->actingAs($steward)
            ->get(route('finance.tariff.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tariffs.0.state', 'ACTIVE')
                ->where('tariffs.0.version', 1)
                ->where('tariffs.0.latest_head_version', 3)
                ->where('tariffs.0.latest_head_state', 'RETIRED')
                ->where('tariffs.0.actions.revise_url', null)
                ->where('tariffs.0.actions.retire_url', null));
    }

    private function actor(string $roleSlug, bool $systemAdministrator = false): User
    {
        $user = User::factory()->create(['is_system_administrator' => $systemAdministrator]);
        $user->roles()->sync([Role::query()->where('slug', $roleSlug)->firstOrFail()->id]);

        return $user;
    }
}
