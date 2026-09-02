<?php

namespace App\Support\Emergency;

use App\Models\EmergencyTriageCodeReservation;
use App\Models\EmergencyTriageVocabulary;
use App\Models\EmergencyTriageVocabularyVersion;
use App\Models\User;

final class EmergencyTriageVocabularyService
{
    public const FIXED_CODES = ['MERAH', 'KUNING', 'HIJAU', 'HITAM'];

    public function __construct(
        private readonly EmergencyActorPolicy $policy,
        private readonly EmergencyOperationCoordinator $operations,
        private readonly EmergencyEvidenceFingerprint $fingerprints,
    ) {}

    /** @param array<int, mixed> $categories */
    public function create(User $actor, string $code, string $displayName, array $categories, string $idempotencyKey): EmergencyMutationResult
    {
        $normalized = $this->normalize($displayName, $categories);
        $code = mb_strtoupper(trim($code));
        if (! preg_match('/\A[A-Z0-9_]{3,64}\z/', $code)) {
            throw new EmergencyDenied('validation_failed', 'Kode kosakata triase tidak valid.');
        }

        return $this->operations->perform($actor, 'EMERGENCY_TRIAGE_VOCABULARY_CREATE', null, $idempotencyKey, compact('code', 'normalized'), fn () => $this->policy->master($actor), function () use ($actor, $code, $normalized): EmergencyTriageVocabularyVersion {
            EmergencyTriageCodeReservation::query()->create(['actor_user_id' => $actor->id, 'normalized_code' => $code, 'created_at' => now()]);
            $digest = $this->fingerprints->vocabulary($normalized['display_name'], $normalized['categories'], EmergencyTriageVocabulary::ACTIVE);
            $vocabulary = EmergencyTriageVocabulary::query()->create(['vocabulary_code' => $code, 'display_name' => $normalized['display_name'], 'state' => EmergencyTriageVocabulary::ACTIVE, 'version' => 1, 'current_content_digest' => $digest]);
            $version = EmergencyTriageVocabularyVersion::query()->create(['emergency_triage_vocabulary_id' => $vocabulary->id, 'actor_user_id' => $actor->id, 'version' => 1, 'display_name' => $normalized['display_name'], 'categories' => $normalized['categories'], 'state' => EmergencyTriageVocabulary::ACTIVE, 'content_digest' => $digest, 'created_at' => now()]);

            return $version;
        });
    }

    /** @param array<int, mixed> $categories */
    public function revise(string $vocabularyPublicId, User $actor, int $expectedVersion, string $displayName, array $categories, bool $retire, string $idempotencyKey): EmergencyMutationResult
    {
        $normalized = $this->normalize($displayName, $categories);
        $state = $retire ? EmergencyTriageVocabulary::RETIRED : EmergencyTriageVocabulary::ACTIVE;

        return $this->operations->perform($actor, 'EMERGENCY_TRIAGE_VOCABULARY_REVISE', $vocabularyPublicId, $idempotencyKey, compact('vocabularyPublicId', 'expectedVersion', 'normalized', 'state'), fn () => $this->policy->master($actor), function () use ($vocabularyPublicId, $actor, $expectedVersion, $normalized, $state): EmergencyTriageVocabularyVersion {
            $vocabulary = EmergencyTriageVocabulary::query()->where('public_id', $vocabularyPublicId)->lockForUpdate()->firstOrFail();
            if ($vocabulary->state !== EmergencyTriageVocabulary::ACTIVE) {
                throw new EmergencyDenied('vocabulary_retired', 'Kosakata triase sudah dihentikan.');
            }
            if ($vocabulary->version !== $expectedVersion) {
                throw new EmergencyDenied('stale_version', 'Versi kosakata triase telah berubah.');
            }
            $version = $vocabulary->version + 1;
            $digest = $this->fingerprints->vocabulary($normalized['display_name'], $normalized['categories'], $state);
            $versionRecord = EmergencyTriageVocabularyVersion::query()->create(['emergency_triage_vocabulary_id' => $vocabulary->id, 'actor_user_id' => $actor->id, 'version' => $version, 'display_name' => $normalized['display_name'], 'categories' => $normalized['categories'], 'state' => $state, 'content_digest' => $digest, 'created_at' => now()]);
            $vocabulary->update(['display_name' => $normalized['display_name'], 'state' => $state, 'version' => $version, 'current_content_digest' => $digest]);

            return $versionRecord;
        });
    }

    /**
     * @param  array<int, mixed>  $categories
     * @return array{display_name:string,categories:list<array{code:string,rank:int,display_name:string,text_cue:string,colour_token:string,guidance_text:?string}>}
     */
    private function normalize(string $displayName, array $categories): array
    {
        $displayName = trim($displayName);
        if (mb_strlen($displayName) < 3 || mb_strlen($displayName) > 160 || count($categories) !== 4) {
            throw new EmergencyDenied('validation_failed', 'Kosakata triase harus memiliki empat kategori tetap.');
        }
        $normalized = [];
        foreach (array_values($categories) as $index => $category) {
            if (! is_array($category) || ($category['code'] ?? null) !== self::FIXED_CODES[$index] || (int) ($category['rank'] ?? 0) !== $index + 1) {
                throw new EmergencyDenied('fixed_category_violation', 'Kode dan urutan kategori triase tidak dapat diubah.');
            }
            $label = $this->text($category['display_name'] ?? null, 2, 120);
            $cue = $this->text($category['text_cue'] ?? null, 2, 160);
            $colour = trim((string) ($category['colour_token'] ?? ''));
            if (! preg_match('/\A[a-z][a-z0-9-]{1,31}\z/', $colour)) {
                throw new EmergencyDenied('validation_failed', 'Token warna kategori tidak valid.');
            }
            $guidance = isset($category['guidance_text']) && trim((string) $category['guidance_text']) !== '' ? $this->text($category['guidance_text'], 1, 1000) : null;
            $normalized[] = ['code' => self::FIXED_CODES[$index], 'rank' => $index + 1, 'display_name' => $label, 'text_cue' => $cue, 'colour_token' => $colour, 'guidance_text' => $guidance];
        }

        return ['display_name' => $displayName, 'categories' => $normalized];
    }

    private function text(mixed $value, int $min, int $max): string
    {
        $text = trim((string) $value);
        if (mb_strlen($text) < $min || mb_strlen($text) > $max) {
            throw new EmergencyDenied('validation_failed', 'Teks kosakata triase tidak valid.');
        }

        return $text;
    }
}
