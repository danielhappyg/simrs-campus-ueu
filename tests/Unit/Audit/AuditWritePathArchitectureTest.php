<?php

namespace Tests\Unit\Audit;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class AuditWritePathArchitectureTest extends TestCase
{
    public function test_application_audit_writes_use_only_the_model_validated_recorder_path(): void
    {
        $queryCreateCallers = [];
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

    public function test_five_existing_mutation_callers_remain_explicitly_classified_as_best_effort_non_atomic(): void
    {
        $bestEffortCallers = [
            'Http/Controllers/Emergency/EmergencyExaminationController.php',
            'Http/Controllers/Emergency/EmergencyRegistrationController.php',
            'Http/Controllers/Inpatient/InpatientExaminationController.php',
            'Http/Controllers/Inpatient/InpatientRegistrationController.php',
            'Http/Controllers/Outpatient/OutpatientRegistrationController.php',
        ];

        foreach ($bestEffortCallers as $relativePath) {
            $contents = file_get_contents(app_path($relativePath));
            $this->assertIsString($contents);
            $this->assertStringContainsString('$this->auditRecorder->record(', $contents);
        }
    }
}
