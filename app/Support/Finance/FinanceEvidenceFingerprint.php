<?php

namespace App\Support\Finance;

use App\Models\FinanceBill;
use App\Models\FinanceBillLine;
use App\Models\FinanceBillVersion;
use App\Models\FinanceChargeEvent;
use Illuminate\Support\Collection;

final class FinanceEvidenceFingerprint
{
    /** @param Collection<int, FinanceChargeEvent> $events */
    public function sourceSet(Collection $events): string
    {
        return FinanceCanonicalJson::digest($events
            ->sort(fn (FinanceChargeEvent $a, FinanceChargeEvent $b): int => [
                $a->occurred_at->format('Y-m-d\TH:i:s.uP'), $a->source_domain, $a->source_public_id, $a->id,
            ] <=> [
                $b->occurred_at->format('Y-m-d\TH:i:s.uP'), $b->source_domain, $b->source_public_id, $b->id,
            ])
            ->map(fn (FinanceChargeEvent $event): array => [
                $event->public_id, $event->source_domain, $event->source_public_id,
                $event->source_content_digest, $event->content_digest,
            ])->values()->all());
    }

    public function bill(FinanceBill $bill): string
    {
        return FinanceCanonicalJson::digest([
            $bill->public_id, $bill->bill_number, $bill->encounter_id, $bill->patient_id,
            $bill->care_setting, $bill->state, $bill->current_version,
            $bill->current_source_event_count, $bill->current_source_set_digest,
            $bill->current_issued_source_set_digest, $bill->current_content_digest,
        ]);
    }

    public function billContent(FinanceBill $bill): string
    {
        return FinanceCanonicalJson::digest([
            $bill->bill_number, $bill->encounter_id, $bill->patient_id, $bill->care_setting,
            $bill->state, $bill->current_version, $bill->current_source_event_count,
            $bill->current_source_set_digest, $bill->current_issued_source_set_digest,
        ]);
    }

    /** @param Collection<int, FinanceBillLine> $lines */
    public function version(FinanceBillVersion $version, Collection $lines): string
    {
        return FinanceCanonicalJson::digest([
            $version->bill_id, $version->previous_version_id, $version->issued_by_user_id,
            $version->encounter_id, $version->patient_id, $version->version,
            $version->encounter_public_id_snapshot, $version->encounter_number_snapshot,
            $version->patient_public_id_snapshot, $version->patient_name_snapshot,
            $version->medical_record_number_snapshot, $version->care_setting, $version->payer_snapshot,
            $version->service_location_snapshot, $version->coverage_profile,
            $version->source_set_digest, $version->source_event_count,
            $version->source_cutoff_at, $version->gross_amount, $version->reversal_amount,
            $version->net_amount, $version->issue_reason, $version->issued_at,
            $lines->sortBy('line_number')->map(fn (FinanceBillLine $line): string => $line->content_digest)->values()->all(),
        ]);
    }

    public function line(FinanceChargeEvent $event, int $lineNumber): string
    {
        return FinanceCanonicalJson::digest([
            $lineNumber, $event->public_id, $event->source_domain, $event->source_public_id,
            $event->source_content_digest, $event->event_type, $event->quantity,
            $event->unit_amount, $event->signed_amount, $event->description,
            $event->content_digest, $event->occurred_at,
        ]);
    }
}
