<?php

namespace App\Support\Warehouse;

use App\Models\User;
use App\Models\WarehouseOperationReceipt;
use App\Models\WarehouseSupplier;
use App\Models\WarehouseSupplierVersion;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

final class WarehouseSupplierService
{
    public const OP_CREATE = 'WAREHOUSE_SUPPLIER_CREATE';

    public const OP_REVISE = 'WAREHOUSE_SUPPLIER_REVISE';

    public const OP_RETIRE = 'WAREHOUSE_SUPPLIER_RETIRE';

    private const REASON_CREATED = 'SUPPLIER_CREATED';

    private const REASON_RETIRED = 'SUPPLIER_RETIRED';

    private const REVISION_REASONS = [
        'DETAILS_UPDATED',
        'CONTACT_UPDATED',
        'REFERENCE_UPDATED',
        'DETAILS_AND_CONTACT_UPDATED',
    ];

    private const FIELDS = [
        'supplier_code', 'display_name', 'synthetic_contact_name', 'synthetic_email',
        'synthetic_phone', 'synthetic_reference',
    ];

    private const OPTIONAL_FIELDS = [
        'synthetic_contact_name', 'synthetic_email', 'synthetic_phone', 'synthetic_reference',
    ];

    public function __construct(
        private readonly WarehouseActorPolicy $policy,
        private readonly WarehouseOperationCoordinator $operations,
        private readonly WarehouseEvidenceFingerprint $fingerprints,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data, string $idempotencyKey, ?string $correlationId = null): WarehouseMutationResult
    {
        $authorization = $this->operations->authorize(
            $actor,
            self::OP_CREATE,
            null,
            fn () => $this->policy->authorizeSupplierOperation($actor, self::OP_CREATE),
        );
        $normalized = $this->operations->validate(
            $actor,
            self::OP_CREATE,
            null,
            fn (): array => $this->normalize($data, true),
        );

        return $this->operations->execute(
            $actor,
            self::OP_CREATE,
            null,
            $idempotencyKey,
            ['supplier' => $normalized, 'reason_code' => self::REASON_CREATED],
            WarehouseOperationReceipt::RESULT_SUPPLIER,
            $authorization,
            function (?string $resolvedCorrelationId, Connection $connection) use ($actor, $normalized): WarehouseOperationOutcome {
                if (WarehouseSupplier::on($connection->getName())
                    ->where('supplier_code', $normalized['supplier_code'])
                    ->lockForUpdate()
                    ->exists()) {
                    throw new WarehouseDenied('supplier_code_conflict', 'Kode pemasok sudah digunakan.');
                }

                $now = now()->startOfSecond();
                $supplier = new WarehouseSupplier;
                $supplier->setConnection($connection->getName());
                $supplier->public_id = (string) Str::ulid();

                $version = new WarehouseSupplierVersion;
                $version->setConnection($connection->getName());
                $version->public_id = (string) Str::ulid();
                $version->forceFill([
                    'actor_user_id' => $actor->id,
                    'version' => 1,
                    'supplier_code_snapshot' => $normalized['supplier_code'],
                    ...$this->versionFields($normalized),
                    'state' => WarehouseSupplier::ACTIVE,
                    'reason_code' => self::REASON_CREATED,
                    'previous_version_id' => null,
                    'previous_version_number' => null,
                    'previous_content_digest' => null,
                    'request_correlation_id' => $resolvedCorrelationId,
                    'created_at' => $now,
                ]);
                $version->content_digest = $this->fingerprints->supplierVersion($version, (string) $supplier->public_id);

                $supplier->forceFill([
                    'supplier_code' => $normalized['supplier_code'],
                    ...$this->versionFields($normalized),
                    'state' => WarehouseSupplier::ACTIVE,
                    'version' => 1,
                    'current_content_digest' => $version->content_digest,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $supplier->save();
                $version->supplier_id = $supplier->id;
                $version->save();

                return new WarehouseOperationOutcome(
                    $supplier,
                    1,
                    WarehouseSupplier::ACTIVE,
                    (string) $version->content_digest,
                    ['supplier_public_id' => $supplier->public_id],
                );
            },
            $correlationId,
        );
    }

    /** @param array<string, mixed> $data */
    public function revise(
        string $supplierPublicId,
        User $actor,
        int $expectedVersion,
        string $expectedDigest,
        array $data,
        string $reasonCode,
        string $idempotencyKey,
        ?string $correlationId = null,
    ): WarehouseMutationResult {
        $authorization = $this->operations->authorize(
            $actor,
            self::OP_REVISE,
            $supplierPublicId,
            fn () => $this->policy->authorizeSupplierOperation($actor, self::OP_REVISE),
        );
        [$normalized, $normalizedReason, $normalizedDigest] = $this->operations->validate(
            $actor,
            self::OP_REVISE,
            $supplierPublicId,
            function () use ($supplierPublicId, $expectedVersion, $expectedDigest, $data, $reasonCode): array {
                $this->assertPublicId($supplierPublicId);
                $this->assertExpectedVersion($expectedVersion);

                return [
                    $this->normalize($data, false),
                    $this->reasonCode($reasonCode),
                    $this->digest($expectedDigest),
                ];
            },
        );

        return $this->operations->execute(
            $actor,
            self::OP_REVISE,
            $supplierPublicId,
            $idempotencyKey,
            [
                'supplier_public_id' => $supplierPublicId,
                'expected_version' => $expectedVersion,
                'expected_digest' => $normalizedDigest,
                'supplier' => $normalized,
                'reason_code' => $normalizedReason,
            ],
            WarehouseOperationReceipt::RESULT_SUPPLIER,
            $authorization,
            function (?string $resolvedCorrelationId, Connection $connection) use ($supplierPublicId, $actor, $expectedVersion, $normalizedDigest, $normalized, $normalizedReason): WarehouseOperationOutcome {
                $supplier = WarehouseSupplier::on($connection->getName())
                    ->where('public_id', $supplierPublicId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $current = $this->assertCurrentIntegrity($connection, $supplier);
                $this->assertActiveAndExpected($supplier, $expectedVersion, $normalizedDigest);

                if (array_key_exists('supplier_code', $normalized)
                    && $normalized['supplier_code'] !== $supplier->supplier_code) {
                    throw new WarehouseDenied('validation_failed', 'Kode pemasok bersifat tetap dan tidak dapat diubah.');
                }
                $next = $this->mergedFields($supplier, $normalized);
                if ($this->sameVersionFields($current, $next)) {
                    throw new WarehouseDenied('validation_failed', 'Revisi pemasok tidak mengubah data apa pun.');
                }

                return $this->appendVersion(
                    $connection,
                    $supplier,
                    $current,
                    $actor,
                    $next,
                    WarehouseSupplier::ACTIVE,
                    $normalizedReason,
                    $resolvedCorrelationId,
                );
            },
            $correlationId,
        );
    }

    public function retire(
        string $supplierPublicId,
        User $actor,
        int $expectedVersion,
        string $expectedDigest,
        string $idempotencyKey,
        ?string $correlationId = null,
    ): WarehouseMutationResult {
        $authorization = $this->operations->authorize(
            $actor,
            self::OP_RETIRE,
            $supplierPublicId,
            fn () => $this->policy->authorizeSupplierOperation($actor, self::OP_RETIRE),
        );
        $normalizedDigest = $this->operations->validate(
            $actor,
            self::OP_RETIRE,
            $supplierPublicId,
            function () use ($supplierPublicId, $expectedVersion, $expectedDigest): string {
                $this->assertSimulationBoundary();
                $this->assertPublicId($supplierPublicId);
                $this->assertExpectedVersion($expectedVersion);

                return $this->digest($expectedDigest);
            },
        );

        return $this->operations->execute(
            $actor,
            self::OP_RETIRE,
            $supplierPublicId,
            $idempotencyKey,
            [
                'supplier_public_id' => $supplierPublicId,
                'expected_version' => $expectedVersion,
                'expected_digest' => $normalizedDigest,
                'reason_code' => self::REASON_RETIRED,
            ],
            WarehouseOperationReceipt::RESULT_SUPPLIER,
            $authorization,
            function (?string $resolvedCorrelationId, Connection $connection) use ($supplierPublicId, $actor, $expectedVersion, $normalizedDigest): WarehouseOperationOutcome {
                $supplier = WarehouseSupplier::on($connection->getName())
                    ->where('public_id', $supplierPublicId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $current = $this->assertCurrentIntegrity($connection, $supplier);
                $this->assertActiveAndExpected($supplier, $expectedVersion, $normalizedDigest);

                return $this->appendVersion(
                    $connection,
                    $supplier,
                    $current,
                    $actor,
                    $this->mergedFields($supplier, []),
                    WarehouseSupplier::RETIRED,
                    self::REASON_RETIRED,
                    $resolvedCorrelationId,
                );
            },
            $correlationId,
        );
    }

    /** @param array<string, mixed> $fields */
    private function appendVersion(
        Connection $connection,
        WarehouseSupplier $supplier,
        WarehouseSupplierVersion $current,
        User $actor,
        array $fields,
        string $state,
        string $reasonCode,
        ?string $correlationId,
    ): WarehouseOperationOutcome {
        $now = now()->startOfSecond();
        $nextVersion = (int) $supplier->version + 1;
        $version = new WarehouseSupplierVersion;
        $version->setConnection($connection->getName());
        $version->public_id = (string) Str::ulid();
        $version->forceFill([
            'supplier_id' => $supplier->id,
            'actor_user_id' => $actor->id,
            'version' => $nextVersion,
            'supplier_code_snapshot' => $supplier->supplier_code,
            ...$fields,
            'state' => $state,
            'reason_code' => $reasonCode,
            'previous_version_id' => $current->id,
            'previous_version_number' => $current->version,
            'previous_content_digest' => $current->content_digest,
            'request_correlation_id' => $correlationId,
            'created_at' => $now,
        ]);
        $version->content_digest = $this->fingerprints->supplierVersion($version, (string) $supplier->public_id);
        $version->save();

        $supplier->forceFill([
            ...$fields,
            'state' => $state,
            'version' => $nextVersion,
            'current_content_digest' => $version->content_digest,
            'updated_at' => $now,
        ]);
        $supplier->save();

        return new WarehouseOperationOutcome(
            $supplier,
            $nextVersion,
            $state,
            (string) $version->content_digest,
            ['supplier_public_id' => $supplier->public_id],
        );
    }

    private function assertCurrentIntegrity(Connection $connection, WarehouseSupplier $supplier): WarehouseSupplierVersion
    {
        $versions = WarehouseSupplierVersion::on($connection->getName())
            ->where('supplier_id', $supplier->id)
            ->orderBy('version')
            ->lockForUpdate()
            ->get();
        if ($versions->count() !== (int) $supplier->version) {
            throw new WarehouseDenied('evidence_fingerprint_invalid', 'Rantai versi pemasok tidak lengkap.');
        }

        $previousId = null;
        $previousNumber = null;
        $previousDigest = null;
        $retired = false;
        foreach ($versions as $offset => $version) {
            if ((int) $version->version !== $offset + 1
                || ! Str::isUlid((string) $version->public_id)
                || $version->supplier_code_snapshot !== $supplier->supplier_code
                || ($version->previous_version_id === null ? null : (int) $version->previous_version_id) !== $previousId
                || ($version->previous_version_number === null ? null : (int) $version->previous_version_number) !== $previousNumber
                || $version->previous_content_digest !== $previousDigest
                || $retired
                || ! $this->versionReasonMatchesState($version)
                || ! $this->versionUsesSyntheticMarkers($version)
                || ($version->request_correlation_id !== null && ! Str::isUlid((string) $version->request_correlation_id))
                || ! hash_equals(
                    (string) $version->content_digest,
                    $this->fingerprints->supplierVersion($version, (string) $supplier->public_id),
                )) {
                throw new WarehouseDenied('evidence_fingerprint_invalid', 'Rantai versi pemasok tidak dapat direkonsiliasi.');
            }

            $previousId = (int) $version->id;
            $previousNumber = (int) $version->version;
            $previousDigest = (string) $version->content_digest;
            $retired = $version->state === WarehouseSupplier::RETIRED;
        }

        $current = $versions->last();
        if (! $current instanceof WarehouseSupplierVersion
            || ! $this->sameVersionFields($current, $this->mergedFields($supplier, []))
            || $current->state !== $supplier->state
            || ! hash_equals((string) $supplier->current_content_digest, (string) $current->content_digest)) {
            throw new WarehouseDenied('evidence_fingerprint_invalid', 'Bukti versi pemasok tidak cocok dengan master saat ini.');
        }

        return $current;
    }

    private function assertActiveAndExpected(WarehouseSupplier $supplier, int $expectedVersion, string $expectedDigest): void
    {
        if ((int) $supplier->version !== $expectedVersion) {
            throw new WarehouseDenied('stale_version', 'Versi pemasok sudah berubah.');
        }
        if (! hash_equals((string) $supplier->current_content_digest, $expectedDigest)) {
            throw new WarehouseDenied('stale_fingerprint', 'Digest pemasok sudah berubah.');
        }
        if ($supplier->state !== WarehouseSupplier::ACTIVE) {
            throw new WarehouseDenied('master_retired', 'Pemasok sudah dihentikan dan tidak dapat diubah lagi.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, bool $creating): array
    {
        $this->assertSimulationBoundary();
        $unknown = array_diff(array_keys($data), self::FIELDS);
        if ($unknown !== []) {
            throw new WarehouseDenied('validation_failed', 'Data pemasok memuat kolom yang tidak diizinkan.');
        }
        if ($creating && (! array_key_exists('supplier_code', $data)
            || ! array_key_exists('display_name', $data)
            || ! array_key_exists('synthetic_reference', $data))) {
            throw new WarehouseDenied('validation_failed', 'Kode, nama, dan referensi sintetis pemasok wajib diisi.');
        }
        if (! $creating && $data === []) {
            throw new WarehouseDenied('validation_failed', 'Data revisi pemasok wajib diisi.');
        }

        $normalized = [];
        if (array_key_exists('supplier_code', $data)) {
            if (! is_string($data['supplier_code']) || ! mb_check_encoding($data['supplier_code'], 'UTF-8')) {
                throw new WarehouseDenied('validation_failed', 'Kode pemasok tidak valid.');
            }
            $code = WarehouseSupplier::normalizeCode($data['supplier_code']);
            if (preg_match('/\ASYN-[A-Z0-9][A-Z0-9._-]{0,59}\z/', $code) !== 1) {
                throw new WarehouseDenied('validation_failed', 'Kode pemasok harus memakai penanda SYN- dan maksimum 64 karakter.');
            }
            $normalized['supplier_code'] = $code;
        }
        if (array_key_exists('display_name', $data)) {
            $normalized['display_name'] = $this->requiredText($data['display_name'], 2, 160, 'Nama pemasok');
        }
        foreach (self::OPTIONAL_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $maximum = match ($field) {
                    'synthetic_contact_name' => 160,
                    'synthetic_email' => 255,
                    'synthetic_phone' => 64,
                    default => 120,
                };
                $normalized[$field] = $this->optionalText($data[$field], $maximum, $field);
            } elseif ($creating) {
                $normalized[$field] = null;
            }
        }
        if (array_key_exists('synthetic_email', $normalized) && $normalized['synthetic_email'] !== null) {
            $email = mb_strtolower($normalized['synthetic_email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || ! str_ends_with($email, '.invalid')) {
                throw new WarehouseDenied('validation_failed', 'Email pemasok harus berupa alamat sintetis berdomain .invalid.');
            }
            $normalized['synthetic_email'] = $email;
        }
        if (array_key_exists('synthetic_phone', $normalized)
            && $normalized['synthetic_phone'] !== null
            && preg_match('/\A000(?:[ .()\/-]*0)*\z/', $normalized['synthetic_phone']) !== 1) {
            throw new WarehouseDenied('validation_failed', 'Nomor pemasok harus memakai penanda telepon sintetis 000.');
        }
        if (array_key_exists('synthetic_reference', $normalized)
            && ($normalized['synthetic_reference'] === null
                || preg_match('/\A(?:SYN-|REF-SYN-)[A-Z0-9][A-Z0-9._-]*\z/', $normalized['synthetic_reference']) !== 1)) {
            throw new WarehouseDenied('validation_failed', 'Referensi pemasok harus memakai penanda SYN- atau REF-SYN-.');
        }

        return $normalized;
    }

    private function assertSimulationBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new WarehouseDenied('validation_failed', 'Master pemasok hanya tersedia pada batas simulasi yang diizinkan.');
        }
    }

    private function assertPublicId(string $publicId): void
    {
        if (! Str::isUlid($publicId)) {
            throw new WarehouseDenied('validation_failed', 'Identitas publik pemasok tidak valid.');
        }
    }

    private function assertExpectedVersion(int $version): void
    {
        if ($version < 1) {
            throw new WarehouseDenied('validation_failed', 'Versi pemasok yang diharapkan tidak valid.');
        }
    }

    private function digest(string $digest): string
    {
        $digest = mb_strtolower(trim($digest));
        if (preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
            throw new WarehouseDenied('validation_failed', 'Digest pemasok yang diharapkan tidak valid.');
        }

        return $digest;
    }

    private function reasonCode(string $reasonCode): string
    {
        if (! mb_check_encoding($reasonCode, 'UTF-8')) {
            throw new WarehouseDenied('validation_failed', 'Kode alasan revisi pemasok tidak valid.');
        }
        $reasonCode = mb_strtoupper(trim($reasonCode));
        if (! in_array($reasonCode, self::REVISION_REASONS, true)) {
            throw new WarehouseDenied('validation_failed', 'Kode alasan revisi pemasok tidak valid.');
        }

        return $reasonCode;
    }

    private function requiredText(mixed $value, int $minimum, int $maximum, string $label): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            throw new WarehouseDenied('validation_failed', "{$label} tidak valid.");
        }
        $value = preg_replace('/\s+/u', ' ', trim($value));
        if (! is_string($value)) {
            throw new WarehouseDenied('validation_failed', "{$label} tidak valid.");
        }
        $value = trim($value);
        if (mb_strlen($value) < $minimum || mb_strlen($value) > $maximum) {
            throw new WarehouseDenied('validation_failed', "{$label} harus {$minimum} sampai {$maximum} karakter.");
        }

        return $value;
    }

    private function optionalText(mixed $value, int $maximum, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            throw new WarehouseDenied('validation_failed', "{$label} tidak valid.");
        }
        $normalized = preg_replace('/\s+/u', ' ', trim($value));
        if (! is_string($normalized)) {
            throw new WarehouseDenied('validation_failed', "{$label} tidak valid.");
        }
        if (trim($normalized) === '') {
            return null;
        }

        return $this->requiredText($normalized, 1, $maximum, $label);
    }

    private function versionReasonMatchesState(WarehouseSupplierVersion $version): bool
    {
        if ((int) $version->version === 1) {
            return $version->state === WarehouseSupplier::ACTIVE
                && $version->reason_code === self::REASON_CREATED;
        }
        if ($version->state === WarehouseSupplier::RETIRED) {
            return $version->reason_code === self::REASON_RETIRED;
        }

        return $version->state === WarehouseSupplier::ACTIVE
            && in_array($version->reason_code, self::REVISION_REASONS, true);
    }

    private function versionUsesSyntheticMarkers(WarehouseSupplierVersion $version): bool
    {
        return preg_match('/\ASYN-[A-Z0-9][A-Z0-9._-]{0,59}\z/', (string) $version->supplier_code_snapshot) === 1
            && preg_match('/\A(?:SYN-|REF-SYN-)[A-Z0-9][A-Z0-9._-]*\z/', (string) $version->synthetic_reference) === 1
            && ($version->synthetic_email === null
                || (filter_var($version->synthetic_email, FILTER_VALIDATE_EMAIL) !== false
                    && str_ends_with(mb_strtolower($version->synthetic_email), '.invalid')))
            && ($version->synthetic_phone === null
                || preg_match('/\A000(?:[ .()\/-]*0)*\z/', $version->synthetic_phone) === 1);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function versionFields(array $data): array
    {
        return collect(['display_name', ...self::OPTIONAL_FIELDS])
            ->mapWithKeys(fn (string $field): array => [$field => $data[$field] ?? null])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function mergedFields(WarehouseSupplier $supplier, array $changes): array
    {
        $fields = [];
        foreach (['display_name', ...self::OPTIONAL_FIELDS] as $field) {
            $fields[$field] = array_key_exists($field, $changes)
                ? $changes[$field]
                : $supplier->getAttribute($field);
        }

        return $fields;
    }

    /** @param array<string, mixed> $fields */
    private function sameVersionFields(WarehouseSupplierVersion $version, array $fields): bool
    {
        foreach (['display_name', ...self::OPTIONAL_FIELDS] as $field) {
            if ($version->getAttribute($field) !== $fields[$field]) {
                return false;
            }
        }

        return true;
    }
}
