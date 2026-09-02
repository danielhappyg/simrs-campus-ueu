<?php

namespace App\Support\Warehouse;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class WarehouseOperationAuthorization
{
    private const SUPPLIER_OPERATIONS = [
        'WAREHOUSE_SUPPLIER_CREATE',
        'WAREHOUSE_SUPPLIER_REVISE',
        'WAREHOUSE_SUPPLIER_RETIRE',
    ];

    private function __construct(
        public int $actorId,
        public string $actorPublicId,
        public string $operation,
        public string $role,
        public string $capability,
        public string $stateDigest,
        public bool $rosterAccount,
        public int $teachingAccessEpoch,
        public int $teachingAccessMutex,
        public ?string $leasePublicId,
        public ?string $requestHost,
    ) {}

    public static function supplierManagement(
        int $actorId,
        string $actorPublicId,
        string $operation,
        string $stateDigest,
        bool $rosterAccount,
        int $teachingAccessEpoch,
        int $teachingAccessMutex,
        ?string $leasePublicId,
        ?string $requestHost,
    ): self {
        if ($actorId < 1
            || ! Str::isUlid($actorPublicId)
            || ! in_array($operation, self::SUPPLIER_OPERATIONS, true)
            || preg_match('/\A[a-f0-9]{64}\z/', $stateDigest) !== 1
            || $teachingAccessEpoch < 0
            || $teachingAccessMutex < 0
            || ($leasePublicId !== null && ! Str::isUlid($leasePublicId))) {
            throw new InvalidArgumentException('Warehouse operation authorization claim is invalid.');
        }

        return new self(
            $actorId,
            $actorPublicId,
            $operation,
            RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER,
            Capability::WAREHOUSE_SUPPLIER_MANAGE,
            $stateDigest,
            $rosterAccount,
            $teachingAccessEpoch,
            $teachingAccessMutex,
            $leasePublicId,
            $requestHost,
        );
    }

    public function matches(User $actor, string $operation): bool
    {
        return $this->actorId === (int) $actor->getKey()
            && hash_equals($this->actorPublicId, (string) $actor->public_id)
            && $this->operation === $operation
            && in_array($operation, self::SUPPLIER_OPERATIONS, true)
            && $this->role === RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER
            && $this->capability === Capability::WAREHOUSE_SUPPLIER_MANAGE;
    }
}
