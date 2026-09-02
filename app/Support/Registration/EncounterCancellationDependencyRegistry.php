<?php

namespace App\Support\Registration;

use App\Models\ClinicalEntry;
use App\Models\EmergencyClinicalDocument;
use App\Models\EmergencyDisposition;
use App\Models\EmergencyInpatientHandoff;
use App\Models\EmergencyResultFollowUpProposal;
use App\Models\EmergencyTriageAssessment;
use App\Models\Encounter;
use App\Models\InpatientClinicalDocument;
use App\Models\InpatientLocationEvent;
use App\Models\LaboratoryOrder;
use App\Models\LabServiceRequest;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientRmCompletenessReview;
use App\Models\RadiologyOrder;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;

final class EncounterCancellationDependencyRegistry
{
    public function __construct(private readonly PharmacyEncounterLifecycleGate $pharmacy) {}

    public function firstDenialReason(Encounter $encounter): ?string
    {
        if (ClinicalEntry::query()->where('encounter_id', $encounter->id)->exists()
            || OutpatientClinicalDocument::query()->where('encounter_id', $encounter->id)->exists()
            || InpatientClinicalDocument::query()->where('encounter_id', $encounter->id)->exists()
            || EmergencyTriageAssessment::query()->where('encounter_id', $encounter->id)->exists()
            || EmergencyClinicalDocument::query()->where('encounter_id', $encounter->id)->exists()) {
            return 'clinical_activity_exists';
        }

        if (InpatientLocationEvent::query()
            ->where('encounter_id', $encounter->id)
            ->where('event_type', InpatientLocationEvent::TYPE_TRANSFER)
            ->exists()) {
            return 'location_activity_exists';
        }

        if (LabServiceRequest::query()->where('encounter_id', $encounter->id)->exists()) {
            return 'diagnostic_activity_exists';
        }

        if (LaboratoryOrder::query()->where('encounter_id', $encounter->id)->exists()) {
            return 'diagnostic_activity_exists';
        }

        if (RadiologyOrder::query()->where('encounter_id', $encounter->id)->exists()) {
            return 'diagnostic_activity_exists';
        }

        if ($this->pharmacy->inspect($encounter)['has_any_evidence']) {
            return 'pharmacy_evidence_exists';
        }

        if (OutpatientRmCompletenessReview::query()->where('encounter_id', $encounter->id)->exists()) {
            return 'rm_activity_exists';
        }

        if (EmergencyResultFollowUpProposal::query()->where('encounter_id', $encounter->id)->exists()
            || EmergencyDisposition::query()->where('encounter_id', $encounter->id)->exists()
            || EmergencyInpatientHandoff::query()->where('source_encounter_id', $encounter->id)->exists()) {
            return 'downstream_activity_exists';
        }

        return null;
    }
}
