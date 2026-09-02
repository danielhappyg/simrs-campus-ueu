<?php

namespace App\Support\Laboratory;

use App\Models\Encounter;
use App\Models\LaboratoryCriticalCommunication;
use App\Models\LaboratoryExaminationMaster;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryResultVersion;
use App\Models\LaboratorySpecimenAttempt;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Support\Facades\Route;

final class LaboratoryProjection
{
    public function __construct(private readonly LaboratoryActorPolicy $policy) {}

    /** @return array<string,mixed> */
    public function encounter(Encounter $encounter, User $actor): array
    {
        $physician = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::LABORATORY_ORDER_CREATE);
        $nurse = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_NURSE, Capability::LABORATORY_SPECIMEN_COLLECT);
        $visible = $physician || $nurse;
        $createOrderEligible = $encounter->status === Encounter::STATUS_IN_EXAMINATION
            && ! $this->encounterCancellationExists($encounter);

        return [
            'definition_version' => 'CROSS_SETTING_LABORATORY_SPECIMEN_RESULT_V1',
            'encounter' => ['public_id' => $encounter->public_id, 'care_setting' => $encounter->care_setting, 'status' => $encounter->status],
            'examination_options' => $physician ? LaboratoryExaminationMaster::query()->where('state', LaboratoryExaminationMaster::ACTIVE)->orderBy('display_name')->get()->map(fn ($master) => $this->examination($master))->all() : [],
            'priority_options' => $this->options(['ROUTINE', 'URGENT']),
            'cancellation_reason_options' => $this->options(LaboratoryWorkflowService::CANCELLATION_REASONS),
            'rejection_reason_options' => $this->options(LaboratoryWorkflowService::REJECTION_REASONS),
            'orders' => $visible ? LaboratoryOrder::query()->where('encounter_id', $encounter->id)->with(['encounter.patient', 'encounter.cancellation', 'master', 'orderingPhysician', 'cancellation', 'specimenAttempts.collector', 'specimenAttempts.events.actor', 'resultVersions.author', 'resultVersions.acknowledgement.actor', 'resultVersions.criticalCommunication.recipient'])->orderByDesc('ordered_at')->get()->map(fn ($order) => $this->order($order, $actor))->all() : [],
            'permissions' => ['can_order' => $physician, 'can_cancel_own_order' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::LABORATORY_ORDER_CANCEL), 'can_collect' => $nurse, 'can_acknowledge_own_order' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::LABORATORY_RESULT_ACKNOWLEDGE)],
            'commands' => ['create_order_url' => $physician && $createOrderEligible ? $this->createOrderRoute($encounter) : null],
        ];
    }

    /** @return array<string,mixed> */
    public function order(LaboratoryOrder $order, User $actor): array
    {
        $order->loadMissing(['encounter.patient', 'encounter.cancellation', 'master', 'orderingPhysician', 'cancellation', 'specimenAttempts.collector', 'specimenAttempts.events.actor', 'resultVersions.author', 'resultVersions.acknowledgement.actor', 'resultVersions.criticalCommunication.recipient']);
        $encounter = $order->encounter;
        $attempts = $order->specimenAttempts->sortBy('attempt_number');
        $versions = $order->resultVersions->sortBy('version');
        $latest = $versions->last();
        $base = $versions->first(fn ($version) => $version->state === LaboratoryResultVersion::VERIFIED);
        $baseDraft = $base ? $versions->first(fn ($version) => $version->version === $base->version - 1 && $version->state === LaboratoryResultVersion::DRAFT) : null;
        $amendments = $versions->where('state', LaboratoryResultVersion::AMENDED_VERIFIED);
        $physician = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::LABORATORY_ORDER_CREATE);
        $nurse = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_NURSE, Capability::LABORATORY_SPECIMEN_COLLECT);
        $technologist = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST, Capability::LABORATORY_SPECIMEN_PROCESS);
        $writer = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST, Capability::LABORATORY_RESULT_WRITE);
        $verifier = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER, Capability::LABORATORY_RESULT_VERIFY);
        $mutable = ! in_array($encounter->status, [Encounter::STATUS_CLOSED, Encounter::STATUS_CANCELLED], true)
            && ! $this->encounterCancellationExists($encounter);
        $accepted = $attempts->firstWhere('state', LaboratorySpecimenAttempt::ACCEPTED);
        $activeAttempt = $attempts->last();
        $result = null;
        if ($base && ($physician || $writer || $verifier)) {
            $currentAck = $latest?->acknowledgement;
            $historicalAck = $currentAck ?: $versions->reverse()->first(fn ($version) => $version->acknowledgement !== null)?->acknowledgement;
            $result = ['public_id' => $base->public_id, 'version' => $latest->version, 'state' => 'VERIFIED', 'author_name' => $baseDraft?->author->name ?? '—', 'saved_at' => $baseDraft?->created_at->toIso8601String(), 'verifier_name' => $base->author->name, 'verified_at' => $base->verified_at?->toIso8601String(), 'components' => $base->results, 'critical_communication' => $base->criticalCommunication ? $this->communication($base->criticalCommunication) : null, 'amendments' => $amendments->map(fn ($version) => ['public_id' => $version->public_id, 'version' => $version->version, 'reason_label' => $this->label($version->correction_reason), 'signer_name' => $version->author->name, 'signed_at' => $version->verified_at?->toIso8601String(), 'components' => $version->results, 'critical_communication' => $version->criticalCommunication ? $this->communication($version->criticalCommunication) : null])->values()->all(), 'acknowledgement' => $historicalAck ? ['public_id' => $historicalAck->public_id, 'physician_name' => $historicalAck->actor->name, 'acknowledged_at' => $historicalAck->acknowledged_at->toIso8601String(), 'is_current' => $currentAck !== null] : null];
        } elseif ($latest && $latest->state === LaboratoryResultVersion::DRAFT && ($writer || $verifier)) {
            $result = ['public_id' => $latest->public_id, 'version' => $latest->version, 'state' => 'DRAFT', 'author_name' => $latest->author->name, 'saved_at' => $latest->created_at->toIso8601String(), 'verifier_name' => null, 'verified_at' => null, 'components' => $latest->results, 'critical_communication' => null, 'amendments' => [], 'acknowledgement' => null];
        }

        return [
            'public_id' => $order->public_id, 'version' => $order->version, 'state' => $order->status, 'priority' => $order->priority,
            'ordered_at' => $order->ordered_at->toIso8601String(), 'ordering_physician_name' => $order->orderingPhysician->name, 'ordering_physician_public_id' => $order->orderingPhysician->public_id,
            'care_setting' => $order->care_setting, 'care_location_label' => $order->care_location_label_snapshot, 'encounter_number' => $order->encounter_number_snapshot,
            'encounter_url' => $this->encounterUrl($encounter), 'patient' => ['medical_record_number' => $encounter->patient->medical_record_number, 'display_name' => $encounter->patient->full_name],
            'examination' => ['public_id' => $order->master->public_id, 'code' => $order->master_code, 'display_name' => $order->master_display_name, 'specimen_type' => $order->specimen_type_snapshot, 'collection_instruction' => $order->collection_instruction_snapshot, 'components' => $order->components_snapshot],
            'clinical_question' => $order->clinical_question, 'cancellation' => $order->cancellation ? ['reason_label' => $this->label($order->cancellation->reason_code), 'note' => $order->cancellation->note] : null,
            'specimens' => $attempts->map(fn ($attempt) => $this->specimen($attempt))->values()->all(), 'accepted_specimen_public_id' => $accepted?->public_id, 'result' => $result,
            'actions' => [
                'cancel_url' => $mutable && $physician && $order->ordered_by_user_id === $actor->id && $order->status === LaboratoryOrder::ORDERED && $attempts->isEmpty() ? $this->route('laboratory.orders.cancel', [$order]) : null,
                'collect_url' => $mutable && $nurse && $order->status === LaboratoryOrder::ORDERED && (! $activeAttempt || $activeAttempt->state === LaboratorySpecimenAttempt::REJECTED) ? $this->route('laboratory.specimens.collect', [$order]) : null,
                'receive_url' => $mutable && $technologist && $activeAttempt?->state === LaboratorySpecimenAttempt::COLLECTED ? $this->route('laboratory.specimens.receive', [$activeAttempt]) : null,
                'accept_url' => $mutable && $technologist && $activeAttempt?->state === LaboratorySpecimenAttempt::RECEIVED ? $this->route('laboratory.specimens.accept', [$activeAttempt]) : null,
                'reject_url' => $mutable && $technologist && $activeAttempt?->state === LaboratorySpecimenAttempt::RECEIVED ? $this->route('laboratory.specimens.reject', [$activeAttempt]) : null,
                'save_result_url' => $mutable && $writer && $order->status === LaboratoryOrder::SPECIMEN_ACCEPTED ? $this->route('laboratory.orders.results.save', [$order]) : null,
                'verify_result_url' => $mutable && $verifier && $latest?->state === LaboratoryResultVersion::DRAFT ? $this->route('laboratory.orders.results.verify', [$order]) : null,
                'amend_result_url' => $mutable && $verifier && $order->status === LaboratoryOrder::REPORTED_VERIFIED ? $this->route('laboratory.orders.results.amend', [$order]) : null,
                'acknowledge_url' => $mutable && $physician && $order->ordered_by_user_id === $actor->id && $order->status === LaboratoryOrder::REPORTED_VERIFIED && ! $latest?->acknowledgement ? $this->route('laboratory.orders.results.acknowledge', [$order]) : null,
            ],
        ];
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    public function worklist(User $actor, array $filters = []): array
    {
        $query = LaboratoryOrder::query()
            ->whereHas('encounter.patient', fn ($patient) => $patient->where('is_synthetic', true))
            ->with(['encounter.patient', 'master', 'orderingPhysician', 'cancellation', 'specimenAttempts.collector', 'specimenAttempts.events.actor', 'resultVersions.author', 'resultVersions.acknowledgement.actor', 'resultVersions.criticalCommunication.recipient'])
            ->orderByDesc('ordered_at');
        $state = $filters['state'] ?? $filters['status'] ?? '';
        foreach (['care_setting', 'priority'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        if ($state !== '') {
            $query->where('status', $state);
        }
        if (($filters['q'] ?? '') !== '') {
            $q = trim($filters['q']);
            $query->where(fn ($builder) => $builder->where('master_display_name', 'like', "%{$q}%")->orWhere('encounter_number_snapshot', 'like', "%{$q}%"));
        }

        return ['generated_at' => now()->toIso8601String(), 'filters' => ['q' => $filters['q'] ?? '', 'care_setting' => $filters['care_setting'] ?? '', 'state' => $state, 'priority' => $filters['priority'] ?? ''], 'filter_options' => ['care_settings' => $this->options(Encounter::CARE_SETTINGS), 'states' => $this->options([LaboratoryOrder::ORDERED, LaboratoryOrder::SPECIMEN_ACCEPTED, LaboratoryOrder::REPORTED_VERIFIED, LaboratoryOrder::CANCELLED]), 'priorities' => $this->options(['ROUTINE', 'URGENT'])], 'orders' => $query->with(['encounter.patient', 'encounter.cancellation', 'master', 'orderingPhysician', 'cancellation', 'specimenAttempts.collector', 'specimenAttempts.events.actor', 'resultVersions.author', 'resultVersions.acknowledgement.actor', 'resultVersions.criticalCommunication.recipient'])->limit(100)->get()->map(fn ($order) => $this->order($order, $actor))->all(), 'permissions' => ['can_collect' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_NURSE, Capability::LABORATORY_SPECIMEN_COLLECT), 'can_process_specimen' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST, Capability::LABORATORY_SPECIMEN_PROCESS), 'can_save_result' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST, Capability::LABORATORY_RESULT_WRITE), 'can_verify_result' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER, Capability::LABORATORY_RESULT_VERIFY)], 'rejection_reason_options' => $this->options(LaboratoryWorkflowService::REJECTION_REASONS), 'interpretation_options' => $this->options(['NORMAL', 'ABNORMAL', 'CRITICAL']), 'communication_method_options' => $this->options(LaboratoryWorkflowService::COMMUNICATION_METHODS), 'communication_outcome_options' => $this->options(LaboratoryWorkflowService::COMMUNICATION_OUTCOMES), 'critical_communication_recipient_options' => $this->criticalCommunicationRecipientOptions($actor), 'amendment_reason_options' => $this->options(LaboratoryWorkflowService::CORRECTION_REASONS)];
    }

    /** @return array<string,mixed> */
    public function masters(User $actor): array
    {
        $can = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_ADMIN, Capability::LABORATORY_MASTER_MANAGE);

        return ['examinations' => LaboratoryExaminationMaster::query()->orderBy('display_name')->get()->map(fn ($master) => [...$this->examination($master), 'state' => $master->state, 'version' => $master->version, 'actions' => ['update_url' => $can && $master->state === LaboratoryExaminationMaster::ACTIVE ? $this->route('laboratory.masters.update', [$master]) : null, 'retire_url' => $can && $master->state === LaboratoryExaminationMaster::ACTIVE ? $this->route('laboratory.masters.retire', [$master]) : null]])->all(), 'value_kind_options' => $this->options(['TEXT', 'NUMERIC', 'QUALITATIVE']), 'permissions' => ['can_manage' => $can], 'commands' => ['create_url' => $can ? $this->route('laboratory.masters.store') : null]];
    }

    /** @return array<string, mixed> */
    private function specimen(LaboratorySpecimenAttempt $attempt): array
    {
        $received = $attempt->events->firstWhere('event_type', 'RECEIVED');
        $assessed = $attempt->events->first(fn ($event) => in_array($event->event_type, ['ACCEPTED', 'REJECTED'], true));

        return ['public_id' => $attempt->public_id, 'attempt_number' => $attempt->attempt_number, 'label_identifier' => $attempt->label_identifier, 'state' => $attempt->state, 'collected_at' => $attempt->collected_at->toIso8601String(), 'collector_name' => $attempt->collector->name, 'collection_note' => $attempt->collection_note, 'received_at' => $received?->occurred_at->toIso8601String(), 'receiver_name' => $received?->actor->name, 'assessed_at' => $assessed?->occurred_at->toIso8601String(), 'assessor_name' => $assessed?->actor->name, 'rejection_reason_label' => $assessed?->event_type === 'REJECTED' ? $this->label($assessed->reason_code) : null, 'rejection_note' => $assessed?->event_type === 'REJECTED' ? $assessed->note : null];
    }

    /** @return array<string, mixed> */
    private function communication(LaboratoryCriticalCommunication $communication): array
    {
        return ['communicated_at' => $communication->communicated_at->toIso8601String(), 'method_label' => $this->label($communication->communication_method), 'recipient_physician_name' => $communication->recipient->name, 'outcome_label' => $this->label($communication->outcome), 'note' => $communication->note];
    }

    /** @return array<string, mixed> */
    private function examination(LaboratoryExaminationMaster $master): array
    {
        return ['public_id' => $master->public_id, 'code' => $master->examination_code, 'display_name' => $master->display_name, 'specimen_type' => $master->specimen_type, 'collection_instruction' => $master->collection_instruction, 'components' => $master->components];
    }

    /** @return list<array{value:string,label:string}> */
    private function criticalCommunicationRecipientOptions(User $actor): array
    {
        if (! $this->policy->can($actor, RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER, Capability::LABORATORY_RESULT_VERIFY)) {
            return [];
        }

        return array_values(User::query()
            ->where('status', 'ACTIVE')
            ->where('is_system_administrator', false)
            ->whereHas('roles', fn ($roles) => $roles->where('slug', RoleCapabilityMatrix::ROLE_PHYSICIAN))
            ->whereDoesntHave('roles', fn ($roles) => $roles->where('slug', '!=', RoleCapabilityMatrix::ROLE_PHYSICIAN))
            ->with('roles.permissions')
            ->orderBy('name')
            ->orderBy('public_id')
            ->limit(100)
            ->get()
            ->filter(fn (User $user): bool => $this->policy->can($user, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::LABORATORY_ORDER_CREATE))
            ->map(fn (User $user): array => ['value' => $user->public_id, 'label' => $user->name])
            ->values()
            ->all());
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{value: string, label: string}>
     */
    private function options(array $codes): array
    {
        return array_map(fn (string $code): array => ['value' => $code, 'label' => $this->label($code)], $codes);
    }

    private function label(?string $code): string
    {
        return match ($code) {
            'COMMUNICATED' => 'Tersampaikan',
            'ESCALATED' => 'Dieskalasikan',
            'TELEPHONE' => 'Telepon',
            'DIRECT' => 'Langsung',
            'SECURE_INTERNAL_CHANNEL' => 'Kanal internal aman',
            'ROUTINE' => 'Rutin',
            'URGENT' => 'Segera',
            'ORDERED' => 'Dipesan',
            'SPECIMEN_ACCEPTED' => 'Spesimen diterima',
            'REPORTED_VERIFIED' => 'Hasil terverifikasi',
            'CANCELLED' => 'Dibatalkan',
            'TRANSCRIPTION_CORRECTION' => 'Koreksi transkripsi',
            'TECHNICAL_CORRECTION' => 'Koreksi teknis',
            'VERIFIER_CLARIFICATION' => 'Klarifikasi verifikator',
            default => str((string) $code)->replace('_', ' ')->lower()->title()->toString(),
        };
    }

    /** @param array<int|string, mixed> $params */
    private function route(string $name, array $params = []): ?string
    {
        return Route::has($name) ? route($name, $params) : null;
    }

    private function createOrderRoute(Encounter $encounter): ?string
    {
        return $this->route(match ($encounter->care_setting) {
            Encounter::CARE_SETTING_OUTPATIENT => 'laboratory.outpatient.orders.store', Encounter::CARE_SETTING_EMERGENCY => 'laboratory.emergency.orders.store', Encounter::CARE_SETTING_INPATIENT => 'laboratory.inpatient.orders.store', default => throw new \LogicException('Pengaturan layanan pertemuan tidak didukung.'),
        }, [$encounter]);
    }

    private function encounterUrl(?Encounter $encounter): string
    {
        if (! $encounter) {
            return '#';
        }

        return route(match ($encounter->care_setting) {
            Encounter::CARE_SETTING_OUTPATIENT => 'pemeriksaan.rawat-jalan.show', Encounter::CARE_SETTING_EMERGENCY => 'pemeriksaan.igd.show', Encounter::CARE_SETTING_INPATIENT => 'pemeriksaan.rawat-inap.show', default => throw new \LogicException('Pengaturan layanan pertemuan tidak didukung.'),
        }, $encounter);
    }

    private function encounterCancellationExists(Encounter $encounter): bool
    {
        return $encounter->relationLoaded('cancellation')
            ? $encounter->cancellation !== null
            : $encounter->cancellation()->exists();
    }
}
