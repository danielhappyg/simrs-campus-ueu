<?php

namespace App\Modules\Claims\Services;

use App\Modules\Claims\Enums\EClaimAction;
use App\Modules\Claims\Models\EClaimCase;
use App\Modules\Coding\Enums\CodingAssignmentStatus;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Models\CodingAssignment;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\AdministrativeSex;
use App\Modules\Patient\Enums\IdentifierType;
use DomainException;

final class EClaimPayloadFactory
{
    /** @return array<string, mixed> */
    public function snapshot(Encounter $encounter): array
    {
        $encounter->loadMissing(['patient.identifiers', 'location']);
        $assignments = CodingAssignment::query()
            ->with(['concept', 'release'])
            ->where('encounter_id', $encounter->getKey())
            ->where('status', CodingAssignmentStatus::Approved)
            ->orderBy('recorded_at')
            ->get();
        $diagnoses = $assignments->where('source_type', CodingSourceType::Diagnosis)->values();
        $procedures = $assignments->where('source_type', CodingSourceType::Procedure)->values();

        if ($diagnoses->isEmpty() || $procedures->isEmpty()) {
            throw new DomainException('A claim simulation requires approved ICD-10 diagnosis and ICD-9-CM procedure assignments.');
        }

        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber)?->value;

        if (! is_string($mrn) || $mrn === '') {
            throw new DomainException('A claim simulation requires a synthetic medical-record number.');
        }

        if ($encounter->period_start === null || $encounter->period_end === null) {
            throw new DomainException('A claim simulation requires finalized encounter start and discharge timestamps.');
        }

        if (! in_array($encounter->patient->administrative_sex, [
            AdministrativeSex::Male,
            AdministrativeSex::Female,
        ], true)) {
            throw new DomainException('A claim simulation requires an explicitly recorded administrative sex for the compatibility payload.');
        }

        $sep = 'SIM-SEP-'.strtoupper(substr($encounter->public_id, 0, 14));
        $cardNumber = '990'.$this->digits($encounter->patient->public_id, 10);
        $coderNik = str_repeat('0', 16);
        $diagnosisCodes = $diagnoses->map(fn (CodingAssignment $assignment): string => $assignment->concept->code)->all();
        $procedureCodes = $procedures->map(fn (CodingAssignment $assignment): string => $assignment->concept->code)->all();
        $tariffComponents = [
            'prosedur_non_bedah' => 50000,
            'prosedur_bedah' => 0,
            'konsultasi' => 75000,
            'tenaga_ahli' => 0,
            'keperawatan' => 0,
            'penunjang' => 0,
            'radiologi' => 0,
            'laboratorium' => 125000,
            'pelayanan_darah' => 0,
            'rehabilitasi' => 0,
            'kamar' => 0,
            'rawat_intensif' => 0,
            'obat' => 25000,
            'obat_kronis' => 0,
            'obat_kemoterapi' => 0,
            'alkes' => 0,
            'bmhp' => 0,
            'sewa_alat' => 0,
        ];

