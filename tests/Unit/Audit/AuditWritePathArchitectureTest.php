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
        $auditSchemaGuardCallers = [
            'Support/Warehouse/WarehouseAuditEvidenceGuard.php',
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
                if (str_contains($pattern, "SchemaQualifier::table('audit_events')")
                    && in_array($relativePath, $auditSchemaGuardCallers, true)) {
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

        $this->assertSame([], $queryCreateCallers);
    }

    public function test_warehouse_audit_guard_is_schema_only_and_cannot_write_audit_rows(): void
    {
        $contents = file_get_contents(app_path('Support/Warehouse/WarehouseAuditEvidenceGuard.php'));
        $this->assertIsString($contents);
        $this->assertStringContainsString("SchemaQualifier::table('audit_events')", $contents);
        $this->assertStringContainsString('CREATE TRIGGER', $contents);

        foreach (["DB::table('audit_events')", '->insert(', '->update(', '->delete(', '->upsert(', '->truncate('] as $mutation) {
            $this->assertStringNotContainsString(
                $mutation,
                $contents,
                'The warehouse audit evidence guard may manage trigger DDL but may not mutate audit rows.',
            );
        }
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
            'Http/Controllers/Outpatient/OutpatientRegistrationController.php',
        ];

        foreach ($directAtomicCallers as $relativePath) {
            $contents = file_get_contents(app_path($relativePath));
            $this->assertIsString($contents);
            $this->assertStringContainsString('$this->auditRecorder->record(', $contents);
            $this->assertStringContainsString('abort_if($event === null, 503', $contents);
        }

        $inpatientController = file_get_contents(app_path('Http/Controllers/Inpatient/InpatientRegistrationController.php'));
        $this->assertIsString($inpatientController);
        $this->assertStringContainsString('private readonly InpatientAdmissionService $admissionService', $inpatientController);
        $this->assertStringContainsString('$this->admissionService->admitDirect(', $inpatientController);

        $inpatientAdmission = file_get_contents(app_path('Support/Inpatient/InpatientAdmissionService.php'));
        $this->assertIsString($inpatientAdmission);
        $this->assertStringContainsString('return DB::transaction(', $inpatientAdmission);
        $this->assertStringContainsString('$this->auditRecorder->record(', $inpatientAdmission);
        $this->assertStringContainsString('if ($event === null)', $inpatientAdmission);
        $this->assertStringContainsString("new InpatientAdmissionDenied('audit_unavailable'", $inpatientAdmission);

        $emergencyController = file_get_contents(app_path('Http/Controllers/Emergency/EmergencyExaminationController.php'));
        $this->assertIsString($emergencyController);
        $this->assertStringContainsString("abort(410, 'Legacy emergency note entry is closed.", $emergencyController);

        $emergencyOperations = file_get_contents(app_path('Support/Emergency/EmergencyOperationCoordinator.php'));
        $this->assertIsString($emergencyOperations);
        $this->assertStringContainsString('return DB::transaction(', $emergencyOperations);
        $this->assertStringContainsString('$this->audit->record(', $emergencyOperations);
        $this->assertStringContainsString('if ($audit === null)', $emergencyOperations);
        $this->assertStringContainsString('throw new EmergencyAuditUnavailable(', $emergencyOperations);

        $inpatient = file_get_contents(app_path('Support/Inpatient/InpatientDocumentationService.php'));
        $this->assertIsString($inpatient);
        $this->assertStringContainsString('return DB::transaction(', $inpatient);
        $this->assertStringContainsString('->lockForUpdate()', $inpatient);
        $this->assertStringContainsString('InpatientDocumentationAuditUnavailable', $inpatient);

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
