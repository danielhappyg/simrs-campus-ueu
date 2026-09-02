<?php

namespace Tests\Feature\Emergency;

use App\Models\EmergencyTriageAssessment;
use App\Models\EmergencyTriageVocabulary;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Emergency\EmergencyDenied;
use App\Support\Emergency\EmergencyTriageVocabularyService;
use Database\Seeders\EmergencyTriageVocabularySeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class EmergencySchemaAndVocabularyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_fresh_schema_contains_all_governed_emergency_tables_and_covering_acknowledgement_links(): void
    {
        foreach (['emergency_triage_code_reservations', 'emergency_triage_vocabularies', 'emergency_triage_vocabulary_versions', 'emergency_triage_assessments', 'emergency_clinical_documents', 'emergency_clinical_document_versions', 'emergency_result_follow_up_proposals', 'emergency_result_follow_up_acceptances', 'emergency_dispositions', 'emergency_disposition_correction_intents', 'emergency_disposition_correction_intent_events', 'emergency_inpatient_handoffs', 'emergency_handoff_compensations', 'emergency_operation_receipts'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumn('laboratory_result_acknowledgements', 'emergency_follow_up_acceptance_id'));
        $this->assertTrue(Schema::hasColumn('radiology_report_acknowledgements', 'emergency_follow_up_acceptance_id'));
    }

    public function test_vocabulary_seeder_is_idempotent_and_fixed_to_four_canonical_categories(): void
    {
        $admin = User::factory()->create(['email' => EmergencyTriageVocabularySeeder::ACTOR_EMAIL, 'status' => 'ACTIVE', 'is_system_administrator' => false]);
        $admin->roles()->sync([Role::query()->where('slug', RoleCapabilityMatrix::ROLE_ADMIN)->sole()->id]);
        $this->seed(EmergencyTriageVocabularySeeder::class);
        $this->seed(EmergencyTriageVocabularySeeder::class);

        $vocabulary = EmergencyTriageVocabulary::query()->sole();
        $version = $vocabulary->versions()->sole();
        $this->assertSame(['MERAH', 'KUNING', 'HIJAU', 'HITAM'], collect($version->categories)->pluck('code')->all());
        $this->assertSame([1, 2, 3, 4], collect($version->categories)->pluck('rank')->all());
        $this->assertDatabaseCount('emergency_triage_code_reservations', 1);
        $this->assertDatabaseCount('emergency_operation_receipts', 1);
    }

    public function test_fixed_category_codes_and_order_cannot_be_revised(): void
    {
        $admin = User::factory()->create(['email' => EmergencyTriageVocabularySeeder::ACTOR_EMAIL, 'status' => 'ACTIVE', 'is_system_administrator' => false]);
        $admin->roles()->sync([Role::query()->where('slug', RoleCapabilityMatrix::ROLE_ADMIN)->sole()->id]);
        $this->seed(EmergencyTriageVocabularySeeder::class);
        $categories = EmergencyTriageVocabularySeeder::catalogue();
        [$categories[0], $categories[1]] = [$categories[1], $categories[0]];

        try {
            app(EmergencyTriageVocabularyService::class)->revise(EmergencyTriageVocabulary::query()->sole()->public_id, $admin, 1, 'Kategori Triase IGD', $categories, false, 'vocabulary-invalid-revision-0001');
            $this->fail('Fixed order must be rejected.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('fixed_category_violation', $denial->reason);
        }
    }

    public function test_append_only_model_guard_rejects_direct_assessment_mutation(): void
    {
        $assessment = new EmergencyTriageAssessment;
        $this->expectException(LogicException::class);
        $assessment->save();
    }

    public function test_empty_migration_can_down_and_reapply_but_retained_vocabulary_refuses_rollback(): void
    {
        $migration = require base_path('database/migrations/2026_09_01_000400_create_structured_emergency_triage_disposition_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('emergency_dispositions'));
        $this->assertFalse(Schema::hasColumn('laboratory_result_acknowledgements', 'emergency_follow_up_acceptance_id'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('emergency_dispositions'));

        $admin = User::factory()->create(['email' => EmergencyTriageVocabularySeeder::ACTOR_EMAIL, 'status' => 'ACTIVE', 'is_system_administrator' => false]);
        $admin->roles()->sync([Role::query()->where('slug', RoleCapabilityMatrix::ROLE_ADMIN)->sole()->id]);
        $this->seed(EmergencyTriageVocabularySeeder::class);
        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
