<?php

namespace Tests\Feature\Radiology;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\RadiologyOperationReceipt;
use App\Models\RadiologyOrder;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Radiology\RadiologyDenied;
use App\Support\Radiology\RadiologyEvidenceFingerprint;
use App\Support\Radiology\RadiologyMasterService;
use App\Support\Radiology\RadiologyMutationScope;
use App\Support\Radiology\RadiologyProjection;
use App\Support\Radiology\RadiologyWorkflowService;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FinalizesEmergencyInitialTriage;
use Tests\TestCase;

final class RadiologyBackendHardeningTest extends TestCase
{
    use FinalizesEmergencyInitialTriage;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seedEmergencyTriageVocabulary();
    }

    public function test_encounter_projection_is_exact_physician_only_hides_draft_and_nulls_terminal_actions(): void
    {
        [$encounter, $order, $physician, $technologist, $radiologist] = $this->performedOrder();
        $workflow = app(RadiologyWorkflowService::class);
        $workflow->saveDraft($order->public_id, $radiologist, 0, 'Temuan privat.', 'Kesan privat.', null, 'hardening-draft-0001');
        $projection = app(RadiologyProjection::class);

        $this->assertNull($projection->encounter($encounter, $physician)['orders'][0]['report']);
        $this->assertSame('DRAFT', $projection->order($order->fresh(), $radiologist)['report']['state']);
        $this->assertNull($projection->order($order->fresh(), $technologist)['report']);
        $this->assertSame([], $projection->encounter($encounter, $radiologist)['orders']);
        $this->assertSame([], $projection->encounter($encounter, $technologist)['orders']);
        $this->assertSame([], $projection->encounter($encounter, $this->actor(RoleCapabilityMatrix::ROLE_RMIK))['orders']);

        $physician->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_NURSE)->sole()->id);
        $this->assertSame([], $projection->encounter($encounter, $physician->fresh())['orders']);

        $encounter->forceFill(['status' => Encounter::STATUS_CLOSED])->save();
        $actions = $projection->order($order->fresh(), $radiologist)['actions'];
        $this->assertSame([], array_filter($actions));
    }

    public function test_exact_replays_resolve_immutable_results_after_mutable_heads_advance(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $technologist = $this->actor(RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST);
        $radiologist = $this->actor(RoleCapabilityMatrix::ROLE_RADIOLOGIST);
        $masters = app(RadiologyMasterService::class);
        $master = $masters->create($admin, 'RAD-REPLAY', 'Nama awal', null, 'hardening-master-0001')->record;
        $masters->revise($master->public_id, $admin, 1, 'Nama lanjut', null, 'ACTIVE', 'hardening-master-0002');
        $masterReplay = $masters->create($admin, 'RAD-REPLAY', 'Nama awal', null, 'hardening-master-0001');
        $this->assertTrue($masterReplay->replayed);
        $this->assertSame(2, $masterReplay->record->version);

        $encounter = $this->encounter();
        $workflow = app(RadiologyWorkflowService::class);
        $order = $workflow->createOrder($encounter->public_id, $master->public_id, $physician, 'Pertanyaan klinis replay.', 'hardening-order-0001')->record;
        $workflow->perform($order->public_id, $technologist, 1, 'hardening-perform-0001');
        $orderReplay = $workflow->createOrder($encounter->public_id, $master->public_id, $physician, 'Pertanyaan klinis replay.', 'hardening-order-0001');
        $this->assertTrue($orderReplay->replayed);
        $this->assertSame(RadiologyOrder::PERFORMED, $orderReplay->record->status);

        $draftOne = $workflow->saveDraft($order->public_id, $radiologist, 0, 'Temuan satu.', 'Kesan satu.', null, 'hardening-report-0001')->record;
        $workflow->saveDraft($order->public_id, $radiologist, 1, 'Temuan dua.', 'Kesan dua.', null, 'hardening-report-0002');
        $draftReplay = $workflow->saveDraft($order->public_id, $radiologist, 0, 'Temuan satu.', 'Kesan satu.', null, 'hardening-report-0001');
        $this->assertTrue($draftReplay->replayed);
        $this->assertSame($draftOne->public_id, $draftReplay->record->public_id);

        $this->assertSame([
            RadiologyOperationReceipt::RESULT_MASTER,
            RadiologyOperationReceipt::RESULT_MASTER,
            RadiologyOperationReceipt::RESULT_ORDER,
            RadiologyOperationReceipt::RESULT_PERFORMANCE,
            RadiologyOperationReceipt::RESULT_REPORT_VERSION,
            RadiologyOperationReceipt::RESULT_REPORT_VERSION,
        ], RadiologyOperationReceipt::query()->orderBy('id')->pluck('result_type')->all());
        $this->assertTrue(RadiologyOperationReceipt::query()->get()->every(fn ($receipt) => strlen($receipt->result_digest) === 64));
    }

    public function test_fingerprint_recomputes_every_amendment_and_walks_the_prior_chain(): void
    {
        [$encounter, $order, $physician, $technologist, $radiologist] = $this->performedOrder();
        $workflow = app(RadiologyWorkflowService::class);
        $draft = $workflow->saveDraft($order->public_id, $radiologist, 0, 'Temuan.', 'Kesan.', null, 'hardening-chain-draft')->record;
        $verified = $workflow->verify($order->public_id, $radiologist, $draft->version, 'hardening-chain-verify')->record;
        $first = $workflow->amendVerified($order->public_id, $radiologist, $verified->version, 'CLINICAL_CLARIFICATION', 'Klarifikasi pertama.', 'hardening-chain-amend-1')->record;
        $latest = $workflow->amendVerified($order->public_id, $radiologist, $first->version, 'ADDITIONAL_FINDING', 'Klarifikasi kedua.', 'hardening-chain-amend-2')->record;
        $this->assertSame(64, strlen(app(RadiologyEvidenceFingerprint::class)->current($latest)));

        RadiologyMutationScope::run(fn () => DB::table('radiology_report_versions')->where('id', $first->id)->update(['content_digest' => str_repeat('0', 64)]));
        $this->expectException(RadiologyDenied::class);
        $this->expectExceptionMessage('Rantai bukti laporan tidak valid.');
        app(RadiologyEvidenceFingerprint::class)->current($latest);
    }

    public function test_order_snapshot_uses_the_exact_master_version_after_revision(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $masters = app(RadiologyMasterService::class);
        $master = $masters->create($admin, 'RAD-SNAPSHOT', 'Versi satu', null, 'hardening-snapshot-master-1')->record;
        $masters->revise($master->public_id, $admin, 1, 'Versi dua', 'Persiapan dua', 'ACTIVE', 'hardening-snapshot-master-2');
        $version = DB::table('radiology_examination_master_versions')->where('radiology_examination_master_id', $master->id)->where('version', 2)->sole();

        $order = app(RadiologyWorkflowService::class)->createOrder($this->encounter()->public_id, $master->public_id, $physician, 'Pertanyaan snapshot.', 'hardening-snapshot-order')->record;

        $this->assertSame(2, $order->master_version);
        $this->assertSame($version->public_id, $order->master_version_public_id);
        $this->assertSame($version->content_digest, $order->master_content_digest);
        $this->assertSame('Versi dua', $order->master_display_name);
        $this->assertSame('Persiapan dua', $order->master_preparation_instruction);
    }

    public function test_report_receipt_replay_recomputes_retained_fields(): void
    {
        [$encounter, $order, $physician, $technologist, $radiologist] = $this->performedOrder();
        $workflow = app(RadiologyWorkflowService::class);
        $draft = $workflow->saveDraft($order->public_id, $radiologist, 0, 'Temuan asli.', 'Kesan asli.', null, 'hardening-corrupt-report')->record;
        RadiologyMutationScope::run(fn () => DB::table('radiology_report_versions')->where('id', $draft->id)->update(['findings' => 'Temuan diubah.']));

        $this->expectException(RadiologyDenied::class);
        $this->expectExceptionMessage('Digest hasil laporan tidak cocok.');
        $workflow->saveDraft($order->public_id, $radiologist, 0, 'Temuan asli.', 'Kesan asli.', null, 'hardening-corrupt-report');
    }

    public function test_denials_are_audited_before_policy_and_input_checks(): void
    {
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        try {
            app(RadiologyWorkflowService::class)->perform(str_repeat('0', 26), $registrar, 0, 'bad');
            $this->fail('Expected authorization denial.');
        } catch (AuthorizationException) {
            // Expected: actor policy precedes invalid input and resource lookup.
        }

        $this->actingAs($registrar)->post(route('radiology.orders.perform', ['order' => str_repeat('0', 26)]), [
            'expected_version' => 0,
            'idempotency_key' => 'bad',
        ])->assertForbidden();

        $this->assertSame(2, DB::table('audit_events')->where([
            'action' => 'radiology.workflow.mutate',
            'outcome' => 'DENIED',
            'reason' => 'role_not_permitted',
        ])->count());
    }

    public function test_reset_deletes_amendments_before_base_and_only_related_receipts(): void
    {
        [$encounter, $order, $physician, $technologist, $radiologist] = $this->performedOrder();
        $workflow = app(RadiologyWorkflowService::class);
        $draft = $workflow->saveDraft($order->public_id, $radiologist, 0, 'Temuan.', 'Kesan.', null, 'hardening-reset-draft')->record;
        $verified = $workflow->verify($order->public_id, $radiologist, $draft->version, 'hardening-reset-verify')->record;
        $workflow->amendVerified($order->public_id, $radiologist, $verified->version, 'ADDITIONAL_FINDING', 'Tambahan temuan.', 'hardening-reset-amend');

        app(SyntheticResetService::class)->reset(['actor' => $physician, 'reason' => 'radiology_hardening_test']);

        $this->assertDatabaseCount('radiology_orders', 0);
        $this->assertDatabaseCount('radiology_report_versions', 0);
        $this->assertSame(1, RadiologyOperationReceipt::query()->where('result_type', RadiologyOperationReceipt::RESULT_MASTER)->count());
        $this->assertSame(0, RadiologyOperationReceipt::query()->whereNot('result_type', RadiologyOperationReceipt::RESULT_MASTER)->count());
    }

    /** @return array{Encounter,RadiologyOrder,User,User,User} */
    private function performedOrder(): array
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $technologist = $this->actor(RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST);
        $radiologist = $this->actor(RoleCapabilityMatrix::ROLE_RADIOLOGIST);
        $master = app(RadiologyMasterService::class)->create($admin, 'RAD-'.str()->random(8), 'Radiologi uji', null, 'hardening-master-'.str()->random(12))->record;
        $encounter = $this->encounter();
        $order = app(RadiologyWorkflowService::class)->createOrder($encounter->public_id, $master->public_id, $physician, 'Pertanyaan klinis uji.', 'hardening-order-'.str()->random(12))->record;
        app(RadiologyWorkflowService::class)->perform($order->public_id, $technologist, 1, 'hardening-perform-'.str()->random(12));

        return [$encounter, $order->fresh(), $physician, $technologist, $radiologist];
    }

    private function encounter(): Encounter
    {
        $encounter = Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['is_synthetic' => true])->id,
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'status' => Encounter::STATUS_REGISTERED,
        ]);

        return $this->finalizeEmergencyInitialTriage($encounter, keyPrefix: 'radiology-hardening-triage');
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
