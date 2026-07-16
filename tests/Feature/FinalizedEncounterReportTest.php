<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Coding\Enums\TerminologyProvenanceStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\TerminologyConcept;
use App\Modules\Coding\Models\TerminologyRelease;
use App\Modules\Coding\Support\TerminologyNormalizer;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\DebriefNoteType;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\DebriefNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class FinalizedEncounterReportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_authorized_participant_can_render_watermarked_source_derived_reports_with_minimized_audit(): void
    {
        $this->seedReferenceOutpatient();
        $this->activateReferenceTerminology();
        $command = $this->artisan('simulation:complete-reference-journey');

        if (! $command instanceof PendingCommand) {
            $this->fail('Console output mocking must remain enabled for the reference-journey report test.');
        }

        $command->assertSuccessful()->run();

        $encounter = Encounter::query()->sole();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->sole();
        $participant = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->sole();

        $this->actingAs($facilitator)->post(route('encounters.debrief.notes.store', $encounter), [
            'request_key' => (string) Str::ulid(),
            'note_type' => DebriefNoteType::FacilitatorSynthesis->value,
            'body' => 'Sintesis debrief awal yang hanya untuk pembelajaran bersama.',
            'simulation_attestation' => true,
        ])->assertRedirect();
        $note = DebriefNote::query()->sole();
        $this->actingAs($facilitator)->post(route('debrief-notes.versions.store', $note), [
            'request_key' => (string) Str::ulid(),
            'body' => 'Sintesis debrief terbaru menautkan handoff, koreksi, dan keputusan koding manusia.',
            'change_reason' => 'Menambahkan hubungan keputusan lintas profesi.',
            'simulation_attestation' => true,
        ])->assertRedirect();

        $summary = $this->actingAs($participant)
            ->get(route('encounters.reports.outpatient-summary', $encounter));

        $summary
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('SIMULASI — DATA SINTETIS')
            ->assertSee('Ringkasan Rawat Jalan Simulasi')
            ->assertSee('Pasien Sintetis Arunika')
            ->assertSee('Sindrom pusing dalam evaluasi pada skenario simulasi.')
            ->assertSee('ICD-10 R42')
            ->assertSee('ICD-9-CM 38.99')
            ->assertSee('Tidak ada temuan kritis dalam skenario.')
            ->assertSee('Pratinjau pembelajaran; bukan rekam medis legal')
            ->assertDontSee('terminologySourceHash')
            ->assertDontSee('request_correlation_id')
            ->assertDontSee('ip_hash');

        $debrief = $this->actingAs($participant)
            ->get(route('encounters.reports.debrief-evidence', $encounter));

        $debrief
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertSee('Laporan Bukti Debrief Simulasi')
            ->assertSee('Sintesis debrief terbaru menautkan handoff, koreksi, dan keputusan koding manusia.')
            ->assertSee('Sintesis debrief awal yang hanya untuk pembelajaran bersama.')
            ->assertSee('Alasan perubahan:')
            ->assertSee('Menambahkan hubungan keputusan lintas profesi.')
            ->assertSee('Non-scoring')
            ->assertDontSee('raw_secret_token')
            ->assertDontSee('request_correlation_id')
            ->assertDontSee('ip_hash');

        $renderEvents = AuditEvent::query()
            ->where('action', 'report.rendered')
            ->orderBy('recorded_at')
            ->get();
        $this->assertCount(2, $renderEvents);
        $this->assertSame(['OUTPATIENT_SUMMARY', 'DEBRIEF_EVIDENCE'], $renderEvents
            ->pluck('metadata.report_type')
            ->all());

        foreach ($renderEvents as $event) {
            $metadata = json_encode($event->metadata, JSON_THROW_ON_ERROR);
            $this->assertSame(['report_type', 'section_count', 'source_counts'], array_keys($event->metadata ?? []));
            $this->assertStringNotContainsString('Pasien Sintetis Arunika', $metadata);
            $this->assertStringNotContainsString('Sindrom pusing', $metadata);
            $this->assertStringNotContainsString('Sintesis debrief', $metadata);
        }

        $encounter->session->update(['status' => SessionStatus::Completed]);
        $this->actingAs($participant)
            ->get(route('encounters.reports.debrief-evidence', $encounter))
            ->assertOk();
    }

    public function test_reports_require_finalization_capability_and_exact_case_context(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->sole();
        $participant = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->sole();
        $assignment = Assignment::query()->where('user_id', $participant->getKey())->sole();

        $this->actingAs($participant)
            ->get(route('encounters.reports.outpatient-summary', $encounter))
            ->assertStatus(409);

        $capabilities = $assignment->capabilities;
        $assignment->update([
            'capabilities' => collect($capabilities)
                ->reject(fn (string $capability): bool => $capability === Capability::ReportView->value)
                ->values()
                ->all(),
        ]);
        $this->actingAs($participant)
            ->get(route('encounters.reports.outpatient-summary', $encounter))
            ->assertForbidden();

        $assignment->update([
            'capabilities' => $capabilities,
            'encounter_id' => null,
            'supervisor_assignment_id' => null,
        ]);
        $this->actingAs($participant)
            ->get(route('encounters.reports.debrief-evidence', $encounter))
            ->assertForbidden();

        $this->assertSame(EncounterStatus::Planned, $encounter->status);
        $this->assertDatabaseMissing('audit_events', ['action' => 'report.rendered']);
    }

    private function activateReferenceTerminology(): void
    {
        $this->activateRelease(TerminologySystem::Icd10, 'R42', 'Dizziness and giddiness');
        $this->activateRelease(TerminologySystem::Icd9Cm, '38.99', 'Other puncture of vein');
    }

    private function activateRelease(TerminologySystem $system, string $code, string $display): void
    {
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->sole();
        $actor = Assignment::query()
            ->where('user_id', $facilitator->getKey())
            ->where('application_role', ApplicationRole::Facilitator)
            ->sole();
        $release = TerminologyRelease::query()->create([
            'request_key' => (string) Str::ulid(),
            'classification_system' => $system,
            'logical_version' => $system->logicalVersion(),
            'status' => TerminologyReleaseStatus::Imported,
            'source_filename' => 'synthetic-reference-'.$system->value.'.xlsx',
            'source_sha256' => hash('sha256', "{$system->value}|{$code}|{$display}"),
            'sheet_name' => $system->expectedSheetName(),
            'source_provenance_status' => TerminologyProvenanceStatus::SyntheticFixture,
            'imported_by_user_id' => $actor->user_id,
            'imported_by_assignment_id' => $actor->getKey(),
            'row_count' => 1,
            'ignored_blank_rows' => 0,
            'validation_report' => [
                'schema' => 'terminology-import-validation.v1',
                'classificationSystem' => $system->value,
                'sourceHashVerified' => true,
                'syntheticFixture' => true,
            ],
            'imported_at' => now(),
        ]);
        $normalizer = app(TerminologyNormalizer::class);
        $normalizedDisplay = $normalizer->text($display);
        TerminologyConcept::query()->create([
            'terminology_release_id' => $release->getKey(),
            'code' => $code,
            'display' => $display,
            'normalized_code' => $normalizer->code($code),
            'normalized_display' => $normalizedDisplay,
            'search_tokens' => implode(' ', $normalizer->tokens($normalizedDisplay)),
            'active' => true,
        ]);
        $release->persistActivation($actor, null);
    }
}
