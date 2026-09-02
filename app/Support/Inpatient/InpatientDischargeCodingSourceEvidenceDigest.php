<?php

namespace App\Support\Inpatient;

use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeCodingSourceVersion;
use App\Support\CanonicalJson;

final class InpatientDischargeCodingSourceEvidenceDigest
{
    public function content(InpatientDischargeCodingSourceVersion $version): string
    {
        return hash('sha256', CanonicalJson::encode([
            'principal_diagnosis_statement' => $version->principal_diagnosis_statement,
            'secondary_diagnosis_statements' => $version->secondary_diagnosis_statements,
            'procedure_attestation' => $version->procedure_attestation,
            'performed_procedure_statements' => $version->performed_procedure_statements,
        ]));
    }

    public function provenance(InpatientDischargeCodingSource $source, InpatientDischargeCodingSourceVersion $version): string
    {
        return hash('sha256', CanonicalJson::encode([
            'source_public_id' => $source->public_id, 'version_public_id' => $version->public_id, 'encounter_public_id' => $version->encounter_public_id,
            'actor_user_id' => $version->actor_user_id, 'version' => $version->version, 'source_state' => $version->source_state, 'definition_version' => $version->definition_version,
            'encounter_status' => $version->encounter_status, 'location_sequence' => $version->location_sequence, 'location_event_public_id' => $version->location_event_public_id,
            'location_event_type' => $version->location_event_type, 'history_baseline' => $version->history_baseline, 'history_complete' => $version->history_complete,
            'ward_public_id' => $version->ward_public_id, 'ward_code' => $version->ward_code, 'bed_public_id' => $version->bed_public_id, 'bed_code' => $version->bed_code,
            'finalized_at' => $version->finalized_at?->toISOString(), 'content_digest' => $this->content($version),
        ]));
    }
}
