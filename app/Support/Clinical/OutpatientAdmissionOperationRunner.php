<?php

namespace App\Support\Clinical;

use App\Models\Encounter;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Inpatient\InpatientAdmissionDenied;
use App\Support\Inpatient\InpatientMasterDenied;
use App\Support\Registration\InpatientBedUnavailable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;

/** Records business denials after the operation transaction has rolled back. */
final class OutpatientAdmissionOperationRunner
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function run(string $action, Encounter $encounter, User $actor, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (OutpatientDispositionDenied|InpatientAdmissionDenied|InpatientMasterDenied|InpatientBedUnavailable|UniqueConstraintViolationException|ModelNotFoundException $exception) {
            $denial = match (true) {
                $exception instanceof OutpatientDispositionDenied => $exception,
                $exception instanceof InpatientAdmissionDenied => new OutpatientDispositionDenied($exception->reason, $exception->getMessage(), $exception->httpStatus),
                $exception instanceof InpatientMasterDenied => new OutpatientDispositionDenied($exception->reasonCode, $exception->getMessage(), $exception->status),
                $exception instanceof InpatientBedUnavailable => new OutpatientDispositionDenied('bed_occupied', 'Tempat tidur sudah terisi. Pilih tempat tidur lain.', 409),
                $exception instanceof ModelNotFoundException => new OutpatientDispositionDenied('resource_not_found', 'Data kunjungan atau bukti klinis tidak ditemukan. Muat ulang halaman.', 404),
                default => new OutpatientDispositionDenied('concurrent_change', 'Permintaan berubah bersamaan. Muat ulang sebelum melanjutkan.', 409),
            };
            if ($this->audit->record(
                action: $action,
                resourceType: 'encounter',
                resourceId: $encounter->public_id,
                actor: $actor,
                outcome: 'DENIED',
                reason: $denial->reason,
            ) === null) {
                throw new OutpatientDispositionDenied('audit_unavailable', 'Permintaan dibatalkan karena audit wajib tidak tersedia.', 503);
            }

            throw $denial;
        }
    }
}
