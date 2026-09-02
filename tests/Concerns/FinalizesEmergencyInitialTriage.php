<?php

namespace Tests\Concerns;

use App\Models\EmergencyTriageVocabulary;
use App\Models\Encounter;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Emergency\EmergencyTriageService;
use Database\Seeders\EmergencyTriageVocabularySeeder;

trait FinalizesEmergencyInitialTriage
{
    protected function seedEmergencyTriageVocabulary(): void
    {
        $this->seed(EmergencyTriageVocabularySeeder::class);
    }

    protected function finalizeEmergencyInitialTriage(Encounter $encounter, ?User $nurse = null, string $keyPrefix = 'fixture'): Encounter
    {
        $nurse ??= User::factory()->create(['status' => 'ACTIVE', 'is_system_administrator' => false]);
        if ($nurse->roleSlugs() === []) {
            $nurse->roles()->sync([Role::query()->where('slug', RoleCapabilityMatrix::ROLE_NURSE)->sole()->id]);
            $nurse = $nurse->fresh();
        }
        $vocabulary = EmergencyTriageVocabulary::query()->where('state', EmergencyTriageVocabulary::ACTIVE)->sole();
        app(EmergencyTriageService::class)->finalizeInitial(
            $encounter->public_id,
            $vocabulary->public_id,
            $vocabulary->version,
            $nurse,
            $this->emergencyInitialTriagePayload(),
            $keyPrefix.'-'.$encounter->public_id,
        );

        return $encounter->fresh();
    }

    /** @return array<string, mixed> */
    private function emergencyInitialTriagePayload(): array
    {
        return [
            'category_code' => 'HIJAU',
            'observed_at' => now()->toIso8601String(),
            'presenting_concern' => 'Keluhan untuk pemeriksaan penunjang.',
            'clinical_basis' => 'Kategori dipilih manual dari asesmen ABCDE.',
            'arrival_condition' => 'Pasien sadar dan dapat dinilai.',
            'abcde' => [
                'airway' => ['state' => 'ASSESSED_NO_CONCERN', 'note' => null],
                'breathing' => ['state' => 'ASSESSED_NO_CONCERN', 'note' => null],
                'circulation' => ['state' => 'ASSESSED_NO_CONCERN', 'note' => null],
                'disability' => ['state' => 'ASSESSED_NO_CONCERN', 'note' => null],
                'exposure' => ['state' => 'ASSESSED_NO_CONCERN', 'note' => null],
            ],
            'consciousness' => 'ALERT',
            'vitals' => [
                'respiratory_rate' => 20,
                'pulse' => 80,
                'systolic_pressure' => 120,
                'diastolic_pressure' => 80,
                'oxygen_saturation' => 98,
                'temperature' => 36.5,
                'pain_score' => 0,
                'weight' => null,
            ],
            'unobtainable_fields' => [],
            'unobtainable_reasons' => [],
            'trauma' => false,
            'isolation_precaution' => false,
            'handoff_note' => 'Triase awal selesai sebelum pesanan penunjang.',
        ];
    }
}
