<?php

namespace App\Support\Clinical;

use App\Models\Encounter;
use App\Models\LabDiagnosticResult;
use App\Models\LaboratoryOrder;
use App\Models\LabServiceRequest;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Laboratory\LaboratoryActorPolicy;
use UnexpectedValueException;

final class LegacyLaboratoryCompatibilityProjection
{
    public function __construct(private readonly LaboratoryActorPolicy $policy) {}

    /** @return list<array<string, mixed>> */
    public function encounter(Encounter $encounter, User $actor): array
    {
        $physician = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::LABORATORY_ORDER_CREATE);
        $nurse = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_NURSE, Capability::LABORATORY_SPECIMEN_COLLECT);
        if (! $physician && ! $nurse) {
            return [];
        }

        return array_values($encounter->labServiceRequests()
            ->with(['encounter.patient', 'requestedBy', 'result.enteredBy'])
            ->orderByDesc('requested_at')
            ->get()
            ->map(fn (LabServiceRequest $order): array => $this->order($order, $physician))
            ->all());
    }

    /** @return array<string, mixed> */
    public function order(LabServiceRequest $order, bool $includeResult = false): array
    {
        $order->loadMissing(['encounter.patient', 'requestedBy', 'result.enteredBy']);
        $encounter = $order->encounter;
        $result = $order->result;

        return [
            'source' => 'LEGACY_READ_ONLY',
            'public_id' => $order->public_id,
            'version' => 1,
            'state' => $order->status === LabServiceRequest::STATUS_COMPLETED
                ? LaboratoryOrder::REPORTED_VERIFIED
                : ($order->status === LabServiceRequest::STATUS_CANCELLED ? LaboratoryOrder::CANCELLED : LaboratoryOrder::ORDERED),
            'priority' => 'ROUTINE',
            'ordered_at' => $order->requested_at->toIso8601String(),
            'ordering_physician_name' => $order->requestedBy->name,
            'ordering_physician_public_id' => $order->requestedBy->public_id,
            'care_setting' => $encounter->care_setting,
            'care_location_label' => $encounter->ward_name ?: ($encounter->clinic_name ?: 'Lokasi tidak tersedia'),
            'encounter_number' => $encounter->public_id,
            'encounter_url' => $this->encounterUrl($encounter),
            'patient' => [
                'medical_record_number' => $encounter->patient->medical_record_number,
                'display_name' => $encounter->patient->full_name,
            ],
            'examination' => [
                'public_id' => '',
                'code' => $order->test_code,
                'display_name' => $order->test_label,
                'specimen_type' => 'Arsip laboratorium',
                'collection_instruction' => null,
                'components' => [],
            ],
            'clinical_question' => $order->clinical_question ?? '',
            'cancellation' => null,
            'specimens' => [],
            'accepted_specimen_public_id' => null,
            'result' => $includeResult && $result instanceof LabDiagnosticResult ? $this->result($result) : null,
            'actions' => [
                'cancel_url' => null, 'collect_url' => null, 'receive_url' => null,
                'accept_url' => null, 'reject_url' => null, 'save_result_url' => null,
                'verify_result_url' => null, 'amend_result_url' => null, 'acknowledge_url' => null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function result(LabDiagnosticResult $result): array
    {
        $component = [
            'code' => 'LEGACY_RESULT', 'display_name' => 'Hasil tersimpan', 'value_kind' => 'TEXT',
            'value' => $result->result_text, 'unit_text' => null, 'reference_text' => null,
            'interpretation' => 'NORMAL', 'note' => null,
        ];

        return [
            'public_id' => $result->public_id,
            'version' => 1,
            'state' => 'VERIFIED',
            'author_name' => $result->enteredBy->name,
            'saved_at' => $result->issued_at->toIso8601String(),
            'verifier_name' => $result->enteredBy->name,
            'verified_at' => $result->issued_at->toIso8601String(),
            'components' => [$component],
            'critical_communication' => null,
            'amendments' => [],
            'acknowledgement' => null,
        ];
    }

    private function encounterUrl(Encounter $encounter): string
    {
        return route(match ($encounter->care_setting) {
            Encounter::CARE_SETTING_OUTPATIENT => 'pemeriksaan.rawat-jalan.show',
            Encounter::CARE_SETTING_EMERGENCY => 'pemeriksaan.igd.show',
            Encounter::CARE_SETTING_INPATIENT => 'pemeriksaan.rawat-inap.show',
            default => throw new UnexpectedValueException('Unsupported laboratory encounter care setting.'),
        }, $encounter);
    }
}
