<?php

namespace App\Support\Emergency;

use App\Models\EmergencyDisposition;
use App\Models\EmergencyTriageAssessment;
use App\Models\EmergencyTriageVocabulary;
use App\Models\EmergencyTriageVocabularyVersion;
use App\Models\Encounter;
use App\Models\User;
use Carbon\CarbonImmutable;

final class EmergencyTriageService
{
    private const OBSERVATION_FIELDS = ['respiratory_rate', 'pulse', 'systolic_pressure', 'diastolic_pressure', 'oxygen_saturation', 'temperature', 'pain_score'];

    private const ABCDE_KEYS = ['airway', 'breathing', 'circulation', 'disability', 'exposure'];

    public function __construct(
        private readonly EmergencyActorPolicy $policy,
        private readonly EmergencyOperationCoordinator $operations,
        private readonly EmergencyEvidenceFingerprint $fingerprints,
    ) {}

    /** @param array<string, mixed> $payload */
    public function finalizeInitial(string $encounterPublicId, string $vocabularyPublicId, int $expectedVocabularyVersion, User $actor, array $payload, string $idempotencyKey): EmergencyMutationResult
    {
        return $this->operations->perform($actor, 'EMERGENCY_TRIAGE_INITIAL_FINALIZE', $encounterPublicId, $idempotencyKey, compact('encounterPublicId', 'vocabularyPublicId', 'expectedVocabularyVersion', 'payload'), fn () => $this->policy->triage($actor), function () use ($encounterPublicId, $vocabularyPublicId, $expectedVocabularyVersion, $actor, $payload): EmergencyTriageAssessment {
            $encounter = $this->lockEncounter($encounterPublicId);
            if ($encounter->status !== Encounter::STATUS_REGISTERED || EmergencyTriageAssessment::query()->where('encounter_id', $encounter->id)->exists()) {
                throw new EmergencyDenied('initial_triage_not_permitted', 'Triase awal tidak dapat dicatat pada episode ini.');
            }
            $vocabulary = EmergencyTriageVocabulary::query()->where('public_id', $vocabularyPublicId)->lockForUpdate()->firstOrFail();
            if ($vocabulary->state !== EmergencyTriageVocabulary::ACTIVE) {
                throw new EmergencyDenied('vocabulary_not_active', 'Kosakata triase tidak aktif.');
            }
            if ($vocabulary->version !== $expectedVocabularyVersion) {
                throw new EmergencyDenied('stale_version', 'Versi kosakata triase telah berubah.');
            }
            $version = EmergencyTriageVocabularyVersion::query()->where('emergency_triage_vocabulary_id', $vocabulary->id)->where('version', $vocabulary->version)->firstOrFail();
            $this->fingerprints->vocabularyVersion($version);
            $attributes = $this->normalize($encounter, $version, $actor, $payload, EmergencyTriageAssessment::INITIAL, 1, null);
            $assessment = EmergencyTriageAssessment::query()->create($attributes);
            $differences = $this->fingerprints->assessmentRoundTripDifferences($assessment, $attributes);
            if ($differences !== []) {
                throw new \LogicException('Emergency triage persistence changed fingerprint fields: '.implode(', ', $differences));
            }
            $encounter->update(['status' => Encounter::STATUS_IN_EXAMINATION]);

            return $assessment;
        });
    }

