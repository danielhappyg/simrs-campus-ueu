<?php

namespace App\Modules\Teaching\Enums;

enum WorkTaskType: string
{
    case SessionOrientation = 'SESSION_ORIENTATION';
    case Registration = 'REGISTRATION';
    case NursingIntake = 'NURSING_INTAKE';
    case MedicalAssessment = 'MEDICAL_ASSESSMENT';
    case ResultAcknowledgement = 'RESULT_ACKNOWLEDGEMENT';
    case PharmacyReview = 'PHARMACY_REVIEW';
    case Dispensing = 'DISPENSING';
    case EncounterClosure = 'ENCOUNTER_CLOSURE';
    case RecordReview = 'RECORD_REVIEW';
    case SupervisorReview = 'SUPERVISOR_REVIEW';

    public function requiredCapability(): Capability
    {
        return match ($this) {
            self::SessionOrientation => Capability::SessionView,
            self::Registration => Capability::PatientRegister,
            self::NursingIntake => Capability::IntakeWrite,
            self::MedicalAssessment,
            self::ResultAcknowledgement => Capability::MedicalAssessmentWrite,
            self::PharmacyReview => Capability::PharmacyReview,
            self::Dispensing => Capability::Dispense,
            self::EncounterClosure,
            self::RecordReview => Capability::RecordReview,
            self::SupervisorReview => Capability::SupervisionReview,
        };
    }
}
