<?php

namespace Tests\Feature\Finance;

use App\Models\Encounter;
use App\Models\FinanceBill;
use App\Models\FinanceBillVersion;
use App\Models\FinanceChargeEvent;
use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceLaboratorySourceEvent;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItem;
use App\Models\LaboratoryExaminationMaster;
use App\Models\LaboratoryExaminationMasterVersion;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryResultVersion;
use App\Models\LaboratorySpecimenAttempt;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceBillService;
use App\Support\Finance\FinanceDenied;
use App\Support\Finance\FinanceLaboratorySourceAdapter;
use App\Support\Finance\FinanceLaboratoryTariffBindingService;
use App\Support\Finance\FinanceMutationScope;
use App\Support\Finance\FinanceProjection;
use App\Support\Finance\FinanceSourceCoordinator;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Laboratory\LaboratoryMasterService;
use App\Support\Laboratory\LaboratoryMutationScope;
use App\Support\Laboratory\LaboratoryWorkflowService;
use App\Support\Operations\SyntheticRecoverySnapshot;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use RuntimeException;
use Tests\Concerns\FinalizesEmergencyInitialTriage;
use Tests\TestCase;

final class FinanceLaboratorySourceAdapterTest extends TestCase
{
    use FinalizesEmergencyInitialTriage;
    use RefreshDatabase;

    private User $admin;

    private User $physician;

    private User $nurse;

    private User $technologist;

    private User $verifier;

    private User $cashier;

