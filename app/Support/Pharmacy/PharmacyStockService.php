<?php

namespace App\Support\Pharmacy;

use App\Models\PharmacyDepot;
use App\Models\PharmacyInventoryMutex;
use App\Models\PharmacyMedicine;
use App\Models\PharmacyOperationReceipt;
use App\Models\PharmacyStockLot;
use App\Models\PharmacyStockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;

final class PharmacyStockService
{
    public function __construct(private readonly PharmacyActorPolicy $policy, private readonly PharmacyOperationCoordinator $operations) {}

    /** @param array<string, mixed> $data */
    public function openLot(User $actor, string $medicinePublicId, string $depotPublicId, array $data, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_STOCK_OPEN', null, fn () => $this->policy->inventory($actor));
        $data = $this->operations->validate($actor, 'PHARMACY_STOCK_OPEN', null, fn () => $this->lotData($data));

        return $this->operations->execute($actor, 'PHARMACY_STOCK_OPEN', null, $key, compact('medicinePublicId', 'depotPublicId', 'data'), PharmacyOperationReceipt::RESULT_LOT, function () use ($actor, $medicinePublicId, $depotPublicId, $data): PharmacyStockLot {
            $depot = PharmacyDepot::query()->where('public_id', $depotPublicId)->where('state', PharmacyDepot::ACTIVE)->firstOrFail();
            $medicine = PharmacyMedicine::query()->where('public_id', $medicinePublicId)->where('state', PharmacyMedicine::ACTIVE)->firstOrFail();
            PharmacyInventoryMutex::query()->firstOrCreate(['depot_id' => $depot->id, 'medicine_id' => $medicine->id], ['lock_version' => 0]);
            PharmacyInventoryMutex::query()->where('depot_id', $depot->id)->where('medicine_id', $medicine->id)->lockForUpdate()->firstOrFail();
            $depot = PharmacyDepot::query()->whereKey($depot->id)->lockForUpdate()->firstOrFail();
            $medicine = PharmacyMedicine::query()->whereKey($medicine->id)->lockForUpdate()->firstOrFail();
            if ($depot->state !== PharmacyDepot::ACTIVE || $medicine->state !== PharmacyMedicine::ACTIVE) {
                throw new PharmacyDenied('pharmacy_master_changed', 'Master obat atau depo berubah sebelum lot dibuka.');
            }
            if (PharmacyStockLot::query()->where('depot_id', $depot->id)->where('medicine_id', $medicine->id)->where('lot_code', $data['lot_code'])->exists()) {
                throw new PharmacyDenied('lot_code_conflict', 'Lot sudah tercatat pada depo ini.');
            }
            $content = $this->lotDigest($medicine, $depot, $data, (int) $data['opening_quantity'], 0, PharmacyStockLot::ACTIVE, 1);
            $lot = PharmacyStockLot::query()->create(['medicine_id' => $medicine->id, 'depot_id' => $depot->id, 'opened_by_user_id' => $actor->id, 'medicine_version' => $medicine->version, 'depot_version' => $depot->version, 'medicine_code_snapshot' => $medicine->medicine_code, 'depot_code_snapshot' => $depot->depot_code, 'lot_code' => $data['lot_code'], 'received_at' => $data['received_at'], 'expiry_date' => $data['expiry_date'], 'no_expiry_reason' => $data['no_expiry_reason'], 'available_quantity' => $data['opening_quantity'], 'quarantined_quantity' => 0, 'acquisition_value' => $medicine->acquisition_value, 'source_reference' => $data['source_reference'], 'state' => PharmacyStockLot::ACTIVE, 'version' => 1, 'content_digest' => $content]);
            $this->movement($lot, $actor, PharmacyStockMovement::OPENING, (int) $data['opening_quantity'], 0, 'SYNTHETIC_OPENING', 'LOT', $lot->public_id);

            return $lot;
        });
    }