    /** @param array<string, mixed> $payload */
    public function reassess(string $encounterPublicId, User $actor, int $expectedAssessmentNumber, array $payload, string $idempotencyKey): EmergencyMutationResult
    {
        return $this->operations->perform($actor, 'EMERGENCY_TRIAGE_REASSESS', $encounterPublicId, $idempotencyKey, compact('encounterPublicId', 'expectedAssessmentNumber', 'payload'), fn () => $this->policy->triage($actor), function () use ($encounterPublicId, $actor, $expectedAssessmentNumber, $payload): EmergencyTriageAssessment {
            $encounter = $this->lockEncounter($encounterPublicId);
            if ($encounter->status !== Encounter::STATUS_IN_EXAMINATION || EmergencyDisposition::query()->where('encounter_id', $encounter->id)->exists()) {
                throw new EmergencyDenied('reassessment_not_permitted', 'Asesmen ulang tidak dapat dicatat setelah disposisi atau di luar pemeriksaan.');
            }
            $prior = EmergencyTriageAssessment::query()->where('encounter_id', $encounter->id)->orderByDesc('assessment_number')->lockForUpdate()->firstOrFail();
            if ($prior->assessment_number !== $expectedAssessmentNumber) {
                throw new EmergencyDenied('stale_version', 'Versi triase telah berubah.');
            }
            $priorDigest = $this->fingerprints->assessment($prior);
            $version = $prior->vocabularyVersion()->firstOrFail();
            $this->fingerprints->vocabularyVersion($version);
            $attributes = $this->normalize($encounter, $version, $actor, $payload, EmergencyTriageAssessment::REASSESSMENT, $prior->assessment_number + 1, $prior, $priorDigest);

            $assessment = EmergencyTriageAssessment::query()->create($attributes);
            $differences = $this->fingerprints->assessmentRoundTripDifferences($assessment, $attributes);
            if ($differences !== []) {
                throw new \LogicException('Emergency triage persistence changed fingerprint fields: '.implode(', ', $differences));
            }

            return $assessment;
        });
    }

