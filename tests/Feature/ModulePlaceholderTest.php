<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ScreenVocabulary;
use App\Support\SimrsModuleCategories;
use App\Support\SimrsSahabatMenuCatalog;
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

    public function test_authenticated_users_see_the_sahabat_menu_landing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('modules.placeholder', ['category' => 'rm']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('modules/placeholder')
                ->where('category', 'rm')
                ->where('categoryLabel', 'Medical Records')
                ->has('menus', 7)
                ->where('selected', null)
                ->where('menus.0.label', 'Outpatient Care')
                ->where('menus.2.label', 'Claims Monitor')
                ->where('menus.3.label', 'Filing'));
    }

    public function test_visual_menu_item_opens_a_non_operational_shell(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('modules.placeholder', ['category' => 'rm', 'item' => 'rm-filing']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('modules/placeholder')
                ->where('selected.label', 'Filing')
                ->where('selected.status', 'visual'));
    }

    public function test_live_menu_item_redirects_to_the_operational_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('modules.placeholder', ['category' => 'rm', 'item' => 'rm-rawatjalan']))
            ->assertRedirect('/rm/rawat-jalan');
    }

    public function test_unknown_category_returns_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/modul/bukan-kategori')
            ->assertNotFound();
    }

    public function test_unknown_item_returns_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/modul/rm/bukan-menu')
            ->assertNotFound();
    }

    public function test_all_allowlisted_categories_resolve_with_the_sahabat_count(): void
    {
        $user = User::factory()->create();
        $expectedCounts = [
            'pendaftaran' => 5,
            'pemeriksaan' => 20,
            'rm' => 7,
            'klaim' => 6,
            'laporan' => 117,
            'bpjs' => 2,
            'apotek' => 20,
            'gf' => 23,
            'kasir' => 19,
            'manajemen-data' => 46,
            'iot' => 1,
            'farmasi-ibs' => 1,
            'help' => 1,
        ];

        $this->assertSame(268, collect(SimrsSahabatMenuCatalog::menusByCategory())->flatten(1)->count());

        foreach (SimrsModuleCategories::CATEGORIES as $slug => $label) {
            $this->actingAs($user)
                ->get(route('modules.placeholder', ['category' => $slug]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('modules/placeholder')
                    ->where('category', $slug)
                    ->where('categoryLabel', ScreenVocabulary::label($label))
                    ->has('menus', $expectedCounts[$slug]));
        }
    }
}
