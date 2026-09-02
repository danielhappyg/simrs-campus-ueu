<?php

namespace App\Support\Radiology;

use App\Models\Encounter;
use App\Models\RadiologyExaminationMaster;
use App\Models\RadiologyOrder;
use App\Models\RadiologyReportVersion;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Support\Facades\Route;

final class RadiologyProjection
{
    public function __construct(private readonly RadiologyActorPolicy $policy) {}

    /** @return array<string,mixed> */
    public function encounter(Encounter $encounter, User $actor): array
    {
        $canOrder = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::RADIOLOGY_ORDER_CREATE);
        // Encounter pages expose the narrative chain only to the exact physician
        // role. Technologists and radiologists receive their task-scoped fields
        // through the worklist; RMIK receives only closure facts elsewhere.
        $visible = $actor->roleSlugs() === [RoleCapabilityMatrix::ROLE_PHYSICIAN]
            && ! $actor->is_system_administrator;
        $encounterMutable = $encounter->status === Encounter::STATUS_IN_EXAMINATION
            && ! $encounter->cancellation()->exists();

        return ['definition_version' => 'CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1', 'encounter' => ['public_id' => $encounter->public_id, 'care_setting' => $encounter->care_setting, 'status' => $encounter->status],
            'examination_options' => RadiologyExaminationMaster::query()->where('state', RadiologyExaminationMaster::ACTIVE)->orderBy('display_name')->get()->map(fn ($m) => $this->examination($m))->all(),
            'cancellation_reason_options' => $this->options(RadiologyWorkflowService::CANCELLATION_REASONS), 'orders' => $visible ? $encounter->radiologyOrders()->orderByDesc('ordered_at')->get()->map(fn ($o) => $this->order($o, $actor))->all() : [],
            'permissions' => ['can_order' => $canOrder, 'can_cancel_own_order' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::RADIOLOGY_ORDER_CANCEL), 'can_acknowledge_own_order' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::RADIOLOGY_REPORT_ACKNOWLEDGE)],
            'commands' => ['create_order_url' => $canOrder && $encounterMutable ? $this->createOrderRoute($encounter) : null]];
    }

    /** @return array<string,mixed> */
    public function order(RadiologyOrder $order, User $actor): array
    {
        $order->loadMissing(['encounter.patient', 'orderingPhysician', 'performance.performer', 'cancellation', 'reportVersions.author', 'reportVersions.acknowledgement.actor']);
        $enc = $order->encounter;
        $versions = $order->reportVersions->sortBy('version');
        $baseCandidate = $versions->first(fn ($v) => $v->state === RadiologyReportVersion::VERIFIED);
        $base = $baseCandidate instanceof RadiologyReportVersion ? $baseCandidate : null;
        $latestCandidate = $versions->last();
        $latest = $latestCandidate instanceof RadiologyReportVersion ? $latestCandidate : null;
        $amendments = $versions->where('state', RadiologyReportVersion::AMENDED_VERIFIED);
        $ack = $latest?->acknowledgement;
        $ackCurrent = $ack !== null;
        if (! $ack) {
            $ack = $versions->reverse()->first(fn ($v) => $v->acknowledgement !== null)?->acknowledgement;
        }
        $radiologist = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_RADIOLOGIST, Capability::RADIOLOGY_REPORT_WRITE);
        // Draft narrative is private to the exact radiologist worklist. A
        // physician encounter projection receives nothing until verification.
        $report = $base ? ['public_id' => $base->public_id, 'version' => $latest->version, 'state' => 'VERIFIED', 'examination' => $order->master_display_name, 'findings' => $base->findings, 'impression' => $base->impression, 'recommendation' => $base->recommendation, 'radiologist_name' => $base->author->name, 'verified_at' => $base->verified_at?->toIso8601String(), 'amendments' => $amendments->map(fn ($v) => ['public_id' => $v->public_id, 'version' => $v->version, 'reason' => $v->amendment_reason, 'amended_statement' => $v->amended_statement, 'radiologist_name' => $v->author->name, 'signed_at' => $v->verified_at?->toIso8601String()])->values()->all(), 'acknowledgement' => $ack ? ['public_id' => $ack->public_id, 'physician_name' => $ack->actor->name, 'acknowledged_at' => $ack->acknowledged_at->toIso8601String(), 'is_current' => $ackCurrent] : null] : ($latest && $radiologist ? ['public_id' => $latest->public_id, 'version' => $latest->version, 'state' => 'DRAFT', 'examination' => $order->master_display_name, 'findings' => (string) $latest->findings, 'impression' => (string) $latest->impression, 'recommendation' => $latest->recommendation, 'radiologist_name' => $latest->author->name, 'verified_at' => null, 'amendments' => [], 'acknowledgement' => null] : null);
        $physician = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::RADIOLOGY_ORDER_CANCEL);
        $technologist = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST, Capability::RADIOLOGY_WORKLIST_PERFORM);
        $verifier = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_RADIOLOGIST, Capability::RADIOLOGY_REPORT_VERIFY);
        $encounterMutable = $enc instanceof Encounter
            && $enc->status === Encounter::STATUS_IN_EXAMINATION
            && ! $enc->cancellation()->exists();

        return ['public_id' => $order->public_id, 'version' => $order->version, 'state' => $order->status, 'ordered_at' => $order->ordered_at->toIso8601String(), 'performed_at' => $order->performance?->performed_at->toIso8601String(), 'ordering_physician_name' => $order->orderingPhysician->name, 'performer_name' => $order->performance?->performer->name, 'care_setting' => $order->care_setting, 'care_location_label' => $order->care_location_label_snapshot, 'encounter_number' => $order->encounter_number_snapshot, 'encounter_url' => $this->encounterUrl($enc), 'patient' => ['medical_record_number' => $enc->patient->medical_record_number, 'display_name' => $enc->patient->full_name], 'examination' => ['public_id' => $order->master->public_id, 'code' => $order->master_code, 'display_name' => $order->master_display_name, 'preparation_instruction' => $order->master_preparation_instruction], 'clinical_question' => $order->clinical_indication, 'cancellation' => $order->cancellation ? ['reason_label' => $this->label($order->cancellation->reason_code), 'note' => $order->cancellation->note] : null, 'report' => $report, 'actions' => ['cancel_url' => $encounterMutable && $physician && $order->ordered_by_user_id === $actor->id && $order->status === RadiologyOrder::ORDERED ? $this->route('radiology.orders.cancel', [$order]) : null, 'perform_url' => $encounterMutable && $technologist && $order->status === RadiologyOrder::ORDERED ? $this->route('radiology.orders.perform', [$order]) : null, 'save_report_url' => $encounterMutable && $radiologist && $order->status === RadiologyOrder::PERFORMED ? $this->route('radiology.orders.report.save', [$order]) : null, 'verify_report_url' => $encounterMutable && $verifier && $latest?->state === RadiologyReportVersion::DRAFT ? $this->route('radiology.orders.report.verify', [$order]) : null, 'amend_report_url' => $encounterMutable && $verifier && $order->status === RadiologyOrder::REPORTED_VERIFIED ? $this->route('radiology.orders.report.amend', [$order]) : null, 'acknowledge_url' => $encounterMutable && $physician && $order->ordered_by_user_id === $actor->id && $order->status === RadiologyOrder::REPORTED_VERIFIED && ! $latest?->acknowledgement ? $this->route('radiology.orders.report.acknowledge', [$order]) : null]];
    }

    /** @return array<string,mixed> */
    public function masters(User $actor): array
    {
        $can = $actor->roleSlugs() === [RoleCapabilityMatrix::ROLE_ADMIN] && ! $actor->is_system_administrator && $actor->canCapability(Capability::RADIOLOGY_MASTER_MANAGE);

        return ['examinations' => RadiologyExaminationMaster::query()->orderBy('display_name')->get()->map(fn ($m) => ['public_id' => $m->public_id, 'code' => $m->examination_code, 'display_name' => $m->display_name, 'preparation_instruction' => $m->preparation_instruction, 'state' => $m->state, 'version' => $m->version, 'actions' => ['update_url' => $can && $m->state === RadiologyExaminationMaster::ACTIVE ? $this->route('radiology.masters.update', [$m]) : null, 'retire_url' => $can && $m->state === RadiologyExaminationMaster::ACTIVE ? $this->route('radiology.masters.retire', [$m]) : null]])->all(), 'permissions' => ['can_manage' => $can], 'commands' => ['create_url' => $can ? $this->route('radiology.masters.store') : null]];
    }

    /** @return array<string, mixed> */
    private function examination(RadiologyExaminationMaster $m): array
    {
        return ['public_id' => $m->public_id, 'code' => $m->examination_code, 'display_name' => $m->display_name, 'preparation_instruction' => $m->preparation_instruction];
    }

    private function encounterUrl(?Encounter $e): string
    {
        if (! $e) {
            return '#';
        }$name = match ($e->care_setting) {
            Encounter::CARE_SETTING_OUTPATIENT => 'pemeriksaan.rawat-jalan.show',Encounter::CARE_SETTING_EMERGENCY => 'pemeriksaan.igd.show',Encounter::CARE_SETTING_INPATIENT => 'pemeriksaan.rawat-inap.show', default => null,
        };

        if ($name === null) {
            return '#';
        }

        return route($name, $e);
    }

    private function createOrderRoute(Encounter $e): ?string
    {
        $name = match ($e->care_setting) {
            Encounter::CARE_SETTING_OUTPATIENT => 'radiology.outpatient.orders.store',Encounter::CARE_SETTING_EMERGENCY => 'radiology.emergency.orders.store',Encounter::CARE_SETTING_INPATIENT => 'radiology.inpatient.orders.store', default => null,
        };

        if ($name === null) {
            return null;
        }

        return $this->route($name, [$e]);
    }

    /** @param array<int|string, mixed> $params */
    private function route(string $name, array $params = []): ?string
    {
        return Route::has($name) ? route($name, $params) : null;
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{value:string,label:string}>
     */
    private function options(array $codes): array
    {
        return array_map(fn ($c) => ['value' => $c, 'label' => $this->label($c)], $codes);
    }

    private function label(string $code): string
    {
        return str($code)->replace('_', ' ')->lower()->title()->toString();
    }
}
