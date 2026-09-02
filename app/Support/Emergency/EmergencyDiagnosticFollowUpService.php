<?php

namespace App\Support\Emergency;

use App\Models\EmergencyDisposition;
use App\Models\EmergencyResultFollowUpAcceptance;
use App\Models\EmergencyResultFollowUpProposal;
use App\Models\Encounter;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryResultVersion;
use App\Models\RadiologyOrder;
use App\Models\RadiologyReportVersion;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Laboratory\LaboratoryEvidenceFingerprint;
use App\Support\Laboratory\LaboratoryMutationScope;
use App\Support\Radiology\RadiologyEvidenceFingerprint;
use App\Support\Radiology\RadiologyMutationScope;
use Carbon\CarbonImmutable;

final class EmergencyDiagnosticFollowUpService
{
    public function __construct(
        private readonly EmergencyActorPolicy $policy,
        private readonly EmergencyOperationCoordinator $operations,
        private readonly EmergencyEvidenceFingerprint $fingerprints,
        private readonly LaboratoryEvidenceFingerprint $laboratoryFingerprints,
        private readonly RadiologyEvidenceFingerprint $radiologyFingerprints,
    ) {}

    public function propose(string $encounterPublicId, string $orderType, string $orderPublicId, string $expectedResultFingerprint, string $assigneePublicId, User $actor, string $reason, string $effectiveAt, string $handoffNote, string $idempotencyKey): EmergencyMutationResult
    {
        $orderType = mb_strtoupper(trim($orderType));
        $reason = $this->text($reason, 3, 1000);
        $handoffNote = $this->text($handoffNote, 3, 2000);
        try {
            $effective = CarbonImmutable::parse($effectiveAt);
        } catch (\Throwable) {
            throw new EmergencyDenied('validation_failed', 'Waktu efektif penugasan tidak valid.');
        }
        if ($effective->lt(now()->subMinutes(5)) || $effective->gt(now()->addDay())) {
            throw new EmergencyDenied('validation_failed', 'Waktu efektif penugasan berada di luar rentang.');
        }

        return $this->operations->perform($actor, 'EMERGENCY_FOLLOW_UP_PROPOSE', $encounterPublicId, $idempotencyKey, compact('encounterPublicId', 'orderType', 'orderPublicId', 'expectedResultFingerprint', 'assigneePublicId', 'reason', 'effectiveAt', 'handoffNote'), fn () => $this->policy->followUp($actor), function () use ($encounterPublicId, $orderType, $orderPublicId, $expectedResultFingerprint, $assigneePublicId, $actor, $reason, $effective, $handoffNote): EmergencyResultFollowUpProposal {
            $encounter = $this->lockEncounter($encounterPublicId);
            $order = $this->diagnosticOrder($encounter, $orderType, $orderPublicId, true);
            $assignee = User::query()->where('public_id', $assigneePublicId)->lockForUpdate()->firstOrFail();
            if (! $this->policy->can($assignee, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::EMERGENCY_RESULT_FOLLOW_UP)) {
                throw new EmergencyDenied('assignee_not_eligible', 'Dokter penerima tidak aktif atau tidak memiliki peran tepat.');
            }
            $latest = EmergencyResultFollowUpProposal::query()->where('encounter_id', $encounter->id)->where('order_type', $orderType)->where('order_public_id', $orderPublicId)->orderByDesc('id')->lockForUpdate()->first();
            if ($latest && ! $latest->acceptance()->exists()) {
                throw new EmergencyDenied('prior_assignment_not_accepted', 'Usulan penugasan sebelumnya belum diterima.');
            }
            $accountableUserId = $latest?->acceptance()->exists() ? $latest->proposed_to_user_id : $order->ordered_by_user_id;
            if ($actor->id !== $accountableUserId) {
                throw new EmergencyDenied('actor_not_accountable', 'Hanya dokter yang sedang bertanggung jawab dapat mengalihkan tindak lanjut.');
            }
            $diagnosticFingerprint = $this->diagnosticFingerprint($orderType, $order);
            if (! hash_equals($diagnosticFingerprint, $expectedResultFingerprint)) {
                throw new EmergencyDenied('diagnostic_evidence_changed', 'Bukti diagnostik telah berubah; muat ulang sebelum menugaskan.');
            }
            $priorDigest = $latest ? $this->fingerprints->followUpProposal($latest) : null;
            // The browser's expected result fingerprint is intentionally
            // volatile and detects a stale assignment screen. The persisted
            // assignment scope is stable across later verified/amended
            // results, so an accepted covering-physician assignment remains
            // usable when the result eventually arrives after disposition.
            $attributes = ['encounter_id' => $encounter->id, 'proposed_by_user_id' => $actor->id, 'proposed_to_user_id' => $assignee->id, 'prior_proposal_id' => $latest?->id, 'order_type' => $orderType, 'order_public_id' => $orderPublicId, 'result_fingerprint' => $this->assignmentScopeFingerprint($orderType, $order), 'assignment_reason' => $reason, 'handoff_note' => $handoffNote, 'effective_at' => $effective, 'prior_proposal_digest' => $priorDigest];
            $attributes['content_digest'] = $this->fingerprints->followUpProposalPayload($attributes);
            $proposal = EmergencyResultFollowUpProposal::query()->create([...$attributes, 'created_at' => now()]);
            if ($assignee->id === $actor->id) {
                $this->createAcceptance($proposal, $actor);
            }

            return $proposal;
        });
    }

