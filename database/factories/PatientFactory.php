<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'medical_record_number' => 'RM-'.Str::upper(Str::random(8)),
            'full_name' => fake()->name(),
            'date_of_birth' => fake()->dateTimeBetween('-80 years', '-1 year')->format('Y-m-d'),
            'sex' => fake()->randomElement(Patient::SEX_VALUES),
            'phone' => null,
            'is_synthetic' => true,
            'created_by_user_id' => User::factory(),
        ];
    }
}