    private function lockEncounter(string $publicId): Encounter
    {
        $encounter = Encounter::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
        if ($encounter->care_setting !== Encounter::CARE_SETTING_EMERGENCY || ! $encounter->patient()->where('is_synthetic', true)->exists() || $encounter->cancellation()->exists() || in_array($encounter->status, Encounter::TERMINAL_STATUSES, true)) {
            throw new EmergencyDenied($encounter->isCancelled() ? 'encounter_cancelled' : 'encounter_not_eligible', 'Episode IGD tidak memenuhi syarat.');
        }

        return $encounter;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalize(Encounter $encounter, EmergencyTriageVocabularyVersion $vocabulary, User $actor, array $payload, string $type, int $number, ?EmergencyTriageAssessment $prior, ?string $priorDigest = null): array
    {
        $categoryCode = mb_strtoupper(trim((string) ($payload['category_code'] ?? '')));
        $category = collect($vocabulary->categories ?? [])->first(fn (array $item): bool => ($item['code'] ?? null) === $categoryCode);
        if (! is_array($category) || ! in_array($categoryCode, EmergencyTriageAssessment::CATEGORIES, true)) {
            throw new EmergencyDenied('validation_failed', 'Kategori triase tidak valid.');
        }
        try {
            $observed = CarbonImmutable::parse((string) ($payload['observed_at'] ?? ''));
        } catch (\Throwable) {
            throw new EmergencyDenied('validation_failed', 'Waktu observasi tidak valid.');
        }
        $recorded = CarbonImmutable::now();
        $registered = CarbonImmutable::instance($encounter->registered_at);
        if ($observed->lt($registered->subHours(24)) || $observed->gt($recorded->addMinutes(5))) {
            throw new EmergencyDenied('observed_time_out_of_range', 'Waktu observasi berada di luar rentang yang diizinkan.');
        }
        $lateReason = $this->optional($payload['late_entry_reason'] ?? null, 1000);
        if ($recorded->diffInMinutes($observed, true) > 15 && $lateReason === null) {
            throw new EmergencyDenied('late_entry_reason_required', 'Alasan entri terlambat wajib diisi.');
        }

        $abcdeInput = $payload['abcde'] ?? $payload['abcde_observations'] ?? null;
        if (! is_array($abcdeInput) || array_keys($abcdeInput) !== self::ABCDE_KEYS) {
            throw new EmergencyDenied('validation_failed', 'Observasi ABCDE wajib lengkap dan berurutan.');
        }
        $abcde = [];
        foreach (self::ABCDE_KEYS as $key) {
            $item = $abcdeInput[$key] ?? null;
            $state = is_array($item) ? mb_strtoupper(trim((string) ($item['state'] ?? ''))) : '';
            $note = is_array($item) ? $this->optional($item['note'] ?? null, 1000) : null;
            if (! in_array($state, EmergencyTriageAssessment::ABCDE_STATES, true) || ($state !== 'ASSESSED_NO_CONCERN' && $note === null)) {
                throw new EmergencyDenied('validation_failed', 'Setiap unsur ABCDE membutuhkan status dan catatan yang sesuai.');
            }
            $abcde[$key] = ['state' => $state, 'note' => $note];
        }

        $consciousness = mb_strtoupper(trim((string) ($payload['consciousness'] ?? '')));
        if (! in_array($consciousness, EmergencyTriageAssessment::CONSCIOUSNESS, true)) {
            throw new EmergencyDenied('validation_failed', 'Status kesadaran manual tidak valid.');
        }
        $vitals = is_array($payload['vitals'] ?? null) ? $payload['vitals'] : $payload;
        $values = [
            'respiratory_rate' => $this->integer($vitals['respiratory_rate'] ?? null, 0, 100),
            'pulse' => $this->integer($vitals['pulse'] ?? null, 0, 300),
            'systolic_bp' => $this->integer($vitals['systolic_pressure'] ?? $vitals['systolic_bp'] ?? null, 0, 300),
            'diastolic_bp' => $this->integer($vitals['diastolic_pressure'] ?? $vitals['diastolic_bp'] ?? null, 0, 300),
            'oxygen_saturation' => $this->integer($vitals['oxygen_saturation'] ?? null, 0, 100),
            'temperature_celsius' => $this->decimal($vitals['temperature'] ?? $vitals['temperature_celsius'] ?? null, 20.0, 45.0),
            'pain_score' => $this->integer($vitals['pain_score'] ?? null, 0, 10),
            'weight_kg' => $this->decimal($vitals['weight'] ?? $vitals['weight_kg'] ?? null, 0.1, 500.0),
        ];
        if (($values['systolic_bp'] === null) !== ($values['diastolic_bp'] === null)) {
            throw new EmergencyDenied('blood_pressure_pair_required', 'Tekanan sistolik dan diastolik harus dicatat sebagai pasangan.');
        }
        $unobtainable = array_values(array_unique(array_map(static fn ($value): string => trim((string) $value), is_array($payload['unobtainable_fields'] ?? null) ? $payload['unobtainable_fields'] : [])));
        $reasonsInput = is_array($payload['unobtainable_reasons'] ?? null) ? $payload['unobtainable_reasons'] : [];
        $sharedReason = $this->optional($payload['unobtainable_reason'] ?? null, 1000);
        $reasons = [];
        foreach (self::OBSERVATION_FIELDS as $field) {
            $valueKey = match ($field) {
                'systolic_pressure' => 'systolic_bp',
                'diastolic_pressure' => 'diastolic_bp',
                'temperature' => 'temperature_celsius',
                default => $field,
            };
            $missing = $values[$valueKey] === null;
            if ($missing !== in_array($field, $unobtainable, true)) {
                throw new EmergencyDenied('unobtainable_field_mismatch', 'Setiap observasi yang tidak tersedia harus ditandai secara eksplisit.');
            }
            if ($missing) {
                $reason = $this->optional($reasonsInput[$field] ?? $sharedReason, 1000);
                if ($reason === null) {
                    throw new EmergencyDenied('unobtainable_reason_required', 'Alasan observasi tidak diperoleh wajib diisi.');
                }
                $reasons[$field] = $reason;
            }
        }
        if ($values['weight_kg'] === null && in_array('weight', $unobtainable, true)) {
            $weightReason = $this->optional($reasonsInput['weight'] ?? $sharedReason, 1000);
            if ($weightReason === null) {
                throw new EmergencyDenied('unobtainable_reason_required', 'Alasan berat badan tidak diperoleh wajib diisi.');
            }
            $reasons['weight'] = $weightReason;
        } elseif ($values['weight_kg'] !== null && in_array('weight', $unobtainable, true)) {
            throw new EmergencyDenied('unobtainable_field_mismatch', 'Berat badan yang tercatat tidak boleh ditandai tidak diperoleh.');
        }
        if (array_diff($unobtainable, [...self::OBSERVATION_FIELDS, 'weight']) !== []) {
            throw new EmergencyDenied('validation_failed', 'Daftar observasi tidak diperoleh tidak valid.');
        }
        $trauma = (bool) ($payload['trauma'] ?? $payload['trauma_flag'] ?? false);
        $traumaNote = $this->optional($payload['trauma_note'] ?? null, 1000);
        $isolation = (bool) ($payload['isolation_precaution'] ?? $payload['isolation_precaution_flag'] ?? false);
        $isolationNote = $this->optional($payload['isolation_note'] ?? $payload['isolation_precaution_note'] ?? null, 1000);
        if (($trauma && $traumaNote === null) || ($isolation && $isolationNote === null)) {
            throw new EmergencyDenied('flag_note_required', 'Catatan wajib tersedia untuk penanda trauma atau kewaspadaan isolasi.');
        }
        $reason = $type === EmergencyTriageAssessment::REASSESSMENT ? $this->required($payload['reassessment_reason'] ?? null, 3, 1000) : null;
        $attributes = [
            'encounter_id' => $encounter->id, 'vocabulary_version_id' => $vocabulary->id,
            'assessor_user_id' => $actor->id, 'prior_assessment_id' => $prior?->id,
            'assessment_number' => $number, 'assessment_type' => $type,
            'category_code' => $categoryCode, 'category_label_snapshot' => $category['display_name'],
            'category_rank_snapshot' => (int) $category['rank'], 'category_cue_snapshot' => $category['text_cue'],
            'category_colour_token_snapshot' => $category['colour_token'],
            'observed_at' => $observed, 'recorded_at' => $recorded,
            'late_entry_reason' => $lateReason, 'presenting_concern' => $this->required($payload['presenting_concern'] ?? null, 3, 2000),
            'clinical_basis' => $this->required($payload['clinical_basis'] ?? null, 3, 4000),
            'arrival_condition' => $this->required($payload['arrival_condition'] ?? null, 3, 2000),
            'abcde_observations' => $abcde, 'consciousness' => $consciousness,
            ...$values, 'unobtainable_fields' => $unobtainable, 'unobtainable_reasons' => $reasons,
            'trauma_flag' => $trauma, 'trauma_note' => $traumaNote,
            'isolation_precaution_flag' => $isolation, 'isolation_precaution_note' => $isolationNote,
            'handoff_note' => $this->optional($payload['handoff_note'] ?? null, 2000),
            'reassessment_reason' => $reason, 'prior_assessment_digest' => $priorDigest,
            'created_at' => $recorded,
        ];
        $attributes['content_digest'] = $this->fingerprints->assessmentPayload($attributes);

        return $attributes;
    }

    private function integer(mixed $value, int $min, int $max): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
            throw new EmergencyDenied('vital_out_of_range', 'Nilai observasi berada di luar rentang penyimpanan.');
        }

        return (int) $value;
    }

    private function decimal(mixed $value, float $min, float $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            throw new EmergencyDenied('vital_out_of_range', 'Nilai observasi berada di luar rentang penyimpanan.');
        }

        return number_format((float) $value, 1, '.', '');
    }

    private function required(mixed $value, int $min, int $max): string
    {
        $text = trim((string) $value);
        if (mb_strlen($text) < $min || mb_strlen($text) > $max) {
            throw new EmergencyDenied('validation_failed', 'Teks asesmen tidak valid.');
        }

        return $text;
    }

    private function optional(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new EmergencyDenied('validation_failed', 'Teks asesmen terlalu panjang.');
        }

        return $text;
    }
}
