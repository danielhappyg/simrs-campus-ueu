<?php

namespace App\Modules\Clinical\Enums;

use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\WorkTaskType;

enum ClinicalDocumentType: string
{
    case NursingIntake = 'NURSING_INTAKE';
    case MedicalAssessment = 'MEDICAL_ASSESSMENT';

    public function label(): string
    {
        return match ($this) {
            self::NursingIntake => 'Asesmen Awal dan Skrining Keselamatan',
            self::MedicalAssessment => 'Asesmen Medis Rawat Jalan',
        };
    }

    public function schemaVersion(): string
    {
        return match ($this) {
            self::NursingIntake => 'nursing-intake.v1',
            self::MedicalAssessment => 'medical-assessment.v1',
        };
    }

    public function authorCapability(): Capability
    {
        return match ($this) {
            self::NursingIntake => Capability::IntakeWrite,
            self::MedicalAssessment => Capability::MedicalAssessmentWrite,
        };
    }

    public function workTaskType(): WorkTaskType
    {
        return match ($this) {
            self::NursingIntake => WorkTaskType::NursingIntake,
            self::MedicalAssessment => WorkTaskType::MedicalAssessment,
        };
    }
}