        return [
            'schemaVersion' => 'eclaim-educational-snapshot.v1',
            'boundary' => [
                'classification' => 'SIMULASI — DATA SINTETIS',
                'compatibilityProfile' => (string) config('eclaim.compatibility_profile'),
                'observedInstallationVersion' => (string) config('eclaim.observed_installation_version'),
                'transportState' => 'NOT_SENT',
                'externalEndpoint' => null,
                'certifiedGrouper' => false,
            ],
            'identifiers' => [
                'nomorKartu' => $cardNumber,
                'nomorSep' => $sep,
                'nomorRm' => $mrn,
                'coderNik' => $coderNik,
                'synthetic' => true,
            ],
            'patient' => [
                'publicId' => $encounter->patient->public_id,
                'name' => $encounter->patient->full_name,
                'birthDate' => $encounter->patient->birth_date->format('Y-m-d H:i:s'),
                'gender' => $encounter->patient->administrative_sex === AdministrativeSex::Male ? '1' : '2',
            ],
            'encounter' => [
                'publicId' => $encounter->public_id,
                'number' => $encounter->encounter_number,
                'admittedAt' => $encounter->period_start->format('Y-m-d H:i:s'),
                'dischargedAt' => $encounter->period_end->format('Y-m-d H:i:s'),
                'careType' => '2',
                'careClass' => '3',
                'entryRoute' => 'gp',
                'dischargeStatus' => '1',
                'location' => $encounter->location->name,
            ],
            'coding' => [
                'diagnoses' => $diagnoses->map(fn (CodingAssignment $assignment): array => [
                    'code' => $assignment->concept->code,
                    'display' => $assignment->concept->display,
                    'assignmentPublicId' => $assignment->public_id,
                    'contentHash' => $assignment->content_hash,
                ])->all(),
                'procedures' => $procedures->map(fn (CodingAssignment $assignment): array => [
                    'code' => $assignment->concept->code,
                    'display' => $assignment->concept->display,
                    'assignmentPublicId' => $assignment->public_id,
                    'contentHash' => $assignment->content_hash,
                ])->all(),
                'diagnosisString' => implode('#', $diagnosisCodes),
                'procedureString' => implode('#', $procedureCodes),
            ],
            'billing' => [
                'currency' => 'IDR',
                'components' => $tariffComponents,
                'hospitalTotal' => array_sum($tariffComponents),
                'educationalPlaceholder' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function request(EClaimAction $action, EClaimCase $case): array
    {
        $snapshot = $case->source_snapshot;
        $sep = (string) data_get($snapshot, 'identifiers.nomorSep');
        $tariffComponents = $this->tariffRequestComponents($snapshot);

        return match ($action) {
            EClaimAction::CreateClaim => [
                'metadata' => ['method' => $action->method()],
                'data' => [
                    'nomor_kartu' => data_get($snapshot, 'identifiers.nomorKartu'),
                    'nomor_sep' => $sep,
                    'nomor_rm' => data_get($snapshot, 'identifiers.nomorRm'),
                    'nama_pasien' => data_get($snapshot, 'patient.name'),
                    'tgl_lahir' => data_get($snapshot, 'patient.birthDate'),
                    'gender' => data_get($snapshot, 'patient.gender'),
                ],
            ],
            EClaimAction::StageClaimData => [
                'metadata' => ['method' => $action->method(), 'nomor_sep' => $sep],
                'data' => [
                    'nomor_sep' => $sep,
                    'nomor_kartu' => data_get($snapshot, 'identifiers.nomorKartu'),
                    'tgl_masuk' => data_get($snapshot, 'encounter.admittedAt'),
                    'tgl_pulang' => data_get($snapshot, 'encounter.dischargedAt'),
                    'cara_masuk' => data_get($snapshot, 'encounter.entryRoute'),
                    'jenis_rawat' => data_get($snapshot, 'encounter.careType'),
                    'kelas_rawat' => data_get($snapshot, 'encounter.careClass'),
                    'discharge_status' => data_get($snapshot, 'encounter.dischargeStatus'),
                    'diagnosa' => data_get($snapshot, 'coding.diagnosisString'),
                    'procedure' => data_get($snapshot, 'coding.procedureString'),
                    'tarif_rs' => $tariffComponents,
                    'coder_nik' => data_get($snapshot, 'identifiers.coderNik'),
                ],
            ],
            EClaimAction::GroupClaim => [
                'metadata' => ['method' => $action->method(), 'stage' => '1'],
                'data' => ['nomor_sep' => $sep],
            ],
            EClaimAction::FinalizeClaim => [
                'metadata' => ['method' => $action->method()],
                'data' => [
                    'nomor_sep' => $sep,
                    'coder_nik' => data_get($snapshot, 'identifiers.coderNik'),
                ],
            ],
            EClaimAction::SimulateSubmission => [
                'metadata' => ['method' => $action->method()],
                'data' => [
                    'nomor_sep' => $sep,
                    'simulation_only' => true,
                    'transport_state' => 'NOT_SENT',
                ],
            ],
        };
    }

    private function digits(string $seed, int $length): string
    {
        $bytes = hash('sha256', $seed, true);
        $digits = '';

        for ($index = 0; strlen($digits) < $length; $index++) {
            $digits .= (string) (ord($bytes[$index % strlen($bytes)]) % 10);
        }

        return $digits;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, string>
     */
    private function tariffRequestComponents(array $snapshot): array
    {
        $components = data_get($snapshot, 'billing.components');

        if (! is_array($components)) {
            throw new DomainException('The claim simulation billing snapshot is malformed.');
        }

        $result = [];

        foreach ($components as $name => $amount) {
            if (! is_string($name) || ! is_int($amount)) {
                throw new DomainException('The claim simulation tariff components must use named integer amounts.');
            }

            $result[$name] = (string) $amount;
        }

        return $result;
    }
}