    public function correctLot(string $lotPublicId, User $actor, int $availableDelta, int $quarantinedDelta, string $reasonCode, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_STOCK_CORRECT', $lotPublicId, fn () => $this->policy->inventory($actor));
        [$reasonCode] = $this->operations->validate($actor, 'PHARMACY_STOCK_CORRECT', $lotPublicId, function () use ($reasonCode, $availableDelta, $quarantinedDelta): array {
            $reason = $this->reason($reasonCode);
            if (($availableDelta === 0 && $quarantinedDelta === 0) || abs($availableDelta) > 100000000 || abs($quarantinedDelta) > 100000000) {
                throw new PharmacyDenied('validation_failed', 'Koreksi stok tidak valid.');
            }

            return [$reason];
        });

        return $this->operations->execute($actor, 'PHARMACY_STOCK_CORRECT', $lotPublicId, $key, compact('lotPublicId', 'availableDelta', 'quarantinedDelta', 'reasonCode'), PharmacyOperationReceipt::RESULT_LOT, function () use ($lotPublicId, $actor, $availableDelta, $quarantinedDelta, $reasonCode): PharmacyStockLot {
            $candidate = PharmacyStockLot::query()->where('public_id', $lotPublicId)->firstOrFail();
            $this->lockMutex($candidate);
            $lot = PharmacyStockLot::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($lot->state === PharmacyStockLot::RETIRED) {
                throw new PharmacyDenied('lot_retired', 'Lot pensiun bersifat terminal.');
            }
            if ($lot->state === PharmacyStockLot::QUARANTINED && $availableDelta > 0) {
                throw new PharmacyDenied('lot_quarantined', 'Stok tersedia tidak dapat ditambah pada lot karantina.');
            }
            $available = $lot->available_quantity + $availableDelta;
            $quarantined = $lot->quarantined_quantity + $quarantinedDelta;
            if ($available < 0 || $quarantined < 0) {
                throw new PharmacyDenied('negative_stock', 'Saldo lot tidak boleh negatif.');
            }
            $lot->available_quantity = $available;
            $lot->quarantined_quantity = $quarantined;
            $lot->version++;
            $lot->content_digest = $this->digestLot($lot);
            $lot->save();
            $this->movement($lot, $actor, PharmacyStockMovement::CORRECTION, $availableDelta, $quarantinedDelta, $reasonCode, 'LOT', $lot->public_id);

            return $lot;
        });
    }

