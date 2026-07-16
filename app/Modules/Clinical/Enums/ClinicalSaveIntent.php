<?php

namespace App\Modules\Clinical\Enums;

enum ClinicalSaveIntent: string
{
    case SaveDraft = 'SAVE_DRAFT';
    case Submit = 'SUBMIT';
}
