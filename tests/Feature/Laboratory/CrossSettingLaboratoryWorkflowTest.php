<?php

namespace Tests\Feature\Laboratory;

use App\Models\Encounter;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryResultVersion;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Laboratory\LaboratoryClosureGate;
use App\Support\Laboratory\LaboratoryDenied;
use App\Support\Laboratory\LaboratoryEvidenceFingerprint;
use App\Support\Laboratory\LaboratoryMasterService;
use App\Support\Laboratory\LaboratoryMutationScope;
use App\Support\Laboratory\LaboratoryProjection;
use App\Support\Laboratory\LaboratorySqlWriteGuard;
use App\Support\Laboratory\LaboratoryWorkflowService;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Concerns\FinalizesEmergencyInitialTriage;
use Tests\Support\ExactEngineTestFixture;
use Tests\TestCase;

final class CrossSettingLaboratoryWorkflowTest extends TestCase
{
    use FinalizesEmergencyInitialTriage;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seedEmergencyTriageVocabulary();
    }

    public function test_rejection_recollection_critical_verification_amendment_and_acknowledgement_chain(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $technologist = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST);
        $verifier = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER);
        $master = $this->master($admin);
        $encounter = $this->encounter(Encounter::CARE_SETTING_INPATIENT);
        $workflow = app(LaboratoryWorkflowService::class);

        $order = $workflow->createOrder($encounter->public_id, $master->public_id, $physician, 'URGENT', 'Evaluasi anemia dan trombosit.', 'lab-order-create-0001')->record;
        $first = $workflow->collectSpecimen($order->public_id, $nurse, 1, 'Koleksi pertama.', 'lab-collect-first-0001')->record;
        $workflow->receiveSpecimen($first->public_id, $technologist, 'lab-receive-first-0001');
        $workflow->rejectSpecimen($first->public_id, $technologist, 1, 'MISLABELLED', 'Label tidak sesuai.', 'lab-reject-first-0001');
        $second = $workflow->collectSpecimen($order->public_id, $nurse, 1, null, 'lab-collect-second-0001')->record;
        $workflow->receiveSpecimen($second->public_id, $technologist, 'lab-receive-second-0001');
        $workflow->acceptSpecimen($second->public_id, $technologist, 1, 'lab-accept-second-0001');

        $draft = $workflow->saveDraft($order->public_id, $technologist, 0, $this->results('6.2', '45'), 'lab-result-draft-0001')->record;
        $verified = $workflow->verify($order->public_id, $verifier, $draft->version, [
            'recipient_user_public_id' => $physician->public_id,
            'communicated_at' => now()->toIso8601String(),
            'communication_method' => 'TELEPHONE',
            'outcome' => 'COMMUNICATED',
            'note' => 'Dibacakan ulang oleh dokter pemesan.',
        ], 'lab-result-verify-0001')->record;

        $gate = app(LaboratoryClosureGate::class);
        $this->assertSame([$order->public_id], $gate->inspect($encounter)['stale_acknowledgement_order_public_ids']);
        $workflow->acknowledge($order->public_id, $physician, $order->fresh()->version, $verified->version, 'lab-result-ack-0001');
        $this->assertSame([], $gate->inspect($encounter)['stale_acknowledgement_order_public_ids']);

        $amendment = $workflow->amendVerified($order->public_id, $verifier, $verified->version, 'TECHNICAL_CORRECTION', $this->results('6.4', '48'), [
            'recipient_user_public_id' => $physician->public_id,
            'communicated_at' => now()->toIso8601String(),
            'communication_method' => 'DIRECT',
            'outcome' => 'COMMUNICATED',
            'note' => 'Amandemen kritis dikomunikasikan kembali.',
        ], 'lab-result-amend-0001')->record;
        $this->assertSame(LaboratoryResultVersion::AMENDED_VERIFIED, $amendment->state);
        $this->assertSame([$order->public_id], $gate->inspect($encounter)['stale_acknowledgement_order_public_ids']);
        $workflow->acknowledge($order->public_id, $physician, $order->fresh()->version, $amendment->version, 'lab-result-ack-0002');

        $facts = $gate->inspect($encounter);
        $this->assertSame([], $facts['active_order_public_ids']);
        $this->assertSame([], $facts['unresolved_specimen_order_public_ids']);
        $this->assertSame([], $facts['stale_acknowledgement_order_public_ids']);
        $this->assertDatabaseCount('laboratory_specimen_attempts', 2);
        $this->assertDatabaseCount('laboratory_specimen_events', 4);
        $this->assertDatabaseCount('laboratory_result_versions', 3);
        $this->assertDatabaseCount('laboratory_result_acknowledgements', 2);
    }

    public function test_replay_survives_mutable_heads_and_rejects_changed_payload(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $tech = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST);
        $master = $this->master($admin);
        $encounter = $this->encounter(Encounter::CARE_SETTING_EMERGENCY);
        $workflow = app(LaboratoryWorkflowService::class);
        $order = $workflow->createOrder($encounter->public_id, $master->public_id, $physician, 'ROUTINE', 'Pertanyaan klinis replay.', 'lab-replay-order-0001')->record;
        $attempt = $workflow->collectSpecimen($order->public_id, $nurse, 1, null, 'lab-replay-collect-0001')->record;
        $workflow->receiveSpecimen($attempt->public_id, $tech, 'lab-replay-receive-0001');
        $workflow->acceptSpecimen($attempt->public_id, $tech, 1, 'lab-replay-accept-0001');

        $replay = $workflow->collectSpecimen($order->public_id, $nurse, 1, null, 'lab-replay-collect-0001');
        $this->assertTrue($replay->replayed);
        $this->assertSame($attempt->public_id, $replay->record->public_id);

        $this->expectException(LaboratoryDenied::class);
        $this->expectExceptionMessage('Kunci idempotensi sudah dipakai.');
        $workflow->collectSpecimen($order->public_id, $nurse, 1, 'payload berubah', 'lab-replay-collect-0001');
    }

    public function test_exact_roles_redact_drafts_and_authorization_precedes_lookup(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $tech = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST);
        $verifier = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER);
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $master = $this->master($admin);
        $encounter = $this->encounter(Encounter::CARE_SETTING_OUTPATIENT);
        $workflow = app(LaboratoryWorkflowService::class);
        $order = $workflow->createOrder($encounter->public_id, $master->public_id, $physician, 'ROUTINE', 'Pemeriksaan darah.', 'lab-redact-order-0001')->record;
        $attempt = $workflow->collectSpecimen($order->public_id, $nurse, 1, null, 'lab-redact-collect-0001')->record;
        $workflow->receiveSpecimen($attempt->public_id, $tech, 'lab-redact-receive-0001');
        $workflow->acceptSpecimen($attempt->public_id, $tech, 1, 'lab-redact-accept-0001');
        $workflow->saveDraft($order->public_id, $tech, 0, $this->results('12.0', '150'), 'lab-redact-draft-0001');
        $projection = app(LaboratoryProjection::class);
        $this->assertNull($projection->order($order->fresh(), $physician)['result']);
        $this->assertNull($projection->order($order->fresh(), $nurse)['result']);
        $this->assertSame('DRAFT', $projection->order($order->fresh(), $tech)['result']['state']);
        $this->assertSame('DRAFT', $projection->order($order->fresh(), $verifier)['result']['state']);

        try {
            $workflow->receiveSpecimen(str_repeat('0', 26), $registrar, 'lab-denial-role-0001');
            $this->fail('Expected authorization denial.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertDatabaseHas('audit_events', ['action' => 'laboratory.workflow.mutate', 'outcome' => 'DENIED', 'reason' => 'role_not_permitted']);
    }

    public function test_fingerprint_detects_master_snapshot_and_specimen_event_corruption(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $tech = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST);
        $master = $this->master($admin);
        $workflow = app(LaboratoryWorkflowService::class);
        $order = $workflow->createOrder($this->encounter(Encounter::CARE_SETTING_EMERGENCY)->public_id, $master->public_id, $physician, 'ROUTINE', 'Uji integritas.', 'lab-integrity-order-0001')->record;
        $attempt = $workflow->collectSpecimen($order->public_id, $nurse, 1, null, 'lab-integrity-collect-0001')->record;
        $workflow->receiveSpecimen($attempt->public_id, $tech, 'lab-integrity-receive-0001');
        $workflow->acceptSpecimen($attempt->public_id, $tech, 1, 'lab-integrity-accept-0001');

        ExactEngineTestFixture::corruptWithPostgresTriggersDisabled(
            ['laboratory_specimen_events'],
            function () use ($attempt): void {
                LaboratoryMutationScope::run(fn () => DB::table('laboratory_specimen_events')->where('laboratory_specimen_attempt_id', $attempt->id)->where('event_type', 'RECEIVED')->update(['event_type' => 'REJECTED', 'reason_code' => 'OTHER']));
                $this->expectException(LaboratoryDenied::class);
                $this->expectExceptionMessage('Rantai peristiwa spesimen tidak lengkap.');
                app(LaboratoryEvidenceFingerprint::class)->verifySpecimenEventChain($attempt->fresh());
            },
        );
    }

    public function test_current_result_fingerprint_binds_order_context_result_attribution_and_specimen_ownership(): void
    {
        [$workflow, $order, $physician, $tech, $verifier] = $this->acceptedOrder();
        $draft = $workflow->saveDraft($order->public_id, $tech, 0, $this->normalResults(), 'lab-fingerprint-draft-0001')->record;
        $verified = $workflow->verify($order->public_id, $verifier, $draft->version, null, 'lab-fingerprint-verify-0001')->record;
        $fingerprints = app(LaboratoryEvidenceFingerprint::class);
        $expected = $fingerprints->current($verified->fresh());

        ExactEngineTestFixture::corruptWithPostgresTriggersDisabled(
            ['laboratory_orders'],
            function () use ($order, $expected, $fingerprints, $verified): void {
                LaboratoryMutationScope::run(fn () => DB::table('laboratory_orders')->where('id', $order->id)->update(['care_location_label_snapshot' => 'Lokasi yang diubah']));
                $this->assertNotSame($expected, $fingerprints->current($verified->fresh()));
                LaboratoryMutationScope::run(fn () => DB::table('laboratory_orders')->where('id', $order->id)->update(['care_location_label_snapshot' => $order->care_location_label_snapshot]));
            },
        );

        ExactEngineTestFixture::corruptWithPostgresTriggersDisabled(
            ['laboratory_result_versions'],
            function () use ($verified, $physician, $expected, $fingerprints, $verifier): void {
                LaboratoryMutationScope::run(fn () => DB::table('laboratory_result_versions')->where('id', $verified->id)->update(['author_user_id' => $physician->id]));
                $this->assertNotSame($expected, $fingerprints->current($verified->fresh()));
                LaboratoryMutationScope::run(fn () => DB::table('laboratory_result_versions')->where('id', $verified->id)->update(['author_user_id' => $verifier->id]));
            },
        );

        [, $otherOrder] = $this->acceptedOrder();
        $otherSpecimenId = $otherOrder->specimenAttempts()->where('state', 'ACCEPTED')->sole()->id;
        ExactEngineTestFixture::corruptWithPostgresTriggersDisabled(
            ['laboratory_result_versions'],
            function () use ($verified, $otherSpecimenId, $fingerprints): void {
                LaboratoryMutationScope::run(fn () => DB::table('laboratory_result_versions')->where('id', $verified->id)->update(['laboratory_specimen_attempt_id' => $otherSpecimenId]));
                try {
                    $fingerprints->current($verified->fresh());
                    $this->fail('Expected cross-order specimen evidence denial.');
                } catch (LaboratoryDenied $denial) {
                    $this->assertSame('evidence_fingerprint_invalid', $denial->reason);
                }
            },
        );
    }

    public function test_sql_guard_rejects_write_capable_and_ambiguous_statements(): void
    {
        $guard = app(LaboratorySqlWriteGuard::class);
        foreach (['UPDATE laboratory_orders SET status = \'CANCELLED\'', 'COPY laboratory_orders TO STDOUT', 'WITH changed AS (DELETE FROM laboratory_orders RETURNING *) SELECT * FROM changed', 'SELECT * FROM laboratory_orders; DELETE FROM laboratory_orders'] as $sql) {
            try {
                $guard->assertAllowed($sql);
                $this->fail("Expected guard rejection for {$sql}");
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        $guard->assertAllowed('SELECT * FROM laboratory_orders WHERE id = 1');
        $this->assertTrue(true);
    }

    public function test_ready_for_rm_outpatient_and_inpatient_orders_can_resolve_then_become_terminal(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $technologist = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST);
        $verifier = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER);
        $master = $this->master($admin);
        $workflow = app(LaboratoryWorkflowService::class);
        $projection = app(LaboratoryProjection::class);
        $gate = app(LaboratoryClosureGate::class);

        foreach ([Encounter::CARE_SETTING_OUTPATIENT, Encounter::CARE_SETTING_INPATIENT] as $index => $careSetting) {
            $encounter = $this->encounter($careSetting);
            $order = $workflow->createOrder($encounter->public_id, $master->public_id, $physician, 'ROUTINE', 'Selesaikan sebelum penutupan.', "lab-ready-order-000{$index}")->record;
            $encounter->update(['status' => Encounter::STATUS_READY_FOR_RM]);
            $this->assertNull($projection->encounter($encounter->fresh(), $physician)['commands']['create_order_url']);
            $this->assertNotNull($projection->order($order->fresh(), $nurse)['actions']['collect_url']);

            $attempt = $workflow->collectSpecimen($order->public_id, $nurse, 1, null, "lab-ready-collect-000{$index}")->record;
            $this->assertNotNull($projection->order($order->fresh(), $technologist)['actions']['receive_url']);
            $workflow->receiveSpecimen($attempt->public_id, $technologist, "lab-ready-receive-000{$index}");
            $workflow->acceptSpecimen($attempt->public_id, $technologist, 1, "lab-ready-accept-000{$index}");
            $draft = $workflow->saveDraft($order->public_id, $technologist, 0, [
                ['code' => 'HGB', 'value' => '12.0', 'note' => null, 'interpretation' => 'NORMAL'],
                ['code' => 'PLT', 'value' => '150', 'note' => null, 'interpretation' => 'NORMAL'],
            ], "lab-ready-draft-000{$index}")->record;
            $this->assertNotNull($projection->order($order->fresh(), $verifier)['actions']['verify_result_url']);
            $verified = $workflow->verify($order->public_id, $verifier, $draft->version, null, "lab-ready-verify-000{$index}")->record;
            $this->assertNotNull($projection->order($order->fresh(), $physician)['actions']['acknowledge_url']);
            $workflow->acknowledge($order->public_id, $physician, $order->fresh()->version, $verified->version, "lab-ready-ack-000{$index}");
            $this->assertSame([
                'active_order_public_ids' => [],
                'unresolved_specimen_order_public_ids' => [],
                'stale_acknowledgement_order_public_ids' => [],
            ], $gate->inspect($encounter));

            $encounter->update(['status' => Encounter::STATUS_CLOSED]);
            $actions = $projection->order($order->fresh(), $verifier)['actions'];
            $this->assertTrue(collect($actions)->every(fn ($url): bool => $url === null));
            try {
                $workflow->amendVerified($order->public_id, $verifier, $verified->version, 'TECHNICAL_CORRECTION', $verified->results, null, "lab-closed-amend-000{$index}");
                $this->fail('Closed encounter must reject laboratory mutation.');
            } catch (LaboratoryDenied $denial) {
                $this->assertSame('encounter_closed', $denial->reason);
            }
        }
    }

    public function test_master_revision_stale_write_and_terminal_retirement(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $masters = app(LaboratoryMasterService::class);
        $master = $this->master($admin);
        $revised = $masters->revise($master->public_id, $admin, 1, 'Darah lengkap revisi', 'Darah EDTA', 'Instruksi revisi.', $master->components, 'ACTIVE', 'lab-master-revise-0001')->record;
        $this->assertSame(2, $revised->version);
        try {
            $masters->revise($master->public_id, $admin, 1, 'Stale', 'Darah', null, $master->components, 'ACTIVE', 'lab-master-stale-0001');
            $this->fail('Expected stale version denial.');
        } catch (LaboratoryDenied $denial) {
            $this->assertSame('stale_version', $denial->reason);
        }
        $retired = $masters->revise($master->public_id, $admin, 2, 'Darah lengkap revisi', 'Darah EDTA', 'Instruksi revisi.', $master->components, 'RETIRED', 'lab-master-retire-0001')->record;
        $this->assertSame('RETIRED', $retired->state);
        try {
            $masters->revise($master->public_id, $admin, 3, 'Tidak boleh', 'Darah', null, $master->components, 'ACTIVE', 'lab-master-reopen-0001');
            $this->fail('Expected terminal retirement denial.');
        } catch (LaboratoryDenied $denial) {
            $this->assertSame('master_retired', $denial->reason);
        }
        $this->assertDatabaseCount('laboratory_examination_master_versions', 3);
    }

    public function test_order_cancellation_is_owner_only_and_stops_after_specimen_evidence(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $owner = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $other = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $master = $this->master($admin);
        $workflow = app(LaboratoryWorkflowService::class);
        $cancelled = $workflow->createOrder($this->encounter(Encounter::CARE_SETTING_OUTPATIENT)->public_id, $master->public_id, $owner, 'ROUTINE', 'Pesanan dibatalkan.', 'lab-cancel-order-0001')->record;
        try {
            $workflow->cancel($cancelled->public_id, $other, 1, 'ORDERING_ERROR', null, 'lab-cancel-other-0001');
            $this->fail('Expected owner denial.');
        } catch (LaboratoryDenied $denial) {
            $this->assertSame('cancellation_not_permitted', $denial->reason);
        }
        $workflow->cancel($cancelled->public_id, $owner, 1, 'CLINICAL_PLAN_CHANGED', null, 'lab-cancel-owner-0001');
        $this->assertSame(LaboratoryOrder::CANCELLED, $cancelled->fresh()->status);

        $withSpecimen = $workflow->createOrder($this->encounter(Encounter::CARE_SETTING_EMERGENCY)->public_id, $master->public_id, $owner, 'ROUTINE', 'Pesanan dengan spesimen.', 'lab-cancel-order-0002')->record;
        $workflow->collectSpecimen($withSpecimen->public_id, $nurse, 1, null, 'lab-cancel-collect-0002');
        try {
            $workflow->cancel($withSpecimen->public_id, $owner, 1, 'ORDERING_ERROR', null, 'lab-cancel-after-specimen-0002');
            $this->fail('Expected specimen evidence denial.');
        } catch (LaboratoryDenied $denial) {
            $this->assertSame('specimen_evidence_exists', $denial->reason);
        }
    }

    public function test_critical_communication_is_explicit_exact_and_not_allowed_for_noncritical_results(): void
    {
        [$workflow, $order, $physician, $tech, $verifier] = $this->acceptedOrder();
        $criticalDraft = $workflow->saveDraft($order->public_id, $tech, 0, $this->results('6.0', '40'), 'lab-critical-draft-0001')->record;
        try {
            $workflow->verify($order->public_id, $verifier, $criticalDraft->version, null, 'lab-critical-missing-0001');
            $this->fail('Expected communication requirement.');
        } catch (LaboratoryDenied $denial) {
            $this->assertSame('critical_communication_required', $denial->reason);
        }
        $otherPhysician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        try {
            $workflow->verify($order->public_id, $verifier, $criticalDraft->version, ['recipient_user_public_id' => $otherPhysician->public_id, 'communicated_at' => now()->toIso8601String(), 'communication_method' => 'DIRECT', 'outcome' => 'COMMUNICATED'], 'lab-critical-wrong-recipient-0001');
            $this->fail('Expected recipient mismatch.');
        } catch (LaboratoryDenied $denial) {
            $this->assertSame('critical_recipient_mismatch', $denial->reason);
        }
        try {
            $workflow->verify($order->public_id, $verifier, $criticalDraft->version, ['recipient_user_public_id' => $physician->public_id, 'communication_method' => 'DIRECT', 'outcome' => 'COMMUNICATED'], 'lab-critical-missing-time-0001');
            $this->fail('Expected explicit time validation.');
        } catch (LaboratoryDenied $denial) {
            $this->assertSame('validation_failed', $denial->reason);
        }
        try {
            $workflow->verify($order->public_id, $verifier, $criticalDraft->version, ['recipient_user_public_id' => $physician->public_id, 'communicated_at' => $criticalDraft->created_at->subSecond()->toIso8601String(), 'communication_method' => 'DIRECT', 'outcome' => 'COMMUNICATED'], 'lab-critical-before-draft-0001');
            $this->fail('Expected communication chronology denial.');
        } catch (LaboratoryDenied $denial) {
            $this->assertSame('validation_failed', $denial->reason);
        }
        $coveringPhysician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $verified = $workflow->verify($order->public_id, $verifier, $criticalDraft->version, [
            'recipient_user_public_id' => $coveringPhysician->public_id,
            'communicated_at' => now()->toIso8601String(),
            'communication_method' => 'TELEPHONE',
            'outcome' => 'ESCALATED',
            'note' => 'Dokter pemesan tidak tersedia; diteruskan kepada dokter jaga.',
        ], 'lab-critical-covering-physician-0001')->record;
        $communication = $verified->criticalCommunication()->sole();
        $this->assertSame($verifier->id, $communication->actor_user_id);
        $this->assertSame($coveringPhysician->id, $communication->recipient_user_id);
        $this->assertSame('ESCALATED', $communication->outcome);
        $this->assertNotSame('', app(LaboratoryEvidenceFingerprint::class)->current($verified));

        [$otherWorkflow, $otherOrder, $otherOwner, $otherTech, $otherVerifier] = $this->acceptedOrder();
        $normalDraft = $otherWorkflow->saveDraft($otherOrder->public_id, $otherTech, 0, $this->normalResults(), 'lab-normal-draft-0001')->record;
        try {
            $otherWorkflow->verify($otherOrder->public_id, $otherVerifier, $normalDraft->version, ['recipient_user_public_id' => $otherOwner->public_id, 'communicated_at' => now()->toIso8601String(), 'communication_method' => 'DIRECT', 'outcome' => 'COMMUNICATED'], 'lab-normal-extra-communication-0001');
            $this->fail('Expected noncritical communication denial.');
        } catch (LaboratoryDenied $denial) {
            $this->assertSame('validation_failed', $denial->reason);
        }
    }

    public function test_normal_result_can_be_amended_to_critical_with_immutable_communication_and_acknowledgement(): void
    {
        [$workflow, $order, $physician, $tech, $verifier] = $this->acceptedOrder();
        $draft = $workflow->saveDraft($order->public_id, $tech, 0, $this->normalResults(), 'lab-amend-normal-draft-0001')->record;
        $verified = $workflow->verify($order->public_id, $verifier, $draft->version, null, 'lab-amend-normal-verify-0001')->record;
        try {
            $workflow->amendVerified($order->public_id, $verifier, $verified->version, 'TECHNICAL_CORRECTION', $this->results('6.0', '40'), null, 'lab-amend-introduce-critical-0001');
            $this->fail('Expected critical amendment denial.');
        } catch (LaboratoryDenied $denial) {
            $this->assertSame('critical_communication_required', $denial->reason);
        }
        $this->assertDatabaseCount('laboratory_result_versions', 2);

        $amendment = $workflow->amendVerified($order->public_id, $verifier, $verified->version, 'TECHNICAL_CORRECTION', $this->results('6.0', '40'), [
            'recipient_user_public_id' => $physician->public_id,
            'communicated_at' => now()->toIso8601String(),
            'communication_method' => 'TELEPHONE',
            'outcome' => 'COMMUNICATED',
            'note' => 'Nilai kritis amandemen dibacakan ulang.',
        ], 'lab-amend-introduce-critical-0002')->record;
        $communication = $amendment->criticalCommunication()->sole();
        $this->assertSame($amendment->id, $communication->laboratory_result_version_id);
        $this->assertSame($verifier->id, $communication->actor_user_id);
        $workflow->acknowledge($order->public_id, $physician, $order->fresh()->version, $amendment->version, 'lab-amend-critical-ack-0001');
        $projected = app(LaboratoryProjection::class)->order($order->fresh(), $physician);
        $this->assertSame('Tersampaikan', $projected['result']['amendments'][0]['critical_communication']['outcome_label']);
        $this->assertSame('Nilai kritis amandemen dibacakan ulang.', $projected['result']['amendments'][0]['critical_communication']['note']);
        $this->assertNotSame('', app(LaboratoryEvidenceFingerprint::class)->current($amendment));

        try {
            $workflow->amendVerified($order->public_id, $verifier, $amendment->version, 'VERIFIER_CLARIFICATION', $this->results('6.1', '42'), null, 'lab-amend-critical-changed-missing-0001');
            $this->fail('Expected fresh communication for every critical amendment.');
        } catch (LaboratoryDenied $denial) {
            $this->assertSame('critical_communication_required', $denial->reason);
        }
        $secondAmendment = $workflow->amendVerified($order->public_id, $verifier, $amendment->version, 'VERIFIER_CLARIFICATION', $this->results('6.1', '42'), [
            'recipient_user_public_id' => $physician->public_id,
            'communicated_at' => now()->toIso8601String(),
            'communication_method' => 'DIRECT',
            'outcome' => 'COMMUNICATED',
            'note' => 'Perubahan nilai kritis kedua disampaikan.',
        ], 'lab-amend-critical-changed-accepted-0001')->record;
        $this->assertSame($secondAmendment->id, $secondAmendment->criticalCommunication()->sole()->laboratory_result_version_id);
        $workflow->acknowledge($order->public_id, $physician, $order->fresh()->version, $secondAmendment->version, 'lab-amend-critical-ack-0002');
        $projected = app(LaboratoryProjection::class)->order($order->fresh(), $physician);
        $this->assertSame('Nilai kritis amandemen dibacakan ulang.', $projected['result']['amendments'][0]['critical_communication']['note']);
        $this->assertSame('Perubahan nilai kritis kedua disampaikan.', $projected['result']['amendments'][1]['critical_communication']['note']);
        $this->assertNotSame('', app(LaboratoryEvidenceFingerprint::class)->current($secondAmendment));
    }

    public function test_worklist_projects_only_bounded_active_exact_physician_critical_communication_recipients(): void
    {
        $verifier = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER);
        $first = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $second = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $mixed = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $mixed->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_NURSE)->sole()->id);
        $inactive = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $inactive->forceFill(['status' => 'INACTIVE'])->save();

        $this->actingAs($verifier)->get(route('pemeriksaan.laboratorium.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('critical_communication_recipient_options', 2)
                ->where('critical_communication_recipient_options', fn ($options): bool => collect($options)->pluck('value')->sort()->values()->all() === collect([$first->public_id, $second->public_id])->sort()->values()->all()));
    }

    public function test_exact_technologist_shift_handover_appends_a_new_draft_with_correct_provenance(): void
    {
        [$workflow, $order, $physician, $firstTechnologist, $verifier] = $this->acceptedOrder();
        $secondTechnologist = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST);
        $first = $workflow->saveDraft($order->public_id, $firstTechnologist, 0, $this->normalResults(), 'lab-handover-draft-first-0001')->record;
        $second = $workflow->saveDraft($order->public_id, $secondTechnologist, $first->version, $this->normalResults(), 'lab-handover-draft-second-0001')->record;
        $verified = $workflow->verify($order->public_id, $verifier, $second->version, null, 'lab-handover-verify-0001')->record;

        $this->assertSame($firstTechnologist->id, $first->author_user_id);
        $this->assertSame($secondTechnologist->id, $second->author_user_id);
        $this->assertSame($verifier->id, $verified->author_user_id);
        $projection = app(LaboratoryProjection::class)->order($order->fresh(), $physician);
        $this->assertSame($secondTechnologist->name, $projection['result']['author_name']);
        $this->assertSame($second->created_at->toIso8601String(), $projection['result']['saved_at']);
        $this->assertSame($verifier->name, $projection['result']['verifier_name']);
    }

    public function test_terminal_encounters_mixed_roles_and_system_admin_flags_never_bypass_policy(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $master = $this->master($admin);
        $workflow = app(LaboratoryWorkflowService::class);
        $closed = $this->encounter(Encounter::CARE_SETTING_INPATIENT);
        $closed->forceFill(['status' => Encounter::STATUS_CLOSED])->save();
        try {
            $workflow->createOrder($closed->public_id, $master->public_id, $physician, 'ROUTINE', 'Episode ditutup.', 'lab-terminal-closed-0001');
            $this->fail('Expected closed encounter denial.');
        } catch (LaboratoryDenied $denial) {
            $this->assertSame('encounter_not_eligible', $denial->reason);
        }
        $cancelled = $this->encounter(Encounter::CARE_SETTING_EMERGENCY);
        $cancelled->forceFill(['status' => Encounter::STATUS_CANCELLED])->save();
        try {
            $workflow->createOrder($cancelled->public_id, $master->public_id, $physician, 'ROUTINE', 'Episode dibatalkan.', 'lab-terminal-cancelled-0001');
            $this->fail('Expected cancelled encounter denial.');
        } catch (LaboratoryDenied $denial) {
            $this->assertSame('encounter_cancelled', $denial->reason);
        }

        $physician->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_NURSE)->sole()->id);
        try {
            $workflow->createOrder($this->encounter(Encounter::CARE_SETTING_OUTPATIENT)->public_id, $master->public_id, $physician->fresh(), 'ROUTINE', 'Akun multi peran.', 'lab-mixed-role-0001');
            $this->fail('Expected mixed-role denial.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $systemAdmin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $systemAdmin->forceFill(['is_system_administrator' => true])->save();
        app(LaboratoryMasterService::class)->create(
            $systemAdmin->fresh(),
            'LAB-SYSTEM-ALLOWED',
            'Boleh sebagai system administrator',
            'Darah',
            null,
            [['code' => 'X1', 'display_name' => 'X', 'value_kind' => 'TEXT', 'unit_text' => null, 'reference_text' => null, 'critical_allowed' => false]],
            'lab-system-admin-allowed-0001',
        );
    }

    public function test_receipt_replay_detects_retained_snapshot_corruption_and_reset_preserves_master_evidence(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $master = $this->master($admin);
        $encounter = $this->encounter(Encounter::CARE_SETTING_OUTPATIENT);
        $workflow = app(LaboratoryWorkflowService::class);
        $order = $workflow->createOrder($encounter->public_id, $master->public_id, $physician, 'ROUTINE', 'Integritas receipt.', 'lab-corrupt-order-0001')->record;
        ExactEngineTestFixture::corruptWithPostgresTriggersDisabled(
            ['laboratory_orders'],
            function () use ($order, $workflow, $encounter, $master, $physician): void {
                LaboratoryMutationScope::run(fn () => DB::table('laboratory_orders')->where('id', $order->id)->update(['master_display_name' => 'Korup']));
                try {
                    $workflow->createOrder($encounter->public_id, $master->public_id, $physician, 'ROUTINE', 'Integritas receipt.', 'lab-corrupt-order-0001');
                    $this->fail('Expected receipt corruption denial.');
                } catch (LaboratoryDenied $denial) {
                    $this->assertSame('evidence_fingerprint_invalid', $denial->reason);
                }
                LaboratoryMutationScope::run(fn () => DB::table('laboratory_orders')->where('id', $order->id)->update(['master_display_name' => $master->display_name]));
            },
        );
        app(SyntheticResetService::class)->reset(['actor' => $physician, 'reason' => 'laboratory_core_test']);
        $this->assertDatabaseCount('laboratory_orders', 0);
        $this->assertDatabaseCount('laboratory_examination_masters', 1);
        $this->assertDatabaseCount('laboratory_operation_receipts', 1);
    }

    private function master(User $admin)
    {
        return app(LaboratoryMasterService::class)->create($admin, 'LAB-'.str()->upper(str()->random(8)), 'Darah lengkap uji', 'Darah EDTA', 'Koleksi sesuai prosedur.', [
            ['code' => 'HGB', 'display_name' => 'Hemoglobin', 'value_kind' => 'NUMERIC', 'unit_text' => 'g/dL', 'reference_text' => 'Rujukan unit.', 'critical_allowed' => true],
            ['code' => 'PLT', 'display_name' => 'Trombosit', 'value_kind' => 'NUMERIC', 'unit_text' => '10^3/uL', 'reference_text' => 'Rujukan unit.', 'critical_allowed' => true],
        ], 'lab-master-'.str()->random(12))->record;
    }

    /** @return list<array<string,mixed>> */
    private function results(string $hgb, string $platelet): array
    {
        return [['code' => 'HGB', 'value' => $hgb, 'note' => null, 'interpretation' => 'ABNORMAL'], ['code' => 'PLT', 'value' => $platelet, 'note' => 'Nilai kritis manual.', 'interpretation' => 'CRITICAL']];
    }

    /** @return list<array<string,mixed>> */
    private function normalResults(): array
    {
        return [['code' => 'HGB', 'value' => '13.2', 'note' => null, 'interpretation' => 'NORMAL'], ['code' => 'PLT', 'value' => '220', 'note' => null, 'interpretation' => 'NORMAL']];
    }

    /** @return array{LaboratoryWorkflowService,LaboratoryOrder,User,User,User} */
    private function acceptedOrder(): array
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $tech = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST);
        $verifier = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER);
        $workflow = app(LaboratoryWorkflowService::class);
        $order = $workflow->createOrder($this->encounter(Encounter::CARE_SETTING_EMERGENCY)->public_id, $this->master($admin)->public_id, $physician, 'ROUTINE', 'Persiapan hasil uji.', 'lab-helper-order-'.str()->random(12))->record;
        $attempt = $workflow->collectSpecimen($order->public_id, $nurse, 1, null, 'lab-helper-collect-'.str()->random(12))->record;
        $workflow->receiveSpecimen($attempt->public_id, $tech, 'lab-helper-receive-'.str()->random(12));
        $workflow->acceptSpecimen($attempt->public_id, $tech, 1, 'lab-helper-accept-'.str()->random(12));

        return [$workflow, $order->fresh(), $physician, $tech, $verifier];
    }

    private function encounter(string $careSetting): Encounter
    {
        $encounter = Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['is_synthetic' => true])->id,
            'care_setting' => $careSetting,
            'status' => $careSetting === Encounter::CARE_SETTING_EMERGENCY
                ? Encounter::STATUS_REGISTERED
                : Encounter::STATUS_IN_EXAMINATION,
        ]);
        if ($careSetting === Encounter::CARE_SETTING_EMERGENCY) {
            return $this->finalizeEmergencyInitialTriage($encounter, keyPrefix: 'lab-fixture-triage');
        }

        return $encounter->fresh();
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
