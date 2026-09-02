<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RbacSeeder::class);
        $this->call(OutpatientMastersSeeder::class);

        if (! config('simulation.demo_seed_enabled')) {
            return;
        }

        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Demo fixtures require SIMULATION mode with synthetic-only data enforced.');
        }

        $password = config('simulation.demo_account_password');

        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('DEMO_ACCOUNT_PASSWORD must be set to at least 12 characters when seeding.');
        }

        $this->call(DemoActorsSeeder::class);
        $this->call(EmergencyTriageVocabularySeeder::class);
        $this->call(InpatientMastersSeeder::class);
        $this->call(RadiologyMastersSeeder::class);
        $this->call(LaboratoryMastersSeeder::class);
        $this->call(PharmacyMastersSeeder::class);
        $this->call(PharmacyStockSeeder::class);
        $this->call(TeachingCensusSeeder::class);
    }
}
