<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeCodingSourceOperationReceipt;
use App\Models\InpatientDischargeCodingSourceVersion;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientLocationEvent;
use App\Models\InpatientWard;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class InpatientDischargeCodingSourceService
{
    private const KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/';

    public function __construct(private readonly AuditRecorder $auditRecorder, private readonly InpatientDischargeCodingSourceActorPolicy $actorPolicy, private readonly CanonicalInpatientBedOperationLockCoordinator $locks) {}

    /** @param array<array-key,mixed> $fields */
    public function saveDraft(string $encounterPublicId, User $actor, string $definitionVersion, int $expectedVersion, array $fields, string $idempotencyKey, ?string $requestCorrelationId = null): InpatientDischargeCodingSourceResult
    {
        return $this->execute($encounterPublicId, $actor, $definitionVersion, $expectedVersion, $fields, $idempotencyKey, $requestCorrelationId, false);
    }

    public function finalize(string $encounterPublicId, User $actor, string $definitionVersion, int $expectedVersion, string $idempotencyKey, ?string $requestCorrelationId = null): InpatientDischargeCodingSourceResult
    {
        return $this->execute($encounterPublicId, $actor, $definitionVersion, $expectedVersion, null, $idempotencyKey, $requestCorrelationId, true);
    }

    /** @param array<array-key,mixed>|null $fields */
    private function execute(string $encounterPublicId, User $actor, string $definitionVersion, int $expectedVersion, ?array $fields, string $key, ?string $correlation, bool $finalize): InpatientDischargeCodingSourceResult
    {
        $action = $finalize ? 'clinical.inpatient.discharge-coding-source.finalize' : 'clinical.inpatient.discharge-coding-source.draft.save';
        try {
            $this->actorPolicy->authorize($actor);
        } catch (AuthorizationException $e) {
            if ($this->auditRecorder->record(action: $action, resourceType: 'inpatient_discharge_coding_source', resourceId: null, actor: $actor, outcome: 'DENIED', reason: 'unauthorized_actor', metadata: []) === null) {
                throw new InpatientDischargeCodingSourceAuditUnavailable('Required denial audit unavailable.');
            } throw $e;
        }
        try {
            $this->validateOperation($encounterPublicId, $definitionVersion, $expectedVersion, $key, $correlation);
            $normalized = $finalize ? null : $this->normalize($fields ?? [], false);
            $canonical = mb_strtolower($key);
            $operation = $finalize ? InpatientDischargeCodingSourceOperationReceipt::OPERATION_FINALIZE : InpatientDischargeCodingSourceOperationReceipt::OPERATION_DRAFT_SAVE;
            if ($replay = $this->replayBeforeMutation($encounterPublicId, $actor, $expectedVersion, $normalized, $canonical, $operation, $finalize)) {
                return $replay;
            }
            $attempt = 0;
            while (true) {
                try {
                    return $this->mutate($encounterPublicId, $actor, $expectedVersion, $normalized, $canonical, $correlation, $operation, $finalize, $action);
                } catch (InpatientDischargeCodingSourcePlacementChanged) {
                    if (++$attempt >= 3) {
                        throw new InpatientDischargeCodingSourceDenied('placement_stale', 'Penempatan berubah berulang kali. Silakan coba kembali.');
                    }
                }
            }
        } catch (UniqueConstraintViolationException) {
            if ($replay = $this->replayBeforeMutation($encounterPublicId, $actor, $expectedVersion, $fields === null ? null : $this->normalize($fields, false), mb_strtolower($key), $finalize ? InpatientDischargeCodingSourceOperationReceipt::OPERATION_FINALIZE : InpatientDischargeCodingSourceOperationReceipt::OPERATION_DRAFT_SAVE, $finalize)) {
                return $replay;
            } $d = new InpatientDischargeCodingSourceDenied('concurrent_change', 'Sumber diagnosis berubah bersamaan. Muat ulang.');
            $this->deny($action, $encounterPublicId, $actor, $d);
            throw $d;
        } catch (InpatientDischargeCodingSourceDenied $d) {
            $this->deny($action, $encounterPublicId, $actor, $d);
            throw $d;
        } catch (InvalidArgumentException $e) {
            $d = new InpatientDischargeCodingSourceDenied('validation_failed', $e->getMessage());
            $this->deny($action, $encounterPublicId, $actor, $d);
            throw $d;
        } catch (QueryException) {
            $d = new InpatientDischargeCodingSourceDenied('persistence_unavailable', 'Sumber diagnosis belum dapat disimpan.', 503);
            $this->deny($action, $encounterPublicId, $actor, $d);
            throw $d;
        }
    }

    /** @param array<string,mixed>|null $fields */
    private function mutate(string $encounterPublicId, User $actor, int $expectedVersion, ?array $fields, string $key, ?string $correlation, string $operation, bool $finalize, string $action): InpatientDischargeCodingSourceResult
    {
        return DB::transaction(function () use ($encounterPublicId, $actor, $expectedVersion, $fields, $key, $correlation, $operation, $finalize, $action): InpatientDischargeCodingSourceResult {
            [$encounter,$ward,$bed,$location,$baseline,$complete] = $this->lockPlacement($encounterPublicId);
            $summary = InpatientDischargeSummary::query()->where('encounter_id', $encounter->id)->lockForUpdate()->first();
            if (! $summary instanceof InpatientDischargeSummary) {
                throw new InpatientDischargeCodingSourceDenied('discharge_summary_missing', 'Simpan ringkasan pulang dan tetapkan dokter terlebih dahulu.');
            }
            if ($summary->assigned_physician_user_id !== $actor->id) {
                throw new InpatientDischargeCodingSourceDenied('physician_assignment_mismatch', 'Hanya dokter yang ditetapkan pada ringkasan pulang dapat menulis sumber diagnosis akhir.');
            }
            $source = InpatientDischargeCodingSource::query()->where('encounter_id', $encounter->id)->lockForUpdate()->first();
            if ($source instanceof InpatientDischargeCodingSource && $source->assigned_physician_user_id !== $actor->id) {
                throw new InpatientDischargeCodingSourceDenied('author_mismatch', 'Hanya penulis asli dapat mengubah sumber diagnosis akhir.');
            }
            if ($finalize && ! $source instanceof InpatientDischargeCodingSource) {
                throw new InpatientDischargeCodingSourceDenied('source_missing', 'Simpan draf sumber diagnosis sebelum finalisasi.');
            }
            $effective = $finalize ? $this->fieldsFrom($source) : ($fields ?? []);
            $digest = $this->digest(
                $encounterPublicId,
                $expectedVersion,
                $effective,
                $operation,
                $finalize ? $source->public_id : null,
            );
            $receipt = $this->receipt($actor, $operation, $key);
            if ($receipt instanceof InpatientDischargeCodingSourceOperationReceipt) {
                if (! hash_equals($receipt->payload_digest, $digest)) {
                    throw new InpatientDischargeCodingSourceDenied('idempotency_key_conflict', 'Kunci operasi telah dipakai untuk permintaan berbeda.');
                }

                return $this->replayResult($receipt);
            }
            $current = $source instanceof InpatientDischargeCodingSource ? $source->version : 0;
            if ($expectedVersion !== $current) {
                throw new InpatientDischargeCodingSourceDenied('stale_version', 'Sumber diagnosis telah berubah. Muat ulang.', metadata: ['expected_version' => $expectedVersion, 'current_version' => $current]);
            }
            if ($source instanceof InpatientDischargeCodingSource
                && $source->source_state === InpatientDischargeCodingSource::STATE_FINAL) {
                throw new InpatientDischargeCodingSourceDenied('source_final', 'Sumber diagnosis Final bersifat tetap.');
            }
            if ($finalize) {
                $effective = $this->normalize($effective, true);
            }
            $new = $current + 1;
            $state = $finalize ? InpatientDischargeCodingSource::STATE_FINAL : InpatientDischargeCodingSource::STATE_DRAFT;
            $finalizedAt = $finalize ? now() : null;
            InpatientDischargeCodingSourceMutationScope::run(function () use (&$source, $encounter, $actor, $effective, $new, $state, $finalizedAt, $ward, $bed, $location, $baseline, $complete): void {
                $head = ['finalized_by_user_id' => $finalizedAt ? $actor->id : null, 'source_state' => $state, 'version' => $new, ...$effective, 'finalized_at' => $finalizedAt];
                if (! $source instanceof InpatientDischargeCodingSource) {
                    $source = InpatientDischargeCodingSource::query()->create(['encounter_id' => $encounter->id, 'assigned_physician_user_id' => $actor->id, 'definition_version' => InpatientDischargeCodingSource::DEFINITION_VERSION, ...$head]);
                } else {
                    $source->update($head);
                }
                InpatientDischargeCodingSourceVersion::query()->create(['inpatient_discharge_coding_source_id' => $source->id, 'actor_user_id' => $actor->id, 'version' => $new, 'source_state' => $state, 'definition_version' => InpatientDischargeCodingSource::DEFINITION_VERSION, ...$effective, 'encounter_public_id' => $encounter->public_id, 'care_setting' => $encounter->care_setting, 'encounter_status' => $encounter->status, 'location_sequence' => $location instanceof InpatientLocationEvent ? $location->sequence : 0, 'location_event_public_id' => $location instanceof InpatientLocationEvent ? $location->public_id : null, 'location_event_type' => $location instanceof InpatientLocationEvent ? $location->event_type : null, 'history_baseline' => $baseline, 'history_complete' => $complete, 'ward_public_id' => $ward->public_id, 'ward_code' => $ward->code, 'ward_display_name' => $ward->display_name, 'bed_public_id' => $bed->public_id, 'bed_code' => $bed->code, 'bed_display_name' => $bed->display_name, 'room_label' => $bed->room_label, 'service_class' => $bed->service_class, 'finalized_at' => $finalizedAt]);
            });
            $metadata = ['encounter_public_id' => $encounter->public_id, 'source_state' => $state, 'source_version' => $new, 'definition_version' => InpatientDischargeCodingSource::DEFINITION_VERSION, 'assigned_physician_user_public_id' => $actor->public_id, 'location_sequence' => $location instanceof InpatientLocationEvent ? $location->sequence : 0, 'location_event_public_id' => $location instanceof InpatientLocationEvent ? $location->public_id : null, 'location_event_type' => $location instanceof InpatientLocationEvent ? $location->event_type : null, 'history_baseline' => $baseline, 'history_complete' => $complete, 'ward_public_id' => $ward->public_id, 'ward_code' => $ward->code, 'bed_public_id' => $bed->public_id, 'bed_code' => $bed->code, 'expected_version' => $expectedVersion, 'payload_digest' => $digest];
            if ($this->auditRecorder->record(action: $action, resourceType: 'inpatient_discharge_coding_source', resourceId: $source->public_id, actor: $actor, metadata: $metadata) === null) {
                throw new InpatientDischargeCodingSourceAuditUnavailable('Required coding-source audit unavailable.');
            }
            InpatientDischargeCodingSourceMutationScope::run(fn () => InpatientDischargeCodingSourceOperationReceipt::query()->create(['encounter_id' => $encounter->id, 'actor_user_id' => $actor->id, 'operation' => $operation, 'idempotency_key' => $key, 'payload_digest' => $digest, 'result_source_public_id' => $source->public_id, 'result_version' => $new, 'request_correlation_id' => $correlation, 'completed_at' => now()]));
            $version = InpatientDischargeCodingSourceVersion::query()->where('inpatient_discharge_coding_source_id', $source->id)->where('version', $new)->sole();

            return new InpatientDischargeCodingSourceResult($source->fresh(['assignedPhysician', 'versions']) ?? $source, $version, false);
        }, 3);
    }

    /** @return array{Encounter,InpatientWard,InpatientBed,InpatientLocationEvent|null,string|null,bool} */
    private function lockPlacement(string $publicId): array
    {
        $candidate = Encounter::query()->where('public_id', $publicId)->first();
        if (! $candidate instanceof Encounter) {
            throw new InpatientDischargeCodingSourceDenied('encounter_missing', 'Episode rawat inap tidak ditemukan.', 404);
        }$this->eligible($candidate);
        $candidateBed = $candidate->inpatient_bed_id ? InpatientBed::query()->find($candidate->inpatient_bed_id) : null;
        if (! $candidateBed instanceof InpatientBed) {
            throw new InpatientDischargeCodingSourceDenied('placement_missing', 'Penempatan terkelola tidak tersedia.');
        }
        $this->locks->lockMutexes([$candidateBed->code]);
        $encounter = $this->locks->lockEncounters([$candidate->id])->get($candidate->id);
        if (! $encounter instanceof Encounter || $encounter->inpatient_bed_id !== $candidateBed->id || $encounter->bed_code !== $candidateBed->code) {
            throw new InpatientDischargeCodingSourcePlacementChanged;
        }
        $ward = $this->locks->lockWards([$candidateBed->ward_id])->get($candidateBed->ward_id);
        $bed = $this->locks->lockBeds([$candidateBed->id])->get($candidateBed->id);
        if (! $ward instanceof InpatientWard || ! $bed instanceof InpatientBed || $bed->ward_id !== $ward->id) {
            throw new InpatientDischargeCodingSourceDenied('placement_stale', 'Penempatan telah berubah.');
        }$this->eligible($encounter);
        if ($encounter->cancellation()->exists()) {
            throw new InpatientDischargeCodingSourceDenied('encounter_cancelled', 'Episode telah dibatalkan.');
        }if ($ward->state !== InpatientWard::STATE_ACTIVE || $bed->state !== InpatientBed::STATE_ACTIVE) {
            throw new InpatientDischargeCodingSourceDenied('placement_inactive', 'Bangsal atau tempat tidur tidak aktif.');
        }
        $q = InpatientLocationEvent::query()->where('encounter_id', $encounter->id)->orderByDesc('sequence');
        $location = DB::connection()->getDriverName() === 'mysql' ? $q->sharedLock()->first() : $q->first();
        if ($location && ($location->to_bed_public_id !== $bed->public_id || $location->to_ward_public_id !== $ward->public_id)) {
            throw new InpatientDischargeCodingSourceDenied('placement_stale', 'Riwayat lokasi tidak cocok.');
        }
        if (! $location) {
            return [$encounter, $ward, $bed, null, InpatientDischargeSummary::HISTORY_BASELINE_LEGACY_CURRENT_PLACEMENT, false];
        }$first = InpatientLocationEvent::query()->where('encounter_id', $encounter->id)->orderBy('sequence')->value('event_type');

        return [$encounter, $ward, $bed, $location, null, $first === InpatientLocationEvent::TYPE_ADMISSION];
    }

    private function eligible(Encounter $e): void
    {
        if ($e->care_setting !== Encounter::CARE_SETTING_INPATIENT) {
            throw new InpatientDischargeCodingSourceDenied('not_inpatient', 'Sumber diagnosis akhir hanya untuk rawat inap.');
        }if (! in_array($e->status, Encounter::BED_OCCUPYING_STATUSES, true)) {
            throw new InpatientDischargeCodingSourceDenied($e->status === Encounter::STATUS_CANCELLED ? 'encounter_cancelled' : 'encounter_closed', 'Episode tidak aktif.');
        }if (! $e->patient()->where('is_synthetic', true)->exists()) {
            throw new InpatientDischargeCodingSourceDenied('synthetic_only', 'Episode berada di luar batas data.');
        }
    }

    private function validateOperation(string $id, string $definition, int $expected, string $key, ?string $correlation): void
    {
        if (! Str::isUlid($id)) {
            throw new InvalidArgumentException('Identitas episode tidak valid.');
        }if ($definition !== InpatientDischargeCodingSource::DEFINITION_VERSION) {
            throw new InvalidArgumentException('Versi definisi tidak didukung.');
        }if ($expected < 0) {
            throw new InvalidArgumentException('Versi tidak boleh negatif.');
        }if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('Kunci operasi harus 8-255 karakter aman.');
        }if ($correlation !== null && ! Str::isUlid($correlation)) {
            throw new InvalidArgumentException('Identitas korelasi tidak valid.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @return array{principal_diagnosis_statement: string, secondary_diagnosis_statements: list<string>, procedure_attestation: string, performed_procedure_statements: list<string>}
     */
    private function normalize(array $fields, bool $final): array
    {
        $keys = ['principal_diagnosis_statement', 'secondary_diagnosis_statements', 'procedure_attestation', 'performed_procedure_statements'];
        if (array_diff(array_keys($fields), $keys) !== [] || array_diff($keys, array_keys($fields)) !== []) {
            throw new InpatientDischargeCodingSourceDenied('validation_failed', 'Field sumber diagnosis harus tepat sesuai profil.');
        }
        $principal = $fields['principal_diagnosis_statement'];
        if (! is_string($principal) || ! mb_check_encoding($principal, 'UTF-8') || mb_strlen(trim($principal), 'UTF-8') > 500) {
            throw new InpatientDischargeCodingSourceDenied('validation_failed', 'Diagnosis utama harus teks UTF-8 maksimal 500 karakter.');
        }$principal = trim($principal);
        if ($final && $principal === '') {
            throw new InpatientDischargeCodingSourceDenied('validation_failed', 'Diagnosis utama wajib sebelum Final.');
        }
        $secondary = $this->statements($fields['secondary_diagnosis_statements'], 'diagnosis sekunder');
        $performed = $this->statements($fields['performed_procedure_statements'], 'prosedur');
        $attestation = $fields['procedure_attestation'];
        if (! is_string($attestation) || ! in_array($attestation, [InpatientDischargeCodingSource::ATTESTATION_NONE, InpatientDischargeCodingSource::ATTESTATION_RECORDED], true)) {
            throw new InpatientDischargeCodingSourceDenied('validation_failed', 'Pernyataan prosedur tidak valid.');
        }
        if (($attestation === InpatientDischargeCodingSource::ATTESTATION_NONE && $performed !== []) || ($attestation === InpatientDischargeCodingSource::ATTESTATION_RECORDED && $performed === [])) {
            throw new InpatientDischargeCodingSourceDenied('validation_failed', 'Daftar prosedur tidak sesuai dengan pernyataan prosedur.');
        }

        return ['principal_diagnosis_statement' => $principal === '' ? null : $principal, 'secondary_diagnosis_statements' => $secondary, 'procedure_attestation' => $attestation, 'performed_procedure_statements' => $performed];
    }

    /** @return list<string> */
    private function statements(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 20) {
            throw new InpatientDischargeCodingSourceDenied('validation_failed', "Daftar {$label} harus berupa maksimal 20 item.");
        }$out = [];
        foreach ($value as $v) {
            if (! is_string($v) || ! mb_check_encoding($v, 'UTF-8')) {
                throw new InpatientDischargeCodingSourceDenied('validation_failed', "Setiap {$label} harus teks UTF-8.");
            }$v = trim($v);
            if ($v === '' || mb_strlen($v, 'UTF-8') > 500 || in_array($v, $out, true)) {
                throw new InpatientDischargeCodingSourceDenied('validation_failed', "Setiap {$label} harus unik dan 1-500 karakter.");
            }$out[] = $v;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function fieldsFrom(?InpatientDischargeCodingSource $s): array
    {
        return [
            'principal_diagnosis_statement' => $s instanceof InpatientDischargeCodingSource ? $s->principal_diagnosis_statement : null,
            'secondary_diagnosis_statements' => $s instanceof InpatientDischargeCodingSource ? $s->secondary_diagnosis_statements : [],
            'procedure_attestation' => $s instanceof InpatientDischargeCodingSource ? $s->procedure_attestation : null,
            'performed_procedure_statements' => $s instanceof InpatientDischargeCodingSource ? $s->performed_procedure_statements : [],
        ];
    }

    /** @return array<string,mixed> */
    private function fieldsFromVersion(InpatientDischargeCodingSourceVersion $v): array
    {
        return ['principal_diagnosis_statement' => $v->principal_diagnosis_statement, 'secondary_diagnosis_statements' => $v->secondary_diagnosis_statements ?? [], 'procedure_attestation' => $v->procedure_attestation, 'performed_procedure_statements' => $v->performed_procedure_statements ?? []];
    }

    /** @param array<string,mixed> $fields */
    private function digest(string $encounter, int $expected, array $fields, string $operation, ?string $source): string
    {
        $payload = ['definition_version' => InpatientDischargeCodingSource::DEFINITION_VERSION, 'encounter_public_id' => $encounter, 'expected_version' => $expected, 'operation' => $operation];
        if ($operation === InpatientDischargeCodingSourceOperationReceipt::OPERATION_FINALIZE) {
            $payload['current_source_public_id'] = $source;
            $payload['current_source_version'] = $expected;
            $payload['stored_content_digest'] = hash('sha256', CanonicalJson::encode($fields));
        } else {
            $payload['fields'] = $fields;
        }

        return hash('sha256', CanonicalJson::encode($payload));
    }

    private function receipt(User $actor, string $operation, string $key): ?InpatientDischargeCodingSourceOperationReceipt
    {
        $q = InpatientDischargeCodingSourceOperationReceipt::query()->where('actor_user_id', $actor->id)->where('operation', $operation)->where('idempotency_key', $key);

        return DB::connection()->getDriverName() === 'mysql' ? $q->sharedLock()->first() : $q->first();
    }

    /** @param array<string,mixed>|null $fields */
    private function replayBeforeMutation(string $encounter, User $actor, int $expected, ?array $fields, string $key, string $operation, bool $final): ?InpatientDischargeCodingSourceResult
    {
        $r = $this->receipt($actor, $operation, $key);
        if (! $r) {
            return null;
        }$result = $this->replayResult($r);
        $effective = $final ? $this->fieldsFromVersion($result->resultVersion) : ($fields ?? []);
        $d = $this->digest($encounter, $expected, $effective, $operation, $final ? $r->result_source_public_id : null);
        if (! hash_equals($r->payload_digest, $d)) {
            throw new InpatientDischargeCodingSourceDenied('idempotency_key_conflict', 'Kunci operasi telah dipakai untuk permintaan berbeda.');
        }

        return $result;
    }

    private function replayResult(InpatientDischargeCodingSourceOperationReceipt $r): InpatientDischargeCodingSourceResult
    {
        $source = InpatientDischargeCodingSource::query()->where('public_id', $r->result_source_public_id)->first();
        $encounter = Encounter::query()->find($r->encounter_id);
        if (! $source || ! $encounter || $source->encounter_id !== $r->encounter_id) {
            throw new InpatientDischargeCodingSourceDenied('receipt_binding_invalid', 'Bukti operasi tidak terikat pada sumber yang benar.', 503);
        }$q = InpatientDischargeCodingSourceVersion::query()->where('inpatient_discharge_coding_source_id', $source->id)->where('version', $r->result_version);
        $v = DB::connection()->getDriverName() === 'mysql' ? $q->sharedLock()->first() : $q->first();
        if (! $v || $v->encounter_public_id !== $encounter->public_id) {
            throw new InpatientDischargeCodingSourceDenied('receipt_binding_invalid', 'Versi hasil operasi tidak valid.', 503);
        }$expected = $this->digest($encounter->public_id, $r->result_version - 1, $this->fieldsFromVersion($v), $r->operation, $r->operation === InpatientDischargeCodingSourceOperationReceipt::OPERATION_FINALIZE ? $source->public_id : null);
        if (! hash_equals($r->payload_digest, $expected)) {
            throw new InpatientDischargeCodingSourceDenied('receipt_binding_invalid', 'Digest bukti operasi tidak valid.', 503);
        }

        return new InpatientDischargeCodingSourceResult($source, $v, true);
    }

    private function deny(string $action, string $encounter, User $actor, InpatientDischargeCodingSourceDenied $d): void
    {
        if ($this->auditRecorder->record(action: $action, resourceType: 'encounter', resourceId: Str::isUlid($encounter) ? $encounter : null, actor: $actor, outcome: 'DENIED', reason: $d->reason, metadata: $d->metadata) === null) {
            throw new InpatientDischargeCodingSourceAuditUnavailable('Required denial audit unavailable.');
        }
    }
}
