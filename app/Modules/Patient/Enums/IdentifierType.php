<?php

namespace App\Modules\Patient\Enums;

enum IdentifierType: string
{
    case MedicalRecordNumber = 'MRN';
    case SyntheticNationalId = 'SYNTHETIC_NIK';
    case ScenarioId = 'SCENARIO_ID';
}
