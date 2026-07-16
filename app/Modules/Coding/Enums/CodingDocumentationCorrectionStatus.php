<?php

namespace App\Modules\Coding\Enums;

enum CodingDocumentationCorrectionStatus: string
{
    case Open = 'OPEN';
    case MedicalResponseSubmitted = 'MEDICAL_RESPONSE_SUBMITTED';
    case MedicalApproved = 'MEDICAL_APPROVED';
    case ClosureResponseSubmitted = 'CLOSURE_RESPONSE_SUBMITTED';
    case ReadyForRmik = 'READY_FOR_RMIK';
    case Resolved = 'RESOLVED';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Menunggu amendemen medis',
            self::MedicalResponseSubmitted => 'Menunggu telaah supervisor medis',
            self::MedicalApproved => 'Menunggu penutupan penerus',
            self::ClosureResponseSubmitted => 'Menunggu telaah penutupan',
            self::ReadyForRmik => 'Menunggu telaah ulang RMIK',
            self::Resolved => 'Terselesaikan',
        };
    }
}
