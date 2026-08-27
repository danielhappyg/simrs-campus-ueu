<?php

namespace App\Support\Audit;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\LabDiagnosticResult;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientRmCompletenessReview;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Clinical\LabTestCatalog;

final class AuditEventSchemaRegistry
{
    /** @var list<string> */
    private const PRINT_DOCUMENTS = ['bukti', 'antrian', 'sep', 'gelang', 'kartu', 'consent'];

    /** @var list<string> */
    private const COMPLETENESS_ITEMS = [
        'IDENTITY_LINKED',
        'NURSING_FINAL',
        'NURSING_PROVENANCE',
        'MEDICAL_FINAL',
        'MEDICAL_REQUIRED_FIELDS',
        'MEDICAL_PROVENANCE',
        'NO_ACTIVE_LAB_ORDERS',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function assertAllows(
        string $action,
        string $resourceType,
        ?string $resourceId,
        bool $actorPresent,
        string $outcome,
        ?string $reason,
        array $metadata,
    ): void {
        $tuple = implode('|', [$action, $outcome, $resourceType]);

        switch ($tuple) {
            case 'authorization.rebuild_admin.reconciled|SUCCESS|user':
                $this->assertRebuildAdminReconciled($resourceId, $actorPresent, $reason, $metadata);

                return;
            case 'authorization.teaching_role.activated|SUCCESS|user':
                $this->assertTeachingRoleLifecycle($resourceId, $actorPresent, $reason, $metadata, activated: true);

                return;
            case 'authorization.teaching_role.revoked|SUCCESS|user':
                $this->assertTeachingRoleLifecycle($resourceId, $actorPresent, $reason, $metadata, activated: false);

                return;
            case 'authorization.teaching_role.compensated|SUCCESS|user':
                $this->assertTeachingRoleLifecycle($resourceId, $actorPresent, $reason, $metadata, activated: false);

                return;
            case 'teaching.reset.started|SUCCESS|simulation':
                $this->assertTeachingReset($resourceId, $reason, $metadata, false);

                return;
            case 'teaching.reset.completed|SUCCESS|simulation':
                $this->assertTeachingReset($resourceId, $reason, $metadata, true);

                return;
            case 'patient.register|SUCCESS|encounter':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertPatientRegistration($metadata);

                return;
            case 'clinical.note.write|SUCCESS|encounter':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertClinicalNote($metadata);

                return;
            case 'encounter.print|SUCCESS|encounter':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertEncounterPrint($metadata);

                return;
            case 'authorization.denied|DENIED|http_route':
                $this->assertActor($actorPresent);
                $this->assertRouteName($resourceId);
                $this->assertExactReason($reason, 'authorization_check_failed');
                $this->assertAuthorizationDenial($metadata);

                return;
            case 'clinical.lab.order.create|SUCCESS|lab_service_request':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertLabOrderSuccess($metadata);

                return;
            case 'clinical.lab.order.create|DENIED|encounter':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertExactReason($reason, 'encounter_closed');
                $this->assertLabOrderDenial($metadata);

                return;
            case 'clinical.lab.result.write|SUCCESS|lab_service_request':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertLabResultSuccess($metadata);

                return;
            case 'clinical.lab.result.write|DENIED|lab_service_request':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertReasonIn($reason, ['encounter_closed', 'result_already_final', 'order_not_active']);
                $this->assertLabResultDenial($metadata);

                return;
        }

        if ($this->isDocumentationAction($action)) {
            $this->assertDocumentation($action, $resourceType, $resourceId, $actorPresent, $outcome, $reason, $metadata);

            return;
        }

        if (in_array($action, ['rmik.completeness.review.save', 'rmik.completeness.signoff'], true)) {
            $this->assertCompleteness($action, $resourceType, $resourceId, $actorPresent, $outcome, $reason, $metadata);

            return;
        }

        throw new InvalidAuditEvent('Audit event tuple is not registered.');
    }

    /** @param array<string, mixed> $metadata */
    private function assertRebuildAdminReconciled(?string $resourceId, bool $actorPresent, ?string $reason, array $metadata): void
    {
        if ($actorPresent) {
            throw new InvalidAuditEvent('Rebuild-admin reconciliation attribution must use its operator field.');
        }

        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertText($reason, 'reason', 8, 255);
        $this->assertExactKeys($metadata, [
            'before',
            'after',
            'sessions_revoked',
            'operator',
            'reason',
            'disable_requested',
            'roles_changed',
            'status_changed',
            'mutated',
            'system_admin_bypass_remains',
            'note',
        ]);

        $before = $this->assertMap($metadata['before'], 'metadata.before');
        $after = $this->assertMap($metadata['after'], 'metadata.after');
        $this->assertExactKeys($before, ['roles', 'status']);
        $this->assertExactKeys($after, ['roles', 'status']);

        $canonical = [RoleCapabilityMatrix::ROLE_ADMIN];
        $knownDrift = [
            RoleCapabilityMatrix::ROLE_ADMIN,
            RoleCapabilityMatrix::ROLE_NURSE,
            RoleCapabilityMatrix::ROLE_PHYSICIAN,
            RoleCapabilityMatrix::ROLE_REGISTRAR,
            RoleCapabilityMatrix::ROLE_RMIK,
        ];
        $beforeRoles = $this->assertStringList($before['roles'], 'metadata.before.roles', 1, 5, 64);
        if ($beforeRoles !== $canonical && $beforeRoles !== $knownDrift) {
            throw new InvalidAuditEvent('Audit metadata.before.roles is not a registered reconciliation state.');
        }

        if ($this->assertStringList($after['roles'], 'metadata.after.roles', 1, 1, 64) !== $canonical) {
            throw new InvalidAuditEvent('Audit metadata.after.roles must be the canonical admin role.');
        }

        $this->assertStatus($before['status'], 'metadata.before.status');
        $this->assertStatus($after['status'], 'metadata.after.status');
        $this->assertNonNegativeInt($metadata['sessions_revoked'], 'metadata.sessions_revoked');
        $this->assertText($metadata['operator'], 'metadata.operator', 3, 255);
        $this->assertText($metadata['reason'], 'metadata.reason', 8, 255);

        if ($metadata['reason'] !== $reason) {
            throw new InvalidAuditEvent('Audit metadata.reason must match the top-level reason.');
        }

        foreach (['disable_requested', 'roles_changed', 'status_changed', 'mutated'] as $key) {
            $this->assertBool($metadata[$key], 'metadata.'.$key);
        }

        if ($metadata['system_admin_bypass_remains'] !== true) {
            throw new InvalidAuditEvent('Audit metadata.system_admin_bypass_remains must be true.');
        }

        if ($metadata['note'] !== 'is_system_administrator remains true; the system-admin capability bypass remains active.') {
            throw new InvalidAuditEvent('Audit metadata.note does not match the registered containment note.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertTeachingRoleLifecycle(
        ?string $resourceId,
        bool $actorPresent,
        ?string $reason,
        array $metadata,
        bool $activated,
    ): void {
        if ($actorPresent) {
            throw new InvalidAuditEvent('Teaching-role lifecycle attribution must use its bounded operator field.');
        }

        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertText($reason, 'reason', 8, 240);
        $this->assertExactKeys($metadata, [
            'target_email',
            'expected_role',
            'operator',
            'reason',
            'environment',
            'release_sha',
            'deployment_url',
            'canonical_host',
            'lease_public_id',
            'expires_at',
            'access_epoch',
            'activation_commitment',
            'login_state_commitment',
            'before',
            'after',
            'revoked',
            'login_material_rotated',
            'idempotent',
        ]);

        $roleByEmail = [
            'registrar.demo@example.invalid' => RoleCapabilityMatrix::ROLE_REGISTRAR,
            'nurse.demo@example.invalid' => RoleCapabilityMatrix::ROLE_NURSE,
            'physician.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHYSICIAN,
            'rmik.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RMIK,
        ];
        $targetEmail = $metadata['target_email'] ?? null;
        if (! is_string($targetEmail)
            || ! array_key_exists($targetEmail, $roleByEmail)
            || ($metadata['expected_role'] ?? null) !== $roleByEmail[$targetEmail]) {
            throw new InvalidAuditEvent('Teaching-role audit target is not an exact registered email and role pair.');
        }

        $target = User::query()
            ->where(function ($query) use ($targetEmail, $roleByEmail): void {
                $query->where('email', $targetEmail)
                    ->orWhere('teaching_access_roster_key', $roleByEmail[$targetEmail]);
            })
            ->where('public_id', $resourceId)
            ->first();
        if (! $target instanceof User || $target->public_id !== $resourceId) {
            throw new InvalidAuditEvent('Teaching-role audit resource ID does not match its registered target account.');
        }

        $this->assertText($metadata['operator'], 'metadata.operator', 3, 120);
        $this->assertText($metadata['reason'], 'metadata.reason', 8, 240);
        if ($metadata['reason'] !== $reason) {
            throw new InvalidAuditEvent('Teaching-role audit metadata.reason must match the top-level reason.');
        }

        if (! is_string($metadata['environment'])
            || preg_match('/\A[a-z0-9][a-z0-9._-]{2,63}\z/', $metadata['environment']) !== 1) {
            throw new InvalidAuditEvent('Teaching-role audit environment is invalid.');
        }
        if (! is_string($metadata['release_sha'])
            || preg_match('/\A[a-f0-9]{40}\z/', $metadata['release_sha']) !== 1) {
            throw new InvalidAuditEvent('Teaching-role audit release SHA is invalid.');
        }
        foreach (['deployment_url', 'canonical_host'] as $key) {
            if (! is_string($metadata[$key])
                || preg_match('/\A[a-z0-9][a-z0-9.-]{2,253}\z/', $metadata[$key]) !== 1) {
                throw new InvalidAuditEvent('Teaching-role audit deployment binding is invalid.');
            }
        }
        $this->assertNonNegativeInt($metadata['access_epoch'], 'metadata.access_epoch');
        if ($metadata['access_epoch'] < 1) {
            throw new InvalidAuditEvent('Teaching-role audit access epoch must be positive.');
        }
        foreach (['activation_commitment', 'login_state_commitment'] as $key) {
            $value = $metadata[$key];
            if ($value !== null && (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1)) {
                throw new InvalidAuditEvent('Teaching-role audit commitment is invalid.');
            }
        }
        if (! is_string($metadata['login_state_commitment'])) {
            throw new InvalidAuditEvent('Teaching-role audit login-state commitment is required.');
        }
        foreach (['lease_public_id', 'expires_at'] as $key) {
            if ($metadata[$key] !== null && ! is_string($metadata[$key])) {
                throw new InvalidAuditEvent('Teaching-role audit lease binding is invalid.');
            }
        }
        if ($activated) {
            $this->assertPublicId($metadata['lease_public_id'], 'metadata.lease_public_id');
            $this->assertText($metadata['expires_at'], 'metadata.expires_at', 20, 40);
            if (! is_string($metadata['activation_commitment'])) {
                throw new InvalidAuditEvent('Teaching-role activation requires its one-way activation commitment.');
            }
        } elseif ($metadata['activation_commitment'] !== null) {
            throw new InvalidAuditEvent('Teaching-role closure cannot carry an activation commitment.');
        }

        $before = $this->assertTeachingRoleState($metadata['before'], 'metadata.before');
        $after = $this->assertTeachingRoleState($metadata['after'], 'metadata.after');
        $revoked = $this->assertMap($metadata['revoked'], 'metadata.revoked');
        $this->assertExactKeys($revoked, ['sessions', 'passkeys', 'reset_records']);
        foreach (['sessions', 'passkeys', 'reset_records'] as $key) {
            $this->assertNonNegativeInt($revoked[$key], 'metadata.revoked.'.$key);
        }

        $this->assertBool($metadata['login_material_rotated'], 'metadata.login_material_rotated');
        $this->assertBool($metadata['idempotent'], 'metadata.idempotent');
        if ($metadata['idempotent'] === $metadata['login_material_rotated']) {
            throw new InvalidAuditEvent('Teaching-role audit idempotency and login-material rotation flags are inconsistent.');
        }

        $expectedStatus = $activated ? 'TEACHING_ACTIVE' : 'DISABLED';
        $expectedVerified = $activated;
        if ($after['status'] !== $expectedStatus
            || $after['verified'] !== $expectedVerified
            || $after['remember_present'] !== false
            || $after['mfa_present'] !== false
            || $after['sessions'] !== 0
            || $after['passkeys'] !== 0
            || $after['reset_records'] !== 0) {
            throw new InvalidAuditEvent('Teaching-role audit after state is not the registered closed lifecycle state.');
        }

        if ($metadata['idempotent'] === true && $before !== $after) {
            throw new InvalidAuditEvent('Idempotent teaching-role audit events require identical before and after states.');
        }
    }

    /** @return array<string, mixed> */
    private function assertTeachingRoleState(mixed $value, string $path): array
    {
        $state = $this->assertMap($value, $path);
        $this->assertExactKeys($state, [
            'status',
            'verified',
            'remember_present',
            'mfa_present',
            'sessions',
            'passkeys',
            'reset_records',
        ]);
        $this->assertStatus($state['status'], $path.'.status');
        foreach (['verified', 'remember_present', 'mfa_present'] as $key) {
            $this->assertBool($state[$key], $path.'.'.$key);
        }
        foreach (['sessions', 'passkeys', 'reset_records'] as $key) {
            $this->assertNonNegativeInt($state[$key], $path.'.'.$key);
        }

        return $state;
    }

    /** @param array<string, mixed> $metadata */
    private function assertTeachingReset(?string $resourceId, ?string $reason, array $metadata, bool $completed): void
    {
        if ($resourceId !== 'synthetic-reset') {
            throw new InvalidAuditEvent('Teaching reset resource_id must be synthetic-reset.');
        }

        if ($reason !== null) {
            $this->assertText($reason, 'reason', 1, 255);
        }

        $this->assertExactKeys($metadata, $completed
            ? ['boundary', 'deleted_patients', 'evidence_preserved', 'queue_counter_high_water_preserved']
            : ['boundary', 'evidence_preserved', 'queue_counter_high_water_preserved']);

        if ($metadata['boundary'] !== 'synthetic_patient_graph'
            || $metadata['evidence_preserved'] !== true
            || $metadata['queue_counter_high_water_preserved'] !== true) {
            throw new InvalidAuditEvent('Teaching reset evidence boundary is invalid.');
        }

        if ($completed) {
            $this->assertNonNegativeInt($metadata['deleted_patients'], 'metadata.deleted_patients');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertPatientRegistration(array $metadata): void
    {
        if (! array_key_exists('care_setting', $metadata)) {
            $this->assertExactKeys($metadata, [
                'patient_public_id', 'clinic_name', 'doctor_name', 'schedule_label', 'payer_type', 'queue_date', 'queue_number',
            ]);
            $this->assertClinicRegistrationFields($metadata);

            return;
        }

        if ($metadata['care_setting'] === Encounter::CARE_SETTING_EMERGENCY) {
            $this->assertExactKeys($metadata, [
                'care_setting', 'patient_public_id', 'clinic_name', 'doctor_name', 'schedule_label',
                'payer_type', 'case_type', 'accident_type', 'queue_date', 'queue_number',
            ]);
            $this->assertClinicRegistrationFields($metadata);
            $this->assertEnum($metadata['case_type'], Encounter::CASE_TYPE_VALUES, 'metadata.case_type');
            $this->assertEnum($metadata['accident_type'], Encounter::ACCIDENT_TYPE_VALUES, 'metadata.accident_type');

            return;
        }

        if ($metadata['care_setting'] === Encounter::CARE_SETTING_INPATIENT) {
            $this->assertExactKeys($metadata, [
                'care_setting', 'patient_public_id', 'ward_name', 'ward_class', 'bed_code',
                'continue_from', 'payer_type', 'queue_date', 'queue_number',
            ]);
            $this->assertNullablePublicId($metadata['patient_public_id'], 'metadata.patient_public_id');
            $this->assertText($metadata['ward_name'], 'metadata.ward_name', 1, 120);
            $this->assertText($metadata['ward_class'], 'metadata.ward_class', 1, 120);
            $this->assertText($metadata['bed_code'], 'metadata.bed_code', 1, 32);
            $this->assertEnum($metadata['continue_from'], Encounter::CONTINUE_FROM_VALUES, 'metadata.continue_from');
            $this->assertEnum($metadata['payer_type'], Encounter::PAYER_VALUES, 'metadata.payer_type');
            $this->assertDate($metadata['queue_date'], 'metadata.queue_date');
            $this->assertPositiveInt($metadata['queue_number'], 'metadata.queue_number');

            return;
        }

        throw new InvalidAuditEvent('Audit metadata.care_setting is not a registered patient-registration variant.');
    }

    /** @param array<string, mixed> $metadata */
    private function assertClinicRegistrationFields(array $metadata): void
    {
        $this->assertNullablePublicId($metadata['patient_public_id'], 'metadata.patient_public_id');
        $this->assertText($metadata['clinic_name'], 'metadata.clinic_name', 1, 255);
        $this->assertNullableText($metadata['doctor_name'], 'metadata.doctor_name', 255);
        $this->assertNullableText($metadata['schedule_label'], 'metadata.schedule_label', 255);
        $this->assertEnum($metadata['payer_type'], Encounter::PAYER_VALUES, 'metadata.payer_type');
        $this->assertDate($metadata['queue_date'], 'metadata.queue_date');
        $this->assertPositiveInt($metadata['queue_number'], 'metadata.queue_number');
    }

    /** @param array<string, mixed> $metadata */
    private function assertClinicalNote(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['care_setting', 'entry_type']);
        $this->assertEnum(
            $metadata['care_setting'],
            [Encounter::CARE_SETTING_EMERGENCY, Encounter::CARE_SETTING_INPATIENT],
            'metadata.care_setting',
        );
        $this->assertEnum($metadata['entry_type'], ClinicalEntry::TYPE_VALUES, 'metadata.entry_type');
    }

    /** @param array<string, mixed> $metadata */
    private function assertEncounterPrint(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['documents', 'teaching_only', 'live_bpjs']);
        $documents = $this->assertStringList($metadata['documents'], 'metadata.documents', 1, 6, 32);
        foreach ($documents as $document) {
            if (! in_array($document, self::PRINT_DOCUMENTS, true)) {
                throw new InvalidAuditEvent('Audit metadata.documents contains an unknown document.');
            }
        }

        if ($metadata['teaching_only'] !== true || $metadata['live_bpjs'] !== false) {
            throw new InvalidAuditEvent('Print audit must retain its teaching-only and no-live-BPJS boundary.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertAuthorizationDenial(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['http_method', 'http_status']);
        $this->assertEnum($metadata['http_method'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], 'metadata.http_method');

        if ($metadata['http_status'] !== 403) {
            throw new InvalidAuditEvent('Authorization denial status must be 403.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertDocumentation(
        string $action,
        string $resourceType,
        ?string $resourceId,
        bool $actorPresent,
        string $outcome,
        ?string $reason,
        array $metadata,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $documentType = str_starts_with($action, 'clinical.nursing.')
            ? OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT
            : OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT;

        if ($outcome === 'SUCCESS' && $resourceType === 'outpatient_clinical_document') {
            $this->assertNullReason($reason);
            $this->assertExactKeys($metadata, ['encounter_id', 'document_type', 'version', 'author_user_public_id', 'document_state']);
            $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
            $this->assertExactValue($metadata['document_type'], $documentType, 'metadata.document_type');
            $this->assertPositiveInt($metadata['version'], 'metadata.version');
            $this->assertPublicIdValue($metadata['author_user_public_id'], 'metadata.author_user_public_id');
            $expectedState = str_ends_with($action, '.finalize')
                ? OutpatientClinicalDocument::STATE_FINAL
                : OutpatientClinicalDocument::STATE_DRAFT;
            $this->assertExactValue($metadata['document_state'], $expectedState, 'metadata.document_state');

            return;
        }

        if ($outcome !== 'DENIED' || $resourceType !== 'encounter') {
            throw new InvalidAuditEvent('Documentation audit event tuple is not registered.');
        }

        $this->assertReasonIn($reason, [
            'encounter_closed', 'document_missing', 'validation_failed', 'stale_version', 'author_mismatch', 'document_final',
        ]);
        $required = ['document_type'];

        if ($reason === 'stale_version') {
            $required = ['document_type', 'expected_version', 'current_version'];
        } elseif ($reason === 'validation_failed') {
            $hasDigestList = array_key_exists('invalid_field_key_digests', $metadata);
            $hasDigestCount = array_key_exists('invalid_field_key_count', $metadata);
            if ($hasDigestList !== $hasDigestCount) {
                throw new InvalidAuditEvent('Invalid field-key digest metadata must include its total count.');
            }

            $detailGroups = array_filter([
                array_key_exists('invalid_field_key', $metadata),
                $hasDigestList,
                array_key_exists('missing_field_keys', $metadata),
            ]);
            if (count($detailGroups) > 1) {
                throw new InvalidAuditEvent('Validation denial metadata may contain only one field-key detail.');
            }

            if (array_key_exists('invalid_field_key', $metadata)) {
                $required[] = 'invalid_field_key';
            } elseif ($hasDigestList) {
                $required[] = 'invalid_field_key_digests';
                $required[] = 'invalid_field_key_count';
            } elseif (array_key_exists('missing_field_keys', $metadata)) {
                $required[] = 'missing_field_keys';
            }
        }

        $this->assertExactKeys($metadata, $required);
        $this->assertExactValue($metadata['document_type'], $documentType, 'metadata.document_type');

        if ($reason === 'stale_version') {
            $this->assertNonNegativeInt($metadata['expected_version'], 'metadata.expected_version');
            $this->assertNonNegativeInt($metadata['current_version'], 'metadata.current_version');
        }

        if (array_key_exists('invalid_field_key', $metadata)) {
            $this->assertText($metadata['invalid_field_key'], 'metadata.invalid_field_key', 1, 255);
        }
        if (array_key_exists('invalid_field_key_digests', $metadata)) {
            $digests = $this->assertStringList(
                $metadata['invalid_field_key_digests'],
                'metadata.invalid_field_key_digests',
                1,
                50,
                64,
            );
            foreach ($digests as $digest) {
                if (preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
                    throw new InvalidAuditEvent('Audit invalid_field_key_digests must contain SHA-256 hex digests.');
                }
            }
            $this->assertPositiveInt($metadata['invalid_field_key_count'], 'metadata.invalid_field_key_count');
            if ($metadata['invalid_field_key_count'] < count($digests)) {
                throw new InvalidAuditEvent('Audit invalid_field_key_count cannot be smaller than the digest list.');
            }
        }
        if (array_key_exists('missing_field_keys', $metadata)) {
            $this->assertStringList($metadata['missing_field_keys'], 'metadata.missing_field_keys', 1, 50, 255);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertLabOrderSuccess(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['encounter_id', 'test_code']);
        $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
        $this->assertEnum($metadata['test_code'], LabTestCatalog::codes(), 'metadata.test_code');
    }

    /** @param array<string, mixed> $metadata */
    private function assertLabOrderDenial(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['test_code']);
        $this->assertEnum($metadata['test_code'], LabTestCatalog::codes(), 'metadata.test_code');
    }

    /** @param array<string, mixed> $metadata */
    private function assertLabResultSuccess(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['encounter_id', 'test_code', 'result_status']);
        $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
        $this->assertEnum($metadata['test_code'], LabTestCatalog::codes(), 'metadata.test_code');
        $this->assertExactValue($metadata['result_status'], LabDiagnosticResult::STATUS_FINAL, 'metadata.result_status');
    }

    /** @param array<string, mixed> $metadata */
    private function assertLabResultDenial(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['encounter_id']);
        $this->assertNullablePublicId($metadata['encounter_id'], 'metadata.encounter_id');
    }

    /** @param array<string, mixed> $metadata */
    private function assertCompleteness(
        string $action,
        string $resourceType,
        ?string $resourceId,
        bool $actorPresent,
        string $outcome,
        ?string $reason,
        array $metadata,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');

        if ($outcome === 'SUCCESS' && $resourceType === 'outpatient_rm_completeness_review') {
            $this->assertNullReason($reason);
            $this->assertExactKeys($metadata, [
                'encounter_id', 'review_version', 'definition_version', 'source_fingerprint', 'failed_item_ids',
            ]);
            $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
            $this->assertPositiveInt($metadata['review_version'], 'metadata.review_version');
            $this->assertExactValue(
                $metadata['definition_version'],
                OutpatientRmCompletenessReview::DEFINITION_VERSION,
                'metadata.definition_version',
            );
            if (! is_string($metadata['source_fingerprint']) || preg_match('/\A[a-f0-9]{64}\z/', $metadata['source_fingerprint']) !== 1) {
                throw new InvalidAuditEvent('Audit metadata.source_fingerprint must be a SHA-256 hex digest.');
            }
            $failed = $this->assertStringList($metadata['failed_item_ids'], 'metadata.failed_item_ids', 0, 7, 64);
            $this->assertCompletenessItems($failed);

            return;
        }

        if ($outcome !== 'DENIED' || $resourceType !== 'encounter') {
            throw new InvalidAuditEvent('Completeness audit event tuple is not registered.');
        }

        $allowedReasons = ['encounter_not_ready', 'stale_version', 'source_stale'];
        if ($action === 'rmik.completeness.signoff') {
            $allowedReasons = array_merge($allowedReasons, ['active_lab_orders', 'checklist_incomplete']);
        }
        $this->assertReasonIn($reason, $allowedReasons);

        if (in_array($reason, ['active_lab_orders', 'checklist_incomplete'], true)) {
            $this->assertExactKeys($metadata, ['failed_item_ids']);
            $failed = $this->assertStringList($metadata['failed_item_ids'], 'metadata.failed_item_ids', 1, 7, 64);
            $this->assertCompletenessItems($failed);

            return;
        }

        $this->assertExactKeys($metadata, []);
    }

    /** @param list<string> $items */
    private function assertCompletenessItems(array $items): void
    {
        foreach ($items as $item) {
            if (! in_array($item, self::COMPLETENESS_ITEMS, true)) {
                throw new InvalidAuditEvent('Audit failed_item_ids contains an unknown item.');
            }
        }
    }

    private function isDocumentationAction(string $action): bool
    {
        return in_array($action, [
            'clinical.nursing.draft.save',
            'clinical.nursing.finalize',
            'clinical.medical.draft.save',
            'clinical.medical.finalize',
        ], true);
    }

    private function assertActor(bool $actorPresent): void
    {
        if (! $actorPresent) {
            throw new InvalidAuditEvent('Audit event requires an attributed actor.');
        }
    }

    private function assertPublicId(?string $value, string $path): void
    {
        $this->assertPublicIdValue($value, $path);
    }

    private function assertPublicIdValue(mixed $value, string $path): void
    {
        if (! is_string($value) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) !== 1) {
            throw new InvalidAuditEvent("Audit {$path} must be a ULID.");
        }
    }

    private function assertNullablePublicId(mixed $value, string $path): void
    {
        if ($value !== null) {
            $this->assertPublicIdValue($value, $path);
        }
    }

    private function assertRouteName(?string $value): void
    {
        if ($value === null || mb_strlen($value) > 255 || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new InvalidAuditEvent('Authorization audit resource_id must be a bounded route name.');
        }
    }

    private function assertNullReason(?string $reason): void
    {
        if ($reason !== null) {
            throw new InvalidAuditEvent('Audit reason must be null for this event.');
        }
    }

    private function assertExactReason(?string $reason, string $expected): void
    {
        if ($reason !== $expected) {
            throw new InvalidAuditEvent("Audit reason must be [{$expected}].");
        }
    }

    /** @param list<string> $allowed */
    private function assertReasonIn(?string $reason, array $allowed): void
    {
        if ($reason === null || ! in_array($reason, $allowed, true)) {
            throw new InvalidAuditEvent('Audit reason is not registered for this event.');
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $expected
     */
    private function assertExactKeys(array $metadata, array $expected): void
    {
        $actual = array_keys($metadata);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new InvalidAuditEvent('Audit metadata keys do not match the registered schema.');
        }
    }

    /** @return array<string, mixed> */
    private function assertMap(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidAuditEvent("Audit {$path} must be an object-shaped map.");
        }

        return $value;
    }

    private function assertText(mixed $value, string $path, int $minimum, int $maximum): void
    {
        if (! is_string($value) || mb_strlen($value) < $minimum || mb_strlen($value) > $maximum) {
            throw new InvalidAuditEvent("Audit {$path} must be a bounded string.");
        }
    }

    private function assertNullableText(mixed $value, string $path, int $maximum): void
    {
        if ($value !== null) {
            $this->assertText($value, $path, 1, $maximum);
        }
    }

    /** @param list<string> $allowed */
    private function assertEnum(mixed $value, array $allowed, string $path): void
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidAuditEvent("Audit {$path} is not an allowed value.");
        }
    }

    private function assertExactValue(mixed $value, string $expected, string $path): void
    {
        if ($value !== $expected) {
            throw new InvalidAuditEvent("Audit {$path} does not match the registered value.");
        }
    }

    private function assertBool(mixed $value, string $path): void
    {
        if (! is_bool($value)) {
            throw new InvalidAuditEvent("Audit {$path} must be a boolean.");
        }
    }

    private function assertNonNegativeInt(mixed $value, string $path): void
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidAuditEvent("Audit {$path} must be a non-negative integer.");
        }
    }

    private function assertPositiveInt(mixed $value, string $path): void
    {
        if (! is_int($value) || $value < 1) {
            throw new InvalidAuditEvent("Audit {$path} must be a positive integer.");
        }
    }

    private function assertStatus(mixed $value, string $path): void
    {
        if (! is_string($value) || preg_match('/\A[A-Z][A-Z0-9_]{0,31}\z/', $value) !== 1) {
            throw new InvalidAuditEvent("Audit {$path} must be a bounded status code.");
        }
    }

    private function assertDate(mixed $value, string $path): void
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidAuditEvent("Audit {$path} must be a valid YYYY-MM-DD date.");
        }
    }

    /** @return list<string> */
    private function assertStringList(mixed $value, string $path, int $minimum, int $maximum, int $maxStringLength): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $minimum || count($value) > $maximum) {
            throw new InvalidAuditEvent("Audit {$path} must be a bounded list.");
        }

        foreach ($value as $item) {
            $this->assertText($item, $path.'[]', 1, $maxStringLength);
        }

        return $value;
    }
}
