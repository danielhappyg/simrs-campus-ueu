<?php

namespace App\Support\Inpatient;

use App\Models\InpatientDischargeSummary;
use App\Models\InpatientDischargeSummaryVersion;
use App\Support\CanonicalJson;

final class InpatientDischargeSummaryEvidenceDigest
{
    public function content(InpatientDischargeSummaryVersion $version): string
    {
        $fields = [];
        foreach (InpatientDischargeSummary::NARRATIVE_FIELDS as $field) {
            $fields[$field] = $version->getAttribute($field);
        }

        return hash('sha256', CanonicalJson::encode($fields));
    }

    public function provenance(InpatientDischargeSummary $summary, InpatientDischargeSummaryVersion $version): string
    {
        return hash('sha256', CanonicalJson::encode([
            'summary_public_id' => $summary->public_id,
            'version_public_id' => $version->public_id,
            'encounter_public_id' => $version->encounter_public_id,
            'actor_user_id' => $version->actor_user_id,
            'version' => $version->version,
            'summary_state' => $version->summary_state,
            'definition_version' => $version->definition_version,
            'encounter_status' => $version->encounter_status,
            'location_sequence' => $version->location_sequence,
            'location_event_public_id' => $version->location_event_public_id,
            'location_event_type' => $version->location_event_type,
            'history_baseline' => $version->history_baseline,
            'history_complete' => $version->history_complete,
            'ward_public_id' => $version->ward_public_id,
            'ward_code' => $version->ward_code,
            'ward_display_name' => $version->ward_display_name,
            'bed_public_id' => $version->bed_public_id,
            'bed_code' => $version->bed_code,
            'bed_display_name' => $version->bed_display_name,
            'room_label' => $version->room_label,
            'service_class' => $version->service_class,
            'finalized_at' => $version->finalized_at?->toISOString(),
            'content_digest' => $this->content($version),
        ]));
    }
}
