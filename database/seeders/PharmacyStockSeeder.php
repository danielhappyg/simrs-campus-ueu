<?php

namespace Database\Seeders;

use App\Models\PharmacyDepot;
use App\Models\PharmacyMedicine;
use App\Models\User;
use App\Support\Pharmacy\PharmacyDenied;
use App\Support\Pharmacy\PharmacyStockService;
use Illuminate\Database\Seeder;

class PharmacyStockSeeder extends Seeder
{
    /**
     * Fixed synthetic opening calendar keeps the idempotency payload stable across reseed days.
     */
    private const RECEIVED_AT = '2026-01-01 00:00:00';

    private const EXPIRY_DATE = '2027-01-01';

    public function run(): void
    {
        $actor = User::query()->where('email', PharmacyMastersSeeder::ACTOR_EMAIL)->sole();
        $service = app(PharmacyStockService::class);
        $medicines = PharmacyMedicine::query()->orderBy('medicine_code')->get();
        $depots = PharmacyDepot::query()->orderBy('depot_code')->get();
        foreach ($depots as $depot) {
            foreach ($medicines as $medicine) {
                try {
                    $service->openLot(
                        $actor,
                        $medicine->public_id,
                        $depot->public_id,
                        [
                            'lot_code' => 'LOT-'.$depot->depot_code.'-'.$medicine->medicine_code.'-01',
                            'received_at' => self::RECEIVED_AT,
                            'expiry_date' => self::EXPIRY_DATE,
                            'opening_quantity' => 100,
                            'source_reference' => 'SYNTHETIC-OPENING-2026',
                        ],
                        sprintf(
                            'seed-pharmacy-stock-%s-%s',
                            strtolower($depot->depot_code),
                            strtolower($medicine->medicine_code),
                        ),
                    );
                } catch (PharmacyDenied $denied) {
                    if (in_array($denied->reason, ['idempotency_key_conflict', 'lot_code_conflict'], true)) {
                        continue;
                    }

                    throw $denied;
                }
            }
        }
    }
}
