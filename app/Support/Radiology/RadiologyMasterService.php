<?php

namespace App\Support\Radiology;

use App\Models\RadiologyExaminationMaster;
use App\Models\RadiologyExaminationMasterVersion;
use App\Models\RadiologyMasterCodeReservation;
use App\Models\RadiologyOperationReceipt;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class RadiologyMasterService
{
    public function __construct(private readonly RadiologyActorPolicy $policy, private readonly AuditRecorder $audit) {}

    public function create(User $actor, string $code, string $name, ?string $preparation, string $key): RadiologyMutationResult
    {
        return $this->audited($actor, 'RADIOLOGY_MASTER_CREATE', null, fn () => $this->createOperation($actor, $code, $name, $preparation, $key));
    }

    private function createOperation(User $actor, string $code, string $name, ?string $preparation, string $key): RadiologyMutationResult
    {
        $this->policy->master($actor);
        $code = RadiologyExaminationMaster::normalizeCode($code);
        $this->validate($code, $name, $preparation, $key);

        return $this->mutate($actor, 'RADIOLOGY_MASTER_CREATE', $key, compact('code', 'name', 'preparation'), function (callable $replay) use ($actor, $code, $name, $preparation) {
            if (RadiologyExaminationMaster::query()->where('examination_code', $code)->exists()) {
                throw new RadiologyDenied('master_code_conflict', 'Kode pemeriksaan sudah digunakan.');
            }
            if ($result = $replay()) {
                return $result;
            }
            RadiologyMasterCodeReservation::query()->create(['actor_user_id' => $actor->id, 'normalized_code' => $code, 'created_at' => now()]);
            $m = RadiologyExaminationMaster::query()->create(['examination_code' => $code, 'display_name' => trim($name), 'preparation_instruction' => $this->nullable($preparation), 'state' => RadiologyExaminationMaster::ACTIVE, 'version' => 1]);
            $this->version($m, $actor);

            return $m;
        });
    }

    public function revise(string $masterPublicId, User $actor, int $expectedVersion, string $name, ?string $preparation, string $state, string $key): RadiologyMutationResult
    {
        return $this->audited($actor, 'RADIOLOGY_MASTER_REVISE', $masterPublicId, fn () => $this->reviseOperation($masterPublicId, $actor, $expectedVersion, $name, $preparation, $state, $key));
    }

    private function reviseOperation(string $masterPublicId, User $actor, int $expectedVersion, string $name, ?string $preparation, string $state, string $key): RadiologyMutationResult
    {
        $this->policy->master($actor);
        $master = RadiologyExaminationMaster::query()->where('public_id', $masterPublicId)->firstOrFail();
        $this->validate($master->examination_code, $name, $preparation, $key);
        if (! in_array($state, [RadiologyExaminationMaster::ACTIVE, RadiologyExaminationMaster::RETIRED], true)) {
            throw new RadiologyDenied('invalid_master_state', 'Status master tidak valid.');
        }

        return $this->mutate($actor, 'RADIOLOGY_MASTER_REVISE', $key, compact('masterPublicId', 'expectedVersion', 'name', 'preparation', 'state'), function (callable $replay) use ($master, $actor, $expectedVersion, $name, $preparation, $state) {
            $m = RadiologyExaminationMaster::query()->whereKey($master->id)->lockForUpdate()->firstOrFail();
            if ($result = $replay()) {
                return $result;
            }
            if ($m->version !== $expectedVersion) {
                throw new RadiologyDenied('stale_version', 'Versi master sudah berubah.');
            }
            if ($m->state === RadiologyExaminationMaster::RETIRED) {
                throw new RadiologyDenied('master_retired', 'Master pensiun bersifat terminal.');
            }
            $m->fill(['display_name' => trim($name), 'preparation_instruction' => $this->nullable($preparation), 'state' => $state, 'version' => $m->version + 1])->save();
            $this->version($m, $actor);

            return $m;
        }, $master->public_id);
    }

    private function version(RadiologyExaminationMaster $m, User $a): void
    {
        RadiologyExaminationMasterVersion::query()->create(['radiology_examination_master_id' => $m->id, 'actor_user_id' => $a->id, 'version' => $m->version, 'display_name' => $m->display_name, 'preparation_instruction' => $m->preparation_instruction, 'state' => $m->state, 'content_digest' => hash('sha256', json_encode([$m->display_name, $m->preparation_instruction, $m->state], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'created_at' => now()]);
    }

    private function validate(string $c, string $n, ?string $p, string $k): void
    {
        if (! preg_match('/\A[A-Z0-9][A-Z0-9._-]{1,63}\z/', $c) || trim($n) === '' || mb_strlen(trim($n)) > 160 || ($p !== null && mb_strlen(trim($p)) > 2000)) {
            throw new RadiologyDenied('validation_failed', 'Data master tidak valid.');
        }$this->key($k);
    }

    private function nullable(?string $v): ?string
    {
        $v = $v === null ? null : trim($v);

        return $v === '' ? null : $v;
    }

    private function key(string $k): void
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/', $k)) {
            throw new RadiologyDenied('validation_failed', 'Kunci idempotensi tidak valid.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function mutate(User $a, string $op, string $key, array $payload, callable $write, ?string $resource = null): RadiologyMutationResult
    {
        $key = mb_strtolower(trim($key));
        $digest = hash('sha256', json_encode([$op, $payload, 'CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        try {
            return RadiologyMutationScope::run(fn () => DB::transaction(function () use ($a, $op, $key, $digest, $write): RadiologyMutationResult {
                if ($replayed = $this->replay($a, $op, $key, $digest)) {
                    return $replayed;
                }
                $written = $write(fn (): ?RadiologyMutationResult => $this->replay($a, $op, $key, $digest, true));
                if ($written instanceof RadiologyMutationResult) {
                    return $written;
                }
                $master = $written;
                $version = RadiologyExaminationMasterVersion::query()
                    ->where('radiology_examination_master_id', $master->id)
                    ->where('version', $master->version)
                    ->sole();
                $event = $this->audit->record('radiology.workflow.mutate', 'radiology_record', $master->public_id, $a, 'SUCCESS', metadata: ['operation' => $op, 'state' => $master->state, 'version' => (int) $master->version]);
                if ($event === null) {
                    throw new RadiologyAuditUnavailable('Audit radiologi gagal.');
                }
                RadiologyOperationReceipt::query()->create(['actor_user_id' => $a->id, 'operation' => $op, 'idempotency_key' => $key, 'payload_digest' => $digest, 'result_type' => RadiologyOperationReceipt::RESULT_MASTER, 'result_public_id' => $master->public_id, 'result_version' => $master->version, 'result_state' => $master->state, 'result_digest' => $this->masterVersionDigest($version), 'request_correlation_id' => request()->attributes->get('request_id'), 'completed_at' => now()]);

                return new RadiologyMutationResult($master, false);
            }, 3));
        } catch (UniqueConstraintViolationException) {
            if ($replayed = $this->replay($a, $op, $key, $digest, true)) {
                return $replayed;
            }
            throw new RadiologyDenied('concurrent_state_conflict', 'Operasi bersamaan telah mengubah keadaan.');
        }
    }

    private function replay(User $actor, string $operation, string $key, string $digest, bool $currentRead = false): ?RadiologyMutationResult
    {
        $receiptQuery = RadiologyOperationReceipt::query()->where('actor_user_id', $actor->id)
            ->where('operation', $operation)->where('idempotency_key', $key);
        if ($currentRead && DB::connection()->getDriverName() === 'mysql') {
            $receiptQuery->sharedLock();
        }
        $receipt = $receiptQuery->first();
        if (! $receipt instanceof RadiologyOperationReceipt) {
            return null;
        }
        if (! hash_equals($receipt->payload_digest, $digest) || $receipt->result_type !== RadiologyOperationReceipt::RESULT_MASTER) {
            throw new RadiologyDenied('idempotency_key_conflict', 'Kunci idempotensi sudah dipakai.');
        }
        $masterQuery = RadiologyExaminationMaster::query()->where('public_id', $receipt->result_public_id);
        if ($currentRead && DB::connection()->getDriverName() === 'mysql') {
            $masterQuery->sharedLock();
        }
        $master = $masterQuery->firstOrFail();
        $versionQuery = RadiologyExaminationMasterVersion::query()
            ->where('radiology_examination_master_id', $master->id)
            ->where('version', $receipt->result_version);
        if ($currentRead && DB::connection()->getDriverName() === 'mysql') {
            $versionQuery->sharedLock();
        }
        $version = $versionQuery->firstOrFail();
        $computedContent = hash('sha256', json_encode([$version->display_name, $version->preparation_instruction, $version->state], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($version->actor_user_id !== $receipt->actor_user_id
            || ! hash_equals($computedContent, (string) $version->content_digest)
            || ! hash_equals((string) $receipt->result_digest, $this->masterVersionDigest($version))
            || $receipt->result_state !== $version->state) {
            throw new RadiologyDenied('receipt_corrupt', 'Ikatan versi bukti master tidak cocok.');
        }

        return new RadiologyMutationResult($master, true);
    }

    private function masterVersionDigest(RadiologyExaminationMasterVersion $version): string
    {
        return hash('sha256', json_encode([
            $version->public_id,
            $version->radiology_examination_master_id,
            $version->actor_user_id,
            $version->version,
            $version->display_name,
            $version->preparation_instruction,
            $version->state,
            $version->content_digest,
            $version->created_at->toJSON(),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function audited(User $actor, string $operation, ?string $resource, callable $callback): RadiologyMutationResult
    {
        try {
            return $callback();
        } catch (AuthorizationException $denial) {
            $this->auditDenial($actor, $operation, $resource, 'role_not_permitted');
            throw $denial;
        } catch (ModelNotFoundException $denial) {
            $this->auditDenial($actor, $operation, $resource, 'resource_not_found');
            throw $denial;
        } catch (RadiologyDenied $denial) {
            $this->auditDenial($actor, $operation, $resource, $denial->reason);
            throw $denial;
        }
    }

    private function auditDenial(User $actor, string $operation, ?string $resource, string $reason): void
    {
        $resource = is_string($resource) && strlen($resource) === 26 ? $resource : null;
        if ($this->audit->record('radiology.workflow.mutate', 'radiology_record', $resource, $actor, 'DENIED', $reason, ['operation' => $operation]) === null) {
            throw new RadiologyAuditUnavailable('Audit penolakan master radiologi gagal.');
        }
    }
}