    public function accept(string $proposalPublicId, User $actor, string $expectedProposalFingerprint, string $expectedResultFingerprint, string $idempotencyKey): EmergencyMutationResult
    {
        return $this->operations->perform($actor, 'EMERGENCY_FOLLOW_UP_ACCEPT', $proposalPublicId, $idempotencyKey, compact('proposalPublicId', 'expectedProposalFingerprint', 'expectedResultFingerprint'), fn () => $this->policy->followUp($actor), function () use ($proposalPublicId, $actor, $expectedProposalFingerprint, $expectedResultFingerprint): EmergencyResultFollowUpAcceptance {
            $proposalRef = EmergencyResultFollowUpProposal::query()->where('public_id', $proposalPublicId)->firstOrFail();
            $encounter = Encounter::query()->whereKey($proposalRef->encounter_id)->lockForUpdate()->firstOrFail();
            $proposal = EmergencyResultFollowUpProposal::query()->whereKey($proposalRef->id)->lockForUpdate()->firstOrFail();
            if ($proposal->proposed_to_user_id !== $actor->id) {
                throw new EmergencyDenied('acceptance_not_permitted', 'Hanya dokter yang dituju dapat menerima penugasan.');
            }
            if ($proposal->acceptance()->exists()) {
                throw new EmergencyDenied('assignment_already_accepted', 'Penugasan sudah diterima.');
            }
            $latest = EmergencyResultFollowUpProposal::query()->where('encounter_id', $encounter->id)->where('order_type', $proposal->order_type)->where('order_public_id', $proposal->order_public_id)->orderByDesc('id')->first();
            if (! $latest?->is($proposal)) {
                throw new EmergencyDenied('assignment_superseded', 'Usulan penugasan bukan versi terkini.');
            }
            $fingerprint = $this->fingerprints->followUpProposal($proposal);
            if (! hash_equals($fingerprint, $expectedProposalFingerprint)) {
                throw new EmergencyDenied('stale_assignment_fingerprint', 'Sidik usulan penugasan telah berubah.');
            }
            $order = $this->diagnosticOrder($encounter, $proposal->order_type, $proposal->order_public_id, true);
            $currentResultFingerprint = $this->diagnosticFingerprint($proposal->order_type, $order);
            if (! hash_equals($currentResultFingerprint, $expectedResultFingerprint)
                || ! hash_equals((string) $proposal->result_fingerprint, $this->assignmentScopeFingerprint($proposal->order_type, $order))) {
                throw new EmergencyDenied('diagnostic_evidence_changed', 'Bukti diagnostik atau lingkup penugasan telah berubah; muat ulang sebelum menerima.');
            }

            return $this->createAcceptance($proposal, $actor);
        });
    }

    /** @return list<array{order_type: 'LABORATORY'|'RADIOLOGY', order_public_id: string, fingerprint: string}> */
    public function unresolvedDiagnostics(Encounter $encounter): array
    {
        $items = [];
        foreach (LaboratoryOrder::query()->where('encounter_id', $encounter->id)->where('status', '!=', LaboratoryOrder::CANCELLED)->get() as $order) {
            if ($this->isUnresolved('LABORATORY', $order)) {
                $items[] = ['order_type' => 'LABORATORY', 'order_public_id' => $order->public_id, 'fingerprint' => $this->diagnosticFingerprint('LABORATORY', $order)];
            }
        }
        foreach (RadiologyOrder::query()->where('encounter_id', $encounter->id)->where('status', '!=', RadiologyOrder::CANCELLED)->get() as $order) {
            if ($this->isUnresolved('RADIOLOGY', $order)) {
                $items[] = ['order_type' => 'RADIOLOGY', 'order_public_id' => $order->public_id, 'fingerprint' => $this->diagnosticFingerprint('RADIOLOGY', $order)];
            }
        }

        return $items;
    }

