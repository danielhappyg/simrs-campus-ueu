<?php

namespace App\Modules\Claims\Services;

use App\Modules\Claims\Contracts\EClaimGateway;
use App\Modules\Claims\Enums\EClaimAction;
use App\Modules\Claims\Models\EClaimCase;
use RuntimeException;

final class SimulatedEClaimGateway implements EClaimGateway
{
    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function exchange(EClaimAction $action, array $request, EClaimCase $case): array
    {
        if (config('eclaim.mode') !== 'SIMULATION_ONLY'
            || config('eclaim.outbound_enabled') !== false
            || config('eclaim.endpoint') !== null) {
            throw new RuntimeException('The educational E-Klaim gateway refuses any outbound configuration.');
        }

        $response = match ($action) {
            EClaimAction::CreateClaim => [
                'metadata' => ['code' => 200, 'message' => 'Ok — simulasi lokal'],
                'response' => [
                    'patient_id' => 'SIM-PAT-'.substr($case->patient->public_id, 0, 8),
                    'admission_id' => 'SIM-ADM-'.substr($case->public_id, 0, 8),
                    'hospital_admission_id' => 'SIM-HOSP-'.substr($case->public_id, -8),
                ],
            ],
            EClaimAction::StageClaimData => [
                'metadata' => ['code' => 200, 'message' => 'Data klaim sintetis tersusun'],
            ],
            EClaimAction::GroupClaim => [
                'metadata' => ['code' => 200, 'message' => 'Ok — grouper pendidikan'],
                'response' => [
                    'cbg' => [
                        'code' => 'SIM-RJ-001',
                        'description' => 'Kelompok tarif pengajaran — bukan hasil INA-CBG/IDRG',
                        'tariff' => (int) config('eclaim.simulated_tariff'),
                    ],
                    'classification' => 'EDUCATIONAL_PLACEHOLDER',
                ],
            ],
            EClaimAction::FinalizeClaim => [
                'metadata' => ['code' => 200, 'message' => 'Final simulasi tersimpan'],
            ],
            EClaimAction::SimulateSubmission => [
                'metadata' => ['code' => 200, 'message' => 'Pengiriman disimulasikan; tidak ada data yang dikirim'],
                'response' => [
                    'receipt' => 'SIM-RECEIPT-'.substr(hash('sha256', $case->public_id), 0, 12),
                    'submission' => 'NOT_SENT',
                ],
            ],
        };

        return $response + [
            'boundary' => [
                'classification' => 'SIMULASI — DATA SINTETIS',
                'transport_state' => 'NOT_SENT',
                'external_endpoint' => null,
            ],
        ];
    }
}