    public function changeLotState(string $lotPublicId, User $actor, string $state, string $reasonCode, string $key): PharmacyMutationResult
    {
        $this->operations->authorize($actor, 'PHARMACY_STOCK_STATE_CHANGE', $lotPublicId, fn () => $this->policy->inventory($actor));
        [$state,$reasonCode] = $this->operations->validate($actor, 'PHARMACY_STOCK_STATE_CHANGE', $lotPublicId, function () use ($state, $reasonCode): array {
            $normalized = mb_strtoupper(trim($state));
            $reason = $this->reason($reasonCode);
            if (! in_array($normalized, [PharmacyStockLot::ACTIVE, PharmacyStockLot::QUARANTINED, PharmacyStockLot::RETIRED], true)) {
                throw new PharmacyDenied('invalid_lot_state', 'Status lot tidak valid.');
            }

            return [$normalized, $reason];
        });

        return $this->operations->execute($actor, 'PHARMACY_STOCK_STATE_CHANGE', $lotPublicId, $key, compact('lotPublicId', 'state', 'reasonCode'), PharmacyOperationReceipt::RESULT_LOT, function () use ($lotPublicId, $actor, $state, $reasonCode): PharmacyStockLot {
            $candidate = PharmacyStockLot::query()->where('public_id', $lotPublicId)->firstOrFail();
            $this->lockMutex($candidate);
            $lot = PharmacyStockLot::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($lot->state === $state) {
                throw new PharmacyDenied('stale_version', 'Status lot sudah sama.');
            }
            if ($lot->state === PharmacyStockLot::RETIRED) {
                throw new PharmacyDenied('lot_retired', 'Lot pensiun bersifat terminal.');
            }
            $ad = 0;
            $qd = 0;
            if ($state === PharmacyStockLot::QUARANTINED) {
                $ad = -$lot->available_quantity;
                $qd = $lot->available_quantity;
            } elseif ($state === PharmacyStockLot::ACTIVE) {
                $ad = $lot->quarantined_quantity;
                $qd = -$lot->quarantined_quantity;
            } elseif ($lot->available_quantity !== 0 || $lot->quarantined_quantity !== 0) {
                throw new PharmacyDenied('lot_not_empty', 'Lot berisi tidak dapat dipensiunkan.');
            }
            $lot->available_quantity += $ad;
            $lot->quarantined_quantity += $qd;
            $lot->state = $state;
            $lot->version++;
            $lot->content_digest = $this->digestLot($lot);
            $lot->save();
            $this->movement($lot, $actor, PharmacyStockMovement::QUARANTINE, $ad, $qd, $reasonCode, 'LOT', $lot->public_id);

            return $lot;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{lot_code:string,received_at:Carbon,expiry_date:?string,no_expiry_reason:?string,opening_quantity:int,source_reference:string}
     */
    private function lotData(array $data): array
    {
        try {
            $lot = mb_strtoupper(trim((string) ($data['lot_code'] ?? '')));
            $received = Carbon::parse((string) ($data['received_at'] ?? now()));
            $expiry = isset($data['expiry_date']) && $data['expiry_date'] !== '' ? Carbon::parse((string) $data['expiry_date'])->toDateString() : null;
        } catch (\Throwable) {
            throw new PharmacyDenied('validation_failed', 'Tanggal lot tidak valid.');
        }$reason = $expiry === null ? mb_strtoupper(trim((string) ($data['no_expiry_reason'] ?? ''))) : null;
        $quantity = (int) ($data['opening_quantity'] ?? 0);
        $source = trim((string) ($data['source_reference'] ?? ''));
        if (! preg_match('/\A[A-Z0-9][A-Z0-9._\/-]{1,79}\z/', $lot) || $quantity < 1 || $source === '' || mb_strlen($source) > 120 || ($expiry === null && $reason !== 'NO_EXPIRY_ASSIGNED')) {
            throw new PharmacyDenied('validation_failed', 'Data lot tidak valid.');
        }

        return ['lot_code' => $lot, 'received_at' => $received, 'expiry_date' => $expiry, 'no_expiry_reason' => $reason, 'opening_quantity' => $quantity, 'source_reference' => $source];
    }

    private function reason(string $reason): string
    {
        $reason = preg_replace('/\s+/u', '_', mb_strtoupper(trim($reason))) ?? '';
        if (! preg_match('/\A[A-Z0-9][A-Z0-9._-]{2,63}\z/', $reason)) {
            throw new PharmacyDenied('validation_failed', 'Alasan tidak valid.');
        }

        return $reason;
    }

    private function lockMutex(PharmacyStockLot $lot): void
    {
        PharmacyInventoryMutex::query()->where('depot_id', $lot->depot_id)->where('medicine_id', $lot->medicine_id)->lockForUpdate()->firstOrFail();
    }

    private function movement(PharmacyStockLot $lot, User $actor, string $type, int $availableDelta, int $quarantinedDelta, string $reason, string $sourceType, string $sourcePublicId, ?int $handoverItemId = null): PharmacyStockMovement
    {
        $payload = [$lot->id, $actor->id, $handoverItemId, $type, $availableDelta, $quarantinedDelta, $lot->available_quantity, $lot->quarantined_quantity, $reason, $sourceType, $sourcePublicId];

        return PharmacyStockMovement::query()->create(['stock_lot_id' => $lot->id, 'actor_user_id' => $actor->id, 'handover_item_id' => $handoverItemId, 'movement_type' => $type, 'available_delta' => $availableDelta, 'quarantined_delta' => $quarantinedDelta, 'available_balance_after' => $lot->available_quantity, 'quarantined_balance_after' => $lot->quarantined_quantity, 'reason_code' => $reason, 'source_type' => $sourceType, 'source_public_id' => $sourcePublicId, 'content_digest' => PharmacyCanonicalJson::digest($payload), 'occurred_at' => now(), 'created_at' => now()]);
    }

    /** @param array{lot_code:string,received_at:Carbon,expiry_date:?string,no_expiry_reason:?string,opening_quantity:int,source_reference:string} $data */
    private function lotDigest(PharmacyMedicine $m, PharmacyDepot $d, array $data, int $available, int $quarantined, string $state, int $version): string
    {
        return PharmacyCanonicalJson::digest([$m->public_id, $m->version, $d->public_id, $d->version, $data, $available, $quarantined, $state, $version]);
    }

    private function digestLot(PharmacyStockLot $lot): string
    {
        return PharmacyCanonicalJson::digest([$lot->medicine_id, $lot->depot_id, $lot->medicine_version, $lot->depot_version, $lot->lot_code, $lot->received_at, $lot->expiry_date, $lot->no_expiry_reason, $lot->available_quantity, $lot->quarantined_quantity, $lot->state, $lot->version]);
    }
}
