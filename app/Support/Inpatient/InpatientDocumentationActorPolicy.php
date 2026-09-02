<?php

namespace App\Support\Inpatient;

use App\Models\InpatientClinicalDocument;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class InpatientDocumentationActorPolicy
{
    public function authorize(User $actor, string $documentType): void
    {
        if (! $this->can($actor, $documentType)) {
            throw new AuthorizationException;
        }
    }

    public function can(User $actor, string $documentType): bool
    {
        [$role, $capability] = match ($documentType) {
            InpatientClinicalDocument::TYPE_NURSING_DAILY => [RoleCapabilityMatrix::ROLE_NURSE, Capability::CLINICAL_NURSING_WRITE],
            InpatientClinicalDocument::TYPE_MEDICAL_DAILY => [RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::CLINICAL_MEDICAL_WRITE],
            default => [null, null],
        };

        return is_string($role)
            && is_string($capability)
            && ! $actor->is_system_administrator
            && ! $actor->hasRole(RoleCapabilityMatrix::ROLE_ADMIN)
            && $actor->hasRole($role)
            && $actor->canCapability($capability);
    }
}
