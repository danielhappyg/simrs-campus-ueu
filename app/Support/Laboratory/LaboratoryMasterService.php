<?php

namespace App\Support\Laboratory;

use App\Models\LaboratoryExaminationMaster;
use App\Models\LaboratoryExaminationMasterVersion;
use App\Models\LaboratoryMasterCodeReservation;
use App\Models\LaboratoryOperationReceipt;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class LaboratoryMasterService
{
    public function __construct(private readonly LaboratoryActorPolicy $policy, private readonly AuditRecorder $audit, private readonly LaboratoryEvidenceFingerprint $fingerprints) {}

    /** @param array<int, mixed> $components */
    public function create(User $actor, string $code, string $name, string $specimenType, ?string $instruction, array $components, string $key): LaboratoryMutationResult
    {
        return $this->audited($actor, 'LABORATORY_MASTER_CREATE', null, fn () => $this->createOperation($actor, $code, $name, $specimenType, $instruction, $components, $key));
    }

    /** @param array<int, mixed> $components */
    private function createOperation(User $actor, string $code, string $name, string $specimenType, ?string $instruction, array $components, string $key): LaboratoryMutationResult
    {
        $this->policy->master($actor);
        $code = LaboratoryExaminationMaster::normalizeCode($code);
        [$name, $specimenType, $instruction, $components] = $this->normalize($code, $name, $specimenType, $instruction, $components, $key);

        return $this->mutate($actor, 'LABORATORY_MASTER_CREATE', $key, compact('code', 'name', 'specimenType', 'instruction', 'components'), function (callable $replay) use ($actor, $code, $name, $specimenType, $instruction, $components) {
            if ($result = $replay()) {
                return $result;
            }
            if (LaboratoryExaminationMaster::query()->where('examination_code', $code)->exists()) {
                throw new LaboratoryDenied('master_code_conflict', 'Kode pemeriksaan sudah digunakan.');
            }
            LaboratoryMasterCodeReservation::query()->create(['actor_user_id' => $actor->id, 'normalized_code' => $code, 'created_at' => now()]);
            $master = LaboratoryExaminationMaster::query()->create(['examination_code' => $code, 'display_name' => $name, 'specimen_type' => $specimenType, 'collection_instruction' => $instruction, 'components' => $components, 'state' => LaboratoryExaminationMaster::ACTIVE, 'version' => 1]);
            $this->version($master, $actor);

            return $master;
        });
    }

    /** @param array<int, mixed> $components */
    public function revise(string $masterPublicId, User $actor, int $expectedVersion, string $name, string $specimenType, ?string $instruction, array $components, string $state, string $key): LaboratoryMutationResult
    {
        return $this->audited($actor, 'LABORATORY_MASTER_REVISE', $masterPublicId, fn () => $this->reviseOperation($masterPublicId, $actor, $expectedVersion, $name, $specimenType, $instruction, $components, $state, $key));
    }

    /** @param array<int, mixed> $components */
    private function reviseOperation(string $masterPublicId, User $actor, int $expectedVersion, string $name, string $specimenType, ?string $instruction, array $components, string $state, string $key): LaboratoryMutationResult
    {
        $this->policy->master($actor);
        $candidate = LaboratoryExaminationMaster::query()->where('public_id', $masterPublicId)->firstOrFail();
        [$name, $specimenType, $instruction, $components] = $this->normalize($candidate->examination_code, $name, $specimenType, $instruction, $components, $key);
        $state = mb_strtoupper(trim($state));
        if (! in_array($state, [LaboratoryExaminationMaster::ACTIVE, LaboratoryExaminationMaster::RETIRED], true)) {
            throw new LaboratoryDenied('invalid_master_state', 'Status master tidak valid.');
        }

        return $this->mutate($actor, 'LABORATORY_MASTER_REVISE', $key, compact('masterPublicId', 'expectedVersion', 'name', 'specimenType', 'instruction', 'components', 'state'), function (callable $replay) use ($candidate, $actor, $expectedVersion, $name, $specimenType, $instruction, $components, $state) {
            $master = LaboratoryExaminationMaster::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($result = $replay()) {
                return $result;
            }
            if ($master->version !== $expectedVersion) {
                throw new LaboratoryDenied('stale_version', 'Versi master sudah berubah.');
            }
            if ($master->state === LaboratoryExaminationMaster::RETIRED) {
                throw new LaboratoryDenied('master_retired', 'Master pensiun bersifat terminal.');
            }
            $master->fill(['display_name' => $name, 'specimen_type' => $specimenType, 'collection_instruction' => $instruction, 'components' => $components, 'state' => $state, 'version' => $master->version + 1])->save();
            $this->version($master, $actor);

            return $master;
        });
    }

    private function version(LaboratoryExaminationMaster $master, User $actor): void
    {
        $contentDigest = $this->fingerprints->masterContentDigest($master->display_name, $master->specimen_type, $master->collection_instruction, $master->components, $master->state);
        LaboratoryExaminationMasterVersion::query()->create(['laboratory_examination_master_id' => $master->id, 'actor_user_id' => $actor->id, 'version' => $master->version, 'display_name' => $master->display_name, 'specimen_type' => $master->specimen_type, 'collection_instruction' => $master->collection_instruction, 'components' => $master->components, 'state' => $master->state, 'content_digest' => $contentDigest, 'created_at' => now()]);
    }

    /**
     * @param  array<int, mixed>  $components
     * @return array{string,string,?string,array<int,array{code:string,display_name:string,value_kind:string,unit_text:?string,reference_text:?string,critical_allowed:bool}>}
     */
    private function normalize(string $code, string $name, string $specimenType, ?string $instruction, array $components, string $key): array
    {
        $name = trim($name);
        $specimenType = trim($specimenType);
        $instruction = $this->optional($instruction, 2000);
        $this->key($key);
        if (! preg_match('/\A[A-Z0-9][A-Z0-9._-]{1,63}\z/', $code) || $name === '' || mb_strlen($name) > 160 || $specimenType === '' || mb_strlen($specimenType) > 120 || count($components) < 1 || count($components) > 12) {
            throw new LaboratoryDenied('validation_failed', 'Data master laboratorium tidak valid.');
        }
        $normalized = [];
        $seen = [];
        foreach (array_values($components) as $component) {
            if (! is_array($component)) {
                throw new LaboratoryDenied('validation_failed', 'Komponen pemeriksaan tidak valid.');
            }
            $componentCode = mb_strtoupper(trim((string) ($component['code'] ?? '')));
            $displayName = trim((string) ($component['display_name'] ?? ''));
            $valueKind = mb_strtoupper(trim((string) ($component['value_kind'] ?? '')));
            $unit = $this->optional(isset($component['unit_text']) ? (string) $component['unit_text'] : null, 80);
            $reference = $this->optional(isset($component['reference_text']) ? (string) $component['reference_text'] : null, 240);
            if (! preg_match('/\A[A-Z0-9][A-Z0-9._-]{1,63}\z/', $componentCode) || isset($seen[$componentCode]) || $displayName === '' || mb_strlen($displayName) > 160 || ! in_array($valueKind, ['TEXT', 'NUMERIC', 'QUALITATIVE'], true)) {
                throw new LaboratoryDenied('validation_failed', 'Komponen pemeriksaan tidak valid.');
            }
            $seen[$componentCode] = true;
            $normalized[] = ['code' => $componentCode, 'display_name' => $displayName, 'value_kind' => $valueKind, 'unit_text' => $unit, 'reference_text' => $reference, 'critical_allowed' => filter_var($component['critical_allowed'] ?? false, FILTER_VALIDATE_BOOL)];
        }

        return [$name, $specimenType, $instruction, $normalized];
    }

    private function optional(?string $value, int $max): ?string
    {
        $value = $value === null ? null : trim($value);
        if ($value !== null && mb_strlen($value) > $max) {
            throw new LaboratoryDenied('validation_failed', 'Teks terlalu panjang.');
        }

        return $value === '' ? null : $value;
    }

    private function key(string $key): void
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/', $key)) {
            throw new LaboratoryDenied('validation_failed', 'Kunci idempotensi tidak valid.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(callable(): ?LaboratoryMutationResult): (LaboratoryExaminationMaster|LaboratoryMutationResult)  $write
     */
    private function mutate(User $actor, string $operation, string $key, array $payload, callable $write): LaboratoryMutationResult
    {
        $key = mb_strtolower(trim($key));
        $digest = LaboratoryCanonicalJson::digest([$operation, $payload, 'CROSS_SETTING_LABORATORY_SPECIMEN_RESULT_V1']);
        try {
            return LaboratoryMutationScope::run(fn () => DB::transaction(function () use ($actor, $operation, $key, $digest, $write): LaboratoryMutationResult {
                if ($replay = $this->replay($actor, $operation, $key, $digest)) {
                    return $replay;
                }
                $written = $write(fn () => $this->replay($actor, $operation, $key, $digest, true));
                if ($written instanceof LaboratoryMutationResult) {
                    return $written;
                }
                $version = LaboratoryExaminationMasterVersion::query()->where('laboratory_examination_master_id', $written->id)->where('version', $written->version)->sole();
                if ($this->audit->record('laboratory.workflow.mutate', 'laboratory_record', $written->public_id, $actor, 'SUCCESS', metadata: ['operation' => $operation, 'state' => $written->state, 'version' => $written->version]) === null) {
                    throw new LaboratoryAuditUnavailable('Audit laboratorium gagal.');
                }
                LaboratoryOperationReceipt::query()->create(['actor_user_id' => $actor->id, 'operation' => $operation, 'idempotency_key' => $key, 'payload_digest' => $digest, 'result_type' => LaboratoryOperationReceipt::RESULT_MASTER, 'result_public_id' => $written->public_id, 'result_version' => $written->version, 'result_state' => $written->state, 'result_digest' => $this->versionDigest($version), 'request_correlation_id' => request()->attributes->get('request_id'), 'completed_at' => now()]);

                return new LaboratoryMutationResult($written, false);
            }, 3));
        } catch (UniqueConstraintViolationException) {
            if ($replay = $this->replay($actor, $operation, $key, $digest, true)) {
                return $replay;
            }
            throw new LaboratoryDenied('concurrent_state_conflict', 'Operasi bersamaan telah mengubah keadaan.');
        }
    }

    private function replay(User $actor, string $operation, string $key, string $digest, bool $current = false): ?LaboratoryMutationResult
    {
        $query = LaboratoryOperationReceipt::query()->where('actor_user_id', $actor->id)->where('operation', $operation)->where('idempotency_key', $key);
        if ($current && DB::connection()->getDriverName() === 'mysql') {
            $query->sharedLock();
        }
        $receipt = $query->first();
        if (! $receipt) {
            return null;
        }
        if (! hash_equals($receipt->payload_digest, $digest) || $receipt->result_type !== LaboratoryOperationReceipt::RESULT_MASTER) {
            throw new LaboratoryDenied('idempotency_key_conflict', 'Kunci idempotensi sudah dipakai.');
        }
        $master = LaboratoryExaminationMaster::query()->where('public_id', $receipt->result_public_id)->firstOrFail();
        $version = LaboratoryExaminationMasterVersion::query()->where('laboratory_examination_master_id', $master->id)->where('version', $receipt->result_version)->firstOrFail();
        $computed = $this->fingerprints->masterContentDigest($version->display_name, $version->specimen_type, $version->collection_instruction, $version->components, $version->state);
        if (! hash_equals($computed, (string) $version->content_digest) || ! hash_equals((string) $receipt->result_digest, $this->versionDigest($version)) || $receipt->result_state !== $version->state) {
            throw new LaboratoryDenied('receipt_corrupt', 'Bukti operasi master tidak valid.');
        }

        return new LaboratoryMutationResult($master, true);
    }

    private function versionDigest(LaboratoryExaminationMasterVersion $version): string
    {
        return LaboratoryCanonicalJson::digest([$version->public_id, $version->laboratory_examination_master_id, $version->actor_user_id, $version->version, $version->content_digest, $version->created_at->toJSON()]);
    }

    /** @param callable(): LaboratoryMutationResult $callback */
    private function audited(User $actor, string $operation, ?string $resource, callable $callback): LaboratoryMutationResult
    {
        try {
            return $callback();
        } catch (AuthorizationException $e) {
            $this->auditDenial($actor, $operation, $resource, 'role_not_permitted');
            throw $e;
        } catch (ModelNotFoundException $e) {
            $this->auditDenial($actor, $operation, $resource, 'resource_not_found');
            throw $e;
        } catch (LaboratoryDenied $e) {
            $this->auditDenial($actor, $operation, $resource, $e->reason);
            throw $e;
        }
    }

    private function auditDenial(User $actor, string $operation, ?string $resource, string $reason): void
    {
        $resource = is_string($resource) && strlen($resource) === 26 ? $resource : null;
        if ($this->audit->record('laboratory.workflow.mutate', 'laboratory_record', $resource, $actor, 'DENIED', $reason, ['operation' => $operation]) === null) {
            throw new LaboratoryAuditUnavailable('Audit penolakan laboratorium gagal.');
        }
    }
}
