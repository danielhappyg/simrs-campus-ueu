<?php

namespace App\Support\Authorization;

final class RoleCapabilityMatrix
{
    public const ROLE_REGISTRAR = 'registrar';

    public const ROLE_NURSE = 'nurse';

    public const ROLE_PHYSICIAN = 'physician';

    public const ROLE_RMIK = 'rmik';

    public const ROLE_ADMIN = 'admin';

    /**
     * @return array<string, array{name: string, description: string}>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_REGISTRAR => [
                'name' => 'Registrar',
                'description' => 'Front-office registration and encounter open/cancel',
            ],
            self::ROLE_NURSE => [
                'name' => 'Nurse',
                'description' => 'Nursing documentation for teaching encounters',
            ],
            self::ROLE_PHYSICIAN => [
                'name' => 'Physician',
                'description' => 'Medical documentation and clinical orders',
            ],
            self::ROLE_RMIK => [
                'name' => 'RMIK',
                'description' => 'Medical records coding, review, and completeness',
            ],
            self::ROLE_ADMIN => [
                'name' => 'Administrator',
                'description' => 'Teaching bootstrap admin for users, roles, audit, and reset',
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        return [
            self::ROLE_REGISTRAR => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::PATIENT_REGISTER,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::ENCOUNTER_CANCEL,
            ],
            self::ROLE_NURSE => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::CLINICAL_NURSING_WRITE,
                Capability::CLINICAL_AMEND,
            ],
            self::ROLE_PHYSICIAN => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::CLINICAL_MEDICAL_WRITE,
                Capability::CLINICAL_ORDER_CREATE,
                Capability::CLINICAL_AMEND,
            ],
            self::ROLE_RMIK => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::RMIK_REVIEW,
                Capability::RMIK_CODING_WRITE,
                Capability::RMIK_COMPLETENESS_SIGNOFF,
            ],
            self::ROLE_ADMIN => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::PATIENT_REGISTER,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::ENCOUNTER_CANCEL,
                Capability::AUDIT_VIEW,
                Capability::USER_MANAGE,
                Capability::ROLE_MANAGE,
                Capability::SYNTHETIC_RESET,
                Capability::MASTER_MANAGE,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function capabilitiesFor(string $roleSlug): array
    {
        return self::matrix()[$roleSlug] ?? [];
    }
}
