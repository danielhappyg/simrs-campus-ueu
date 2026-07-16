<?php

namespace App\Modules\Clinical\Support;

use App\Modules\Clinical\Enums\PharmacyReviewItemOutcome;
use App\Modules\Clinical\Enums\PharmacyReviewOutcome;
use DomainException;

final class PharmacyReviewDefinition
{
    /** @return array<string, list<array{code: string, label: string}>> */
    public static function criteria(): array
    {
        return [
            'administrative' => [
                ['code' => 'PATIENT_IDENTITY', 'label' => 'Identitas pasien dan encounter tersedia'],
                ['code' => 'PRESCRIBER_AND_DATE', 'label' => 'Peminta/resep dan waktu penulisan tersedia'],
                ['code' => 'CLINIC_CONTEXT', 'label' => 'Unit/klinik dan konteks layanan tersedia'],
            ],
            'pharmaceutical' => [
                ['code' => 'MEDICINE_FORM_STRENGTH', 'label' => 'Nama obat, bentuk, dan kekuatan ditelaah'],
                ['code' => 'DOSE_DIRECTIONS_QUANTITY', 'label' => 'Dosis, petunjuk, durasi, dan jumlah ditelaah'],
                ['code' => 'PREPARATION_STABILITY', 'label' => 'Kebutuhan penyiapan/stabilitas skenario ditelaah'],
            ],
            'clinical' => [
                ['code' => 'INDICATION_AND_DOSE', 'label' => 'Indikasi dan dosis dinilai oleh penelaah'],
                ['code' => 'DUPLICATION', 'label' => 'Duplikasi terapi dinilai oleh penelaah'],
                ['code' => 'ALLERGY_ADVERSE_REACTION', 'label' => 'Alergi/reaksi tidak diinginkan dinilai oleh penelaah'],
                ['code' => 'CONTRAINDICATION_INTERACTION', 'label' => 'Kontraindikasi/interaksi dinilai oleh penelaah'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<array{criterionCode: string, outcome: string, comment: string|null}>>
     */
    public static function normalizeAndValidate(array $payload, PharmacyReviewOutcome $overallOutcome): array
    {
        $rawDomains = $payload['domain_results'] ?? null;

        if (! is_array($rawDomains)) {
            throw new DomainException('All three pharmacy-review domains must be completed by the reviewer.');
        }

        $normalized = [];
        $findingCount = 0;

        foreach (self::criteria() as $domain => $criteria) {
            $rawItems = $rawDomains[$domain] ?? null;

            if (! is_array($rawItems)) {
                throw new DomainException("The {$domain} pharmacy-review domain is incomplete.");
            }

            $expectedCodes = array_column($criteria, 'code');
            $seenCodes = [];
            $normalized[$domain] = [];

            foreach ($rawItems as $rawItem) {
                if (! is_array($rawItem)) {
                    throw new DomainException("The {$domain} pharmacy-review domain contains an invalid row.");
                }

                $code = trim((string) ($rawItem['criterion_code'] ?? ''));
                $outcome = PharmacyReviewItemOutcome::tryFrom((string) ($rawItem['outcome'] ?? ''));
                $comment = self::nullableText($rawItem['comment'] ?? null);

                if (! in_array($code, $expectedCodes, true)
                    || in_array($code, $seenCodes, true)
                    || ! $outcome) {
                    throw new DomainException("The {$domain} pharmacy-review rows must match the versioned criteria exactly once.");
                }

                if (in_array($outcome, [PharmacyReviewItemOutcome::Finding, PharmacyReviewItemOutcome::NotApplicable], true)
                    && $comment === null) {
                    throw new DomainException('Every finding or not-applicable decision requires a reviewer-authored comment.');
                }

                if ($outcome === PharmacyReviewItemOutcome::Finding) {
                    $findingCount++;
                }

                $seenCodes[] = $code;
                $normalized[$domain][] = [
                    'criterionCode' => $code,
                    'outcome' => $outcome->value,
                    'comment' => $comment,
                ];
            }

            if (count($seenCodes) !== count($expectedCodes)
                || array_diff($expectedCodes, $seenCodes) !== []) {
                throw new DomainException("The {$domain} pharmacy-review domain is incomplete.");
            }
        }

        if ($overallOutcome === PharmacyReviewOutcome::Accept && $findingCount > 0) {
            throw new DomainException('A prescription with an unresolved reviewer finding cannot be accepted.');
        }

        if ($overallOutcome !== PharmacyReviewOutcome::Accept && $findingCount === 0) {
            throw new DomainException('Clarification or cancellation recommendation requires at least one reviewer-authored finding.');
        }

        return $normalized;
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
