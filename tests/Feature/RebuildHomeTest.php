<?php

namespace Tests\Feature;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RebuildHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_home_to_login(): void
    {
        $this->get(route('home'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_users_see_home(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('rebuild/home')
                ->where('counts.kunjungan_hari_ini', 0)
                ->where('counts.pasien_baru_hari_ini', 0)
                ->where('counts.in_examination', 0)
                ->where('counts.ready_for_rm', 0));
    }

    public function test_home_returns_live_outpatient_counts(): void
    {
        $user = User::factory()->create();

        Patient::factory()->create([
            'created_by_user_id' => $user->id,
        ]);

        Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['created_by_user_id' => $user->id])->id,
            'registered_by_user_id' => $user->id,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'registered_at' => now(),
        ]);

        Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['created_by_user_id' => $user->id])->id,
            'registered_by_user_id' => $user->id,
            'status' => Encounter::STATUS_READY_FOR_RM,
            'registered_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('rebuild/home')
                ->where('counts.kunjungan_hari_ini', 2)
                ->where('counts.pasien_baru_hari_ini', 3)
                ->where('counts.in_examination', 1)
                ->where('counts.ready_for_rm', 1));
    }
}
