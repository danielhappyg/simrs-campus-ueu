<?php

namespace Database\Seeders;

use App\Models\EmergencyTriageVocabulary;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Emergency\EmergencyTriageVocabularyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class EmergencyTriageVocabularySeeder extends Seeder
{
    public const ACTOR_EMAIL = 'emergency.master.seeder@simrs-campus.test';

    public const CODE = 'IGD_TRIAGE_TEACHING_V1';

    public function run(): void
    {
        if (EmergencyTriageVocabulary::query()->where('vocabulary_code', self::CODE)->exists()) {
            return;
        }

        DB::transaction(function (): void {
            $actor = User::query()->where('email', self::ACTOR_EMAIL)->lockForUpdate()->first();
            $created = false;
            if (! $actor) {
                $actor = User::query()->create([
                    'email' => self::ACTOR_EMAIL,
                    'name' => 'Emergency Master Seeder',
                    'password' => Str::random(64),
                    'status' => 'ACTIVE',
                    'is_system_administrator' => false,
                    'email_verified_at' => now(),
                ]);
                $actor->roles()->sync([
                    Role::query()->where('slug', RoleCapabilityMatrix::ROLE_ADMIN)->sole()->id,
                ]);
                $created = true;
            }

            if ($actor->is_system_administrator || $actor->roleSlugs() !== [RoleCapabilityMatrix::ROLE_ADMIN]) {
                throw new RuntimeException('Emergency vocabulary seeding requires its exact single-role admin service actor.');
            }

            $originalStatus = $actor->status;
            $originalVerifiedAt = $actor->email_verified_at;
            if ($actor->status !== 'ACTIVE') {
                $actor->forceFill(['status' => 'ACTIVE', 'email_verified_at' => now()])->save();
            }

            app(EmergencyTriageVocabularyService::class)->create(
                $actor->fresh(),
                self::CODE,
                'Kategori Triase IGD',
                self::catalogue(),
                'seed-emergency-triage-vocabulary-v1',
            );

            if ($created || $originalStatus !== 'ACTIVE') {
                $actor->forceFill([
                    'status' => $created ? 'DISABLED' : $originalStatus,
                    'email_verified_at' => $created ? null : $originalVerifiedAt,
                    'remember_token' => null,
                ])->save();
            }
        }, attempts: 1);
    }

    /** @return list<array{code: string, rank: int, display_name: string, text_cue: string, colour_token: string, guidance_text: string}> */
    public static function catalogue(): array
    {
        return [
            ['code' => 'MERAH', 'rank' => 1, 'display_name' => 'Merah', 'text_cue' => 'Prioritas segera', 'colour_token' => 'danger', 'guidance_text' => 'Kategori prioritas tertinggi berdasarkan penilaian manual ABCDE.'],
            ['code' => 'KUNING', 'rank' => 2, 'display_name' => 'Kuning', 'text_cue' => 'Prioritas mendesak', 'colour_token' => 'warning', 'guidance_text' => 'Kategori prioritas mendesak berdasarkan penilaian manual ABCDE.'],
            ['code' => 'HIJAU', 'rank' => 3, 'display_name' => 'Hijau', 'text_cue' => 'Prioritas lebih rendah', 'colour_token' => 'success', 'guidance_text' => 'Kategori prioritas lebih rendah berdasarkan penilaian manual ABCDE.'],
            ['code' => 'HITAM', 'rank' => 4, 'display_name' => 'Hitam', 'text_cue' => 'Kategori hitam', 'colour_token' => 'neutral', 'guidance_text' => 'Fakta kategori triase; bukan penetapan kematian atau sebab kematian.'],
        ];
    }
}