    private User $steward;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-02 10:00:00');
        $this->seed(RbacSeeder::class);
        $this->seedEmergencyTriageVocabulary();
        $this->admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $this->physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $this->nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $this->technologist = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST);
        $this->verifier = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER);
        $this->cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $this->steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $this->app->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            /** @param array<string, mixed> $metadata */
            public function record(string $action, string $resourceType, ?string $resourceId = null, ?User $actor = null, string $outcome = 'SUCCESS', ?string $reason = null, array $metadata = [], ?Request $request = null, bool $includeRequestFingerprint = true): AuditEvent
            {
                return new AuditEvent;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_original_verified_result_materializes_one_typed_source_and_charge(): void
    {
        [$masterVersion, $order, $encounter, $workflow] = $this->acceptedOrder();
        $this->bind($masterVersion, $this->tariff(125000));
        $adapter = app(FinanceLaboratorySourceAdapter::class);

        $this->synchronize($adapter, $encounter);
        $this->assertDatabaseCount('finance_laboratory_source_events', 0);

        $draft = $workflow->saveDraft(
            $order->public_id, $this->technologist, 0, $this->normalResults(), 'lab-fin-draft-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $draft);
        $this->synchronize($adapter, $encounter);
        $this->assertDatabaseCount('finance_laboratory_source_events', 0);

        $verified = $workflow->verify(
            $order->public_id, $this->verifier, $draft->version, null, 'lab-fin-verify-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $verified);
        $events = $this->synchronize($adapter, $encounter);

        $this->assertCount(1, $events);
        $source = FinanceLaboratorySourceEvent::query()->sole();
        $this->assertSame($verified->id, $source->laboratory_result_version_id);
        $this->assertSame(LaboratoryResultVersion::VERIFIED, $verified->state);
        $this->assertNull($verified->base_verified_version_id);
        $this->assertSame($verified->verified_at->toDateTimeString(), $source->verified_at->toDateTimeString());
        $this->assertSame('2026-09-02', $source->service_date->format('Y-m-d'));
        $this->assertSame(125000, $source->signed_amount);
        $this->assertNull($source->laboratory_critical_communication_id);
        $this->assertDatabaseHas('finance_charge_events', [
            'finance_laboratory_source_event_id' => $source->id,
            'pharmacy_financial_source_event_id' => null,
            'finance_radiology_source_event_id' => null,
            'source_domain' => 'LABORATORY',
            'source_table' => 'finance_laboratory_source_events',
            'signed_amount' => 125000,
        ]);

        $this->synchronize($adapter, $encounter);
        $this->assertDatabaseCount('finance_laboratory_source_events', 1);
        $this->assertDatabaseCount('finance_charge_events', 1);
    }

    public function test_original_verified_result_resolves_in_all_three_care_settings(): void
    {
        $amount = 51000;
        foreach (Encounter::CARE_SETTINGS as $index => $careSetting) {
            [$masterVersion, $order, $encounter, $workflow] = $this->acceptedOrder(careSetting: $careSetting);
            $this->bind($masterVersion, $this->tariff($amount + $index, $careSetting), $careSetting);
            $draft = $workflow->saveDraft(
                $order->public_id, $this->technologist, 0, $this->normalResults(),
                'lab-setting-draft-'.$index.'-0001',
            )->record;
            $this->assertInstanceOf(LaboratoryResultVersion::class, $draft);
            $verified = $workflow->verify(
                $order->public_id, $this->verifier, $draft->version, null,
                'lab-setting-verify-'.$index.'-0001',
            )->record;
            $this->assertInstanceOf(LaboratoryResultVersion::class, $verified);

            $events = $this->synchronize(app(FinanceLaboratorySourceAdapter::class), $encounter);
            $this->assertCount(1, $events);
            $source = FinanceLaboratorySourceEvent::query()
                ->where('laboratory_result_version_id', $verified->id)->sole();
            $this->assertSame($careSetting, $source->care_setting);
            $this->assertSame($amount + $index, $source->unit_amount);
        }
    }

    public function test_rejected_recollection_and_required_original_critical_communication_create_one_source(): void
    {
        [$masterVersion, $order, $encounter, $workflow] = $this->acceptedOrder(recollect: true);
        $this->bind($masterVersion, $this->tariff(97500));
        $draft = $workflow->saveDraft(
            $order->public_id, $this->technologist, 0, $this->criticalResults(), 'lab-critical-draft-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $draft);
        $verified = $workflow->verify($order->public_id, $this->verifier, $draft->version, [
            'recipient_user_public_id' => $this->physician->public_id,
            'communicated_at' => now()->toIso8601String(),
            'communication_method' => 'TELEPHONE',
            'outcome' => 'COMMUNICATED',
            'note' => 'Nilai kritis dibacakan ulang.',
        ], 'lab-critical-verify-0001')->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $verified);

        $this->synchronize(app(FinanceLaboratorySourceAdapter::class), $encounter);
        $source = FinanceLaboratorySourceEvent::query()->sole();
        $communication = $verified->criticalCommunication()->sole();
        $this->assertSame($communication->id, $source->laboratory_critical_communication_id);
        $this->assertSame($communication->public_id, $source->critical_communication_public_id);
        $this->assertSame($communication->content_digest, $source->critical_communication_content_digest);
        $this->assertDatabaseCount('laboratory_specimen_attempts', 2);
        $this->assertDatabaseCount('finance_laboratory_source_events', 1);
    }

    public function test_amendment_and_acknowledgement_do_not_duplicate_or_revalue_original_source(): void
    {
        [$masterVersion, $order, $encounter, $workflow] = $this->acceptedOrder();
        $this->bind($masterVersion, $this->tariff(88000));
        $draft = $workflow->saveDraft(
            $order->public_id, $this->technologist, 0, $this->normalResults(), 'lab-stable-draft-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $draft);
        $verified = $workflow->verify(
            $order->public_id, $this->verifier, $draft->version, null, 'lab-stable-verify-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $verified);
        $adapter = app(FinanceLaboratorySourceAdapter::class);
        $this->synchronize($adapter, $encounter);
        $source = FinanceLaboratorySourceEvent::query()->sole();
        $originalDigest = $source->content_digest;

        $workflow->acknowledge(
            $order->public_id, $this->physician, $order->fresh()->version,
            $verified->version, 'lab-stable-ack-0001',
        );
        $amendment = $workflow->amendVerified(
            $order->public_id, $this->verifier, $verified->version, 'TECHNICAL_CORRECTION',
            [['code' => 'HGB', 'value' => '13.4', 'note' => 'Koreksi.', 'interpretation' => 'NORMAL']],
            null, 'lab-stable-amend-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $amendment);
        $this->assertSame(LaboratoryResultVersion::AMENDED_VERIFIED, $amendment->state);

        $events = $this->synchronize($adapter, $encounter);
        $adapter->verifyRetained($encounter, $events);
        $this->assertDatabaseCount('finance_laboratory_source_events', 1);
        $this->assertDatabaseCount('finance_charge_events', 1);
        $this->assertSame($verified->id, FinanceLaboratorySourceEvent::query()->sole()->laboratory_result_version_id);
        $this->assertSame($originalDigest, FinanceLaboratorySourceEvent::query()->sole()->content_digest);
        $this->assertSame(88000, FinanceLaboratorySourceEvent::query()->sole()->signed_amount);
    }

    public function test_duplicate_original_verified_results_fail_closed_without_materializing_source(): void
    {
        [$masterVersion, $order, $encounter, $workflow] = $this->acceptedOrder();
        $this->bind($masterVersion, $this->tariff(75000));
        $draft = $workflow->saveDraft(
            $order->public_id, $this->technologist, 0, $this->normalResults(), 'lab-duplicate-draft-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $draft);
        $verified = $workflow->verify(
            $order->public_id, $this->verifier, $draft->version, null, 'lab-duplicate-verify-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $verified);
        LaboratoryMutationScope::run(fn () => DB::table('laboratory_result_versions')->insert([
            'public_id' => (string) str()->ulid(),
            'laboratory_order_id' => $verified->laboratory_order_id,
            'laboratory_specimen_attempt_id' => $verified->laboratory_specimen_attempt_id,
            'author_user_id' => $verified->author_user_id,
            'base_verified_version_id' => null,
            'version' => $verified->version + 1,
            'state' => LaboratoryResultVersion::VERIFIED,
            'results' => json_encode($verified->results, JSON_THROW_ON_ERROR),
            'correction_reason' => null,
            'base_verified_digest' => null,
            'prior_amendment_digest' => null,
            'content_digest' => $verified->content_digest,
            'verified_at' => now(),
            'created_at' => now(),
        ]));

        $this->synchronize(app(FinanceLaboratorySourceAdapter::class), $encounter);
        $this->assertDatabaseCount('finance_laboratory_source_events', 0);
        $this->assertDatabaseCount('finance_charge_events', 0);
    }

    public function test_original_verified_result_is_ready_synchronized_and_issued_with_exact_laboratory_provenance(): void
    {
        [$masterVersion, $order, $encounter, $workflow] = $this->acceptedOrder();
        $this->bind($masterVersion, $this->tariff(125000));
        $draft = $workflow->saveDraft(
            $order->public_id, $this->technologist, 0, $this->normalResults(), 'lab-bill-draft-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $draft);
        $verified = $workflow->verify(
            $order->public_id, $this->verifier, $draft->version, null, 'lab-bill-verify-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $verified);

        $coordinator = app(FinanceSourceCoordinator::class);
        $before = $coordinator->readiness($encounter);
        $this->assertSame(1, $before['resolved_count']);
        $this->assertSame(0, $before['unresolved_count']);
        $this->assertSame('LABORATORY', $before['items'][0]['source_domain']);
        $this->assertSame('SIAP_DISINKRONKAN', $before['items'][0]['state']);
        $this->assertSame(125000, $before['items'][0]['preview_signed_amount']);
        $this->assertSame($verified->verified_at->toIso8601String(), $before['items'][0]['service_at']);
        $candidate = collect(app(FinanceProjection::class)->worklist($this->cashier)['synchronization_candidates'])->sole();
        $this->assertSame(1, $candidate['source_event_count']);
        $this->assertSame(125000, $candidate['gross_amount']);
        $this->assertSame(125000, $candidate['net_amount']);
        $this->assertSame($verified->verified_at->toIso8601String(), $candidate['latest_source_at']);
        $this->assertDatabaseCount('finance_laboratory_source_events', 0);
        $this->assertDatabaseCount('finance_charge_events', 0);

        $service = app(FinanceBillService::class);
        $bill = $service->synchronize(
            $encounter->public_id, $this->cashier, 'lab-bill-sync-0001',
        )->record;
        $this->assertInstanceOf(FinanceBill::class, $bill);
        $this->assertSame('TERSINKRONISASI', $coordinator->readiness($encounter)['items'][0]['state']);

        $version = $service->issue(
            $bill->public_id,
            $this->cashier,
            app(FinanceProjection::class)->fingerprint($bill->fresh()),
            'Penerbitan hasil laboratorium terverifikasi.',
            'lab-bill-issue-0001',
        )->record;
        $this->assertInstanceOf(FinanceBillVersion::class, $version);
        $this->assertSame(FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_V1, $version->coverage_profile);
        $this->assertSame(125000, $version->net_amount);
        $this->assertSame('LABORATORY', $version->lines->sole()->source_domain);

        $projection = app(FinanceProjection::class)->bill($bill->fresh(), $this->cashier);
        $sources = $projection['sources'];
        if (! is_array($sources) || count($sources) !== 1 || ! isset($sources[0]) || ! is_array($sources[0])) {
            $this->fail('The bill projection must expose exactly one structured laboratory source.');
        }
        $source = $sources[0];
        $this->assertSame('LABORATORY', $source['source_domain']);
        $this->assertSame($verified->public_id, $source['tariff_provenance']['laboratory_result_public_id']);
        $this->assertSame($masterVersion->public_id, $source['tariff_provenance']['laboratory_master_version_public_id']);
        $this->assertSame('2026-09-02', $source['tariff_provenance']['service_date']);
    }

    public function test_mapped_laboratory_result_synchronizes_but_unmapped_result_blocks_issue(): void
    {
        [$mappedMaster, $mappedOrder, $encounter, $mappedWorkflow] = $this->acceptedOrder();
        [, $unmappedOrder, , $unmappedWorkflow] = $this->acceptedOrder(encounter: $encounter);
        $this->bind($mappedMaster, $this->tariff(90000));

        foreach ([[$mappedOrder, $mappedWorkflow, 'mapped'], [$unmappedOrder, $unmappedWorkflow, 'unmapped']] as [$order, $workflow, $key]) {
            $draft = $workflow->saveDraft(
                $order->public_id, $this->technologist, 0, $this->normalResults(), "lab-partial-{$key}-draft",
            )->record;
            $this->assertInstanceOf(LaboratoryResultVersion::class, $draft);
            $verified = $workflow->verify(
                $order->public_id, $this->verifier, $draft->version, null, "lab-partial-{$key}-verify",
            )->record;
            $this->assertInstanceOf(LaboratoryResultVersion::class, $verified);
        }

        $bill = app(FinanceBillService::class)->synchronize(
            $encounter->public_id, $this->cashier, 'lab-partial-sync-0001',
        )->record;
        $this->assertInstanceOf(FinanceBill::class, $bill);
        $this->assertDatabaseCount('finance_laboratory_source_events', 1);
        $readiness = app(FinanceSourceCoordinator::class)->readiness($encounter);
        $this->assertSame(1, $readiness['resolved_count']);
        $this->assertSame(1, $readiness['unresolved_count']);
        $this->assertTrue($readiness['issue_blocked']);
        $this->assertContains('TARIF_BELUM_DIPETAKAN', array_column($readiness['items'], 'state'));

        try {
            app(FinanceBillService::class)->issue(
                $bill->public_id,
                $this->cashier,
                app(FinanceProjection::class)->fingerprint($bill->fresh()),
                'Tidak boleh terbit sebelum semua hasil memiliki tarif.',
                'lab-partial-issue-0001',
            );
            $this->fail('An unresolved original verified laboratory result must block issuance.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('unresolved_laboratory_source', $denied->reason);
        }
        $this->assertDatabaseCount('finance_bill_versions', 0);
    }

    public function test_recovery_integrity_round_trip_accepts_original_verified_laboratory_source(): void
    {
        [$masterVersion, $order, $encounter, $workflow] = $this->acceptedOrder();
        $this->bind($masterVersion, $this->tariff(93000));
        $draft = $workflow->saveDraft(
            $order->public_id, $this->technologist, 0, $this->normalResults(), 'lab-recovery-draft-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $draft);
        $verified = $workflow->verify(
            $order->public_id, $this->verifier, $draft->version, null, 'lab-recovery-verify-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $verified);
        $this->synchronize(app(FinanceLaboratorySourceAdapter::class), $encounter);

        foreach ([
            'financeLaboratoryBindingVersionChainMismatchCount',
            'financeLaboratoryBindingHeadMismatchCount',
            'financeLaboratoryBindingUpstreamMismatchCount',
            'financeLaboratoryBindingReceiptResultMismatchCount',
            'financeLaboratorySourceMismatchCount',
        ] as $method) {
            $this->assertSame(0, $this->recoveryIntegrityCount($method), $method);
        }
        $this->assertSame($verified->id, FinanceLaboratorySourceEvent::query()->sole()->laboratory_result_version_id);
    }

    public function test_recovery_snapshot_registers_complete_laboratory_tariff_source_contract(): void
    {
        $source = file_get_contents(app_path('Support/Operations/SyntheticRecoverySnapshot.php'));
        $this->assertIsString($source);
        foreach ([
            'finance_laboratory_tariff_bindings',
            'finance_laboratory_tariff_binding_versions',
            'finance_laboratory_tariff_operation_receipts',
            'finance_laboratory_source_events',
        ] as $table) {
            $this->assertStringContainsString("'{$table}' =>", $source);
            $this->assertStringContainsString("'{$table}_sha256' =>", $source);
        }
        foreach ([
            'finance_charge_without_laboratory_source',
            'finance_laboratory_binding_version_chain_mismatches',
            'finance_laboratory_binding_head_mismatches',
            'finance_laboratory_binding_upstream_mismatches',
            'finance_laboratory_binding_receipt_result_mismatches',
            'finance_laboratory_source_mismatches',
        ] as $contract) {
            $this->assertStringContainsString("'{$contract}' =>", $source);
        }
        $this->assertStringContainsString("'finance_laboratory_source_event_id'", $source);
    }

    public function test_strict_recovery_tamper_refusal_can_roll_back_without_altering_valid_laboratory_source(): void
    {
        [$masterVersion, $order, $encounter, $workflow] = $this->acceptedOrder();
        $this->bind($masterVersion, $this->tariff(94000));
        $draft = $workflow->saveDraft(
            $order->public_id, $this->technologist, 0, $this->normalResults(), 'lab-recovery-tamper-draft',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $draft);
        $workflow->verify($order->public_id, $this->verifier, $draft->version, null, 'lab-recovery-tamper-verify');
        $this->synchronize(app(FinanceLaboratorySourceAdapter::class), $encounter);
        $digest = FinanceLaboratorySourceEvent::query()->sole()->content_digest;

        try {
            DB::transaction(function (): void {
                FinanceAppendOnlyGuard::runSyntheticReset(
                    fn () => FinanceMutationScope::run(fn () => DB::table('finance_charge_events')->delete()),
                );
                if ($this->recoveryIntegrityCount('financeLaboratorySourceMismatchCount') > 0) {
                    throw new RuntimeException('Strict recovery refusal.');
                }
            });
            $this->fail('Tampered laboratory recovery evidence must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Strict recovery refusal.', $exception->getMessage());
        }

        $this->assertDatabaseCount('finance_charge_events', 1);
        $this->assertSame($digest, FinanceLaboratorySourceEvent::query()->sole()->content_digest);
        $this->assertSame(0, $this->recoveryIntegrityCount('financeLaboratorySourceMismatchCount'));
    }

    public function test_synthetic_reset_removes_laboratory_finance_graph_and_leaves_other_source_tables_consistent(): void
    {
        [$masterVersion, $order, $encounter, $workflow] = $this->acceptedOrder();
        $this->bind($masterVersion, $this->tariff(95000));
        $draft = $workflow->saveDraft(
            $order->public_id, $this->technologist, 0, $this->normalResults(), 'lab-reset-draft-0001',
        )->record;
        $this->assertInstanceOf(LaboratoryResultVersion::class, $draft);
        $workflow->verify($order->public_id, $this->verifier, $draft->version, null, 'lab-reset-verify-0001');
        $this->synchronize(app(FinanceLaboratorySourceAdapter::class), $encounter);

        app(SyntheticResetService::class)->reset(['actor' => $this->steward, 'reason' => 'laboratory_finance_reset_test']);

        foreach ([
            'finance_charge_events', 'finance_laboratory_source_events',
            'finance_laboratory_tariff_operation_receipts',
            'finance_laboratory_tariff_binding_versions', 'finance_laboratory_tariff_bindings',
            'finance_radiology_source_events', 'pharmacy_financial_source_events',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(0, $this->recoveryIntegrityCount('financeLaboratorySourceMismatchCount'));
    }

    /** @return array{LaboratoryExaminationMasterVersion,LaboratoryOrder,Encounter,LaboratoryWorkflowService} */
    private function acceptedOrder(
        bool $recollect = false,
        string $careSetting = Encounter::CARE_SETTING_OUTPATIENT,
        ?Encounter $encounter = null,
    ): array {
        $master = app(LaboratoryMasterService::class)->create(
            $this->admin, 'LAB-'.str()->upper(str()->random(8)), 'Hemoglobin', 'Darah EDTA',
            'Koleksi sesuai prosedur.', [[
                'code' => 'HGB', 'display_name' => 'Hemoglobin', 'value_kind' => 'NUMERIC',
                'unit_text' => 'g/dL', 'reference_text' => 'Rujukan unit.', 'critical_allowed' => true,
            ]], 'lab-source-master-'.str()->lower(str()->random(12)),
        )->record;
        $this->assertInstanceOf(LaboratoryExaminationMaster::class, $master);
        $masterVersion = LaboratoryExaminationMasterVersion::query()
            ->where('laboratory_examination_master_id', $master->id)->sole();
        $providedEncounter = $encounter instanceof Encounter;
        $encounter ??= Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['is_synthetic' => true])->id,
            'care_setting' => $careSetting,
            'status' => $careSetting === Encounter::CARE_SETTING_EMERGENCY
                ? Encounter::STATUS_REGISTERED
                : Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Lokasi klinis uji',
        ])->fresh('patient');
        $careSetting = $encounter->care_setting;
        if (! $providedEncounter && $careSetting === Encounter::CARE_SETTING_EMERGENCY) {
            $encounter = $this->finalizeEmergencyInitialTriage(
                $encounter,
                keyPrefix: 'lab-source-triage-'.str()->lower(str()->random(8)),
            )->fresh('patient');
        }
        $workflow = app(LaboratoryWorkflowService::class);
        $order = $workflow->createOrder(
            $encounter->public_id, $master->public_id, $this->physician,
            'ROUTINE', 'Pemeriksaan hemoglobin.', 'lab-source-order-'.str()->lower(str()->random(12)),
        )->record;
        $this->assertInstanceOf(LaboratoryOrder::class, $order);
        $attempt = $workflow->collectSpecimen(
            $order->public_id, $this->nurse, 1, null, 'lab-source-collect-'.str()->lower(str()->random(12)),
        )->record;
        $this->assertInstanceOf(LaboratorySpecimenAttempt::class, $attempt);
        $workflow->receiveSpecimen($attempt->public_id, $this->technologist, 'lab-source-receive-'.str()->lower(str()->random(12)));
        if ($recollect) {
            $workflow->rejectSpecimen(
                $attempt->public_id, $this->technologist, 1, 'HEMOLYSED', 'Spesimen hemolisis.',
                'lab-source-reject-'.str()->lower(str()->random(12)),
            );
            $attempt = $workflow->collectSpecimen(
                $order->public_id, $this->nurse, 1, null, 'lab-source-recollect-'.str()->lower(str()->random(12)),
            )->record;
            $this->assertInstanceOf(LaboratorySpecimenAttempt::class, $attempt);
            $workflow->receiveSpecimen($attempt->public_id, $this->technologist, 'lab-source-rereceive-'.str()->lower(str()->random(12)));
        }
        $workflow->acceptSpecimen(
            $attempt->public_id, $this->technologist, 1, 'lab-source-accept-'.str()->lower(str()->random(12)),
        );

        return [$masterVersion, LaboratoryOrder::query()->findOrFail($order->id), $encounter, $workflow];
    }

    private function tariff(
        int $amount,
        string $careSetting = Encounter::CARE_SETTING_OUTPATIENT,
    ): FinanceTariffItem {
        $service = app(FinanceTariffMasterService::class);
        $suffix = str()->upper(str()->random(6));
        $group = $service->createGroup($this->steward, 'G'.$suffix, 'Laboratorium', 'Pembuatan kelompok', 'lab-src-group-'.str()->lower(str()->random(12)))->record;
        $this->assertInstanceOf(FinanceCostComponentGroup::class, $group);
        $component = $service->createComponent($this->steward, $group->public_id, 'C'.$suffix, 'Komponen laboratorium', null, null, 'Pembuatan komponen', 'lab-src-component-'.str()->lower(str()->random(12)))->record;
        $this->assertInstanceOf(FinanceCostComponent::class, $component);
        $catalogue = $service->createCatalogue($this->steward, 'K'.$suffix, 'Katalog laboratorium', 'Pembuatan katalog', 'lab-src-catalogue-'.str()->lower(str()->random(12)))->record;
        $this->assertInstanceOf(FinanceTariffCatalogue::class, $catalogue);
        $tariff = $service->createTariffItem(
            $this->steward, $catalogue->public_id, $component->public_id, 'T'.$suffix,
            'Tarif laboratorium', $careSetting, 'LABORATORY', null, null, $amount,
            '2026-09-02', 'Pembuatan tarif', 'lab-src-tariff-'.str()->lower(str()->random(12)),
        )->record;
        $this->assertInstanceOf(FinanceTariffItem::class, $tariff);

        return $tariff->fresh();
    }

    private function bind(
        LaboratoryExaminationMasterVersion $master,
        FinanceTariffItem $tariff,
        string $careSetting = Encounter::CARE_SETTING_OUTPATIENT,
    ): void {
        app(FinanceLaboratoryTariffBindingService::class)->create(
            $this->steward, $master->public_id, $careSetting, $tariff->public_id,
            '2026-09-02', 'Pemetaan tarif laboratorium.', 'lab-src-bind-'.str()->lower(str()->random(12)),
        );
    }

    /** @return Collection<int, FinanceChargeEvent> */
    private function synchronize(FinanceLaboratorySourceAdapter $adapter, Encounter $encounter): Collection
    {
        return DB::transaction(fn () => FinanceMutationScope::run(
            fn () => $adapter->synchronize($encounter->fresh('patient'), $this->cashier),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function normalResults(): array
    {
        return [['code' => 'HGB', 'value' => '13.2', 'note' => null, 'interpretation' => 'NORMAL']];
    }

    /** @return list<array<string, mixed>> */
    private function criticalResults(): array
    {
        return [['code' => 'HGB', 'value' => '6.2', 'note' => 'Nilai kritis.', 'interpretation' => 'CRITICAL']];
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }

    private function recoveryIntegrityCount(string $method): int
    {
        $value = (new ReflectionClass(SyntheticRecoverySnapshot::class))->getMethod($method)
            ->invoke(new SyntheticRecoverySnapshot);

        return is_int($value) ? $value : -1;
    }
}