    /** @param array{order_type: string, order_public_id: string, fingerprint?: string} $item */
    public function hasAcceptedCurrentAssignment(Encounter $encounter, array $item): bool
    {
        $proposal = EmergencyResultFollowUpProposal::query()->where('encounter_id', $encounter->id)->where('order_type', $item['order_type'])->where('order_public_id', $item['order_public_id'])->orderByDesc('id')->first();

        if ($proposal === null || ! $proposal->acceptance()->exists()) {
            return false;
        }
        $order = $this->diagnosticOrder($encounter, (string) $item['order_type'], (string) $item['order_public_id'], true);

        return hash_equals((string) $proposal->result_fingerprint, $this->assignmentScopeFingerprint((string) $item['order_type'], $order));
    }

    public function acceptedAssignmentForAcknowledgement(Encounter $encounter, string $orderType, string $orderPublicId, User $actor, string $currentDiagnosticFingerprint): ?EmergencyResultFollowUpAcceptance
    {
        $orderType = mb_strtoupper(trim($orderType));
        if ($encounter->care_setting !== Encounter::CARE_SETTING_EMERGENCY || ! $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::EMERGENCY_RESULT_FOLLOW_UP)) {
            return null;
        }
        $order = match ($orderType) {
            'LABORATORY' => LaboratoryOrder::query()->where('encounter_id', $encounter->id)->where('public_id', $orderPublicId)->first(),
            'RADIOLOGY' => RadiologyOrder::query()->where('encounter_id', $encounter->id)->where('public_id', $orderPublicId)->first(),
            default => null,
        };
        if (! $order instanceof LaboratoryOrder && ! $order instanceof RadiologyOrder) {
            return null;
        }
        if (! hash_equals($this->diagnosticFingerprint($orderType, $order), $currentDiagnosticFingerprint)) {
            return null;
        }
        $assignmentFingerprint = $this->assignmentScopeFingerprint($orderType, $order);
        $proposal = EmergencyResultFollowUpProposal::query()->where('encounter_id', $encounter->id)->where('order_type', $orderType)->where('order_public_id', $orderPublicId)->orderByDesc('id')->first();
        if (! $proposal || $proposal->proposed_to_user_id !== $actor->id || ! hash_equals((string) $proposal->result_fingerprint, $assignmentFingerprint)) {
            return null;
        }
        $acceptance = $proposal->acceptance()->first();
        if (! $acceptance || $acceptance->accepted_by_user_id !== $actor->id) {
            return null;
        }
        $this->fingerprints->followUpAcceptance($acceptance);

