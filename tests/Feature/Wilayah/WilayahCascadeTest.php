<?php

namespace Tests\Feature\Wilayah;

use App\Models\Role;
use App\Models\User;
use App\Models\WilayahProvince;
use App\Support\Authorization\Capability;
use Database\Seeders\RbacSeeder;
use Database\Seeders\WilayahMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WilayahCascadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_wilayah_endpoints_require_authentication(): void
    {
        $this->getJson(route('wilayah.provinces'))->assertUnauthorized();
    }

    public function test_authenticated_registrar_can_cascade_wilayah(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(WilayahMinimalSeeder::class);

        $user = User::factory()->create([
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(
            Role::query()->where('slug', 'registrar')->value('id'),
        );

        $this->actingAs($user)
            ->getJson(route('wilayah.provinces'))
            ->assertOk()
            ->assertJsonStructure(['options' => [['value', 'label']]]);

        $province = WilayahProvince::query()->where('code', '31')->firstOrFail();

        $regencies = $this->actingAs($user)
            ->getJson(route('wilayah.regencies', ['provinceCode' => $province->code]))
            ->assertOk()
            ->json('options');

        $this->assertNotEmpty($regencies);
        $regencyCode = $regencies[0]['value'];

        $districts = $this->actingAs($user)
            ->getJson(route('wilayah.districts', ['regencyCode' => $regencyCode]))
            ->assertOk()
            ->json('options');

        $this->assertNotEmpty($districts);
        $districtCode = $districts[0]['value'];

        $this->actingAs($user)
            ->getJson(route('wilayah.villages', ['districtCode' => $districtCode]))
            ->assertOk()
            ->assertJsonStructure(['options' => [['value', 'label']]]);
    }

    public function test_user_without_patient_search_cannot_read_wilayah(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(WilayahMinimalSeeder::class);

        $user = User::factory()->create([
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
            'is_system_administrator' => false,
        ]);

        $this->actingAs($user)
            ->getJson(route('wilayah.provinces'))
            ->assertForbidden();

        $this->assertFalse($user->canCapability(Capability::PATIENT_SEARCH));
    }
}
