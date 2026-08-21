<?php

namespace Tests\Feature;

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

    public function test_authenticated_users_see_rebuild_home(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('rebuild/home')
                ->where('branch', 'rebuild/clean-slate')
                ->where('docsPath', 'docs/new-simrs-rebuild/')
                ->where('mode', 'SIMULATION')
                ->where('syntheticOnly', true));
    }
}