        return $acceptance;
    }

    private function createAcceptance(EmergencyResultFollowUpProposal $proposal, User $actor): EmergencyResultFollowUpAcceptance
    {
        $proposalDigest = $this->fingerprints->followUpProposal($proposal);
        $acceptedAt = now();
        $digest = $this->fingerprints->followUpAcceptancePayload($proposal->id, $actor->id, $proposalDigest, $acceptedAt);

        return EmergencyResultFollowUpAcceptance::query()->create(['proposal_id' => $proposal->id, 'accepted_by_user_id' => $actor->id, 'proposal_fingerprint' => $proposalDigest, 'content_digest' => $digest, 'accepted_at' => $acceptedAt, 'created_at' => $acceptedAt]);
    }

    private function lockEncounter(string $publicId): Encounter
    {
        $encounter = Encounter::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
        if ($encounter->care_setting !== Encounter::CARE_SETTING_EMERGENCY || $encounter->status !== Encounter::STATUS_IN_EXAMINATION || $encounter->cancellation()->exists() || EmergencyDisposition::query()->where('encounter_id', $encounter->id)->exists()) {
            throw new EmergencyDenied('encounter_not_eligible', 'Penugasan tindak lanjut hanya dapat dibuat sebelum disposisi.');
        }

        return $encounter;
    }

    private function diagnosticOrder(Encounter $encounter, string $type, string $publicId, bool $requireUnresolved): LaboratoryOrder|RadiologyOrder
    {
        $model = match ($type) {
            'LABORATORY' => LaboratoryMutationScope::run(
                fn () => LaboratoryOrder::query()->where('public_id', $publicId)->where('encounter_id', $encounter->id)->lockForUpdate()->firstOrFail(),
            ),
            'RADIOLOGY' => RadiologyMutationScope::run(
                fn () => RadiologyOrder::query()->where('public_id', $publicId)->where('encounter_id', $encounter->id)->lockForUpdate()->firstOrFail(),
            ),
            default => throw new EmergencyDenied('validation_failed', 'Jenis diagnostik tidak valid.'),
        };
        if ($requireUnresolved && ! $this->isUnresolved($type, $model)) {
            throw new EmergencyDenied('diagnostic_not_unresolved', 'Pemeriksaan diagnostik tidak lagi memerlukan penugasan.');
        }

        return $model;
    }

    private function isUnresolved(string $type, LaboratoryOrder|RadiologyOrder $order): bool
    {
        if ($type === 'LABORATORY' && $order instanceof LaboratoryOrder) {
            if ($order->status !== LaboratoryOrder::REPORTED_VERIFIED) {
                return true;
            }
            $latest = LaboratoryResultVersion::query()->where('laboratory_order_id', $order->id)->whereIn('state', [LaboratoryResultVersion::VERIFIED, LaboratoryResultVersion::AMENDED_VERIFIED])->orderByDesc('version')->first();
            if (! $latest) {
                return true;
            }
            $fingerprint = $this->laboratoryFingerprints->current($latest);

            return ! $latest->acknowledgement()->where('result_fingerprint', $fingerprint)->exists();
        }
        if ($type !== 'RADIOLOGY' || ! $order instanceof RadiologyOrder) {
            throw new EmergencyDenied('diagnostic_type_mismatch', 'Jenis diagnostik tidak cocok dengan pesanan.');
        }
        if ($order->status !== RadiologyOrder::REPORTED_VERIFIED) {
            return true;
        }
        $latest = RadiologyReportVersion::query()->where('radiology_order_id', $order->id)->whereIn('state', [RadiologyReportVersion::VERIFIED, RadiologyReportVersion::AMENDED_VERIFIED])->orderByDesc('version')->first();
        if (! $latest) {
            return true;
        }
        $fingerprint = $this->radiologyFingerprints->current($latest);

        return ! $latest->acknowledgement()->where('report_fingerprint', $fingerprint)->exists();
    }

    private function diagnosticFingerprint(string $type, LaboratoryOrder|RadiologyOrder $order): string
    {
        return EmergencyCanonicalJson::digest([$type, $order->public_id, $order->version, $order->status, $this->verifiedResultFingerprint($type, $order)]);
    }

    private function assignmentScopeFingerprint(string $type, LaboratoryOrder|RadiologyOrder $order): string
    {
        if ($type === 'LABORATORY') {
            if (! $order instanceof LaboratoryOrder) {
                throw new EmergencyDenied('diagnostic_type_mismatch', 'Jenis diagnostik tidak cocok dengan pesanan.');
            }
            $snapshot = $this->laboratoryFingerprints->verifyOrderSnapshot($order);
        } elseif ($type === 'RADIOLOGY') {
            if (! $order instanceof RadiologyOrder) {
                throw new EmergencyDenied('diagnostic_type_mismatch', 'Jenis diagnostik tidak cocok dengan pesanan.');
            }
            $snapshot = EmergencyCanonicalJson::digest([
                $order->public_id,
                $order->encounter_id,
                $order->master_id,
                $order->ordered_by_user_id,
                $order->master_version,
                $order->master_version_public_id,
                $order->master_content_digest,
                $order->master_code,
                $order->master_display_name,
                $order->master_preparation_instruction,
                $order->care_setting,
                $order->encounter_status_snapshot,
                $order->encounter_number_snapshot,
                $order->care_location_label_snapshot,
                $order->clinical_indication,
                $order->ordered_at,
                $order->created_at,
            ]);
        } else {
            throw new EmergencyDenied('diagnostic_type_mismatch', 'Jenis diagnostik tidak cocok dengan pesanan.');
        }

        return EmergencyCanonicalJson::digest([$type, $order->public_id, $snapshot]);
    }

    private function verifiedResultFingerprint(string $type, LaboratoryOrder|RadiologyOrder $order): ?string
    {
        if ($type === 'LABORATORY' && $order instanceof LaboratoryOrder) {
            $latest = LaboratoryResultVersion::query()->where('laboratory_order_id', $order->id)->whereIn('state', [LaboratoryResultVersion::VERIFIED, LaboratoryResultVersion::AMENDED_VERIFIED])->orderByDesc('version')->first();

            return $latest ? $this->laboratoryFingerprints->current($latest) : null;
        }
        if ($type !== 'RADIOLOGY' || ! $order instanceof RadiologyOrder) {
            throw new EmergencyDenied('diagnostic_type_mismatch', 'Jenis diagnostik tidak cocok dengan pesanan.');
        }
        $latest = RadiologyReportVersion::query()->where('radiology_order_id', $order->id)->whereIn('state', [RadiologyReportVersion::VERIFIED, RadiologyReportVersion::AMENDED_VERIFIED])->orderByDesc('version')->first();

        return $latest ? $this->radiologyFingerprints->current($latest) : null;
    }

    private function text(string $value, int $min, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) < $min || mb_strlen($value) > $max) {
            throw new EmergencyDenied('validation_failed', 'Teks penugasan tindak lanjut tidak valid.');
        }

        return $value;
    }
}
