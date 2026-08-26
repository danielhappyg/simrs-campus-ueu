<?php

namespace Tests\Unit\Audit;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class AuditWritePathArchitectureTest extends TestCase
{
    public function test_application_audit_writes_use_only_the_model_validated_recorder_path(): void
    {
        $queryCreateCallers = [];
        $readOnlyRawAuditQueryCallers = [
            'Support/Audit/AuditActorAttributionPreflight.php',
        ];
        $forbiddenPatterns = [
            'AuditEvent::create(',
            'AuditEvent::forceCreate(',
            'AuditEvent::insert(',
            'AuditEvent::upsert(',
            'AuditEvent::withoutEvents(',
            'AuditEvent::make(',
            'AuditEvent::query()->forceCreate(',
            'AuditEvent::query()->insert(',
            'AuditEvent::query()->make(',
            'AuditEvent::query()->upsert(',
            'new AuditEvent',
            "DB::table('audit_events')",
            'DB::table("audit_events")',
            "SchemaQualifier::table('audit_events')",
            'SchemaQualifier::table("audit_events")',
        ];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $contents = $file->getContents();
            $relativePath = $file->getRelativePathname();

            foreach ($forbiddenPatterns as $pattern) {
                if (str_contains($pattern, "table('audit_events')")
                    && in_array($relativePath, $readOnlyRawAuditQueryCallers, true)) {
                    continue;
                }

                $this->assertStringNotContainsString($pattern, $contents, "Forbidden audit write found in {$relativePath}.");
            }

            $this->assertDoesNotMatchRegularExpression(
                '/\$[A-Za-z_][A-Za-z0-9_]*audit[A-Za-z0-9_]*->save\s*\(/i',
                $contents,
                "AuditEvent save path found in {$relativePath}.",
            );

            if (str_contains($contents, 'AuditEvent::query()->create(')) {
                $queryCreateCallers[] = $relativePath;
            }
        }

        $this->assertSame(['Support/Audit/AuditRecorder.php'], $queryCreateCallers);
    }

    public function test_attribution_preflight_raw_audit_path_remains_read_only(): void
    {
        $contents = file_get_contents(app_path('Support/Audit/AuditActorAttributionPreflight.php'));
        $this->assertIsString($contents);
        $this->assertStringContainsString("SchemaQualifier::table('audit_events')", $contents);
        $this->assertStringContainsString('->select([', $contents);
        $this->assertStringContainsString('->lazyById(', $contents);

        foreach (['->insert(', '->update(', '->delete(', '->upsert(', '->truncate('] as $mutation) {
            $this->assertStringNotContainsString(
                $mutation,
                $contents,
                'The attribution preflight must remain a read-only raw audit-table path.',
            );
        }
    }

    public function test_registration_and_shared_clinical_mutations_fail_closed_inside_their_transactions(): void
    {
        $directAtomicCallers = [
            'Http/Controllers/Emergency/EmergencyRegistrationController.php',
            'Http/Controllers/Inpatient/InpatientRegistrationController.php',
            'Http/Controllers/Outpatient/OutpatientRegistrationController.php',
        ];

        foreach ($directAtomicCallers as $relativePath) {
            $contents = file_get_contents(app_path($relativePath));
            $this->assertIsString($contents);
            $this->assertStringContainsString('$this->auditRecorder->record(', $contents);
            $this->assertStringContainsString('abort_if($event === null, 503', $contents);
        }

        foreach ([
            'Http/Controllers/Emergency/EmergencyExaminationController.php',
            'Http/Controllers/Inpatient/InpatientExaminationController.php',
        ] as $relativePath) {
            $contents = file_get_contents(app_path($relativePath));
            $this->assertIsString($contents);
            $this->assertStringContainsString('$this->clinicalEntryWriter->write(', $contents);
        }

        $writer = file_get_contents(app_path('Support/Clinical/LockedClinicalEntryWriter.php'));
        $this->assertIsString($writer);
        $this->assertStringContainsString('DB::transaction(', $writer);
        $this->assertStringContainsString('->lockForUpdate()', $writer);
        $this->assertStringContainsString('$this->auditRecorder->record(', $writer);
        $this->assertStringContainsString('abort_if($event === null, 503', $writer);
    }

    public function test_application_does_not_offer_a_user_hard_delete_path(): void
    {
        $forbiddenPatterns = [
            'User::destroy(',
            'User::query()->delete(',
            'User::query()->forceDelete(',
            '$user->delete(',
            '$user->forceDelete(',
            "DB::table('users')",
            'DB::table("users")',
        ];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            foreach ($forbiddenPatterns as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern,
                    $file->getContents(),
                    "Forbidden user deletion path found in {$file->getRelativePathname()}.",
                );
            }
        }
    }
}
