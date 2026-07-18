<?php

namespace App\Modules\Teaching\Services;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\DiagnosticResult;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Clinical\Models\MedicationDispense;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Teaching\Models\Assignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class EncounterDebriefTimeline
{
    private const MAX_EVENTS = 300;

    /** @var list<string> */
    private const MATERIAL_ACTIONS = [
        'synthetic_registration.created',
        'appointment.checked_in',
        'appointment.terminated',
        'clinical.outpatient_early_departure_recorded',
        'encounter.transitioned',
        'clinical.nursing_intake_version_created',
        'clinical.medical_assessment_version_created',
        'clinical.version_submitted',
        'clinical.version_approved_for_simulation',
        'clinical.version_changes_requested',
        'clinical.synthetic_result_released',
        'clinical.synthetic_result_corrected',
        'clinical.synthetic_result_acknowledged',
        'clinical.pharmacy_review_recorded',
        'clinical.pharmacy_intervention_responded',
        'clinical.medication_dispense_recorded',
        'clinical.encounter_closure_draft_saved',
        'clinical.encounter_closure_submitted',
        'clinical.encounter_closure_approved_for_simulation',
        'clinical.encounter_closure_changes_requested',
        'record_quality.review_draft_saved',
        'record_quality.review_submitted',
        'record_quality.correction_requested',
        'record_quality.review_approved_for_simulation',
        'record_quality.review_changes_requested',
        'coding.suggestions_generated',
        'coding.suggestion_no_reliable_candidate',
        'coding.procedure_suggestions_generated',
        'coding.procedure_suggestion_no_reliable_candidate',
        'coding.candidate_accepted_to_draft',
        'coding.manual_alternative_selected',
        'coding.candidates_rejected',
        'coding.documentation_correction_requested',
        'coding.procedure_documentation_correction_requested',
        'coding.assignment_submitted',
        'coding.assignment_approved_for_simulation',
        'coding.assignment_changes_requested',
    ];

    /**
     * @return array{
     *   events: list<array<string, mixed>>,
     *   summary: array<string, mixed>
     * }
     */
    public function build(Encounter $encounter): array
    {
        $query = AuditEvent::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereIn('action', self::MATERIAL_ACTIONS);
        $totalAvailable = (clone $query)->count();
        $auditEvents = $query
            ->with(['actor:id,name', 'assignment.user:id,name'])
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit(self::MAX_EVENTS)
            ->get();
        $occurrences = $this->clinicalOccurrences($auditEvents);

        $events = $auditEvents
            ->map(fn (AuditEvent $event): array => $this->project($event, $occurrences))
            ->sort(function (array $left, array $right): int {
                return [$left['_sortAt'], $left['_sortRecordedAt'], $left['publicId']]
                    <=> [$right['_sortAt'], $right['_sortRecordedAt'], $right['publicId']];
            })
            ->values()
            ->map(function (array $event, int $index): array {
                unset($event['_sortAt'], $event['_sortRecordedAt']);
                $event['sequence'] = $index + 1;

                return $event;
            });

        $categoryCounts = $events
            ->countBy(fn (array $event): string => (string) data_get($event, 'category.label'))
            ->map(fn (int $count, string $label): array => ['label' => $label, 'count' => $count])
            ->values()
            ->all();
        $programCounts = $events
            ->countBy(fn (array $event): string => (string) data_get($event, 'actor.program'))
            ->map(fn (int $count, string $label): array => ['label' => $label, 'count' => $count])
            ->values()
            ->all();

        return [
            'events' => array_values($events->all()),
            'summary' => [
                'displayedEventCount' => $events->count(),
                'totalAvailableEventCount' => $totalAvailable,
                'truncated' => $totalAvailable > self::MAX_EVENTS,
                'categoryCounts' => $categoryCounts,
                'programCounts' => $programCounts,
                'correctionCount' => $events->filter(fn (array $event): bool => $this->hasTag($event, 'CORRECTION'))->count(),
                'supervisionCount' => $events->filter(fn (array $event): bool => $this->hasTag($event, 'SUPERVISION'))->count(),
                'handoffCount' => $events->filter(fn (array $event): bool => $this->hasTag($event, 'HANDOFF'))->count(),
            ],
        ];
    }

