<?php

namespace App\Support\Pharmacy;

use App\Models\PharmacyDepot;
use App\Models\PharmacyDepotVersion;
use App\Models\PharmacyHandover;
use App\Models\PharmacyMedicine;
use App\Models\PharmacyMedicineVersion;
use App\Models\PharmacyOperationReceipt;
use App\Models\PharmacyPreparation;
use App\Models\PharmacyPrescription;
use App\Models\PharmacyPrescriptionVersion;
use App\Models\PharmacyReturn;
use App\Models\PharmacyStockLot;
use App\Models\PharmacyStockMovement;
use App\Models\PharmacyVerification;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class PharmacyOperationCoordinator
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function authorize(User $actor, string $operation, ?string $resource, callable $authorization): void
    {
        try {
            $authorization();
        } catch (AuthorizationException $exception) {
            $this->denial($actor, $operation, $resource, 'role_not_permitted');

            throw $exception;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $validation
     * @return T
     */
    public function validate(User $actor, string $operation, ?string $resource, callable $validation): mixed
    {
        try {
            return $validation();
        } catch (PharmacyDenied $exception) {
            $this->denial($actor, $operation, $resource, $exception->reason);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(): Model  $write
     */
    public function execute(User $actor, string $operation, ?string $resource, string $key, array $payload, string $resultType, callable $write): PharmacyMutationResult
    {
        $key = mb_strtolower(trim($key));
        if (! preg_match('/\A[a-z0-9][a-z0-9._:-]{7,254}\z/', $key)) {
            $denied = new PharmacyDenied('validation_failed', 'Kunci idempotensi tidak valid.');
            $this->denial($actor, $operation, $resource, $denied->reason);
            throw $denied;
        }
        $digest = PharmacyCanonicalJson::digest([$operation, $payload, 'CROSS_SETTING_MEDICATION_DISPENSING_STOCK_LEDGER_V1']);
        try {
            return PharmacyMutationScope::run(fn () => DB::transaction(function () use ($actor, $operation, $key, $digest, $resultType, $write): PharmacyMutationResult {
                $driver = DB::connection()->getDriverName();
                if ($driver === 'pgsql') {
                    DB::statement("SET LOCAL simrs.pharmacy_mutation = '1'");
                } elseif ($driver === 'mysql') {
                    DB::statement('SET @simrs_pharmacy_mutation = 1');
                }
                try {
                    if ($replay = $this->replay($actor, $operation, $key, $digest, $resultType)) {
                        return $replay;
                    }
                    $record = $write();
                    $state = $this->state($record);
                    $version = $this->version($record);
                    $recordPublicId = $this->publicId($record);
                    if ($this->audit->record('pharmacy.workflow.mutate', 'pharmacy_record', $recordPublicId, $actor, 'SUCCESS', metadata: ['operation' => $operation, 'state' => $state, 'version' => $version]) === null) {
                        throw new PharmacyAuditUnavailable('Audit farmasi tidak tersedia.');
                    }
                    PharmacyOperationReceipt::query()->create([
                        'actor_user_id' => $actor->id, 'operation' => $operation, 'idempotency_key' => $key,
                        'payload_digest' => $digest, 'result_type' => $resultType,
                        'result_public_id' => $recordPublicId, 'result_version' => $version,
                        'result_state' => $state, 'result_digest' => $this->resultDigest($record, $version, $state),
                        'request_correlation_id' => app()->bound('request') ? request()->attributes->get('request_id') : null,
                        'completed_at' => now(),
                    ]);

                    return new PharmacyMutationResult($record, false);
                } finally {
                    if ($driver === 'mysql') {
                        DB::statement('SET @simrs_pharmacy_mutation = 0');
                    }
                }
            }, 3));
        } catch (AuthorizationException $e) {
            $this->denial($actor, $operation, $resource, 'role_not_permitted');
            throw $e;
        } catch (ModelNotFoundException $e) {
            $this->denial($actor, $operation, $resource, 'resource_not_found');
            throw $e;
        } catch (PharmacyDenied $e) {
            $this->denial($actor, $operation, $resource, $e->reason);
            throw $e;
        } catch (UniqueConstraintViolationException) {
            if ($replay = $this->replay($actor, $operation, $key, $digest, $resultType)) {
                return $replay;
            }
            $denied = new PharmacyDenied('concurrent_state_conflict', 'Keadaan farmasi berubah bersamaan.');
            $this->denial($actor, $operation, $resource, $denied->reason);
            throw $denied;
        }
    }

    private function replay(User $actor, string $operation, string $key, string $payloadDigest, string $resultType): ?PharmacyMutationResult
    {
        $receipt = PharmacyOperationReceipt::query()->where('actor_user_id', $actor->id)->where('operation', $operation)->where('idempotency_key', $key)->first();
        if (! $receipt) {
            return null;
        }
        if (! hash_equals($receipt->payload_digest, $payloadDigest) || $receipt->result_type !== $resultType) {
            throw new PharmacyDenied('idempotency_key_conflict', 'Kunci idempotensi sudah digunakan untuk muatan lain.');
        }
        $record = $this->resolve($resultType, $receipt->result_public_id);
        if (! $record) {
            throw new PharmacyDenied('receipt_corrupt', 'Bukti operasi farmasi tidak valid.');
        }
        $computed = $this->retainedResultDigest($record, (int) $receipt->result_version, (string) $receipt->result_state);
        if (! hash_equals((string) $receipt->result_digest, $computed)) {
            throw new PharmacyDenied('receipt_corrupt', 'Bukti operasi farmasi tidak valid.');
        }

        return new PharmacyMutationResult($this->retainedView($record, $receipt), true);
    }

    private function resolve(string $type, string $publicId): ?Model
    {
        $class = match ($type) {
            PharmacyOperationReceipt::RESULT_MEDICINE => PharmacyMedicine::class,
            PharmacyOperationReceipt::RESULT_DEPOT => PharmacyDepot::class,
            PharmacyOperationReceipt::RESULT_LOT => PharmacyStockLot::class,
            PharmacyOperationReceipt::RESULT_PRESCRIPTION => PharmacyPrescription::class,
            PharmacyOperationReceipt::RESULT_VERIFICATION => PharmacyVerification::class,
            PharmacyOperationReceipt::RESULT_PREPARATION => PharmacyPreparation::class,
            PharmacyOperationReceipt::RESULT_HANDOVER => PharmacyHandover::class,
            PharmacyOperationReceipt::RESULT_RETURN => PharmacyReturn::class,
            default => null,
        };

        return $class ? $class::query()->where('public_id', $publicId)->first() : null;
    }

    private function state(Model $record): string
    {
        return (string) ($record->state ?? $record->status ?? $record->decision ?? 'RECORDED');
    }

    private function version(Model $record): int
    {
        return max(1, (int) ($record->version ?? $record->sequence ?? 1));
    }

    private function resultDigest(Model $record, int $version, string $state): string
    {
        return $this->retainedResultDigest($record, $version, $state);
    }

    private function retainedResultDigest(Model $record, int $version, string $state): string
    {
        $contentDigest = match (true) {
            $record instanceof PharmacyMedicine => PharmacyMedicineVersion::query()->where('medicine_id', $record->id)->where('version', $version)->value('content_digest'),
            $record instanceof PharmacyDepot => PharmacyDepotVersion::query()->where('depot_id', $record->id)->where('version', $version)->value('content_digest'),
            $record instanceof PharmacyPrescription => PharmacyPrescriptionVersion::query()->where('prescription_id', $record->id)->where('version', $version)->value('content_digest'),
            $record instanceof PharmacyStockLot => PharmacyStockMovement::query()->where('stock_lot_id', $record->id)->orderBy('id')->skip(max(0, $version - 1))->value('content_digest'),
            default => $record->getAttribute('content_digest'),
        };
        if (! is_string($contentDigest) || strlen($contentDigest) !== 64) {
            throw new PharmacyDenied('receipt_corrupt', 'Bukti hasil operasi farmasi tidak lengkap.');
        }

        return PharmacyCanonicalJson::digest([get_class($record), $this->publicId($record), $version, $state, $contentDigest]);
    }

    private function publicId(Model $record): string
    {
        $publicId = $record->getAttribute('public_id');
        if (! is_string($publicId) || strlen($publicId) !== 26) {
            throw new PharmacyDenied('receipt_corrupt', 'Bukti hasil operasi farmasi tidak memiliki identitas publik yang valid.');
        }

        return $publicId;
    }

    private function retainedView(Model $record, PharmacyOperationReceipt $receipt): Model
    {
        $view = clone $record;
        $attributes = $view->getAttributes();
        $attributes['version'] = (int) $receipt->result_version;
        if (array_key_exists('status', $attributes)) {
            $attributes['status'] = $receipt->result_state;
        } elseif (array_key_exists('state', $attributes)) {
            $attributes['state'] = $receipt->result_state;
        } elseif (array_key_exists('decision', $attributes)) {
            $attributes['decision'] = $receipt->result_state;
        }
        if ($record instanceof PharmacyPrescription) {
            $version = PharmacyPrescriptionVersion::query()->where('prescription_id', $record->id)->where('version', $receipt->result_version)->firstOrFail();
            $attributes['current_content_digest'] = $version->content_digest;
            $attributes['clinical_note'] = $version->content_snapshot['clinical_note'] ?? null;
        } elseif ($record instanceof PharmacyMedicine) {
            $version = PharmacyMedicineVersion::query()->where('medicine_id', $record->id)->where('version', $receipt->result_version)->firstOrFail();
            foreach (['generic_name', 'brand_name', 'strength_text', 'dosage_form', 'base_unit', 'route_choices', 'acquisition_value', 'teaching_sale_value'] as $field) {
                $attributes[$field] = $version->getAttribute($field);
            }
            $attributes['current_content_digest'] = $version->content_digest;
        } elseif ($record instanceof PharmacyDepot) {
            $version = PharmacyDepotVersion::query()->where('depot_id', $record->id)->where('version', $receipt->result_version)->firstOrFail();
            foreach (['display_name', 'eligible_care_settings'] as $field) {
                $attributes[$field] = $version->getAttribute($field);
            }
            $attributes['current_content_digest'] = $version->content_digest;
        } elseif ($record instanceof PharmacyStockLot) {
            $movement = PharmacyStockMovement::query()->where('stock_lot_id', $record->id)->orderBy('id')
                ->skip(max(0, ((int) $receipt->result_version) - 1))->first();
            if ($movement) {
                $attributes['available_quantity'] = $movement->available_balance_after;
                $attributes['quarantined_quantity'] = $movement->quarantined_balance_after;
                $attributes['content_digest'] = $movement->content_digest;
            }
        }
        $view->setRawAttributes($attributes, true);

        return $view;
    }

    private function denial(User $actor, string $operation, ?string $resource, string $reason): void
    {
        if ($this->audit->record('pharmacy.workflow.mutate', 'pharmacy_record', $resource, $actor, 'DENIED', $reason, ['operation' => $operation]) === null) {
            throw new PharmacyAuditUnavailable('Audit penolakan farmasi tidak tersedia.');
        }
    }
}
