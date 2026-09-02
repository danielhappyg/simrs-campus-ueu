<?php

namespace App\Support\Pharmacy;

use App\Models\Encounter;
use App\Models\PharmacyDepot;
use App\Models\PharmacyDepotCodeReservation;
use App\Models\PharmacyDepotVersion;
use App\Models\PharmacyInventoryMutex;
use App\Models\PharmacyMedicine;
use App\Models\PharmacyMedicineCodeReservation;
use App\Models\PharmacyMedicineVersion;
use App\Models\PharmacyOperationReceipt;
use App\Models\User;

final class PharmacyMasterService
{
    public function __construct(private readonly PharmacyActorPolicy $policy, private readonly PharmacyOperationCoordinator $operations) {}

    /** @param array<string, mixed> $data */
    public function createMedicine(User $actor, array $data, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_MEDICINE_CREATE', null, fn () => $this->policy->inventory($actor));
        $data = $this->operations->validate($actor, 'PHARMACY_MEDICINE_CREATE', null, fn () => $this->medicineData($data));

        return $this->operations->execute($actor, 'PHARMACY_MEDICINE_CREATE', null, $key, $data, PharmacyOperationReceipt::RESULT_MEDICINE, function () use ($actor, $data): PharmacyMedicine {
            if (PharmacyMedicine::query()->where('medicine_code', $data['medicine_code'])->exists()) {
                throw new PharmacyDenied('master_code_conflict', 'Kode obat sudah digunakan.');
            }
            PharmacyMedicineCodeReservation::query()->create(['actor_user_id' => $actor->id, 'normalized_code' => $data['medicine_code'], 'created_at' => now()]);
            $digest = PharmacyCanonicalJson::digest($data);
            $medicine = PharmacyMedicine::query()->create([...$data, 'state' => PharmacyMedicine::ACTIVE, 'version' => 1, 'current_content_digest' => $digest]);
            $this->medicineVersion($medicine, $actor);

            return $medicine;
        });
    }

    /** @param array<string, mixed> $data */
    public function reviseMedicine(string $publicId, User $actor, int $expectedVersion, array $data, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_MEDICINE_REVISE', $publicId, fn () => $this->policy->inventory($actor));
        $data = $this->operations->validate($actor, 'PHARMACY_MEDICINE_REVISE', $publicId, fn () => $this->medicineData($data, true));

        return $this->operations->execute($actor, 'PHARMACY_MEDICINE_REVISE', $publicId, $key, compact('publicId', 'expectedVersion', 'data'), PharmacyOperationReceipt::RESULT_MEDICINE, function () use ($publicId, $actor, $expectedVersion, $data): PharmacyMedicine {
            $medicine = $this->lockMedicineHead($publicId);
            if ($medicine->version !== $expectedVersion) {
                throw new PharmacyDenied('stale_version', 'Versi obat sudah berubah.');
            }
            if ($medicine->state === PharmacyMedicine::RETIRED) {
                throw new PharmacyDenied('master_retired', 'Master obat pensiun bersifat terminal.');
            }
            $state = $data['state'] ?? PharmacyMedicine::ACTIVE;
            unset($data['medicine_code'],$data['state']);
            $digest = PharmacyCanonicalJson::digest([...$data, 'state' => $state]);
            $medicine->fill([...$data, 'state' => $state, 'version' => $medicine->version + 1, 'current_content_digest' => $digest])->save();
            $this->medicineVersion($medicine, $actor);

            return $medicine;
        });
    }

    /** @param array<string, mixed> $data */
    public function createDepot(User $actor, array $data, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_DEPOT_CREATE', null, fn () => $this->policy->inventory($actor));
        $data = $this->operations->validate($actor, 'PHARMACY_DEPOT_CREATE', null, fn () => $this->depotData($data));

        return $this->operations->execute($actor, 'PHARMACY_DEPOT_CREATE', null, $key, $data, PharmacyOperationReceipt::RESULT_DEPOT, function () use ($actor, $data): PharmacyDepot {
            if (PharmacyDepot::query()->where('depot_code', $data['depot_code'])->exists()) {
                throw new PharmacyDenied('master_code_conflict', 'Kode depo sudah digunakan.');
            }
            PharmacyDepotCodeReservation::query()->create(['actor_user_id' => $actor->id, 'normalized_code' => $data['depot_code'], 'created_at' => now()]);
            $digest = PharmacyCanonicalJson::digest($data);
            $depot = PharmacyDepot::query()->create([...$data, 'state' => PharmacyDepot::ACTIVE, 'version' => 1, 'current_content_digest' => $digest]);
            $this->depotVersion($depot, $actor);

            return $depot;
        });
    }

