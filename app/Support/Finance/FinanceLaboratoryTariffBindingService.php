<?php

namespace App\Support\Finance;

use App\Models\FinanceLaboratoryTariffBinding;
use App\Models\FinanceLaboratoryTariffBindingVersion;
use App\Models\FinanceLaboratoryTariffOperationReceipt;
use App\Models\FinanceTariffItem;
use App\Models\FinanceTariffItemVersion;
use App\Models\LaboratoryExaminationMaster;
use App\Models\LaboratoryExaminationMasterVersion;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinanceLaboratoryTariffBindingService
{
    private const DEFINITION = 'CROSS_SETTING_LABORATORY_VERIFIED_RESULT_TARIFF_SOURCE_V1';

    public function __construct(
        private readonly FinanceLaboratoryTariffActorPolicy $policy,
        private readonly FinanceLaboratoryTariffContentDigest $digests,
        private readonly FinanceTariffContentDigest $tariffDigests,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(
        User $actor,
        string $laboratoryMasterVersionPublicId,
        string $careSetting,
        string $tariffItemPublicId,
        string $effectiveFrom,
        string $reason,
        string $idempotencyKey,
        ?string $correlationId = null,
    ): FinanceLaboratoryTariffMutationResult {
        return $this->audited($actor, FinanceLaboratoryTariffOperation::CREATE, null, $careSetting, function () use (
            $actor, $laboratoryMasterVersionPublicId, $careSetting, $tariffItemPublicId,
            $effectiveFrom, $reason, $idempotencyKey, $correlationId,
        ): FinanceLaboratoryTariffMutationResult {
            $this->policy->manage($actor);
            $careSetting = $this->careSetting($careSetting);
            $effectiveFrom = $this->effectiveDate($effectiveFrom, true);
            $reason = $this->reason($reason);
            $this->ulid($laboratoryMasterVersionPublicId);
            $this->ulid($tariffItemPublicId);
            $this->correlation($correlationId);

            $payload = compact(
                'laboratoryMasterVersionPublicId', 'careSetting', 'tariffItemPublicId',
                'effectiveFrom', 'reason',
            );

            return $this->execute(
                $actor,
                FinanceLaboratoryTariffOperation::CREATE,
                $idempotencyKey,
                $payload,
                $correlationId,
                function () use ($actor, $laboratoryMasterVersionPublicId, $careSetting, $tariffItemPublicId, $effectiveFrom, $reason, $correlationId): FinanceLaboratoryTariffBinding {
                    [$master, $masterVersion] = $this->retainedMasterVersion($laboratoryMasterVersionPublicId);
                    $tariffItem = FinanceTariffItem::query()->where('public_id', $tariffItemPublicId)->lockForUpdate()->firstOrFail();
                    $this->assertTariffContext($tariffItem, $careSetting, $effectiveFrom);

                    $binding = new FinanceLaboratoryTariffBinding([
                        'laboratory_master_id' => $master->id,
                        'laboratory_master_version_id' => $masterVersion->id,
                        'laboratory_master_version_public_id' => $masterVersion->public_id,
                        'laboratory_master_version' => $masterVersion->version,
                        'laboratory_master_content_digest' => $masterVersion->content_digest,
                        'laboratory_master_code' => $master->examination_code,
                        'care_setting' => $careSetting,
                        'state' => FinanceLaboratoryTariffBinding::ACTIVE,
                        'version' => 1,
                        'latest_effective_from' => $effectiveFrom,
                        'current_content_digest' => str_repeat('0', 64),
                    ]);
                    $attributes = $this->versionAttributes($binding, $master, $tariffItem, $actor, FinanceLaboratoryTariffBinding::ACTIVE, $effectiveFrom, $reason, null, $correlationId);
                    $binding->current_content_digest = $this->digests->binding($attributes);
                    $binding->save();
                    $attributes['binding_id'] = $binding->id;
                    $attributes['content_digest'] = $binding->current_content_digest;
                    FinanceLaboratoryTariffBindingVersion::query()->create($attributes);

                    return $binding;
                },
            );
        });
    }

    public function appendVersion(
        User $actor,
        string $bindingPublicId,
        string $tariffItemPublicId,
        string $effectiveFrom,
        int $expectedVersion,
        string $expectedDigest,
        string $reason,
        string $idempotencyKey,
        ?string $correlationId = null,
    ): FinanceLaboratoryTariffMutationResult {
        return $this->audited($actor, FinanceLaboratoryTariffOperation::APPEND_VERSION, $bindingPublicId, null, function () use (
            $actor, $bindingPublicId, $tariffItemPublicId, $effectiveFrom, $expectedVersion,
            $expectedDigest, $reason, $idempotencyKey, $correlationId,
        ): FinanceLaboratoryTariffMutationResult {
            $this->policy->manage($actor);
            $this->ulid($bindingPublicId);
            $this->ulid($tariffItemPublicId);
            $effectiveFrom = $this->effectiveDate($effectiveFrom, true);
            $this->expected($expectedVersion, $expectedDigest);
            $reason = $this->reason($reason);
            $this->correlation($correlationId);
            $payload = compact(
                'bindingPublicId', 'tariffItemPublicId', 'effectiveFrom', 'expectedVersion',
                'expectedDigest', 'reason',
            );

            return $this->execute(
                $actor,
                FinanceLaboratoryTariffOperation::APPEND_VERSION,
                $idempotencyKey,
                $payload,
                $correlationId,
                function () use ($actor, $bindingPublicId, $tariffItemPublicId, $effectiveFrom, $expectedVersion, $expectedDigest, $reason, $correlationId): FinanceLaboratoryTariffBinding {
                    $binding = FinanceLaboratoryTariffBinding::query()->where('public_id', $bindingPublicId)->lockForUpdate()->firstOrFail();
                    $this->assertMutable($binding, $expectedVersion, $expectedDigest);
                    $this->assertNextDate($binding, $effectiveFrom);
                    $master = LaboratoryExaminationMaster::query()->whereKey($binding->laboratory_master_id)->firstOrFail();
                    $this->assertBindingMaster($binding);
                    $tariffItem = FinanceTariffItem::query()->where('public_id', $tariffItemPublicId)->lockForUpdate()->firstOrFail();
                    $this->assertTariffContext($tariffItem, $binding->care_setting, $effectiveFrom);
                    $previous = $binding->current_content_digest;
                    $binding->state = FinanceLaboratoryTariffBinding::ACTIVE;
                    $binding->version++;
                    $binding->latest_effective_from = Carbon::parse($effectiveFrom);
                    $attributes = $this->versionAttributes($binding, $master, $tariffItem, $actor, FinanceLaboratoryTariffBinding::ACTIVE, $effectiveFrom, $reason, $previous, $correlationId);
                    $binding->current_content_digest = $this->digests->binding($attributes);
                    $binding->save();
                    $attributes['binding_id'] = $binding->id;
                    $attributes['content_digest'] = $binding->current_content_digest;
                    FinanceLaboratoryTariffBindingVersion::query()->create($attributes);

                    return $binding;
                },
            );
        });
    }

    public function retire(
        User $actor,
        string $bindingPublicId,
        string $effectiveFrom,
        int $expectedVersion,
        string $expectedDigest,
        string $reason,
        string $idempotencyKey,
        ?string $correlationId = null,
    ): FinanceLaboratoryTariffMutationResult {
        return $this->audited($actor, FinanceLaboratoryTariffOperation::RETIRE, $bindingPublicId, null, function () use (
            $actor, $bindingPublicId, $effectiveFrom, $expectedVersion, $expectedDigest,
            $reason, $idempotencyKey, $correlationId,
        ): FinanceLaboratoryTariffMutationResult {
            $this->policy->manage($actor);
            $this->ulid($bindingPublicId);
            $effectiveFrom = $this->effectiveDate($effectiveFrom, true);
            $this->expected($expectedVersion, $expectedDigest);
            $reason = $this->reason($reason);
            $this->correlation($correlationId);
            $payload = compact('bindingPublicId', 'effectiveFrom', 'expectedVersion', 'expectedDigest', 'reason');

            return $this->execute(
                $actor,
                FinanceLaboratoryTariffOperation::RETIRE,
                $idempotencyKey,
                $payload,
                $correlationId,
                function () use ($actor, $bindingPublicId, $effectiveFrom, $expectedVersion, $expectedDigest, $reason, $correlationId): FinanceLaboratoryTariffBinding {
                    $binding = FinanceLaboratoryTariffBinding::query()->where('public_id', $bindingPublicId)->lockForUpdate()->firstOrFail();
                    $this->assertMutable($binding, $expectedVersion, $expectedDigest);
                    $this->assertNextDate($binding, $effectiveFrom);
                    $master = LaboratoryExaminationMaster::query()->whereKey($binding->laboratory_master_id)->firstOrFail();
                    $this->assertBindingMaster($binding);
                    $latest = FinanceLaboratoryTariffBindingVersion::query()->where('binding_id', $binding->id)->orderByDesc('version')->firstOrFail();
                    $tariffItem = FinanceTariffItem::query()->whereKey($latest->tariff_item_id)->firstOrFail();
                    $previous = $binding->current_content_digest;
                    $binding->state = FinanceLaboratoryTariffBinding::RETIRED;
                    $binding->version++;
                    $binding->latest_effective_from = Carbon::parse($effectiveFrom);
                    $attributes = $this->versionAttributes($binding, $master, $tariffItem, $actor, FinanceLaboratoryTariffBinding::RETIRED, $effectiveFrom, $reason, $previous, $correlationId);
                    $binding->current_content_digest = $this->digests->binding($attributes);
                    $binding->save();
                    $attributes['binding_id'] = $binding->id;
                    $attributes['content_digest'] = $binding->current_content_digest;
                    FinanceLaboratoryTariffBindingVersion::query()->create($attributes);

                    return $binding;
                },
            );
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(): FinanceLaboratoryTariffBinding  $write
     */
    private function execute(User $actor, string $operation, string $key, array $payload, ?string $correlationId, callable $write): FinanceLaboratoryTariffMutationResult
    {
        $key = mb_strtolower(trim($key));
        if (! preg_match('/\A[a-z0-9][a-z0-9._:-]{7,254}\z/', $key)) {
            throw new FinanceTariffDenied('validation_failed', 'Kunci idempotensi tidak valid.');
        }
        $payloadDigest = FinanceCanonicalJson::digest([$operation, $payload, self::DEFINITION]);

        try {
            return DB::transaction(function () use ($actor, $operation, $key, $payloadDigest, $correlationId, $write): FinanceLaboratoryTariffMutationResult {
                return FinanceLaboratoryTariffMutationScope::run(function () use ($actor, $operation, $key, $payloadDigest, $correlationId, $write): FinanceLaboratoryTariffMutationResult {
                    if ($replay = $this->replay($actor, $operation, $key, $payloadDigest)) {
                        $this->recordSuccess($actor, $operation, $replay->record, true, $correlationId);

                        return $replay;
                    }
                    $binding = $write();
                    $version = FinanceLaboratoryTariffBindingVersion::query()
                        ->where('binding_id', $binding->id)->where('version', $binding->version)->firstOrFail();
                    $this->recordSuccess($actor, $operation, $binding, false, $correlationId);
                    FinanceLaboratoryTariffOperationReceipt::query()->create([
                        'actor_user_id' => $actor->id,
                        'operation' => $operation,
                        'idempotency_key' => $key,
                        'payload_digest' => $payloadDigest,
                        'result_type' => FinanceLaboratoryTariffOperationReceipt::RESULT_BINDING,
                        'result_public_id' => $binding->public_id,
                        'result_version' => $binding->version,
                        'result_state' => $binding->state,
                        'result_digest' => $this->digests->retainedResult($binding->public_id, $binding->version, $binding->state, $version->content_digest),
                        'request_correlation_id' => $correlationId,
                        'completed_at' => now(),
                    ]);

                    return new FinanceLaboratoryTariffMutationResult($binding, false);
                });
            }, 3);
        } catch (UniqueConstraintViolationException) {
            if ($replay = $this->replay($actor, $operation, $key, $payloadDigest)) {
                $this->recordSuccess($actor, $operation, $replay->record, true, $correlationId);

                return $replay;
            }
            throw new FinanceTariffDenied('concurrent_state_conflict', 'Operasi bersamaan telah mengubah pemetaan tarif laboratorium.');
        } catch (FinanceTariffDenied $denied) {
            if (in_array($denied->reason, ['stale_version', 'stale_digest', 'concurrent_state_conflict'], true)
                && ($replay = $this->replay($actor, $operation, $key, $payloadDigest))) {
                $this->recordSuccess($actor, $operation, $replay->record, true, $correlationId);

                return $replay;
            }
            throw $denied;
        }
    }

    private function replay(User $actor, string $operation, string $key, string $payloadDigest): ?FinanceLaboratoryTariffMutationResult
    {
        $receipt = FinanceLaboratoryTariffOperationReceipt::query()
            ->where('actor_user_id', $actor->id)->where('operation', $operation)
            ->where('idempotency_key', $key)->first();
        if (! $receipt instanceof FinanceLaboratoryTariffOperationReceipt) {
            return null;
        }
        if (! hash_equals($receipt->payload_digest, $payloadDigest)
            || $receipt->result_type !== FinanceLaboratoryTariffOperationReceipt::RESULT_BINDING) {
            throw new FinanceTariffDenied('idempotency_key_conflict', 'Kunci idempotensi sudah digunakan untuk muatan lain.');
        }
        $binding = FinanceLaboratoryTariffBinding::query()->where('public_id', $receipt->result_public_id)->first();
        $version = $binding instanceof FinanceLaboratoryTariffBinding
            ? FinanceLaboratoryTariffBindingVersion::query()->where('binding_id', $binding->id)
                ->where('version', $receipt->result_version)->first()
            : null;
        if (! $binding instanceof FinanceLaboratoryTariffBinding || ! $version instanceof FinanceLaboratoryTariffBindingVersion
            || ! hash_equals($version->content_digest, $this->digests->bindingVersion($binding->loadMissing('laboratoryMaster'), $version))) {
            throw new FinanceTariffDenied('receipt_corrupt', 'Bukti operasi pemetaan tarif laboratorium tidak dapat direkonsiliasi.');
        }
        $computed = $this->digests->retainedResult(
            $binding->public_id,
            $receipt->result_version,
            $receipt->result_state,
            $version->content_digest,
        );
        if (! hash_equals($receipt->result_digest, $computed)) {
            throw new FinanceTariffDenied('receipt_corrupt', 'Digest bukti operasi pemetaan tarif laboratorium tidak valid.');
        }

        return new FinanceLaboratoryTariffMutationResult($this->retainedView($binding, $version), true);
    }

    private function retainedView(FinanceLaboratoryTariffBinding $binding, FinanceLaboratoryTariffBindingVersion $version): FinanceLaboratoryTariffBinding
    {
        $view = clone $binding;
        $attributes = $view->getAttributes();
        $attributes['state'] = $version->state;
        $attributes['version'] = $version->version;
        $attributes['latest_effective_from'] = $version->effective_from;
        $attributes['current_content_digest'] = $version->content_digest;
        $view->setRawAttributes($attributes, true);

        return $view;
    }

    /** @return array{LaboratoryExaminationMaster,LaboratoryExaminationMasterVersion} */
    private function retainedMasterVersion(string $publicId): array
    {
        $version = LaboratoryExaminationMasterVersion::query()->where('public_id', $publicId)->firstOrFail();
        $master = LaboratoryExaminationMaster::query()->whereKey($version->laboratory_examination_master_id)->firstOrFail();
        $computed = $this->digests->laboratoryMasterVersion(
            $version->display_name,
            $version->specimen_type,
            $version->collection_instruction,
            $version->components,
            $version->state,
        );
        if (! hash_equals($version->content_digest, $computed) || $version->version < 1) {
            throw new FinanceTariffDenied('master_version_mismatch', 'Versi master laboratorium tidak dapat direkonsiliasi.');
        }

        return [$master, $version];
    }

    private function assertBindingMaster(FinanceLaboratoryTariffBinding $binding): void
    {
        [$master, $version] = $this->retainedMasterVersion($binding->laboratory_master_version_public_id);
        if ($master->id !== $binding->laboratory_master_id || $version->id !== $binding->laboratory_master_version_id
            || $version->version !== $binding->laboratory_master_version
            || ! hash_equals($version->content_digest, $binding->laboratory_master_content_digest)
            || $master->examination_code !== $binding->laboratory_master_code) {
            throw new FinanceTariffDenied('master_version_mismatch', 'Ikatan master laboratorium telah berubah.');
        }
    }

    private function assertTariffContext(FinanceTariffItem $item, string $careSetting, string $effectiveFrom): FinanceTariffItemVersion
    {
        $version = FinanceTariffItemVersion::query()->where('tariff_item_id', $item->id)
            ->whereDate('effective_from', '<=', $effectiveFrom)
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
        if (! $version instanceof FinanceTariffItemVersion || $version->state !== FinanceTariffItem::ACTIVE) {
            throw new FinanceTariffDenied('tariff_not_effective', 'Item tarif tidak efektif pada tanggal pemetaan.');
        }
        if ($version->service_domain !== 'LABORATORY' || $version->care_setting !== $careSetting) {
            throw new FinanceTariffDenied('source_binding_invalid', 'Item tarif harus berdomain LABORATORY dan memiliki care setting yang sama.');
        }
        if (! hash_equals($version->content_digest, $this->tariffDigests->tariffVersion($version, $item->tariff_code))) {
            throw new FinanceTariffDenied('source_integrity_failure', 'Digest item tarif tidak valid.');
        }

        return $version;
    }

    /** @return array<string, mixed> */
    private function versionAttributes(FinanceLaboratoryTariffBinding $binding, LaboratoryExaminationMaster $master, FinanceTariffItem $item, User $actor, string $state, string $effectiveFrom, string $reason, ?string $previousDigest, ?string $correlationId): array
    {
        return [
            'actor_user_id' => $actor->id,
            'version' => $binding->version,
            'laboratory_master_public_id' => $master->public_id,
            'laboratory_master_version_public_id' => $binding->laboratory_master_version_public_id,
            'laboratory_master_version' => $binding->laboratory_master_version,
            'laboratory_master_content_digest' => $binding->laboratory_master_content_digest,
            'laboratory_master_code' => $binding->laboratory_master_code,
            'care_setting' => $binding->care_setting,
            'tariff_item_id' => $item->id,
            'tariff_item_public_id' => $item->public_id,
            'tariff_item_code' => $item->tariff_code,
            'state' => $state,
            'effective_from' => $effectiveFrom,
            'reason' => $reason,
            'previous_content_digest' => $previousDigest,
            'request_correlation_id' => $correlationId,
            'created_at' => now(),
        ];
    }

    private function assertMutable(FinanceLaboratoryTariffBinding $binding, int $expectedVersion, string $expectedDigest): void
    {
        if ($binding->state === FinanceLaboratoryTariffBinding::RETIRED) {
            throw new FinanceTariffDenied('binding_retired', 'Pemetaan yang telah dipensiunkan tidak dapat dibuka kembali.');
        }
        if ($binding->version !== $expectedVersion) {
            throw new FinanceTariffDenied('stale_version', 'Versi pemetaan sudah berubah.');
        }
        if (! hash_equals($binding->current_content_digest, $expectedDigest)) {
            throw new FinanceTariffDenied('stale_digest', 'Fingerprint pemetaan sudah berubah.');
        }
    }

    private function assertNextDate(FinanceLaboratoryTariffBinding $binding, string $effectiveFrom): void
    {
        if ($effectiveFrom <= $binding->latest_effective_from->format('Y-m-d')) {
            throw new FinanceTariffDenied('effective_date_not_after_latest', 'Tanggal efektif harus sesudah versi pemetaan terbaru.');
        }
    }

    private function expected(int $version, string $digest): void
    {
        if ($version < 1 || ! preg_match('/\A[a-f0-9]{64}\z/', $digest)) {
            throw new FinanceTariffDenied('validation_failed', 'Versi atau fingerprint yang diharapkan tidak valid.');
        }
    }

    private function careSetting(string $careSetting): string
    {
        $careSetting = mb_strtoupper(trim($careSetting));
        if (! in_array($careSetting, FinanceTariffItemVersion::CARE_SETTINGS, true)) {
            throw new FinanceTariffDenied('validation_failed', 'Care setting pemetaan tidak valid.');
        }

        return $careSetting;
    }

    private function effectiveDate(string $date, bool $denyPast): string
    {
        $date = trim($date);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if (! $parsed || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date) {
            throw new FinanceTariffDenied('validation_failed', 'Tanggal efektif harus menggunakan format YYYY-MM-DD.');
        }
        if ($denyPast && $date < now()->toDateString()) {
            throw new FinanceTariffDenied('retroactive_effective_date', 'Tanggal efektif pemetaan tidak boleh retrospektif.');
        }

        return $date;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new FinanceTariffDenied('validation_failed', 'Alasan pemetaan harus 3-500 karakter.');
        }

        return $reason;
    }

    private function ulid(string $publicId): void
    {
        if (! Str::isUlid($publicId)) {
            throw new FinanceTariffDenied('validation_failed', 'Identitas publik pemetaan tidak valid.');
        }
    }

    private function correlation(?string $correlationId): void
    {
        if ($correlationId !== null && ! Str::isUlid($correlationId)) {
            throw new FinanceTariffDenied('validation_failed', 'Identitas korelasi tidak valid.');
        }
    }

    private function recordSuccess(User $actor, string $operation, FinanceLaboratoryTariffBinding $binding, bool $replayed, ?string $correlationId): void
    {
        if ($this->audit->record(
            'finance.tariff.mutate',
            'finance_tariff_record',
            $binding->public_id,
            $actor,
            'SUCCESS',
            metadata: [
                'operation' => $operation,
                'entity_type' => 'LABORATORY_TARIFF_BINDING',
                'state' => $binding->state,
                'version' => $binding->version,
                'effective_from' => $binding->latest_effective_from->format('Y-m-d'),
                'replayed' => $replayed,
                'future_activation' => $binding->latest_effective_from->format('Y-m-d') > now()->toDateString(),
                'care_setting' => $binding->care_setting,
            ],
            request: $this->auditRequest($correlationId),
        ) === null) {
            throw new FinanceTariffAuditUnavailable('Perubahan pemetaan tarif laboratorium dibatalkan karena audit wajib tidak tersedia.');
        }
    }

    /** @param callable(): FinanceLaboratoryTariffMutationResult $callback */
    private function audited(User $actor, string $operation, ?string $resource, ?string $careSetting, callable $callback): FinanceLaboratoryTariffMutationResult
    {
        try {
            return $callback();
        } catch (AuthorizationException $exception) {
            $this->recordDenial($actor, $operation, null, 'role_not_permitted', $careSetting);
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            $this->recordDenial($actor, $operation, $resource, 'resource_not_found', $careSetting);
            throw $exception;
        } catch (FinanceTariffDenied $exception) {
            $this->recordDenial($actor, $operation, $resource, $exception->reason, $careSetting);
            throw $exception;
        }
    }

    private function recordDenial(User $actor, string $operation, ?string $resource, string $reason, ?string $careSetting): void
    {
        $resource = is_string($resource) && Str::isUlid($resource) ? $resource : null;
        $metadata = ['operation' => $operation, 'entity_type' => 'LABORATORY_TARIFF_BINDING'];
        if (is_string($careSetting) && in_array(mb_strtoupper(trim($careSetting)), FinanceTariffItemVersion::CARE_SETTINGS, true)) {
            $metadata['care_setting'] = mb_strtoupper(trim($careSetting));
        }
        if ($this->audit->record(
            'finance.tariff.mutate', 'finance_tariff_record', $resource, $actor, 'DENIED', $reason, $metadata,
        ) === null) {
            throw new FinanceTariffAuditUnavailable('Penolakan pemetaan tarif laboratorium tidak dapat diaudit.');
        }
    }

    private function auditRequest(?string $correlationId): Request
    {
        $request = request();
        if ($correlationId === null || $request->attributes->get('request_id') === $correlationId) {
            return $request;
        }
        $copy = $request->duplicate();
        $copy->attributes->set('request_id', $correlationId);

        return $copy;
    }
}
