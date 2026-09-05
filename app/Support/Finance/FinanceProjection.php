<?php

namespace App\Support\Finance;

use App\Models\Encounter;
use App\Models\FinanceBill;
use App\Models\FinanceBillLine;
use App\Models\FinanceBillVersion;
use App\Models\FinanceChargeEvent;
use App\Models\InpatientLocationEvent;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryResultVersion;
use App\Models\PharmacyFinancialSourceEvent;
use App\Models\PharmacyPrescription;
use App\Models\RadiologyOrder;
use App\Models\User;
use Illuminate\Support\Collection;

final class FinanceProjection
{
    public function __construct(
        private readonly FinanceActorPolicy $policy,
        private readonly FinanceEvidenceFingerprint $fingerprints,
        private readonly FinanceSourceCoordinator $sources,
        private readonly FinanceCandidatePreviewTotals $candidateTotals,
    ) {}

    /** @return array{bills:list<array<string,mixed>>,synchronization_candidates:list<array<string,mixed>>} */
    public function worklist(User $actor): array
    {
        $this->policy->view($actor);
        $bills = FinanceBill::query()->with(['encounter.patient', 'versions'])
            ->orderByRaw("CASE state WHEN 'NEW_SOURCE_PENDING' THEN 0 WHEN 'OPEN_NO_VERSION' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')->get();

        $sourcePrescriptionIds = PharmacyFinancialSourceEvent::query()->distinct()->pluck('prescription_id');
        $pharmacyEncounterIds = PharmacyPrescription::query()->whereIn('id', $sourcePrescriptionIds)->distinct()->pluck('encounter_id');
        $radiologyEncounterIds = RadiologyOrder::query()->whereHas('performance')->distinct()->pluck('encounter_id');
        $laboratoryEncounterIds = LaboratoryOrder::query()
            ->whereHas('resultVersions', fn ($query) => $query
                ->where('state', LaboratoryResultVersion::VERIFIED)
                ->whereNull('base_verified_version_id')
                ->whereNotNull('verified_at'))
            ->distinct()->pluck('encounter_id');
        $accommodationEncounterIds = InpatientLocationEvent::query()->distinct()->pluck('encounter_id');
        $candidateEncounterIds = $pharmacyEncounterIds->merge($radiologyEncounterIds)
            ->merge($laboratoryEncounterIds)->merge($accommodationEncounterIds)->unique()->values();
        $candidates = Encounter::query()->with('patient')
            ->whereIn('id', $candidateEncounterIds)
            ->whereNotIn('id', FinanceBill::query()->pluck('encounter_id'))
            ->whereHas('patient', fn ($query) => $query->where('is_synthetic', true))
            ->orderByDesc('registered_at')->get()
            ->map(function (Encounter $encounter): array {
                $prescriptionIds = PharmacyPrescription::query()->where('encounter_id', $encounter->id)->pluck('id');
                $events = PharmacyFinancialSourceEvent::query()->whereIn('prescription_id', $prescriptionIds)->get();
                $readiness = $this->sources->readiness($encounter);
                $totals = $this->candidateTotals->summarize($events, $readiness);

                return [
                    'encounter_public_id' => $encounter->public_id,
                    'care_setting' => $encounter->care_setting,
                    'encounter_status' => $encounter->status,
                    'location_label' => $encounter->care_setting === Encounter::CARE_SETTING_INPATIENT
                        ? (trim(implode(' · ', array_filter([$encounter->ward_name, $encounter->bed_code]))) ?: 'Rawat Inap')
                        : ($encounter->clinic_name ?: $encounter->care_setting),
                    'patient' => [
                        'public_id' => $encounter->patient?->public_id,
                        'medical_record_number' => $encounter->patient?->medical_record_number,
                        'full_name' => $encounter->patient?->full_name,
                    ],
                    ...$totals,
                    'source_readiness' => $readiness,
                ];
            })->values()->all();

        return [
            'bills' => array_values($bills->map(fn (FinanceBill $bill): array => $this->summary($bill))->all()),
            'synchronization_candidates' => array_values($candidates),
        ];
    }

    /** @return array<string,mixed> */
    public function bill(FinanceBill $bill, User $actor): array
    {
        $this->policy->view($actor);
        $bill->loadMissing([
            'encounter.patient', 'patient',
            'versions.lines.chargeEvent.radiologySource.bindingVersion',
            'versions.lines.chargeEvent.radiologySource.tariffItemVersion',
            'versions.lines.chargeEvent.laboratorySource.bindingVersion',
            'versions.lines.chargeEvent.laboratorySource.tariffItemVersion',
            'versions.lines.chargeEvent.accommodationSource.bindingVersion',
            'versions.lines.chargeEvent.accommodationSource.tariffItemVersion',
        ]);
        $events = FinanceChargeEvent::query()->where('encounter_id', $bill->encounter_id)
            ->with([
                'radiologySource.bindingVersion', 'radiologySource.tariffItemVersion',
                'laboratorySource.bindingVersion', 'laboratorySource.tariffItemVersion',
                'accommodationSource.bindingVersion', 'accommodationSource.tariffItemVersion',
            ])
            ->orderBy('occurred_at')->orderBy('source_domain')->orderBy('source_public_id')->orderBy('id')->get();
        $coverageProfile = $this->coverageProfile($events);

        return [
            ...$this->summary($bill),
            'fingerprint' => $this->fingerprint($bill),
            'coverage_profile' => $coverageProfile,
            'coverage_label' => $this->coverageLabel($coverageProfile),
            'sources' => $events->map(fn (FinanceChargeEvent $event): array => [
                'public_id' => $event->public_id,
                'source_domain' => $event->source_domain,
                'source_public_id' => $event->source_public_id,
                'event_type' => $event->event_type,
                'description' => $event->description,
                'quantity' => $event->quantity,
                'unit_amount' => $event->unit_amount,
                'signed_amount' => $event->signed_amount,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'tariff_provenance' => $this->tariffProvenance($event),
            ])->all(),
            'versions' => $bill->versions->sortByDesc('version')->values()->map(fn (FinanceBillVersion $version): array => [
                'public_id' => $version->public_id,
                'version' => $version->version,
                'source_event_count' => $version->source_event_count,
                'gross_amount' => $version->gross_amount,
                'reversal_amount' => $version->reversal_amount,
                'net_amount' => $version->net_amount,
                'issue_reason' => $version->issue_reason,
                'issued_at' => $version->issued_at->toIso8601String(),
                'issued_by_user_id' => $version->issued_by_user_id,
                'coverage_profile' => $version->coverage_profile,
                'coverage_label' => $this->coverageLabel($version->coverage_profile),
                'lines' => $version->lines->map(fn (FinanceBillLine $line): array => [
                    'public_id' => $line->public_id,
                    'line_number' => $line->line_number,
                    'source_domain' => $line->source_domain,
                    'source_public_id' => $line->source_public_id,
                    'event_type' => $line->event_type,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_amount' => $line->unit_amount,
                    'signed_amount' => $line->signed_amount,
                    'occurred_at' => $line->occurred_at->toIso8601String(),
                    'tariff_provenance' => $line->chargeEvent instanceof FinanceChargeEvent
                        ? $this->tariffProvenance($line->chargeEvent)
                        : null,
                ])->all(),
            ])->all(),
            'actions' => [
                'can_synchronize' => true,
                'can_issue' => in_array($bill->state, [FinanceBill::OPEN_NO_VERSION, FinanceBill::NEW_SOURCE_PENDING], true)
                    && ! $this->sources->readiness($bill->encounter)['issue_blocked'],
            ],
        ];
    }

    public function fingerprint(FinanceBill $bill): string
    {
        return $this->fingerprints->bill($bill);
    }

    /** @return array<string,mixed> */
    private function summary(FinanceBill $bill): array
    {
        $bill->loadMissing(['encounter.patient', 'patient']);
        $events = FinanceChargeEvent::query()->where('encounter_id', $bill->encounter_id)->get();
        $prescriptionIds = PharmacyPrescription::query()->where('encounter_id', $bill->encounter_id)->pluck('id');
        $pharmacySourceIds = PharmacyFinancialSourceEvent::query()->whereIn('prescription_id', $prescriptionIds)->pluck('id');
        $importedSourceIds = $events->pluck('pharmacy_financial_source_event_id');
        $pendingPharmacyCount = $pharmacySourceIds->diff($importedSourceIds)->count();
        $readiness = $this->sources->readiness($bill->encounter);
        $pendingDiagnosticCount = count(array_filter(
            $readiness['items'],
            static fn (array $item): bool => $item['state'] === 'SIAP_DISINKRONKAN',
        ));
        $pendingSourceCount = $pendingPharmacyCount + $pendingDiagnosticCount;
        $gross = (int) $events->where('event_type', FinanceChargeEvent::CHARGE)->sum('signed_amount');
        $reversal = (int) $events->where('event_type', FinanceChargeEvent::REVERSAL)->sum('signed_amount');

        return [
            'public_id' => $bill->public_id,
            'bill_number' => $bill->bill_number,
            'state' => $bill->state,
            'state_label' => match ($bill->state) {
                FinanceBill::OPEN_NO_VERSION => __('Belum diterbitkan'),
                FinanceBill::ISSUED_CURRENT => __('Versi terkini'),
                FinanceBill::NEW_SOURCE_PENDING => __('Ada sumber biaya baru'),
                default => __('Status tidak dikenal'),
            },
            'current_version' => $bill->current_version,
            'current_source_event_count' => $bill->current_source_event_count,
            'pending_source_count' => $pendingSourceCount,
            'synchronization_available' => $pendingSourceCount > 0,
            'latest_source_at' => $events->max('occurred_at')?->toIso8601String(),
            'encounter' => [
                'public_id' => $bill->encounter?->public_id,
                'care_setting' => $bill->care_setting,
                'status' => $bill->encounter?->status,
                'location_label' => $bill->care_setting === 'INPATIENT'
                    ? (trim(implode(' · ', array_filter([$bill->encounter?->ward_name, $bill->encounter?->bed_code]))) ?: __('Rawat Inap'))
                    : ($bill->encounter?->clinic_name ?: $bill->care_setting),
            ],
            'patient' => [
                'public_id' => $bill->patient?->public_id,
                'medical_record_number' => $bill->patient?->medical_record_number,
                'full_name' => $bill->patient?->full_name,
            ],
            'control_totals' => [
                'gross_amount' => $gross,
                'reversal_amount' => $reversal,
                'net_amount' => $gross + $reversal,
            ],
            'source_readiness' => $readiness,
        ];
    }

    /** @param Collection<int, FinanceChargeEvent> $events */
    private function coverageProfile(Collection $events): string
    {
        if ($events->contains(
            static fn (FinanceChargeEvent $event): bool => $event->source_domain === FinanceChargeEvent::SOURCE_ACCOMMODATION,
        )) {
            return FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1;
        }

        if ($events->contains(
            static fn (FinanceChargeEvent $event): bool => $event->source_domain === FinanceChargeEvent::SOURCE_LABORATORY,
        )) {
            return FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_V1;
        }

        return $events->contains(
            static fn (FinanceChargeEvent $event): bool => $event->source_domain === FinanceChargeEvent::SOURCE_RADIOLOGY,
        ) ? FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_V1 : FinanceBillVersion::COVERAGE_PHARMACY_V1;
    }

    private function coverageLabel(string $profile): string
    {
        return match ($profile) {
            FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1 => __('Obat yang diserahkan atau diretur, pemeriksaan radiologi selesai, hasil laboratorium terverifikasi bertarif, dan hari akomodasi rawat inap tertutup'),
            FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_V1 => __('Obat yang diserahkan atau diretur, pemeriksaan radiologi selesai, dan hasil laboratorium terverifikasi bertarif'),
            FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_V1 => __('Obat yang diserahkan atau diretur dan pemeriksaan radiologi selesai bertarif'),
            default => __('Obat yang telah diserahkan dan retur terkait'),
        };
    }

    /** @return array<string, mixed>|null */
    private function tariffProvenance(FinanceChargeEvent $event): ?array
    {
        if ($event->source_domain === FinanceChargeEvent::SOURCE_ACCOMMODATION) {
            $source = $event->accommodationSource;
            if ($source === null) {
                return null;
            }

            return [
                'binding_public_id' => $source->binding_public_id,
                'binding_version_public_id' => $source->binding_version_public_id,
                'binding_version' => $source->binding_version,
                'binding_content_digest' => $source->binding_content_digest,
                'opening_location_event_public_id' => $source->opening_location_event_public_id,
                'opening_location_event_digest' => $source->opening_location_event_digest,
                'closing_type' => $source->closing_type,
                'closing_public_id' => $source->closing_public_id,
                'closing_content_digest' => $source->closing_content_digest,
                'bed_public_id' => $source->bed_public_id,
                'bed_code' => $source->bed_code,
                'inpatient_bed_version_public_id' => $source->inpatient_bed_version_public_id,
                'inpatient_bed_version' => $source->inpatient_bed_version,
                'inpatient_bed_content_digest' => $source->inpatient_bed_content_digest,
                'ward_code' => $source->ward_code,
                'room_label' => $source->room_label,
                'service_class' => $source->service_class,
                'pricing_unit' => $source->pricing_unit,
                'occupancy_anchor_at' => $source->occupancy_anchor_at->toIso8601String(),
                'interval_start_at' => $source->interval_start_at->toIso8601String(),
                'interval_end_at' => $source->interval_end_at->toIso8601String(),
                'tariff_item_public_id' => $source->tariff_item_public_id,
                'tariff_item_version_public_id' => $source->tariff_item_version_public_id,
                'tariff_item_code' => $source->tariff_item_code,
                'tariff_content_digest' => $source->tariff_content_digest,
                'component_public_id' => $source->component_public_id,
                'component_code' => $source->component_code,
                'component_content_digest' => $source->component_content_digest,
                'service_date' => $source->service_date->format('Y-m-d'),
                'effective_from' => $source->tariffItemVersion->effective_from->format('Y-m-d'),
            ];
        }

        if ($event->source_domain === FinanceChargeEvent::SOURCE_LABORATORY) {
            $source = $event->laboratorySource;
            if ($source === null) {
                return null;
            }

            return [
                'binding_public_id' => $source->binding_public_id,
                'binding_version_public_id' => $source->binding_version_public_id,
                'binding_version' => $source->binding_version,
                'binding_content_digest' => $source->binding_content_digest,
                'laboratory_result_public_id' => $source->result_public_id,
                'laboratory_result_version' => $source->result_version,
                'laboratory_result_content_digest' => $source->result_content_digest,
                'laboratory_verified_at' => $source->verified_at->toIso8601String(),
                'laboratory_specimen_public_id' => $source->specimen_public_id,
                'laboratory_master_version_public_id' => $source->laboratory_master_version_public_id,
                'laboratory_master_version' => $source->laboratory_master_version,
                'laboratory_master_code' => $source->laboratory_master_code,
                'laboratory_master_content_digest' => $source->laboratory_master_content_digest,
                'tariff_item_public_id' => $source->tariff_item_public_id,
                'tariff_item_version_public_id' => $source->tariff_item_version_public_id,
                'tariff_item_version' => $source->tariffItemVersion->version,
                'tariff_code' => $source->tariff_item_code,
                'tariff_content_digest' => $source->tariff_content_digest,
                'component_public_id' => $source->component_public_id,
                'component_code' => $source->component_code,
                'component_content_digest' => $source->component_content_digest,
                'service_date' => $source->service_date->format('Y-m-d'),
                'effective_from' => $source->tariffItemVersion->effective_from->format('Y-m-d'),
            ];
        }

        $source = $event->radiologySource;
        if ($event->source_domain !== FinanceChargeEvent::SOURCE_RADIOLOGY || $source === null) {
            return null;
        }

        return [
            'binding_public_id' => $source->binding_public_id,
            'binding_version_public_id' => $source->binding_version_public_id,
            'binding_version' => $source->binding_version,
            'binding_content_digest' => $source->binding_content_digest,
            'radiology_master_version_public_id' => $source->radiology_master_version_public_id,
            'radiology_master_version' => $source->radiology_master_version,
            'radiology_master_code' => $source->radiology_master_code,
            'radiology_master_content_digest' => $source->radiology_master_content_digest,
            'tariff_item_public_id' => $source->tariff_item_public_id,
            'tariff_item_version_public_id' => $source->tariff_item_version_public_id,
            'tariff_item_version' => $source->tariffItemVersion->version,
            'tariff_code' => $source->tariff_item_code,
            'tariff_content_digest' => $source->tariff_content_digest,
            'component_public_id' => $source->component_public_id,
            'component_code' => $source->component_code,
            'component_content_digest' => $source->component_content_digest,
            'service_date' => $source->service_date->format('Y-m-d'),
            'effective_from' => $source->tariffItemVersion->effective_from->format('Y-m-d'),
        ];
    }
}
