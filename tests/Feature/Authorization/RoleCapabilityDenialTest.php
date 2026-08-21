<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleCapabilityDenialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        Route::middleware(['web', 'auth', 'capability:'.Capability::RMIK_CODING_WRITE])
            ->get('/__test/rmik-coding', fn () => response('ok', 200))
            ->name('test.rmik-coding');
    }

    public function test_registrar_cannot_write_rmik_coding(): void
    {
        $registrar = User::factory()->create([
            'email' => 'registrar.capability@example.invalid',
        ]);

        $role = Role::query()->where('slug', RoleCapabilityMatrix::ROLE_REGISTRAR)->firstOrFail();
        $registrar->roles()->sync([$role->id]);

        $this->assertFalse($registrar->canCapability(Capability::RMIK_CODING_WRITE));
        $this->assertTrue($registrar->canCapability(Capability::PATIENT_REGISTER));

        $this->actingAs($registrar)
            ->get('/__test/rmik-coding')
            ->assertForbidden();
    }
}
