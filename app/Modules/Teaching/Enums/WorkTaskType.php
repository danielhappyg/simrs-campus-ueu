<?php

namespace App\Modules\Teaching\Enums;

enum WorkTaskType: string
{
    case SessionOrientation = 'SESSION_ORIENTATION';
    case Registration = 'REGISTRATION';
    case NursingIntake = 'NURSING_INTAKE';
    case MedicalAssessment = 'MEDICAL_ASSESSMENT';
    case SyntheticResultRelease = 'SYNTHETIC_RESULT_RELEASE';
    case ResultAcknowledgement = 'RESULT_ACKNOWLEDGEMENT';
    case PharmacyReview = 'PHARMACY_REVIEW';
    case PrescriptionInterventionResponse = 'PRESCRIPTION_INTERVENTION_RESPONSE';
    case Dispensing = 'DISPENSING';
    case EncounterClosure = 'ENCOUNTER_CLOSURE';
    case EncounterClosureReview = 'ENCOUNTER_CLOSURE_REVIEW';
    case RecordReview = 'RECORD_REVIEW';
    case RecordCorrection = 'RECORD_CORRECTION';
    case RecordQualityReview = 'RECORD_QUALITY_REVIEW';
    case Coding = 'CODING';
    case CodingSourceCorrection = 'CODING_SOURCE_CORRECTION';
    case ProcedureSourceCorrection = 'PROCEDURE_SOURCE_CORRECTION';
    case CodingReview = 'CODING_REVIEW';
    case SupervisorReview = 'SUPERVISOR_REVIEW';
    case Debrief = 'DEBRIEF';

    public function requiredCapability(): Capability
    {
        return match ($this) {
            self::SessionOrientation => Capability::SessionView,
            self::Registration => Capability::PatientRegister,
            self::NursingIntake => Capability::IntakeWrite,
            self::MedicalAssessment,
            self::ResultAcknowledgement => Capability::MedicalAssessmentWrite,
            self::SyntheticResultRelease => Capability::SessionFacilitate,
            self::PharmacyReview => Capability::PharmacyReview,
            self::PrescriptionInterventionResponse => Capability::PrescriptionWrite,
            self::Dispensing => Capability::Dispense,
            self::EncounterClosure => Capability::MedicalAssessmentWrite,
            self::EncounterClosureReview => Capability::SupervisionReview,
            self::RecordReview => Capability::RecordReview,
            self::RecordCorrection => Capability::MedicalAssessmentWrite,
            self::RecordQualityReview => Capability::SupervisionReview,
            self::Coding => Capability::CodingWrite,
            self::CodingSourceCorrection,
            self::ProcedureSourceCorrection => Capability::MedicalAssessmentWrite,
            self::CodingReview => Capability::SupervisionReview,
            self::SupervisorReview => Capability::SupervisionReview,
            self::Debrief => Capability::DebriefView,
        };
    }
}
