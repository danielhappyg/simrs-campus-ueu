<?php

namespace App\Support\Pharmacy;

use App\Models\Encounter;
use App\Models\PharmacyDepot;
use App\Models\PharmacyFinancialSourceEvent;
use App\Models\PharmacyHandover;
use App\Models\PharmacyMedicine;
use App\Models\PharmacyPreparation;
use App\Models\PharmacyPrescription;
use App\Models\PharmacyPrescriptionItem;
use App\Models\PharmacyReturnItem;
use App\Models\PharmacyStockLot;
use App\Models\PharmacyStockMovement;
use App\Models\PharmacyVerification;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Route;

final class PharmacyProjection
{
    public const DEFINITION_VERSION = 'CROSS_SETTING_MEDICATION_DISPENSING_STOCK_LEDGER_V1';

    public function __construct(private readonly PharmacyActorPolicy $policy, private readonly PharmacyEvidenceFingerprint $fingerprints) {}

    /** @return array<string, mixed> */
    public function encounter(Encounter $encounter, User $actor): array
    {
        if (! $this->canView($actor)) {
            $encounter->loadMissing('patient');

            return ['definition_version' => self::DEFINITION_VERSION, 'encounter' => $this->encounterSummary($encounter), 'medicine_options' => [], 'depot_options' => [], 'prescriptions' => [], 'permissions' => ['can_prescribe' => false, 'can_view' => false], 'commands' => ['create_draft_url' => null]];
        }
        $encounter->loadMissing(['patient', 'pharmacyPrescriptions' => fn ($q) => $q->with($this->relations())]);

        return ['definition_version' => self::DEFINITION_VERSION, 'encounter' => $this->encounterSummary($encounter), 'medicine_options' => PharmacyMedicine::query()->where('state', PharmacyMedicine::ACTIVE)->orderBy('generic_name')->get()->map(fn ($m) => $this->medicine($m))->all(), 'depot_options' => PharmacyDepot::query()->where('state', PharmacyDepot::ACTIVE)->orderBy('display_name')->get()->filter(fn ($d) => in_array($encounter->care_setting, $d->eligible_care_settings, true))->map(fn ($d) => $this->depot($d))->values()->all(), 'prescriptions' => $encounter->pharmacyPrescriptions->map(fn ($p) => $this->prescription($p, $actor))->all(), 'permissions' => ['can_prescribe' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::PHARMACY_PRESCRIPTION_WRITE), 'can_view' => $this->canView($actor)], 'commands' => ['create_draft_url' => $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::PHARMACY_PRESCRIPTION_WRITE) ? $this->url('pharmacy.encounters.prescriptions.store', ['encounter' => $encounter]) : null]];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function worklist(User $actor, array $filters = []): array
    {
        $q = PharmacyPrescription::query()->with($this->relations())->whereHas('encounter.patient');
        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $q->where(fn ($x) => $x->where('public_id', 'like', "%{$term}%")->orWhereHas('patient', fn ($p) => $p->where('full_name', 'like', "%{$term}%")->orWhere('medical_record_number', 'like', "%{$term}%")));
        }
        if (in_array($filters['care_setting'] ?? '', Encounter::CARE_SETTINGS, true)) {
            $q->where('care_setting', $filters['care_setting']);
        }
        if (in_array($filters['state'] ?? '', $this->states(), true)) {
            $q->where('status', $filters['state']);
        }
        if ($depot = trim((string) ($filters['depot'] ?? ''))) {
            $q->whereHas('depot', fn ($d) => $d->where('public_id', $depot));
        }
        $prescriptions = $q->orderByDesc('updated_at')->limit(100)->get();

        return ['generated_at' => now()->toIso8601String(), 'prescriptions' => $prescriptions->map(fn ($p) => $this->prescription($p, $actor))->all(), 'filters' => ['q' => $filters['q'] ?? '', 'care_setting' => $filters['care_setting'] ?? '', 'state' => $filters['state'] ?? '', 'depot' => $filters['depot'] ?? ''], 'filter_options' => ['care_settings' => $this->options(Encounter::CARE_SETTINGS), 'states' => $this->options($this->states()), 'depots' => PharmacyDepot::query()->orderBy('display_name')->get()->map(fn ($d) => ['value' => $d->public_id, 'label' => $d->display_name])->all()], 'permissions' => $this->workflowPermissions($actor), 'read_error' => null];
    }

    /** @return array<string, mixed> */
    public function masters(User $actor): array
    {
        $can = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER, Capability::PHARMACY_INVENTORY_MANAGE);

        return ['medicines' => PharmacyMedicine::query()->orderBy('generic_name')->get()->map(function ($m) use ($can) {
            return [...$this->medicine($m), 'standard_acquisition_value_rupiah' => $m->acquisition_value, 'teaching_sale_value_rupiah' => $m->teaching_sale_value, 'actions' => ['update_url' => $can && $m->state === PharmacyMedicine::ACTIVE ? $this->url('pharmacy.medicines.update', ['medicine' => $m]) : null, 'retire_url' => $can && $m->state === PharmacyMedicine::ACTIVE ? $this->url('pharmacy.medicines.retire', ['medicine' => $m]) : null]];
        })->all(), 'depots' => PharmacyDepot::query()->orderBy('display_name')->get()->map(function ($d) use ($can) {
            return [...$this->depot($d), 'actions' => ['update_url' => $can && $d->state === PharmacyDepot::ACTIVE ? $this->url('pharmacy.depots.update', ['depot' => $d]) : null, 'retire_url' => $can && $d->state === PharmacyDepot::ACTIVE ? $this->url('pharmacy.depots.retire', ['depot' => $d]) : null]];
        })->all(), 'lots' => PharmacyStockLot::query()->with(['medicine', 'depot'])->orderBy('lot_code')->get()->map(fn ($l) => $this->lot($l, $can))->all(), 'route_options' => $this->options(PharmacyMedicine::query()->get()->flatMap(fn ($m) => $m->route_choices)->unique()->sort()->values()->all()), 'dosage_form_options' => $this->options(PharmacyMedicine::query()->pluck('dosage_form')->unique()->sort()->values()->all()), 'care_setting_options' => $this->options(Encounter::CARE_SETTINGS), 'permissions' => ['can_manage_medicines' => $can, 'can_manage_depots' => $can, 'can_manage_inventory' => $can], 'commands' => ['create_medicine_url' => $can ? $this->url('pharmacy.medicines.store') : null, 'create_depot_url' => $can ? $this->url('pharmacy.depots.store') : null, 'open_lot_url' => $can ? $this->url('pharmacy.lots.store') : null], 'read_error' => null];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function stockCard(User $actor, array $filters = []): array
    {
        $can = $this->policy->can($actor, RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER, Capability::PHARMACY_INVENTORY_MANAGE);
        $q = PharmacyStockLot::query()->with(['medicine', 'depot', 'movements.actor']);
        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $q->where(fn ($x) => $x->where('lot_code', 'like', "%{$term}%")->orWhereHas('medicine', fn ($m) => $m->where('generic_name', 'like', "%{$term}%")));
        }
        if ($id = $filters['medicine'] ?? null) {
            $q->whereHas('medicine', fn ($m) => $m->where('public_id', $id));
        }if ($id = $filters['depot'] ?? null) {
            $q->whereHas('depot', fn ($d) => $d->where('public_id', $id));
        }if ($state = $filters['state'] ?? null) {
            $q->where('state', $state);
        }

        return ['generated_at' => now()->toIso8601String(), 'lots' => $q->orderBy('lot_code')->get()->map(function ($lot) use ($can) {
            $movements = $lot->movements->values();

            return [...$this->lot($lot, $can), 'movements' => $movements->map(fn (PharmacyStockMovement $movement, int $index) => ['public_id' => $movement->public_id, 'sequence' => $index + 1, 'movement_type' => $movement->movement_type, 'available_delta' => $movement->available_delta, 'quarantined_delta' => $movement->quarantined_delta, 'available_balance' => $movement->available_balance_after, 'quarantined_balance' => $movement->quarantined_balance_after, 'reason' => $movement->reason_code, 'source_reference' => $movement->source_public_id, 'actor_name' => $movement->actor?->name, 'occurred_at' => $movement->occurred_at->toIso8601String(), 'fingerprint' => $movement->content_digest])->all(), 'reconciled' => $this->stockReconciled($lot, $movements)];
        })->all(), 'filters' => ['q' => $filters['q'] ?? '', 'medicine' => $filters['medicine'] ?? '', 'depot' => $filters['depot'] ?? '', 'state' => $filters['state'] ?? ''], 'filter_options' => ['medicines' => PharmacyMedicine::query()->orderBy('generic_name')->get()->map(fn ($m) => ['value' => $m->public_id, 'label' => $m->generic_name])->all(), 'depots' => PharmacyDepot::query()->orderBy('display_name')->get()->map(fn ($d) => ['value' => $d->public_id, 'label' => $d->display_name])->all(), 'states' => $this->options([PharmacyStockLot::ACTIVE, PharmacyStockLot::QUARANTINED, PharmacyStockLot::RETIRED])], 'read_error' => null];
    }

    /** @return array<string, mixed> */
    public function prescription(PharmacyPrescription $p, User $actor): array
    {
        $p->loadMissing($this->relations());
        $verification = $p->verification;
        $itemProjections = $p->items->map(function (PharmacyPrescriptionItem $item) use ($p, $verification) {
            $decision = $verification ? collect($verification->item_decisions)->firstWhere('item_public_id', $item->public_id) : null;
            $handed = $p->handovers->flatMap->items->where('prescription_item_id', $item->id)->sum('quantity');
            $returned = $p->handovers->flatMap->items->where('prescription_item_id', $item->id)->flatMap->returnItems->sum('quantity');
            $verified = $decision ? (int) $decision['verified_quantity'] : null;

            return ['public_id' => $item->public_id, 'medicine' => ['public_id' => $item->medicine_version_public_id, 'code' => $item->medicine_code, 'generic_display_name' => $item->medicine_name, 'brand_display_name' => null, 'strength_text' => $item->strength_text, 'dosage_form' => $item->dosage_form, 'base_issue_unit' => $item->base_unit, 'route_choices' => [$item->route], 'state' => 'ACTIVE', 'version' => $item->medicine_version], 'dose_text' => $item->dose_text, 'route' => $item->route, 'frequency_text' => $item->frequency_text, 'duration_text' => $item->duration_text, 'requested_quantity' => $item->requested_quantity, 'verified_quantity' => $verified, 'handed_over_quantity' => $handed, 'returned_quantity' => $returned, 'remaining_quantity' => max(0, ($verified ?? $item->requested_quantity) - $handed), 'clinical_instruction' => $item->instruction];
        });
        $preparation = $p->preparations->sortByDesc('sequence')->first(
            fn ($prep) => $prep->state === PharmacyPreparation::ACTIVE && $prep->handover === null,
        );
        $returns = [];
        foreach ($p->handovers as $handover) {
            foreach ($handover->returns as $return) {
                foreach ($return->items as $returnItem) {
                    $hi = $returnItem->handoverItem;
                    $returns[] = ['public_id' => $returnItem->public_id, 'sequence' => count($returns) + 1, 'prescription_item_public_id' => $hi->prescriptionItem->public_id, 'medicine_label' => $hi->prescriptionItem->medicine_name, 'lot_code' => $hi->lot->lot_code, 'quantity' => $returnItem->quantity, 'condition' => $returnItem->condition, 'reason' => $return->reason_code, 'pharmacist_name' => $return->pharmacist?->name, 'returned_at' => $return->returned_at->toIso8601String(), 'fingerprint' => $returnItem->content_digest];
                }
            }
        }
        $events = $p->versions->map(fn ($v) => ['public_id' => $v->public_id, 'state' => $v->state, 'version' => $v->version, 'actor_name' => $v->actor?->name, 'reason' => $v->reason_code ?? ($v->content_snapshot['reason_code'] ?? null), 'occurred_at' => $v->created_at->toIso8601String(), 'fingerprint' => $v->content_digest]);
        $financial = PharmacyFinancialSourceEvent::query()->where('prescription_id', $p->id)->get();
        $returnItems = $p->handovers->flatMap->returns->flatMap->items;

        return ['public_id' => $p->public_id, 'version' => $p->version, 'state' => $p->status, 'fingerprint' => $this->fingerprints->prescription($p), 'encounter' => $this->encounterSummary($p->encounter), 'depot' => $this->depot($p->depot), 'ordering_physician' => ['public_id' => $p->orderingPhysician?->public_id, 'name' => $p->orderingPhysician?->name], 'clinical_note' => $p->clinical_note ?? '', 'items' => $itemProjections->all(), 'verification' => $verification ? $this->verification($verification, $p) : null, 'preparation' => $preparation ? $this->preparation($preparation) : null, 'handovers' => $p->handovers->map(fn ($h) => $this->handover($h, $p))->all(), 'returns' => $returns, 'history' => $events->all(), 'control_totals' => ['ordered_quantity' => $p->items->sum('requested_quantity'), 'verified_quantity' => $verification ? collect($verification->item_decisions)->sum('verified_quantity') : 0, 'handed_over_quantity' => $p->handovers->flatMap->items->sum('quantity'), 'returned_to_stock_quantity' => $returnItems->where('condition', PharmacyReturnItem::RETURN_TO_STOCK)->sum('quantity'), 'quarantined_return_quantity' => $returnItems->where('condition', PharmacyReturnItem::QUARANTINE)->sum('quantity'), 'non_returnable_quantity' => $returnItems->where('condition', PharmacyReturnItem::DESTROYED_OR_NOT_RETURNABLE)->sum('quantity'), 'gross_charge_source_rupiah' => $financial->where('event_type', PharmacyFinancialSourceEvent::CHARGE)->sum('amount'), 'reversed_charge_source_rupiah' => abs($financial->where('event_type', PharmacyFinancialSourceEvent::REVERSAL)->sum('amount')), 'net_charge_source_rupiah' => $financial->sum('amount')], 'actions' => $this->actions($p, $actor, $preparation)];
    }

    /** @return array<string, mixed> */
    private function verification(PharmacyVerification $v, PharmacyPrescription $p): array
    {
        return ['public_id' => $v->public_id, 'state' => $v->decision, 'version' => 1, 'pharmacist_name' => $v->pharmacist?->name, 'identity_context_confirmed' => ($v->checklist['identity_confirmed'] ?? false) && ($v->checklist['context_confirmed'] ?? false), 'instruction_readability_confirmed' => ($v->checklist['medicine_readable'] ?? false) && ($v->checklist['instruction_readable'] ?? false), 'manual_allergy_review_status' => $v->manual_allergy_review, 'note' => $v->note, 'refusal_reason' => $v->reason_code, 'items' => collect($v->item_decisions)->map(function ($d) use ($p) {
            $item = $p->items->firstWhere('public_id', $d['item_public_id']);
            if (! $item instanceof PharmacyPrescriptionItem) {
                throw new PharmacyDenied('receipt_corrupt', 'Keputusan verifikasi menunjuk item resep yang tidak tersedia.');
            }

            return ['prescription_item_public_id' => $d['item_public_id'], 'medicine_label' => $item->medicine_name, 'requested_quantity' => $item->requested_quantity, 'verified_quantity' => (int) $d['verified_quantity'], 'reduction_reason' => $d['reason_code'] ?? null];
        })->all(), 'recorded_at' => $v->verified_at->toIso8601String(), 'fingerprint' => $v->content_digest];
    }

    /** @return array<string, mixed> */
    private function preparation(PharmacyPreparation $prep): array
    {
        return ['public_id' => $prep->public_id, 'version' => $prep->sequence, 'technician_name' => $prep->technician?->name, 'note' => null, 'allocations' => $prep->allocations->map(fn ($a) => ['public_id' => $a->public_id, 'prescription_item_public_id' => $a->item->public_id, 'medicine_label' => $a->item->medicine_name, 'lot_public_id' => $a->lot->public_id, 'lot_code' => $a->lot->lot_code, 'expiry_date' => $a->lot->expiry_date?->toDateString(), 'no_expiry_reason' => $a->lot->no_expiry_reason, 'quantity' => $a->quantity])->all(), 'prepared_at' => $prep->prepared_at->toIso8601String(), 'fingerprint' => $this->fingerprints->preparation($prep)];
    }

    /** @return array<string, mixed> */
    private function handover(PharmacyHandover $h, PharmacyPrescription $p): array
    {
        $verified = $p->verification ? collect($p->verification->item_decisions)->sum('verified_quantity') : 0;
        $through = $p->handovers->where('sequence', '<=', $h->sequence)->flatMap->items->sum('quantity');

        return ['public_id' => $h->public_id, 'sequence' => $h->sequence, 'pharmacist_name' => $h->pharmacist?->name, 'unfilled_quantity' => max(0, $verified - $through), 'partial_reason' => $h->partial_reason, 'items' => $h->items->map(fn ($i) => ['public_id' => $i->public_id, 'prescription_item_public_id' => $i->prescriptionItem->public_id, 'medicine_label' => $i->prescriptionItem->medicine_name, 'lot_code' => $i->lot->lot_code, 'quantity' => $i->quantity, 'returnable_quantity' => max(0, $i->quantity - $i->returnItems->sum('quantity'))])->all(), 'handed_over_at' => $h->handed_over_at->toIso8601String(), 'fingerprint' => $this->fingerprints->handover($h)];
    }

    /** @return array<string, string|null> */
    private function actions(PharmacyPrescription $p, User $a, ?PharmacyPreparation $prep): array
    {
        $physician = $this->policy->can($a, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::PHARMACY_PRESCRIPTION_WRITE) && $p->ordering_physician_user_id === $a->id;
        $pharmacist = $this->policy->can($a, RoleCapabilityMatrix::ROLE_PHARMACIST, Capability::PHARMACY_PRESCRIPTION_VERIFY);
        $technician = $this->policy->can($a, RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN, Capability::PHARMACY_DISPENSE_PREPARE);
        $return = $this->policy->can($a, RoleCapabilityMatrix::ROLE_PHARMACIST, Capability::PHARMACY_RETURN_RECORD);

        return ['save_draft_url' => $physician && $p->status === PharmacyPrescription::DRAFT ? $this->url('pharmacy.prescriptions.draft.update', ['prescription' => $p]) : null, 'order_url' => $physician && $p->status === PharmacyPrescription::DRAFT ? $this->url('pharmacy.prescriptions.order', ['prescription' => $p]) : null, 'replace_url' => $physician && in_array($p->status, [PharmacyPrescription::DRAFT, PharmacyPrescription::ORDERED, PharmacyPrescription::VERIFIED], true) ? $this->url('pharmacy.prescriptions.replace', ['prescription' => $p]) : null, 'cancel_url' => $physician && in_array($p->status, [PharmacyPrescription::DRAFT, PharmacyPrescription::ORDERED], true) ? $this->url('pharmacy.prescriptions.cancel', ['prescription' => $p]) : null, 'verify_url' => $pharmacist && $p->status === PharmacyPrescription::ORDERED ? $this->url('pharmacy.prescriptions.verify', ['prescription' => $p]) : null, 'refuse_url' => $pharmacist && $p->status === PharmacyPrescription::ORDERED ? $this->url('pharmacy.prescriptions.refuse', ['prescription' => $p]) : null, 'prepare_url' => $technician && in_array($p->status, [PharmacyPrescription::VERIFIED, PharmacyPrescription::PARTIALLY_HANDED_OVER, PharmacyPrescription::PREPARED], true) ? $this->url('pharmacy.prescriptions.prepare', ['prescription' => $p]) : null, 'handover_url' => $pharmacist && $prep ? $this->url('pharmacy.preparations.handover', ['preparation' => $prep]) : null, 'close_unfilled_url' => $pharmacist && $p->status === PharmacyPrescription::PARTIALLY_HANDED_OVER ? $this->url('pharmacy.prescriptions.close-unfilled', ['prescription' => $p]) : null, 'return_url' => $return && $p->handovers->isNotEmpty() ? $this->url('pharmacy.prescriptions.returns.store', ['prescription' => $p]) : null];
    }

    /** @return array<string, mixed> */
    private function lot(PharmacyStockLot $l, bool $can): array
    {
        return ['public_id' => $l->public_id, 'medicine' => $this->medicine($l->medicine), 'depot' => $this->depot($l->depot), 'lot_code' => $l->lot_code, 'state' => $l->state, 'opened_at' => $l->received_at->toIso8601String(), 'expiry_date' => $l->expiry_date?->toDateString(), 'no_expiry_reason' => $l->no_expiry_reason, 'available_quantity' => $l->available_quantity, 'quarantined_quantity' => $l->quarantined_quantity, 'acquisition_value_rupiah' => $l->acquisition_value, 'source_reference' => $l->source_reference, 'fingerprint' => $this->fingerprints->lot($l), 'actions' => ['quarantine_url' => $can && $l->state === PharmacyStockLot::ACTIVE ? $this->url('pharmacy.lots.quarantine', ['lot' => $l]) : null, 'release_url' => $can && $l->state === PharmacyStockLot::QUARANTINED ? $this->url('pharmacy.lots.release', ['lot' => $l]) : null, 'correct_url' => $can && $l->state !== PharmacyStockLot::RETIRED ? $this->url('pharmacy.lots.correct', ['lot' => $l]) : null]];
    }

    /** @param EloquentCollection<int, PharmacyStockMovement> $movements */
    private function stockReconciled(PharmacyStockLot $lot, EloquentCollection $movements): bool
    {
        $available = 0;
        $quarantined = 0;
        foreach ($movements as $movement) {
            $available += (int) $movement->available_delta;
            $quarantined += (int) $movement->quarantined_delta;
            if ($available !== (int) $movement->available_balance_after || $quarantined !== (int) $movement->quarantined_balance_after) {
                return false;
            }
        }

        return $available === (int) $lot->available_quantity && $quarantined === (int) $lot->quarantined_quantity;
    }

    /** @return array<string, mixed> */
    private function medicine(PharmacyMedicine $m): array
    {
        return ['public_id' => $m->public_id, 'code' => $m->medicine_code, 'generic_display_name' => $m->generic_name, 'brand_display_name' => $m->brand_name, 'strength_text' => $m->strength_text, 'dosage_form' => $m->dosage_form, 'base_issue_unit' => $m->base_unit, 'route_choices' => $m->route_choices, 'state' => $m->state, 'version' => $m->version];
    }

    /** @return array<string, mixed> */
    private function depot(PharmacyDepot $d): array
    {
        return ['public_id' => $d->public_id, 'code' => $d->depot_code, 'display_name' => $d->display_name, 'eligible_care_settings' => $d->eligible_care_settings, 'state' => $d->state, 'version' => $d->version];
    }

    /** @return array<string, mixed> */
    private function encounterSummary(Encounter $e): array
    {
        return ['public_id' => $e->public_id, 'care_setting' => $e->care_setting, 'status' => $e->status, 'location_label' => $e->care_setting === Encounter::CARE_SETTING_INPATIENT ? trim(implode(' · ', array_filter([$e->ward_name, $e->bed_code]))) : ($e->clinic_name ?: $e->care_setting), 'registered_at' => $e->registered_at->toIso8601String(), 'patient' => ['public_id' => $e->patient?->public_id, 'medical_record_number' => $e->patient?->medical_record_number, 'full_name' => $e->patient?->full_name, 'date_of_birth' => $e->patient?->date_of_birth?->toDateString(), 'sex' => $e->patient?->sex]];
    }

    /** @return array<string, bool> */
    private function workflowPermissions(User $a): array
    {
        return ['can_verify' => $this->policy->can($a, RoleCapabilityMatrix::ROLE_PHARMACIST, Capability::PHARMACY_PRESCRIPTION_VERIFY), 'can_prepare' => $this->policy->can($a, RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN, Capability::PHARMACY_DISPENSE_PREPARE), 'can_handover' => $this->policy->can($a, RoleCapabilityMatrix::ROLE_PHARMACIST, Capability::PHARMACY_DISPENSE_HANDOVER), 'can_return' => $this->policy->can($a, RoleCapabilityMatrix::ROLE_PHARMACIST, Capability::PHARMACY_RETURN_RECORD)];
    }

    private function canView(User $a): bool
    {
        return ! $a->is_system_administrator && count($a->roleSlugs()) === 1 && $a->canCapability(Capability::PHARMACY_PRESCRIPTION_VIEW);
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['encounter.patient', 'depot', 'orderingPhysician', 'items', 'verification.pharmacist', 'versions.actor', 'preparations.technician', 'preparations.allocations.lot', 'preparations.allocations.item', 'preparations.handover', 'handovers.pharmacist', 'handovers.items.lot', 'handovers.items.prescriptionItem', 'handovers.items.returnItems', 'handovers.returns.pharmacist', 'handovers.returns.items.handoverItem.lot', 'handovers.returns.items.handoverItem.prescriptionItem'];
    }

    /** @return list<string> */
    private function states(): array
    {
        return [PharmacyPrescription::DRAFT, PharmacyPrescription::ORDERED, PharmacyPrescription::VERIFIED, PharmacyPrescription::REFUSED, PharmacyPrescription::PREPARED, PharmacyPrescription::PARTIALLY_HANDED_OVER, PharmacyPrescription::HANDED_OVER, PharmacyPrescription::UNFILLED_CLOSED, PharmacyPrescription::CANCELLED];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<array{value:string,label:string}>
     */
    private function options(array $values): array
    {
        return array_values(array_map(function (mixed $value): array {
            $normalized = (string) $value;

            return ['value' => $normalized, 'label' => str_replace('_', ' ', mb_convert_case($normalized, MB_CASE_TITLE))];
        }, $values));
    }

    /** @param array<string, mixed> $parameters */
    private function url(string $name, array $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters) : null;
    }
}
