<?php

namespace App\Support\Clinical;

use App\Models\Encounter;
use App\Models\LabServiceRequest;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientRmCompletenessReview;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\CanonicalJson;
use App\Support\Laboratory\LaboratoryClosureGate;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use App\Support\Radiology\RadiologyClosureGate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OutpatientRmCompletenessService
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly PharmacyEncounterLifecycleGate $pharmacy,
    ) {}

    /** @return array{source_fingerprint: string, items: list<array{item_code: string, label: string, is_blocking: bool, is_complete: bool, source_reference: string|null}>, blockers: list<string>} */
    public function snapshot(Encounter $encounter): array
    {
        $encounter->loadMissing([
            'patient',
            'outpatientClinicalDocuments',
            'labServiceRequests',
            'latestOutpatientDisposition',
        ]);
        $documents = $encounter->outpatientClinicalDocuments->keyBy('document_type');
        /** @var OutpatientClinicalDocument|null $nursing */
        $nursing = $documents->get(OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT);
        /** @var OutpatientClinicalDocument|null $medical */
        $medical = $documents->get(OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT);
        $activeOrderIds = $encounter->labServiceRequests
            ->where('status', LabServiceRequest::STATUS_ACTIVE)
            ->sortBy('public_id')
            ->pluck('public_id')
            ->values()
            ->all();
        $radiology = app(RadiologyClosureGate::class)->inspect($encounter);
        $laboratory = app(LaboratoryClosureGate::class)->inspect($encounter);
        $pharmacy = $this->pharmacy->inspectReadModel($encounter);
        $allActiveLabOrderIds = collect($activeOrderIds)
            ->merge($laboratory['active_order_public_ids'])
            ->unique()
            ->sort()
            ->values()
            ->all();

        $medicalFieldsComplete = $medical !== null
            && collect(OutpatientDocumentationDefinition::requiredOnFinal($medical->document_type))
                ->every(fn (string $key): bool => trim((string) data_get($medical->fields, $key, '')) !== '');
        $disposition = $encounter->latestOutpatientDisposition;

        $items = [
            $this->item('IDENTITY_LINKED', 'Identitas pasien dan kunjungan terhubung', $encounter->patient !== null, $encounter->patient?->public_id),
            $this->item('NURSING_FINAL', 'Catatan keperawatan tersedia dan final', $nursing?->document_state === OutpatientClinicalDocument::STATE_FINAL, $nursing?->public_id),
            $this->item('NURSING_PROVENANCE', 'Penulis dan waktu finalisasi keperawatan tercatat', $nursing !== null && $nursing->author_user_id > 0 && $nursing->finalized_by_user_id !== null && $nursing->finalized_at !== null, $nursing?->public_id),
            $this->item('MEDICAL_FINAL', 'Catatan medis tersedia dan final', $medical?->document_state === OutpatientClinicalDocument::STATE_FINAL, $medical?->public_id),
            $this->item('MEDICAL_REQUIRED_FIELDS', 'Field wajib catatan medis final terisi', $medical?->document_state === OutpatientClinicalDocument::STATE_FINAL && $medicalFieldsComplete, $medical?->public_id),
            $this->item('MEDICAL_PROVENANCE', 'Dokter dan waktu finalisasi tercatat', $medical !== null && $medical->author_user_id > 0 && $medical->finalized_by_user_id !== null && $medical->finalized_at !== null, $medical?->public_id),
            $this->item('DISPOSITION_SIGNED', 'Disposisi dokter tersedia dan ditandatangani', $disposition !== null, $disposition?->public_id),
            $this->item('NO_ACTIVE_LAB_ORDERS', 'Tidak ada order laboratorium aktif', $allActiveLabOrderIds === [], null),
            $this->item('NO_UNRESOLVED_LAB_SPECIMENS', 'Tidak ada spesimen laboratorium yang belum terselesaikan', $laboratory['unresolved_specimen_order_public_ids'] === [], null),
            $this->item('NO_UNACKNOWLEDGED_VERIFIED_LAB_RESULTS', 'Tidak ada hasil laboratorium terverifikasi yang belum diakui', $laboratory['stale_acknowledgement_order_public_ids'] === [], null),
            $this->item('NO_ACTIVE_RADIOLOGY_ORDERS', 'Tidak ada pesanan radiologi aktif', $radiology['active_order_public_ids'] === [], null),
            $this->item('NO_UNACKNOWLEDGED_VERIFIED_RADIOLOGY_REPORTS', 'Tidak ada laporan radiologi terverifikasi yang belum diakui', $radiology['stale_acknowledgement_order_public_ids'] === [], null),
            $this->item('NO_ACTIVE_MEDICATION_PRESCRIPTIONS', 'Tidak ada resep obat aktif', $pharmacy['active_prescription_public_ids'] === [], null),
        ];

        $fingerprint = hash('sha256', CanonicalJson::encode([
            'definition_version' => OutpatientRmCompletenessReview::DEFINITION_VERSION,
            'encounter_public_id' => $encounter->public_id,
            'patient_public_id' => $encounter->patient?->public_id,
            'documents' => collect([$nursing, $medical])
                ->filter()
                ->map(fn (OutpatientClinicalDocument $document): array => [
                    'public_id' => $document->public_id,
                    'document_type' => $document->document_type,
                    'document_state' => $document->document_state,
                    'definition_version' => $document->definition_version,
                    'version' => $document->version,
                    'author_user_id' => $document->author_user_id,
                    'finalized_by_user_id' => $document->finalized_by_user_id,
                    'finalized_at' => $document->finalized_at?->toIso8601String(),
                ])
                ->sortBy('document_type')
                ->values()
                ->all(),
            'disposition' => $disposition ? [
                'public_id' => $disposition->public_id,
                'version' => $disposition->version,
                'type' => $disposition->disposition_type,
                'content_digest' => $disposition->content_digest,
            ] : null,
            'active_lab_order_public_ids' => $allActiveLabOrderIds,
            'unresolved_lab_specimen_order_public_ids' => $laboratory['unresolved_specimen_order_public_ids'],
            'stale_lab_acknowledgement_order_public_ids' => $laboratory['stale_acknowledgement_order_public_ids'],
            'active_radiology_order_public_ids' => $radiology['active_order_public_ids'],
            'stale_radiology_acknowledgement_order_public_ids' => $radiology['stale_acknowledgement_order_public_ids'],
            'active_medication_prescription_public_ids' => $pharmacy['active_prescription_public_ids'],
        ]));

        return [
            'source_fingerprint' => $fingerprint,
            'items' => $items,
            'blockers' => array_values(array_map(
                static fn (array $item): string => $item['item_code'],
                array_filter($items, static fn (array $item): bool => $item['is_blocking'] && ! $item['is_complete']),
            )),
        ];
    }

    public function saveReview(
        Encounter $encounter,
        User $actor,
        int $expectedVersion,
        string $expectedFingerprint,
    ): OutpatientRmCompletenessReview {
        try {
            return DB::transaction(function () use ($encounter, $actor, $expectedVersion, $expectedFingerprint): OutpatientRmCompletenessReview {
                $lockedEncounter = $this->lockEncounter($encounter);
                $this->assertReviewable($lockedEncounter);
                $latest = $this->lockLatestReview($lockedEncounter);
                $currentVersion = $latest === null ? 0 : $latest->version;

                if ($expectedVersion !== $currentVersion) {
                    throw new OutpatientLifecycleDenial('stale_version', 'Pemeriksaan kelengkapan telah berubah. Muat ulang sebelum melanjutkan.');
                }

                $snapshot = $this->snapshot($lockedEncounter);
                if (! hash_equals($snapshot['source_fingerprint'], $expectedFingerprint)) {
                    throw new OutpatientLifecycleDenial('source_stale', 'Sumber rekam medis telah berubah. Muat ulang sebelum melanjutkan.');
                }

                $review = $this->createSnapshotReview(
                    encounter: $lockedEncounter,
                    actor: $actor,
                    version: $currentVersion + 1,
                    state: OutpatientRmCompletenessReview::STATE_DRAFT,
                    snapshot: $snapshot,
                );

                $this->recordSuccess('rmik.completeness.review.save', $lockedEncounter, $review, $actor, $snapshot['blockers']);

                return $review;
            });
        } catch (OutpatientLifecycleDenial $denial) {
            $this->recordDenial('rmik.completeness.review.save', $encounter, $actor, $denial);
            abort($denial->status, $denial->getMessage());
        }
    }

    public function signoff(
        Encounter $encounter,
        User $actor,
        int $expectedVersion,
        string $expectedFingerprint,
    ): OutpatientRmCompletenessReview {
        try {
            return DB::transaction(function () use ($encounter, $actor, $expectedVersion, $expectedFingerprint): OutpatientRmCompletenessReview {
                $this->pharmacy->lockInventoryForEncounter((int) $encounter->id);
                $lockedEncounter = $this->lockEncounter($encounter);
                $this->assertReviewable($lockedEncounter);
                $latest = $this->lockLatestReview($lockedEncounter);

                if ($latest === null || $latest->version !== $expectedVersion || $latest->review_state !== OutpatientRmCompletenessReview::STATE_DRAFT) {
                    throw new OutpatientLifecycleDenial('stale_version', 'Pemeriksaan kelengkapan telah berubah. Muat ulang sebelum melanjutkan.');
                }

                $snapshot = $this->snapshot($lockedEncounter);
                if (! hash_equals($snapshot['source_fingerprint'], $expectedFingerprint)
                    || ! hash_equals($latest->source_fingerprint, $expectedFingerprint)) {
                    throw new OutpatientLifecycleDenial('source_stale', 'Sumber rekam medis telah berubah. Muat ulang sebelum melanjutkan.');
                }

                if (in_array('NO_ACTIVE_LAB_ORDERS', $snapshot['blockers'], true)) {
                    throw new OutpatientLifecycleDenial(
                        'active_lab_orders',
                        'Kunjungan belum dapat ditutup karena masih ada order lab aktif.',
                        metadata: ['failed_item_ids' => ['NO_ACTIVE_LAB_ORDERS']],
                    );
                }

                if (in_array('NO_UNRESOLVED_LAB_SPECIMENS', $snapshot['blockers'], true)) {
                    throw new OutpatientLifecycleDenial(
                        'unresolved_lab_specimens',
                        'Kunjungan belum dapat ditutup karena masih ada spesimen laboratorium yang belum terselesaikan.',
                        metadata: ['failed_item_ids' => ['NO_UNRESOLVED_LAB_SPECIMENS']],
                    );
                }

                if (in_array('NO_UNACKNOWLEDGED_VERIFIED_LAB_RESULTS', $snapshot['blockers'], true)) {
                    throw new OutpatientLifecycleDenial(
                        'lab_result_not_acknowledged',
                        'Hasil laboratorium terbaru belum diakui dokter pemesan.',
                        metadata: ['failed_item_ids' => ['NO_UNACKNOWLEDGED_VERIFIED_LAB_RESULTS']],
                    );
                }

                if (in_array('NO_ACTIVE_RADIOLOGY_ORDERS', $snapshot['blockers'], true)) {
                    throw new OutpatientLifecycleDenial('active_radiology_orders', 'Kunjungan belum dapat ditutup karena masih ada pesanan radiologi aktif.', metadata: ['failed_item_ids' => ['NO_ACTIVE_RADIOLOGY_ORDERS']]);
                }
                if (in_array('NO_UNACKNOWLEDGED_VERIFIED_RADIOLOGY_REPORTS', $snapshot['blockers'], true)) {
                    throw new OutpatientLifecycleDenial('radiology_report_not_acknowledged', 'Laporan radiologi terbaru belum diakui dokter pemesan.', metadata: ['failed_item_ids' => ['NO_UNACKNOWLEDGED_VERIFIED_RADIOLOGY_REPORTS']]);
                }

                if (in_array('NO_ACTIVE_MEDICATION_PRESCRIPTIONS', $snapshot['blockers'], true)) {
                    throw new OutpatientLifecycleDenial(
                        'active_pharmacy_prescriptions',
                        'Kunjungan belum dapat ditutup karena masih ada resep obat aktif.',
                        metadata: ['failed_item_ids' => ['NO_ACTIVE_MEDICATION_PRESCRIPTIONS']],
                    );
                }

                if ($snapshot['blockers'] !== []) {
                    throw new OutpatientLifecycleDenial(
                        'checklist_incomplete',
                        'Rekam medis belum lengkap dan belum dapat ditutup.',
                        metadata: ['failed_item_ids' => $snapshot['blockers']],
                    );
                }

                $review = $this->createSnapshotReview(
                    encounter: $lockedEncounter,
                    actor: $actor,
                    version: $expectedVersion + 1,
                    state: OutpatientRmCompletenessReview::STATE_SIGNED_OFF,
                    snapshot: $snapshot,
                );
                $lockedEncounter->update(['status' => Encounter::STATUS_CLOSED]);

                $this->recordSuccess('rmik.completeness.signoff', $lockedEncounter, $review, $actor, []);

                return $review;
            });
        } catch (OutpatientLifecycleDenial $denial) {
            $this->recordDenial('rmik.completeness.signoff', $encounter, $actor, $denial);
            abort($denial->status, $denial->getMessage());
        }
    }

    /** @return array{item_code: string, label: string, is_blocking: bool, is_complete: bool, source_reference: string|null} */
    private function item(string $code, string $label, bool $complete, ?string $source): array
    {
        return [
            'item_code' => $code,
            'label' => $label,
            'is_blocking' => true,
            'is_complete' => $complete,
            'source_reference' => $source,
        ];
    }

    private function lockEncounter(Encounter $encounter): Encounter
    {
        $locked = Encounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
        abort_unless($locked->care_setting === Encounter::CARE_SETTING_OUTPATIENT, 404);

        return $locked;
    }

    private function assertReviewable(Encounter $encounter): void
    {
        if ($encounter->status !== Encounter::STATUS_READY_FOR_RM) {
            throw new OutpatientLifecycleDenial('encounter_not_ready', 'Kunjungan belum siap untuk pemeriksaan kelengkapan RM.');
        }
    }

    private function lockLatestReview(Encounter $encounter): ?OutpatientRmCompletenessReview
    {
        return OutpatientRmCompletenessReview::query()
            ->where('encounter_id', $encounter->id)
            ->orderByDesc('version')
            ->lockForUpdate()
            ->first();
    }

    /** @param array{source_fingerprint: string, items: list<array{item_code: string, label: string, is_blocking: bool, is_complete: bool, source_reference: string|null}>, blockers: list<string>} $snapshot */
    private function createSnapshotReview(
        Encounter $encounter,
        User $actor,
        int $version,
        string $state,
        array $snapshot,
    ): OutpatientRmCompletenessReview {
        $signed = $state === OutpatientRmCompletenessReview::STATE_SIGNED_OFF;
        $review = OutpatientRmCompletenessReview::query()->create([
            'encounter_id' => $encounter->id,
            'reviewed_by_user_id' => $actor->id,
            'signed_off_by_user_id' => $signed ? $actor->id : null,
            'definition_version' => OutpatientRmCompletenessReview::DEFINITION_VERSION,
            'version' => $version,
            'source_fingerprint' => $snapshot['source_fingerprint'],
            'review_state' => $state,
            'reviewed_at' => now(),
            'signed_off_at' => $signed ? now() : null,
        ]);

        $review->items()->createMany($snapshot['items']);

        return $review->load(['items', 'reviewedBy', 'signedOffBy']);
    }

    /** @param list<string> $blockers */
    private function recordSuccess(string $action, Encounter $encounter, OutpatientRmCompletenessReview $review, User $actor, array $blockers): void
    {
        $event = $this->auditRecorder->record(
            action: $action,
            resourceType: 'outpatient_rm_completeness_review',
            resourceId: $review->public_id,
            actor: $actor,
            metadata: [
                'encounter_id' => $encounter->public_id,
                'review_version' => $review->version,
                'definition_version' => $review->definition_version,
                'source_fingerprint' => $review->source_fingerprint,
                'failed_item_ids' => $blockers,
            ],
        );

        if ($event === null) {
            throw new RuntimeException("Audit wajib gagal direkam untuk aksi {$action}.");
        }
    }

    private function recordDenial(string $action, Encounter $encounter, User $actor, OutpatientLifecycleDenial $denial): void
    {
        $event = $this->auditRecorder->record(
            action: $action,
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $actor,
            outcome: 'DENIED',
            reason: $denial->reason,
            metadata: $denial->metadata,
        );

        if ($event === null) {
            throw new RuntimeException("Audit penolakan gagal direkam untuk aksi {$action}.");
        }
    }
}