    /** @param array<string, mixed> $data */
    public function reviseDepot(string $publicId, User $actor, int $expectedVersion, array $data, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_DEPOT_REVISE', $publicId, fn () => $this->policy->inventory($actor));
        $data = $this->operations->validate($actor, 'PHARMACY_DEPOT_REVISE', $publicId, fn () => $this->depotData($data, true));

        return $this->operations->execute($actor, 'PHARMACY_DEPOT_REVISE', $publicId, $key, compact('publicId', 'expectedVersion', 'data'), PharmacyOperationReceipt::RESULT_DEPOT, function () use ($publicId, $actor, $expectedVersion, $data): PharmacyDepot {
            $depot = $this->lockDepotHead($publicId);
            if ($depot->version !== $expectedVersion) {
                throw new PharmacyDenied('stale_version', 'Versi depo sudah berubah.');
            }
            if ($depot->state === PharmacyDepot::RETIRED) {
                throw new PharmacyDenied('master_retired', 'Depo pensiun bersifat terminal.');
            }
            $state = $data['state'] ?? PharmacyDepot::ACTIVE;
            unset($data['depot_code'],$data['state']);
            $digest = PharmacyCanonicalJson::digest([...$data, 'state' => $state]);
            $depot->fill([...$data, 'state' => $state, 'version' => $depot->version + 1, 'current_content_digest' => $digest])->save();
            $this->depotVersion($depot, $actor);

            return $depot;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{medicine_code:string,generic_name:string,brand_name:?string,strength_text:string,dosage_form:string,base_unit:string,route_choices:list<string>,acquisition_value:int,teaching_sale_value:int,state?:string}
     */
    private function medicineData(array $data, bool $revision = false): array
    {
        $routes = array_values(array_unique(array_map(fn ($v) => mb_strtoupper(trim((string) $v)), (array) ($data['route_choices'] ?? []))));
        sort($routes);
        $normalized = ['medicine_code' => PharmacyMedicine::normalizeCode((string) ($data['medicine_code'] ?? 'KEEP')), 'generic_name' => trim((string) ($data['generic_name'] ?? '')), 'brand_name' => $this->optional($data['brand_name'] ?? null, 160), 'strength_text' => trim((string) ($data['strength_text'] ?? '')), 'dosage_form' => mb_strtoupper(trim((string) ($data['dosage_form'] ?? ''))), 'base_unit' => mb_strtoupper(trim((string) ($data['base_unit'] ?? ''))), 'route_choices' => $routes, 'acquisition_value' => (int) ($data['acquisition_value'] ?? -1), 'teaching_sale_value' => (int) ($data['teaching_sale_value'] ?? -1)];
        if (! $revision && ! preg_match('/\A[A-Z0-9][A-Z0-9._-]{1,63}\z/', $normalized['medicine_code'])) {
            throw new PharmacyDenied('validation_failed', 'Kode obat tidak valid.');
        }
        if ($normalized['generic_name'] === '' || mb_strlen($normalized['generic_name']) > 160 || $normalized['strength_text'] === '' || $normalized['dosage_form'] === '' || $normalized['base_unit'] === '' || $routes === [] || $normalized['acquisition_value'] < 0 || $normalized['teaching_sale_value'] < 0) {
            throw new PharmacyDenied('validation_failed', 'Data master obat tidak valid.');
        }
        if ($revision) {
            $state = mb_strtoupper(trim((string) ($data['state'] ?? PharmacyMedicine::ACTIVE)));
            if (! in_array($state, [PharmacyMedicine::ACTIVE, PharmacyMedicine::RETIRED], true)) {
                throw new PharmacyDenied('invalid_master_state', 'Status obat tidak valid.');
            }$normalized['state'] = $state;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{depot_code:string,display_name:string,eligible_care_settings:list<string>,state?:string}
     */
    private function depotData(array $data, bool $revision = false): array
    {
        $settings = array_values(array_unique(array_map(fn ($v) => mb_strtoupper(trim((string) $v)), (array) ($data['eligible_care_settings'] ?? []))));
        sort($settings);
        $normalized = ['depot_code' => PharmacyDepot::normalizeCode((string) ($data['depot_code'] ?? 'KEEP')), 'display_name' => trim((string) ($data['display_name'] ?? '')), 'eligible_care_settings' => $settings];
        if (! $revision && ! preg_match('/\A[A-Z0-9][A-Z0-9._-]{1,63}\z/', $normalized['depot_code'])) {
            throw new PharmacyDenied('validation_failed', 'Kode depo tidak valid.');
        }
        if ($normalized['display_name'] === '' || mb_strlen($normalized['display_name']) > 160 || $settings === [] || array_diff($settings, Encounter::CARE_SETTINGS)) {
            throw new PharmacyDenied('validation_failed', 'Data depo tidak valid.');
        }
        if ($revision) {
            $state = mb_strtoupper(trim((string) ($data['state'] ?? PharmacyDepot::ACTIVE)));
            if (! in_array($state, [PharmacyDepot::ACTIVE, PharmacyDepot::RETIRED], true)) {
                throw new PharmacyDenied('invalid_master_state', 'Status depo tidak valid.');
            }$normalized['state'] = $state;
        }

        return $normalized;
    }

    private function lockMedicineHead(string $publicId): PharmacyMedicine
    {
        $candidate = PharmacyMedicine::query()->where('public_id', $publicId)->firstOrFail();
        $depots = PharmacyDepot::query()->orderBy('depot_code')->get(['id', 'depot_code']);
        foreach ($depots as $depot) {
            PharmacyInventoryMutex::query()->firstOrCreate(['depot_id' => $depot->id, 'medicine_id' => $candidate->id], ['lock_version' => 0]);
        }
        foreach ($depots as $depot) {
            PharmacyInventoryMutex::query()->where('depot_id', $depot->id)->where('medicine_id', $candidate->id)->lockForUpdate()->firstOrFail();
        }

        return PharmacyMedicine::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
    }

    private function lockDepotHead(string $publicId): PharmacyDepot
    {
        $candidate = PharmacyDepot::query()->where('public_id', $publicId)->firstOrFail();
        $medicines = PharmacyMedicine::query()->orderBy('medicine_code')->get(['id', 'medicine_code']);
        foreach ($medicines as $medicine) {
            PharmacyInventoryMutex::query()->firstOrCreate(['depot_id' => $candidate->id, 'medicine_id' => $medicine->id], ['lock_version' => 0]);
        }
        foreach ($medicines as $medicine) {
            PharmacyInventoryMutex::query()->where('depot_id', $candidate->id)->where('medicine_id', $medicine->id)->lockForUpdate()->firstOrFail();
        }

        return PharmacyDepot::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
    }

    private function medicineVersion(PharmacyMedicine $m, User $actor): void
    {
        PharmacyMedicineVersion::query()->create(['medicine_id' => $m->id, 'actor_user_id' => $actor->id, 'version' => $m->version, 'generic_name' => $m->generic_name, 'brand_name' => $m->brand_name, 'strength_text' => $m->strength_text, 'dosage_form' => $m->dosage_form, 'base_unit' => $m->base_unit, 'route_choices' => $m->route_choices, 'acquisition_value' => $m->acquisition_value, 'teaching_sale_value' => $m->teaching_sale_value, 'state' => $m->state, 'content_digest' => $m->current_content_digest, 'created_at' => now()]);
    }

    private function depotVersion(PharmacyDepot $d, User $actor): void
    {
        PharmacyDepotVersion::query()->create(['depot_id' => $d->id, 'actor_user_id' => $actor->id, 'version' => $d->version, 'display_name' => $d->display_name, 'eligible_care_settings' => $d->eligible_care_settings, 'state' => $d->state, 'content_digest' => $d->current_content_digest, 'created_at' => now()]);
    }

    private function optional(mixed $value, int $max): ?string
    {
        $v = $value === null ? null : trim((string) $value);
        if ($v !== null && mb_strlen($v) > $max) {
            throw new PharmacyDenied('validation_failed', 'Teks terlalu panjang.');
        }

        return $v === '' ? null : $v;
    }
}
