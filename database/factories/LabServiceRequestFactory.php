<?php

namespace Database\Factories;

use App\Models\Encounter;
use App\Models\LabServiceRequest;
use App\Models\User;
use App\Support\Clinical\LabTestCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabServiceRequest>
 */
class LabServiceRequestFactory extends Factory
{
    protected $model = LabServiceRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $test = fake()->randomElement(LabTestCatalog::all());

        return [
            'encounter_id' => Encounter::factory(),
            'requested_by_user_id' => User::factory(),
            'test_code' => $test['code'],
            'test_label' => $test['label'],
            'clinical_question' => fake()->optional()->sentence(),
            'status' => LabServiceRequest::STATUS_ACTIVE,
            'requested_at' => now(),
        ];
    }
}
