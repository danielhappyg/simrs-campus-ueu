<?php

namespace Tests\Feature;

use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Encounter\Models\Encounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class ClinicalDraftRecoveryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_marked_inertia_draft_save_requires_reauthentication_without_redirect_or_content_echo(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->sole();
        $protectedText = 'Keluhan sintetis yang hanya boleh tetap di memori tab.';

        $response = $this->withHeaders($this->recoveryHeaders())
            ->post(route('encounters.nursing-intake.versions.store', $encounter), [
                'chief_complaint' => $protectedText,
            ]);

        $response
            ->assertStatus(401)
            ->assertHeader('X-SIMRS-Draft-Recovery', 'reauthentication-required')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'code' => 'REAUTHENTICATION_REQUIRED',
                'message' => 'Authentication must be restored before this draft can be saved.',
            ])
            ->assertDontSee($protectedText);

        $this->assertDatabaseCount((new ClinicalEntryVersion)->getTable(), 0);
    }

    public function test_unmarked_unauthenticated_inertia_post_keeps_the_standard_login_redirect(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->sole();

        $this->withHeader('X-Inertia', 'true')
            ->post(route('encounters.nursing-intake.versions.store', $encounter))
            ->assertRedirect(route('login'));
    }

    public function test_recovery_header_cannot_change_authentication_behavior_on_an_unrelated_post(): void
    {
        Route::middleware('auth')
            ->post('/_test/unrelated-marked-post', fn () => response()->noContent())
            ->name('test.unrelated-marked-post');

        $this->withHeaders($this->recoveryHeaders())
            ->post('/_test/unrelated-marked-post')
            ->assertRedirect(route('login'))
            ->assertHeaderMissing('X-SIMRS-Draft-Recovery');
    }

    public function test_marked_token_mismatch_returns_the_same_generic_no_store_recovery_contract(): void
    {
        Route::post('/_test/expired-clinical-draft', function (): never {
            throw new TokenMismatchException('Synthetic test mismatch.');
        })->name('encounters.closure.versions.store');
        $protectedText = 'Isi draf tidak boleh masuk ke respons 419.';

        $response = $this->withHeaders($this->recoveryHeaders())
            ->post('/_test/expired-clinical-draft', [
                'clinical_text' => $protectedText,
            ]);

        $response
            ->assertStatus(419)
            ->assertHeader('X-SIMRS-Draft-Recovery', 'reauthentication-required')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'code' => 'REAUTHENTICATION_REQUIRED',
                'message' => 'Authentication must be restored before this draft can be saved.',
            ])
            ->assertDontSee($protectedText)
            ->assertDontSee('Synthetic test mismatch.');
    }

    /** @return array<string, string> */
    private function recoveryHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-SIMRS-Draft-Recovery' => 'same-tab',
        ];
    }
}
