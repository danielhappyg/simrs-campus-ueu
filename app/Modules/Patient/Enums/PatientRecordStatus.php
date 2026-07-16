<?php

namespace App\Modules\Patient\Enums;

enum PatientRecordStatus: string
{
    case Active = 'ACTIVE';
    case PotentialDuplicate = 'POTENTIAL_DUPLICATE';
    case MergedReference = 'MERGED_REFERENCE';
    case Archived = 'ARCHIVED';
}