    /**
     * @param  array<string, CarbonInterface>  $occurrences
     * @return array<string, mixed>
     */
    private function project(AuditEvent $event, array $occurrences): array
    {
        $metadata = is_array($event->metadata) ? $event->metadata : [];
        $occurrence = $occurrences[$event->resource_type.'|'.$event->resource_id] ?? null;
        $recordedAt = $event->recorded_at;
        $materiallyDifferent = $occurrence instanceof CarbonInterface
            && abs($occurrence->diffInSeconds($recordedAt, false)) >= 60;
        $primaryAt = $occurrence ?? $recordedAt;
        $actorRelation = $event->getRelation('actor');
        $assignment = $event->getRelation('assignment');
        $assignment = $assignment instanceof Assignment ? $assignment : null;
        $assignmentUserRelation = $assignment?->getRelation('user');
        $documentType = ClinicalDocumentType::tryFrom((string) ($metadata['document_type'] ?? ''));
        $sourceType = CodingSourceType::tryFrom((string) ($metadata['source_type'] ?? ''));
        $presentation = $this->presentation($event->action, $metadata, $documentType, $sourceType);

        return [
            'publicId' => $event->id,
            'sequence' => 0,
            'category' => $presentation['category'],
            'title' => $presentation['title'],
            'detail' => $presentation['detail'],
            'actor' => [
                'name' => $this->actorName($actorRelation, $assignmentUserRelation),
                'program' => $assignment?->program?->label() ?? 'Sistem',
                'role' => $assignment?->application_role?->label() ?? 'Otomasi sistem',
                'assignmentPublicId' => $assignment?->public_id,
            ],
            'source' => [
                'label' => $this->resourceLabel($event->resource_type),
                'publicId' => $event->resource_id,
                'version' => $this->versionLabel($metadata),
            ],
            'recordedAt' => $recordedAt->toIso8601String(),
            'clinicalOccurrenceAt' => $occurrence?->toIso8601String(),
            'primaryAt' => $primaryAt->toIso8601String(),
            'showsRecordedTimeDifference' => $materiallyDifferent,
            'outcome' => $event->outcome,
            'tags' => $this->tags($event->action),
            '_sortAt' => $primaryAt->getTimestampMs(),
            '_sortRecordedAt' => $recordedAt->getTimestampMs(),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{category: array{code: string, label: string}, title: string, detail: string|null}
     */
    private function presentation(
        string $action,
        array $metadata,
        ?ClinicalDocumentType $documentType,
        ?CodingSourceType $sourceType,
    ): array {
        $documentLabel = $documentType?->label() ?? 'Dokumentasi klinis';
        $sourceLabel = $sourceType?->label() ?? 'Sumber klinis';
        $version = $this->versionLabel($metadata);

        return match ($action) {
            'synthetic_registration.created' => $this->card('REGISTRATION', 'Registrasi', 'Registrasi pasien sintetis dibuat', 'Identitas, appointment, dan encounter ditautkan dalam satu konteks simulasi.'),
            'appointment.checked_in' => $this->card('REGISTRATION', 'Registrasi', 'Check-in poliklinik dicatat', $this->ticketDetail($metadata)),
            'appointment.terminated' => $this->terminationCard($metadata),
            'clinical.outpatient_early_departure_recorded' => $this->card(
                'DEPARTURE',
                'Disposisi keluar',
                'Pulang atas permintaan sendiri dicatat',
                'Encounter berakhir lebih awal atas permintaan pasien sintetis; rekam tidak difinalisasi otomatis.',
            ),
            'encounter.transitioned' => $this->card('WORKFLOW', 'Alur encounter', 'Status encounter berubah', $this->transitionDetail($metadata)),
            'clinical.nursing_intake_version_created' => $this->card('NURSING', 'Keperawatan', 'Versi asesmen awal dibuat', $version ? "Sumber {$version} direkam tanpa menimpa versi sebelumnya." : null),
            'clinical.medical_assessment_version_created' => $this->card('MEDICINE', 'Kedokteran', 'Versi asesmen medis dibuat', $version ? "Sumber {$version} direkam tanpa menimpa versi sebelumnya." : null),
            'clinical.version_submitted' => $this->card($this->documentCategory($documentType), $this->documentCategoryLabel($documentType), "{$documentLabel} diajukan", $version ? "{$version} dikirim untuk tinjauan supervisor." : 'Dokumen dikirim untuk tinjauan supervisor.'),
            'clinical.version_approved_for_simulation' => $this->card($this->documentCategory($documentType), $this->documentCategoryLabel($documentType), "{$documentLabel} disetujui untuk simulasi", $version ? "Persetujuan melekat pada {$version}." : 'Persetujuan melekat pada versi yang ditinjau.'),
            'clinical.version_changes_requested' => $this->card($this->documentCategory($documentType), $this->documentCategoryLabel($documentType), "Perbaikan {$documentLabel} diminta", $version ? "{$version} tetap tersimpan dan versi penerus diperlukan." : 'Versi yang ditinjau tetap tersimpan dan versi penerus diperlukan.'),
            'clinical.synthetic_result_released' => $this->card('RESULTS', 'Hasil', 'Hasil sintetis dirilis', $version ? "Hasil {$version} tersedia untuk ditinjau." : null),
            'clinical.synthetic_result_corrected' => $this->card('RESULTS', 'Hasil', 'Koreksi hasil sintetis dirilis', $version ? "Hasil {$version} menggantikan versi sebelumnya tanpa menghapus riwayat." : 'Versi lama tetap dipertahankan.'),
            'clinical.synthetic_result_acknowledged' => $this->card('RESULTS', 'Hasil', 'Hasil sintetis diakui', $version ? "Hasil {$version} telah dibaca dalam konteks encounter." : null),
            'clinical.pharmacy_review_recorded' => $this->card('PHARMACY', 'Farmasi', 'Telaah farmasi dicatat', $this->pharmacyOutcomeDetail($metadata)),
            'clinical.pharmacy_intervention_responded' => $this->card('PHARMACY', 'Farmasi', 'Intervensi farmasi ditanggapi', 'Tanggapan prescriber dicatat tanpa mengubah permintaan obat sebelumnya.'),
            'clinical.medication_dispense_recorded' => $this->card('PHARMACY', 'Farmasi', 'Penyerahan obat simulasi dicatat', $this->dispenseDetail($metadata)),
            'clinical.encounter_closure_draft_saved' => $this->card('CLOSURE', 'Penutupan', 'Draf penutupan encounter disimpan', $version ? "Penutupan {$version} masih berupa draf." : null),
            'clinical.encounter_closure_submitted' => $this->card('CLOSURE', 'Penutupan', 'Penutupan encounter diajukan', $version ? "Penutupan {$version} dikirim untuk tinjauan." : null),
            'clinical.encounter_closure_approved_for_simulation' => $this->card('CLOSURE', 'Penutupan', 'Penutupan disetujui untuk simulasi', $version ? "Persetujuan melekat pada penutupan {$version}." : null),
            'clinical.encounter_closure_changes_requested' => $this->card('CLOSURE', 'Penutupan', 'Perbaikan penutupan diminta', $version ? "Penutupan {$version} tetap tersimpan dan versi penerus diperlukan." : null),
            'record_quality.review_draft_saved' => $this->card('RMIK', 'RMIK', 'Draf telaah mutu rekam disimpan', $version ? "Checklist {$version} belum diajukan." : null),
            'record_quality.review_submitted' => $this->card('RMIK', 'RMIK', 'Telaah mutu rekam diajukan', $this->findingDetail($metadata, $version)),
            'record_quality.correction_requested' => $this->card('RMIK', 'RMIK', 'Koreksi dokumentasi diminta', 'Temuan diarahkan kepada penulis sumber; RMIK tidak mengubah teks klinis.'),
            'record_quality.review_approved_for_simulation' => $this->card('RMIK', 'RMIK', 'Telaah mutu rekam disetujui', $version ? "Persetujuan melekat pada checklist {$version}." : null),
            'record_quality.review_changes_requested' => $this->card('RMIK', 'RMIK', 'Perbaikan telaah mutu diminta', $this->findingDetail($metadata, $version)),
            'coding.suggestions_generated',
            'coding.procedure_suggestions_generated' => $this->card('CODING', 'Koding', "Kandidat kode untuk {$sourceLabel} dibuat", $this->candidateDetail($metadata)),
            'coding.suggestion_no_reliable_candidate',
            'coding.procedure_suggestion_no_reliable_candidate' => $this->card('CODING', 'Koding', "Tidak ada kandidat kode andal untuk {$sourceLabel}", 'Koder harus mencari dan menilai alternatif secara manual.'),
            'coding.candidate_accepted_to_draft' => $this->card('CODING', 'Koding', 'Kandidat diterima ke draf koding', 'Keputusan manusia membuat draf; kode belum final.'),
            'coding.manual_alternative_selected' => $this->card('CODING', 'Koding', 'Alternatif kode dipilih manual', 'Koder memilih alternatif di luar kandidat teratas.'),
            'coding.candidates_rejected' => $this->card('CODING', 'Koding', 'Kandidat kode ditolak', 'Koder menolak kandidat dan mempertahankan sumber klinis.'),
            'coding.documentation_correction_requested' => $this->card('CODING', 'Koding', 'Koreksi sumber diagnosis diminta', 'Koding dijeda sampai penulis dan supervisor menyelesaikan versi sumber penerus.'),
            'coding.procedure_documentation_correction_requested' => $this->card('CODING', 'Koding', 'Koreksi sumber prosedur diminta', 'Koding dijeda sampai penulis penutupan dan supervisor menyelesaikan versi penerus.'),
            'coding.assignment_submitted' => $this->card('CODING', 'Koding', "Koding {$sourceLabel} diajukan", $this->codingAssignmentDetail($metadata, false)),
            'coding.assignment_approved_for_simulation' => $this->card('CODING', 'Koding', "Koding {$sourceLabel} disetujui untuk simulasi", $this->codingAssignmentDetail($metadata, true)),
            'coding.assignment_changes_requested' => $this->card('CODING', 'Koding', "Perbaikan koding {$sourceLabel} diminta", 'Draf yang ditinjau tetap tersimpan dan keputusan baru diperlukan.'),
            default => $this->card('WORKFLOW', 'Alur encounter', 'Peristiwa encounter dicatat', null),
        };
    }

    /** @return array{category: array{code: string, label: string}, title: string, detail: string|null} */
    private function card(string $code, string $label, string $title, ?string $detail): array
    {
        return [
            'category' => ['code' => $code, 'label' => $label],
            'title' => $title,
            'detail' => $detail,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function transitionDetail(array $metadata): string
    {
        $from = EncounterStatus::tryFrom((string) ($metadata['from_status'] ?? ''))?->label() ?? 'Awal';
        $to = EncounterStatus::tryFrom((string) ($metadata['to_status'] ?? ''))?->label() ?? 'Tahap berikutnya';

        return "{$from} → {$to}.";
    }

    /** @param array<string, mixed> $metadata */
    private function ticketDetail(array $metadata): ?string
    {
        $ticket = $metadata['ticket_number'] ?? null;

        return is_string($ticket) && $ticket !== '' ? "Nomor antrean {$ticket}." : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{category: array{code: string, label: string}, title: string, detail: string|null}
     */
    private function terminationCard(array $metadata): array
    {
        $status = AppointmentStatus::tryFrom((string) ($metadata['appointment_status'] ?? ''));

        return match ($status) {
            AppointmentStatus::Cancelled => $this->card(
                'REGISTRATION',
                'Registrasi',
                'Kunjungan dibatalkan',
                'Outcome kunjungan: Dibatalkan.',
            ),
            AppointmentStatus::NoShow => $this->card(
                'REGISTRATION',
                'Registrasi',
                'Pasien tidak hadir',
                'Outcome kunjungan: Tidak hadir.',
            ),
            default => $this->card(
                'REGISTRATION',
                'Registrasi',
                'Outcome kunjungan dicatat',
                'Outcome terminal tersimpan pada sumber registrasi.',
            ),
        };
    }

    /** @param array<string, mixed> $metadata */
    private function pharmacyOutcomeDetail(array $metadata): ?string
    {
        $outcome = match ($metadata['overall_outcome'] ?? null) {
            'ACCEPT' => 'diterima',
            'CLARIFICATION_REQUIRED' => 'memerlukan klarifikasi',
            'REJECT' => 'tidak dapat diteruskan',
            default => null,
        };

        return $outcome ? "Outcome telaah: {$outcome}." : null;
    }

    /** @param array<string, mixed> $metadata */
    private function dispenseDetail(array $metadata): ?string
    {
        $outcome = match ($metadata['outcome'] ?? null) {
            'COMPLETE' => 'lengkap',
            'PARTIAL' => 'sebagian',
            'NOT_DISPENSED' => 'tidak diserahkan',
            default => null,
        };
        $quantity = $metadata['quantity'] ?? null;
        $unit = $metadata['unit'] ?? null;
        $quantityText = (is_numeric($quantity) && is_string($unit) && $unit !== '')
            ? " Kuantitas: {$quantity} {$unit}."
            : '';

        return $outcome ? "Outcome penyerahan: {$outcome}.{$quantityText}" : null;
    }

    /** @param array<string, mixed> $metadata */
    private function findingDetail(array $metadata, ?string $version): ?string
    {
        $count = $metadata['finding_count'] ?? $metadata['manual_finding_count'] ?? null;
        $prefix = $version ? "Checklist {$version}. " : '';

        return is_numeric($count) ? "{$prefix}Temuan tercatat: {$count}." : ($prefix !== '' ? trim($prefix) : null);
    }

    /** @param array<string, mixed> $metadata */
    private function candidateDetail(array $metadata): string
    {
        $count = is_numeric($metadata['candidate_count'] ?? null) ? (int) $metadata['candidate_count'] : 0;

        return "{$count} kandidat tersedia; seluruhnya tetap memerlukan keputusan koder.";
    }

    /** @param array<string, mixed> $metadata */
    private function codingAssignmentDetail(array $metadata, bool $approved): string
    {
        $code = $metadata['code'] ?? null;
        $codeText = is_string($code) && $code !== '' ? "Kode {$code}. " : '';

        return $approved
            ? "{$codeText}Persetujuan supervisor melekat pada sumber dan release terminologi yang ditinjau."
            : "{$codeText}Draf dikirim untuk tinjauan supervisor; tidak difinalkan otomatis.";
    }

    private function documentCategory(?ClinicalDocumentType $documentType): string
    {
        return $documentType === ClinicalDocumentType::NursingIntake ? 'NURSING' : 'MEDICINE';
    }

    private function documentCategoryLabel(?ClinicalDocumentType $documentType): string
    {
        return $documentType === ClinicalDocumentType::NursingIntake ? 'Keperawatan' : 'Kedokteran';
    }

    private function actorName(mixed $actor, mixed $assignmentUser): string
    {
        if ($actor instanceof User) {
            return $actor->name;
        }

        if ($assignmentUser instanceof User) {
            return $assignmentUser->name;
        }

        return 'Sistem SIMRS';
    }

    /** @param array<string, mixed> $metadata */
    private function versionLabel(array $metadata): ?string
    {
        $version = $metadata['version_number'] ?? $metadata['result_version'] ?? $metadata['review_version'] ?? null;

        return is_numeric($version) ? 'v'.(int) $version : null;
    }

    private function resourceLabel(string $resourceType): string
    {
        return match ($resourceType) {
            'appointment_registration' => 'Registrasi/appointment',
            'encounter' => 'Encounter',
            'clinical_entry_version' => 'Versi dokumen klinis',
            'diagnostic_result' => 'Versi hasil sintetis',
            'pharmacy_review' => 'Telaah farmasi',
            'pharmacy_intervention' => 'Intervensi farmasi',
            'medication_dispense' => 'Catatan penyerahan obat',
            'encounter_closure' => 'Versi penutupan encounter',
            'record_quality_review' => 'Versi telaah mutu rekam',
            'record_correction_request' => 'Permintaan koreksi rekam',
            'coding_suggestion_run' => 'Proses kandidat koding',
            'coding_suggestion_decision' => 'Keputusan kandidat koding',
            'coding_assignment' => 'Penetapan kode',
            default => 'Sumber encounter',
        };
    }

    /** @return list<array{code: string, label: string}> */
    private function tags(string $action): array
    {
        $tags = [];

        if (str_contains($action, 'correction') || str_contains($action, 'corrected') || str_contains($action, 'changes_requested')) {
            $tags[] = ['code' => 'CORRECTION', 'label' => 'Koreksi/versi penerus'];
        }

        if (str_contains($action, 'approved_for_simulation') || str_contains($action, 'changes_requested')) {
            $tags[] = ['code' => 'SUPERVISION', 'label' => 'Keputusan supervisor'];
        }

        if ($action === 'encounter.transitioned' || $action === 'appointment.checked_in' || str_ends_with($action, '_submitted')) {
            $tags[] = ['code' => 'HANDOFF', 'label' => 'Handoff'];
        }

        if (str_starts_with($action, 'coding.')) {
            $tags[] = ['code' => 'HUMAN_CODING', 'label' => 'Keputusan koding manusia'];
        }

        return $tags;
    }

    /** @param array<string, mixed> $event */
    private function hasTag(array $event, string $code): bool
    {
        $tags = $event['tags'] ?? null;

        if (! is_array($tags)) {
            return false;
        }

        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, AuditEvent>  $events
     * @return array<string, CarbonInterface>
     */
    private function clinicalOccurrences(Collection $events): array
    {
        $idsFor = fn (string $resourceType): array => $events
            ->where('resource_type', $resourceType)
            ->pluck('resource_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
        $occurrences = [];

        ClinicalEntryVersion::query()
            ->whereIn('public_id', $idsFor('clinical_entry_version'))
            ->get(['public_id', 'clinical_occurrence_at'])
            ->each(function (ClinicalEntryVersion $version) use (&$occurrences): void {
                $occurrences['clinical_entry_version|'.$version->public_id] = $version->clinical_occurrence_at;
            });
        EncounterClosure::query()
            ->whereIn('public_id', $idsFor('encounter_closure'))
            ->get(['public_id', 'clinical_occurrence_at'])
            ->each(function (EncounterClosure $closure) use (&$occurrences): void {
                $occurrences['encounter_closure|'.$closure->public_id] = $closure->clinical_occurrence_at;
            });
        DiagnosticResult::query()
            ->whereIn('public_id', $idsFor('diagnostic_result'))
            ->get(['public_id', 'effective_at'])
            ->each(function (DiagnosticResult $result) use (&$occurrences): void {
                $occurrences['diagnostic_result|'.$result->public_id] = $result->effective_at;
            });
        MedicationDispense::query()
            ->whereIn('public_id', $idsFor('medication_dispense'))
            ->get(['public_id', 'prepared_at', 'checked_at', 'handed_over_at'])
            ->each(function (MedicationDispense $dispense) use (&$occurrences): void {
                $occurrences['medication_dispense|'.$dispense->public_id] = $dispense->handed_over_at
                    ?? $dispense->checked_at
                    ?? $dispense->prepared_at;
            });

        return $occurrences;
    }
}
