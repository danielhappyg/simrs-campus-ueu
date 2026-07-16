<?php

namespace App\Modules\Coding\Enums;

enum ProcedureDocumentationCorrectionStatus: string
{
    case Open = 'OPEN';
    case ClosureResponseSubmitted = 'CLOSURE_RESPONSE_SUBMITTED';
    case ReadyForRmik = 'READY_FOR_RMIK';
    case Resolved = 'RESOLVED';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Menunggu amendemen prosedur',
            self::ClosureResponseSubmitted => 'Menunggu telaah penutupan',
            self::ReadyForRmik => 'Menunggu telaah ulang RMIK',
            self::Resolved => 'Terselesaikan',
        };
    }
}
