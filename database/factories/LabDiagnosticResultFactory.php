<?php

namespace Database\Factories;

use App\Models\LabDiagnosticResult;
use App\Models\LabServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabDiagnosticResult>
 */
class LabDiagnosticResultFactory extends Factory
{
    protected $model = LabDiagnosticResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lab_service_request_id' => LabServiceRequest::factory(),
            'entered_by_user_id' => User::factory(),
            'status' => LabDiagnosticResult::STATUS_FINAL,
            'result_text' => fake()->paragraph(),
            'issued_at' => now(),
        ];
    }
}
