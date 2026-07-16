<?php

namespace App\Modules\Clinical\Enums;

enum ProcedureDocumentationState: string
{
    case NonePerformed = 'NONE_PERFORMED';
    case ProceduresRecorded = 'PROCEDURES_RECORDED';

    public function label(): string
    {
        return match ($this) {
            self::NonePerformed => 'Tidak ada tindakan/prosedur yang dilakukan',
            self::ProceduresRecorded => 'Ada tindakan/prosedur yang dilakukan',
        };
    }
}
