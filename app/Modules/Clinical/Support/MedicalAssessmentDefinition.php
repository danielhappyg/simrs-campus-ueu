<?php

namespace App\Modules\Clinical\Support;

final class MedicalAssessmentDefinition
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function buildContent(array $payload): array
    {
        $diagnoses = [];
        $rawDiagnoses = $payload['diagnoses'] ?? [];

        if (is_array($rawDiagnoses)) {
            foreach ($rawDiagnoses as $rawDiagnosis) {
                if (! is_array($rawDiagnosis)) {
                    continue;
                }

                $authoredText = self::nullableText($rawDiagnosis['authored_text'] ?? null);

                if ($authoredText === null) {
                    continue;
                }

                $diagnoses[] = [
                    'authoredText' => $authoredText,
                    'certainty' => self::nullableText($rawDiagnosis['certainty'] ?? null),
                    'role' => self::nullableText($rawDiagnosis['role'] ?? null),
                    'onsetAt' => self::nullableText($rawDiagnosis['onset_at'] ?? null),
                ];
            }
        }

        $serviceRequests = [];
        $rawServiceRequests = $payload['service_requests'] ?? [];

        if (is_array($rawServiceRequests)) {
            foreach ($rawServiceRequests as $rawRequest) {
                if (! is_array($rawRequest)) {
                    continue;
                }

                $authoredService = self::nullableText($rawRequest['authored_service'] ?? null);

                if ($authoredService === null) {
                    continue;
                }

                $serviceRequests[] = [
                    'requestType' => self::nullableText($rawRequest['request_type'] ?? null),
                    'authoredService' => $authoredService,
                    'clinicalQuestion' => self::nullableText($rawRequest['clinical_question'] ?? null),
                    'priority' => self::nullableText($rawRequest['priority'] ?? null),
                    'sourceDiagnosisIndex' => is_int($rawRequest['source_diagnosis_index'] ?? null)
                        ? $rawRequest['source_diagnosis_index']
                        : null,
                ];
            }
        }

        $medicationRequests = [];
        $rawMedicationRequests = $payload['medication_requests'] ?? [];

        if (is_array($rawMedicationRequests)) {
            foreach ($rawMedicationRequests as $rawRequest) {
                if (! is_array($rawRequest)) {
                    continue;
                }

                $authoredMedication = self::nullableText($rawRequest['authored_medication'] ?? null);

                if ($authoredMedication === null) {
                    continue;
                }

                $medicationRequests[] = [
                    'authoredMedication' => $authoredMedication,
                    'form' => self::nullableText($rawRequest['form'] ?? null),
                    'strength' => self::nullableText($rawRequest['strength'] ?? null),
                    'doseValue' => self::nullableScalarText($rawRequest['dose_value'] ?? null),
                    'doseUnit' => self::nullableText($rawRequest['dose_unit'] ?? null),
                    'route' => self::nullableText($rawRequest['route'] ?? null),
                    'frequency' => self::nullableText($rawRequest['frequency'] ?? null),
                    'duration' => self::nullableText($rawRequest['duration'] ?? null),
                    'quantityValue' => self::nullableScalarText($rawRequest['quantity_value'] ?? null),
                    'quantityUnit' => self::nullableText($rawRequest['quantity_unit'] ?? null),
                    'directions' => self::nullableText($rawRequest['directions'] ?? null),
                    'indicationText' => self::nullableText($rawRequest['indication_text'] ?? null),
                    'sourceDiagnosisIndex' => is_int($rawRequest['source_diagnosis_index'] ?? null)
                        ? $rawRequest['source_diagnosis_index']
                        : null,
                ];
            }
        }

        return [
            'history' => [
                'source' => self::nullableText($payload['history_source'] ?? null),
                'presentIllness' => self::nullableText($payload['present_illness'] ?? null),
                'pastMedical' => self::nullableText($payload['past_medical_history'] ?? null),
                'family' => self::nullableText($payload['family_history'] ?? null),
                'social' => self::nullableText($payload['social_history'] ?? null),
            ],
            'examination' => [
                'general' => self::nullableText($payload['general_examination'] ?? null),
                'focused' => self::nullableText($payload['focused_examination'] ?? null),
            ],
            'assessmentSummary' => self::nullableText($payload['assessment_summary'] ?? null),
            'diagnoses' => $diagnoses,
            'serviceRequests' => $serviceRequests,
            'medicationRequests' => $medicationRequests,
            'plan' => [
                'carePlan' => self::nullableText($payload['care_plan'] ?? null),
                'education' => self::nullableText($payload['education'] ?? null),
                'followUp' => self::nullableText($payload['follow_up_plan'] ?? null),
                'intendedDisposition' => self::nullableText($payload['intended_disposition'] ?? null),
            ],
        ];
    }

    private static function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function nullableScalarText(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return self::nullableText($value);
    }
}
