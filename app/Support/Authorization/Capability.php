<?php

namespace App\Support\Authorization;

final class Capability
{
    public const PATIENT_SEARCH = 'patient.search';

    public const PATIENT_VIEW = 'patient.view';

    public const PATIENT_REGISTER = 'patient.register';

    public const ENCOUNTER_LIST = 'encounter.list';

    public const ENCOUNTER_OPEN = 'encounter.open';

    public const ENCOUNTER_CANCEL = 'encounter.cancel';

    public const CLINICAL_NURSING_WRITE = 'clinical.nursing.write';

    public const CLINICAL_MEDICAL_WRITE = 'clinical.medical.write';

    public const CLINICAL_ORDER_CREATE = 'clinical.order.create';

    public const CLINICAL_AMEND = 'clinical.amend';

    public const RMIK_REVIEW = 'rmik.review';

    public const RMIK_CODING_WRITE = 'rmik.coding.write';

    public const RMIK_COMPLETENESS_SIGNOFF = 'rmik.completeness.signoff';

    public const AUDIT_VIEW = 'audit.view';

    public const USER_MANAGE = 'user.manage';

    public const ROLE_MANAGE = 'role.manage';

    public const SYNTHETIC_RESET = 'synthetic.reset';

    public const MASTER_MANAGE = 'master.manage';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PATIENT_SEARCH,
            self::PATIENT_VIEW,
            self::PATIENT_REGISTER,
            self::ENCOUNTER_LIST,
            self::ENCOUNTER_OPEN,
            self::ENCOUNTER_CANCEL,
            self::CLINICAL_NURSING_WRITE,
            self::CLINICAL_MEDICAL_WRITE,
            self::CLINICAL_ORDER_CREATE,
            self::CLINICAL_AMEND,
            self::RMIK_REVIEW,
            self::RMIK_CODING_WRITE,
            self::RMIK_COMPLETENESS_SIGNOFF,
            self::AUDIT_VIEW,
            self::USER_MANAGE,
            self::ROLE_MANAGE,
            self::SYNTHETIC_RESET,
            self::MASTER_MANAGE,
        ];
    }
}
