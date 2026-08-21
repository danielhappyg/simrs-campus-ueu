<?php

namespace Database\Factories;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalEntry>
 */
class ClinicalEntryFactory extends Factory
{
    protected $model = ClinicalEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'encounter_id' => Encounter::factory(),
            'author_user_id' => User::factory(),
            'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
            'body' => fake()->paragraph(),
        ];
    }
}
