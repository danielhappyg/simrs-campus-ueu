<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SimrsModuleCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModulePlaceholderTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_module_placeholder_to_login(): void
    {
        $this->get(route('modules.placeholder', ['category' => 'pendaftaran']))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_users_see_module_placeholder(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('modules.placeholder', ['category' => 'pendaftaran']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('modules/placeholder')
                ->where('category', 'pendaftaran')
                ->where('categoryLabel', 'Pendaftaran'));
    }

    public function test_unknown_category_returns_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/modul/bukan-kategori')
            ->assertNotFound();
    }

    public function test_all_allowlisted_categories_resolve(): void
    {
        $user = User::factory()->create();

        foreach (SimrsModuleCategories::CATEGORIES as $slug => $label) {
            $this->actingAs($user)
                ->get(route('modules.placeholder', ['category' => $slug]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('modules/placeholder')
                    ->where('category', $slug)
                    ->where('categoryLabel', $label));
        }
    }
}
