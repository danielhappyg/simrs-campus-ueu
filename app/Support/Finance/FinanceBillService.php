<?php

namespace App\Support\Finance;

use App\Models\Encounter;
use App\Models\FinanceBill;
use App\Models\FinanceBillLine;
use App\Models\FinanceBillVersion;
use App\Models\FinanceChargeEvent;
use App\Models\FinanceOperationReceipt;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinanceBillService
{
    public const OPERATION_SOURCE_SYNC = 'FINANCE_SOURCE_SYNC';

    public const OPERATION_BILL_ISSUE = 'FINANCE_BILL_ISSUE';

    public function __construct(
        private readonly FinanceActorPolicy $policy,
        private readonly FinanceSourceCoordinator $sources,
        private readonly FinanceEvidenceFingerprint $fingerprints,
        private readonly AuditRecorder $audit,
    ) {}

    public function synchronize(string $encounterPublicId, User $actor, string $idempotencyKey): FinanceMutationResult
    {
        $this->authorize($actor, self::OPERATION_SOURCE_SYNC, $encounterPublicId, fn () => $this->policy->issue($actor));
        $key = $this->key($idempotencyKey, $actor, self::OPERATION_SOURCE_SYNC, $encounterPublicId);
        $payloadDigest = FinanceCanonicalJson::digest([
            self::OPERATION_SOURCE_SYNC, ['encounter_public_id' => $encounterPublicId],
            'CROSS_SETTING_VERSIONED_ENCOUNTER_BILL_V1',
        ]);
        $candidate = null;
        try {
            $candidate = Encounter::query()->where('public_id', $encounterPublicId)->firstOrFail();

            return $this->transaction(function () use ($candidate, $actor, $key, $payloadDigest): FinanceMutationResult {
                $encounter = Encounter::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $encounter->load('patient');
                $bill = FinanceBill::query()->where('encounter_id', $encounter->id)->lockForUpdate()->first();
                if ($replay = $this->replay($actor, self::OPERATION_SOURCE_SYNC, $key, $payloadDigest, $encounter, FinanceOperationReceipt::RESULT_BILL)) {
                    return $replay;
                }

                $synchronization = $this->sources->synchronize($encounter, $actor);
                $events = $synchronization->events;
                $sourceDigest = $this->fingerprints->sourceSet($events);
                $bill ??= $this->createBill($encounter, $sourceDigest, $events->count());
                $bill = $this->synchronizeHead($bill, $sourceDigest, $events->count());
                $this->successAudit($actor, self::OPERATION_SOURCE_SYNC, $bill);
                $this->receipt($actor, self::OPERATION_SOURCE_SYNC, $key, $payloadDigest, FinanceOperationReceipt::RESULT_BILL, $bill, $events, null);

                return new FinanceMutationResult($bill, false);
            });
        } catch (AuthorizationException $exception) {
            $this->denial($actor, self::OPERATION_SOURCE_SYNC, $encounterPublicId, 'role_not_permitted');
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            $this->denial($actor, self::OPERATION_SOURCE_SYNC, $encounterPublicId, 'resource_not_found');
            throw $exception;
        } catch (FinanceDenied $exception) {
            $this->denial($actor, self::OPERATION_SOURCE_SYNC, $encounterPublicId, $exception->reason);
            throw $exception;
        } catch (UniqueConstraintViolationException $exception) {
            if ($candidate && ($replay = $this->replayOutsideTransaction($actor, self::OPERATION_SOURCE_SYNC, $key, $payloadDigest, $candidate, FinanceOperationReceipt::RESULT_BILL))) {
                return $replay;
            }
            $denied = new FinanceDenied('concurrent_state_conflict', 'Keadaan tagihan berubah bersamaan.');
            $this->denial($actor, self::OPERATION_SOURCE_SYNC, $encounterPublicId, $denied->reason);
            throw $denied;
        }
    }

    public function issue(string $billPublicId, User $actor, string $expectedFingerprint, string $issueReason, string $idempotencyKey): FinanceMutationResult
    {
        $this->authorize($actor, self::OPERATION_BILL_ISSUE, $billPublicId, fn () => $this->policy->issue($actor));
        $key = $this->key($idempotencyKey, $actor, self::OPERATION_BILL_ISSUE, $billPublicId);
        $reason = trim($issueReason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500 || ! preg_match('/\A[a-f0-9]{64}\z/', $expectedFingerprint)) {
            $denied = new FinanceDenied('validation_failed', 'Alasan penerbitan atau fingerprint tagihan tidak valid.');
            $this->denial($actor, self::OPERATION_BILL_ISSUE, $billPublicId, $denied->reason);
            throw $denied;
        }
        $payloadDigest = FinanceCanonicalJson::digest([
            self::OPERATION_BILL_ISSUE,
            ['bill_public_id' => $billPublicId, 'expected_fingerprint' => $expectedFingerprint, 'issue_reason' => $reason],
            'CROSS_SETTING_VERSIONED_ENCOUNTER_BILL_V1',
        ]);
        $candidate = null;
        try {
            $candidate = FinanceBill::query()->where('public_id', $billPublicId)->firstOrFail();

            return $this->transaction(function () use ($candidate, $actor, $key, $payloadDigest, $expectedFingerprint, $reason): FinanceMutationResult {
                $encounter = Encounter::query()->whereKey($candidate->encounter_id)->lockForUpdate()->firstOrFail();
                $encounter->load('patient');
                $bill = FinanceBill::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if ($replay = $this->replay($actor, self::OPERATION_BILL_ISSUE, $key, $payloadDigest, $encounter, FinanceOperationReceipt::RESULT_BILL_VERSION)) {
                    return $replay;
                }
                if ($encounter->status === Encounter::STATUS_CANCELLED) {
                    throw new FinanceDenied('encounter_cancelled', 'Encounter yang dibatalkan tidak dapat diterbitkan tagihannya.');
                }

                $synchronization = $this->sources->synchronize($encounter, $actor);
                $events = $synchronization->events;
                if ($synchronization->hasUnresolved()) {
                    $accommodationUnresolved = collect($synchronization->unresolved())->contains(
                        static fn (array $item): bool => ($item['source_domain'] ?? null) === FinanceChargeEvent::SOURCE_ACCOMMODATION,
                    );
                    $laboratoryUnresolved = collect($synchronization->unresolved())->contains(
                        static fn (array $item): bool => ($item['source_domain'] ?? null) === FinanceChargeEvent::SOURCE_LABORATORY,
                    );
                    throw new FinanceDenied(
                        $accommodationUnresolved
                            ? 'unresolved_accommodation_source'
                            : ($laboratoryUnresolved ? 'unresolved_laboratory_source' : 'unresolved_radiology_source'),
                        'Satu atau lebih sumber layanan belum memiliki biaya yang dapat diterbitkan.',
                    );
                }
                $sourceDigest = $this->fingerprints->sourceSet($events);
                $bill = $this->synchronizeHead($bill, $sourceDigest, $events->count());
                if (! hash_equals($expectedFingerprint, $this->fingerprints->bill($bill))) {
                    throw new FinanceDenied('stale_bill', 'Sumber biaya atau versi tagihan telah berubah. Muat ulang sebelum menerbitkan.');
                }
                if ($bill->current_version > 0 && hash_equals((string) $bill->current_issued_source_set_digest, $sourceDigest)) {
                    throw new FinanceDenied('source_set_unchanged', 'Tidak ada sumber biaya baru untuk versi berikutnya.');
                }

                $version = $this->createVersion($bill, $encounter, $actor, $events, $sourceDigest, $reason);
                $bill->fill([
                    'state' => FinanceBill::ISSUED_CURRENT,
                    'current_version' => $version->version,
                    'current_source_event_count' => $events->count(),
                    'current_source_set_digest' => $sourceDigest,
                    'current_issued_source_set_digest' => $sourceDigest,
                ]);
                $bill->current_content_digest = $this->fingerprints->billContent($bill);
                $bill->save();
                $this->successAudit($actor, self::OPERATION_BILL_ISSUE, $bill);
                $this->receipt($actor, self::OPERATION_BILL_ISSUE, $key, $payloadDigest, FinanceOperationReceipt::RESULT_BILL_VERSION, $bill, $events, $version);

                return new FinanceMutationResult($version, false);
            });
        } catch (AuthorizationException $exception) {
            $this->denial($actor, self::OPERATION_BILL_ISSUE, $billPublicId, 'role_not_permitted');
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            $this->denial($actor, self::OPERATION_BILL_ISSUE, $billPublicId, 'resource_not_found');
            throw $exception;
        } catch (FinanceDenied $exception) {
            $this->denial($actor, self::OPERATION_BILL_ISSUE, $billPublicId, $exception->reason);
            throw $exception;
        } catch (UniqueConstraintViolationException $exception) {
            $candidate = $candidate ?? FinanceBill::query()->where('public_id', $billPublicId)->first();
            if ($candidate && ($replay = $this->replayOutsideTransaction($actor, self::OPERATION_BILL_ISSUE, $key, $payloadDigest, $candidate->encounter, FinanceOperationReceipt::RESULT_BILL_VERSION))) {
                return $replay;
            }
            $denied = new FinanceDenied('concurrent_state_conflict', 'Keadaan tagihan berubah bersamaan.');
            $this->denial($actor, self::OPERATION_BILL_ISSUE, $billPublicId, $denied->reason);
            throw $denied;
        }
    }

    private function createBill(Encounter $encounter, string $sourceDigest, int $count): FinanceBill
    {
        $bill = new FinanceBill([
            'bill_number' => 'TAG-'.$encounter->public_id,
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'care_setting' => $encounter->care_setting,
            'state' => FinanceBill::OPEN_NO_VERSION,
            'current_version' => 0,
            'current_source_event_count' => $count,
            'current_source_set_digest' => $sourceDigest,
            'current_issued_source_set_digest' => null,
            'current_content_digest' => str_repeat('0', 64),
        ]);
        $bill->current_content_digest = $this->fingerprints->billContent($bill);
        $bill->save();

        return $bill;
    }

    private function synchronizeHead(FinanceBill $bill, string $sourceDigest, int $count): FinanceBill
    {
        $state = $bill->current_version === 0
            ? FinanceBill::OPEN_NO_VERSION
            : (hash_equals((string) $bill->current_issued_source_set_digest, $sourceDigest)
                ? FinanceBill::ISSUED_CURRENT
                : FinanceBill::NEW_SOURCE_PENDING);
        $bill->fill([
            'state' => $state,
            'current_source_event_count' => $count,
            'current_source_set_digest' => $sourceDigest,
        ]);
        $bill->current_content_digest = $this->fingerprints->billContent($bill);
        $bill->save();

        return $bill;
    }

    /** @param Collection<int, FinanceChargeEvent> $events */
    private function createVersion(FinanceBill $bill, Encounter $encounter, User $actor, Collection $events, string $sourceDigest, string $reason): FinanceBillVersion
    {
        $gross = (int) $events->where('event_type', FinanceChargeEvent::CHARGE)->sum('signed_amount');
        $reversal = (int) $events->where('event_type', FinanceChargeEvent::REVERSAL)->sum('signed_amount');
        $net = $gross + $reversal;
        if ($gross < 0 || $reversal > 0 || $net < 0 || $events->isEmpty()) {
            throw new FinanceDenied('over_reversal', 'Rekonsiliasi nilai tagihan tidak valid.');
        }
        // The encounter and mutable bill head are already locked by the
        // caller, so they serialize version issuance for this bill. Historical
        // versions remain append-only and need no UPDATE-class row lock.
        $previous = FinanceBillVersion::query()->where('bill_id', $bill->id)->orderByDesc('version')->first();
        $previousVersion = $previous instanceof FinanceBillVersion ? $previous->version : 0;
        if ($previousVersion !== $bill->current_version) {
            throw new FinanceDenied('stale_bill', 'Rantai versi tagihan tidak cocok dengan kepala tagihan.');
        }
        $ordered = $events->sort(fn (FinanceChargeEvent $a, FinanceChargeEvent $b): int => [
            $a->occurred_at->format('Y-m-d\TH:i:s.uP'), $a->source_domain, $a->source_public_id, $a->id,
        ] <=> [
            $b->occurred_at->format('Y-m-d\TH:i:s.uP'), $b->source_domain, $b->source_public_id, $b->id,
        ])->values();
        $now = now();
        $version = new FinanceBillVersion([
            'bill_id' => $bill->id,
            'previous_version_id' => $previous instanceof FinanceBillVersion ? $previous->id : null,
            'issued_by_user_id' => $actor->id,
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'version' => $bill->current_version + 1,
            'encounter_public_id_snapshot' => $encounter->public_id,
            'encounter_number_snapshot' => $encounter->public_id,
            'patient_public_id_snapshot' => $encounter->patient->public_id,
            'patient_name_snapshot' => $encounter->patient->full_name,
            'medical_record_number_snapshot' => $encounter->patient->medical_record_number,
            'care_setting' => $encounter->care_setting,
            'payer_snapshot' => $encounter->payer_type,
            'service_location_snapshot' => $this->location($encounter),
            'coverage_profile' => $this->coverageProfile($events),
            'source_set_digest' => $sourceDigest,
            'source_event_count' => $ordered->count(),
            'source_cutoff_at' => $ordered->max('occurred_at'),
            'gross_amount' => $gross,
            'reversal_amount' => $reversal,
            'net_amount' => $net,
            'issue_reason' => $reason,
            'content_digest' => str_repeat('0', 64),
            'issued_at' => $now,
            'created_at' => $now,
        ]);
        $lineModels = $ordered->map(function (FinanceChargeEvent $event, int $index): FinanceBillLine {
            $lineNumber = $index + 1;

            return new FinanceBillLine([
                'line_number' => $lineNumber,
                'charge_event_id' => $event->id,
                'source_domain' => $event->source_domain,
                'source_public_id' => $event->source_public_id,
                'source_content_digest' => $event->source_content_digest,
                'event_type' => $event->event_type,
                'quantity' => $event->quantity,
                'unit_amount' => $event->unit_amount,
                'signed_amount' => $event->signed_amount,
                'description' => $event->description,
                'charge_event_content_digest' => $event->content_digest,
                'content_digest' => $this->fingerprints->line($event, $lineNumber),
                'occurred_at' => $event->occurred_at,
                'created_at' => now(),
            ]);
        });
        $version->content_digest = $this->fingerprints->version($version, $lineModels);
        $version->save();
        foreach ($lineModels as $line) {
            $line->bill_version_id = $version->id;
            $line->save();
        }

        return $version->load('lines');
    }

    /** @param Collection<int, FinanceChargeEvent> $events */
    private function coverageProfile(Collection $events): string
    {
        if ($events->contains(
            static fn (FinanceChargeEvent $event): bool => $event->source_domain === FinanceChargeEvent::SOURCE_ACCOMMODATION,
        )) {
            return FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1;
        }

        if ($events->contains(
            static fn (FinanceChargeEvent $event): bool => $event->source_domain === FinanceChargeEvent::SOURCE_LABORATORY,
        )) {
            return FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_V1;
        }

        return $events->contains(
            static fn (FinanceChargeEvent $event): bool => $event->source_domain === FinanceChargeEvent::SOURCE_RADIOLOGY,
        )
            ? FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_V1
            : FinanceBillVersion::COVERAGE_PHARMACY_V1;
    }

    /** @param Collection<int, FinanceChargeEvent> $events */
    private function receipt(User $actor, string $operation, string $key, string $payloadDigest, string $type, FinanceBill $bill, Collection $events, ?FinanceBillVersion $version): void
    {
        $resultPublicId = $version !== null ? $version->public_id : $bill->public_id;
        $resultVersion = $version !== null ? $version->version : $bill->current_version;
        $resultState = $version !== null ? FinanceBill::ISSUED_CURRENT : $bill->state;
        $sourceDigest = $this->fingerprints->sourceSet($events);
        $evidenceDigest = $version !== null ? $version->content_digest : null;
        $resultDigest = FinanceCanonicalJson::digest([$type, $resultPublicId, $resultVersion, $resultState, $events->count(), $sourceDigest, $evidenceDigest]);
        FinanceOperationReceipt::query()->create([
            'actor_user_id' => $actor->id,
            'operation' => $operation,
            'idempotency_key' => $key,
            'payload_digest' => $payloadDigest,
            'result_type' => $type,
            'result_public_id' => $resultPublicId,
            'result_version' => $resultVersion,
            'result_state' => $resultState,
            'result_source_event_count' => $events->count(),
            'result_source_set_digest' => $sourceDigest,
            'result_digest' => $resultDigest,
            'request_correlation_id' => app()->bound('request') ? request()->attributes->get('request_id') : null,
            'completed_at' => now(),
        ]);
    }

    private function replay(User $actor, string $operation, string $key, string $payloadDigest, Encounter $encounter, string $type): ?FinanceMutationResult
    {
        $receipt = FinanceOperationReceipt::query()->where('actor_user_id', $actor->id)
            ->where('operation', $operation)->where('idempotency_key', $key)->first();
        if (! $receipt) {
            return null;
        }
        if (! hash_equals($receipt->payload_digest, $payloadDigest) || $receipt->result_type !== $type) {
            throw new FinanceDenied('idempotency_key_conflict', 'Kunci idempotensi sudah digunakan untuk muatan lain.');
        }
        $events = FinanceChargeEvent::query()->where('encounter_id', $encounter->id)
            ->orderBy('occurred_at')->orderBy('source_domain')->orderBy('source_public_id')->orderBy('id')
            ->limit($receipt->result_source_event_count)->get();
        if ($events->count() !== $receipt->result_source_event_count
            || ! hash_equals($receipt->result_source_set_digest, $this->fingerprints->sourceSet($events))) {
            throw new FinanceDenied('receipt_corrupt', 'Bukti operasi tagihan tidak valid.');
        }
        $this->sources->verifyRetained($encounter->loadMissing('patient'), $events);

        if ($type === FinanceOperationReceipt::RESULT_BILL_VERSION) {
            $record = FinanceBillVersion::query()->where('public_id', $receipt->result_public_id)->with('lines')->first();
            if (! $record || $record->version !== $receipt->result_version
                || ! hash_equals($record->source_set_digest, $receipt->result_source_set_digest)
                || ! hash_equals($record->content_digest, $this->fingerprints->version($record, $record->lines))) {
                throw new FinanceDenied('receipt_corrupt', 'Bukti versi tagihan tidak valid.');
            }
            $expected = FinanceCanonicalJson::digest([$type, $record->public_id, $record->version, $receipt->result_state, $events->count(), $receipt->result_source_set_digest, $record->content_digest]);
        } else {
            $record = FinanceBill::query()->where('public_id', $receipt->result_public_id)->first();
            if (! $record) {
                throw new FinanceDenied('receipt_corrupt', 'Bukti tagihan tidak valid.');
            }
            $expected = FinanceCanonicalJson::digest([$type, $record->public_id, $receipt->result_version, $receipt->result_state, $events->count(), $receipt->result_source_set_digest, null]);
            $view = clone $record;
            $attributes = $view->getAttributes();
            $attributes['state'] = $receipt->result_state;
            $attributes['current_version'] = $receipt->result_version;
            $attributes['current_source_event_count'] = $receipt->result_source_event_count;
            $attributes['current_source_set_digest'] = $receipt->result_source_set_digest;
            $view->setRawAttributes($attributes, true);
            $record = $view;
        }
        if (! hash_equals($receipt->result_digest, $expected)) {
            throw new FinanceDenied('receipt_corrupt', 'Bukti operasi tagihan tidak valid.');
        }

        return new FinanceMutationResult($record, true);
    }

    private function replayOutsideTransaction(User $actor, string $operation, string $key, string $payloadDigest, Encounter $encounter, string $type): ?FinanceMutationResult
    {
        return DB::transaction(fn () => $this->replay($actor, $operation, $key, $payloadDigest, $encounter, $type));
    }

    private function key(string $key, User $actor, string $operation, ?string $resource): string
    {
        $key = mb_strtolower(trim($key));
        if (! preg_match('/\A[a-z0-9][a-z0-9._:-]{7,254}\z/', $key)) {
            $denied = new FinanceDenied('validation_failed', 'Kunci idempotensi tidak valid.');
            $this->denial($actor, $operation, $resource, $denied->reason);
            throw $denied;
        }

        return $key;
    }

    private function transaction(callable $callback): mixed
    {
        return FinanceMutationScope::run(fn () => DB::transaction(function () use ($callback): mixed {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'pgsql') {
                DB::statement("SET LOCAL simrs.finance_mutation = '1'");
            } elseif ($driver === 'mysql') {
                DB::statement('SET @simrs_finance_mutation = 1');
            }
            try {
                return $callback();
            } finally {
                if ($driver === 'mysql') {
                    DB::statement('SET @simrs_finance_mutation = 0');
                }
            }
        }, 3));
    }

    private function authorize(User $actor, string $operation, ?string $resource, callable $authorization): void
    {
        try {
            $authorization();
        } catch (AuthorizationException $exception) {
            $this->denial($actor, $operation, $resource, 'role_not_permitted');
            throw $exception;
        }
    }

    private function successAudit(User $actor, string $operation, FinanceBill $bill): void
    {
        if ($this->audit->record('finance.workflow.mutate', 'finance_record', $bill->public_id, $actor, 'SUCCESS', metadata: [
            'operation' => $operation, 'state' => $bill->state, 'version' => $bill->current_version,
        ]) === null) {
            throw new FinanceAuditUnavailable('Audit keuangan tidak tersedia.');
        }
    }

    private function denial(User $actor, string $operation, ?string $resource, string $reason): void
    {
        $resource = is_string($resource) && Str::isUlid($resource) ? $resource : null;
        if ($this->audit->record('finance.workflow.mutate', 'finance_record', $resource, $actor, 'DENIED', $reason, [
            'operation' => $operation,
        ]) === null) {
            throw new FinanceAuditUnavailable('Audit penolakan keuangan tidak tersedia.');
        }
    }

    private function location(Encounter $encounter): string
    {
        if ($encounter->care_setting === Encounter::CARE_SETTING_INPATIENT) {
            return trim(implode(' · ', array_filter([$encounter->ward_name, $encounter->ward_class, $encounter->bed_code]))) ?: 'Rawat Inap';
        }

        return $encounter->clinic_name ?: $encounter->care_setting;
    }
}
