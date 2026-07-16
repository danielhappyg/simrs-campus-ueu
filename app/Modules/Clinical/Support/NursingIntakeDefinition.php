<?php

namespace App\Modules\Clinical\Support;

final class NursingIntakeDefinition
{
    public const MAPPING_VERSION = 'OPD-NURSING-VITALS-v1';

    /**
     * @return array<string, array{label: string, code: string, display: string, unitCode: string, unitDisplay: string}>
     */
    public static function vitalDefinitions(): array
    {
        return [
            'temperature' => [
                'label' => 'Suhu tubuh',
                'code' => '8310-5',
                'display' => 'Body temperature',
                'unitCode' => 'Cel',
                'unitDisplay' => '°C',
            ],
            'heart_rate' => [
                'label' => 'Frekuensi nadi',
                'code' => '8867-4',
                'display' => 'Heart rate',
                'unitCode' => '/min',
                'unitDisplay' => 'x/menit',
            ],
            'respiratory_rate' => [
                'label' => 'Frekuensi napas',
                'code' => '9279-1',
                'display' => 'Respiratory rate',
                'unitCode' => '/min',
                'unitDisplay' => 'x/menit',
            ],
            'systolic_blood_pressure' => [
                'label' => 'Tekanan darah sistolik',
                'code' => '8480-6',
                'display' => 'Systolic blood pressure',
                'unitCode' => 'mm[Hg]',
                'unitDisplay' => 'mmHg',
            ],
            'diastolic_blood_pressure' => [
                'label' => 'Tekanan darah diastolik',
                'code' => '8462-4',
                'display' => 'Diastolic blood pressure',
                'unitCode' => 'mm[Hg]',
                'unitDisplay' => 'mmHg',
            ],
            'oxygen_saturation' => [
                'label' => 'Saturasi oksigen',
                'code' => '2708-6',
                'display' => 'Oxygen saturation in arterial blood',
                'unitCode' => '%',
                'unitDisplay' => '%',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function buildContent(array $payload): array
    {
        $vitals = [];

        foreach (self::vitalDefinitions() as $key => $definition) {
            $value = data_get($payload, "vitals.{$key}");

            $vitals[$key] = [
                'codeSystem' => 'http://loinc.org',
                'code' => $definition['code'],
                'display' => $definition['display'],
                'codeVersion' => null,
                'mappingVersion' => self::MAPPING_VERSION,
                'value' => $value === null || $value === '' ? null : (string) $value,
                'unitSystem' => 'http://unitsofmeasure.org',
                'unitCode' => $definition['unitCode'],
                'unitDisplay' => $definition['unitDisplay'],
            ];
        }

        $safetyResponses = [];
        $rawSafetyResponses = $payload['safety_responses'] ?? [];

        if (is_array($rawSafetyResponses)) {
            foreach ($rawSafetyResponses as $rawResponse) {
                if (! is_array($rawResponse)) {
                    continue;
                }

                $safetyResponses[] = [
                    'questionCode' => (string) ($rawResponse['question_code'] ?? ''),
                    'response' => (string) ($rawResponse['response'] ?? ''),
                    'note' => self::nullableText($rawResponse['note'] ?? null),
                ];
            }
        }

        return [
            'historySource' => self::nullableText($payload['history_source'] ?? null),
            'chiefComplaint' => self::nullableText($payload['chief_complaint'] ?? null),
            'onsetDuration' => self::nullableText($payload['onset_duration'] ?? null),
            'consciousness' => self::nullableText($payload['consciousness'] ?? null),
            'allergyAssessment' => [
                'state' => $payload['allergy_state'] ?? null,
                'details' => self::nullableText($payload['allergy_details'] ?? null),
            ],
            'currentMedication' => [
                'state' => $payload['current_medication_state'] ?? null,
                'details' => self::nullableText($payload['current_medication_details'] ?? null),
            ],
            'vitalObservations' => $vitals,
            'safetyScreenResponses' => $safetyResponses,
            'safetyDecision' => $payload['safety_decision'] ?? null,
            'note' => self::nullableText($payload['note'] ?? null),
            'handoffSummary' => self::nullableText($payload['handoff_summary'] ?? null),
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
}
