<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\TerminologyConcept;
use App\Modules\Coding\Services\TerminologyImportService;
use App\Modules\Teaching\Models\Assignment;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;
use ZipArchive;

class TerminologyImportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    /** @var list<string> */
    private array $temporaryFiles = [];

    public function test_valid_xlsx_is_checksum_bound_activated_versioned_and_append_only(): void
    {
        $this->seedReferenceOutpatient();
        $actor = $this->terminologyManager();
        $system = TerminologySystem::Icd10;
        $firstPath = $this->workbook($system, [
            ['R42', 'Dizziness and giddiness', $system->logicalVersion()],
            ['H81.1', 'Benign paroxysmal vertigo', $system->logicalVersion()],
        ], blankRows: 2);
        $firstHash = $this->sha256($firstPath);
        $requestKey = (string) Str::ulid();
        $service = app(TerminologyImportService::class);

        $first = $service->importXlsx(
            path: $firstPath,
            system: $system,
            expectedSha256: $firstHash,
            actorAssignment: $actor,
            requestKey: $requestKey,
        );
        $idempotent = $service->importXlsx(
            path: $firstPath,
            system: $system,
            expectedSha256: $firstHash,
            actorAssignment: $actor,
            requestKey: $requestKey,
        );

        $this->assertSame($first->getKey(), $idempotent->getKey());
        $this->assertSame(TerminologyReleaseStatus::Active, $first->status);
        $this->assertSame($firstHash, $first->source_sha256);
        $this->assertSame(2, $first->row_count);
        $this->assertSame(2, $first->ignored_blank_rows);
        $this->assertSame(2, $first->concepts()->count());
        $this->assertDatabaseHas('terminology_concepts', [
            'terminology_release_id' => $first->getKey(),
            'code' => 'R42',
            'display' => 'Dizziness and giddiness',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'terminology.release_imported_and_activated',
            'resource_id' => $first->public_id,
        ]);

        $secondPath = $this->workbook($system, [
            ['R42', 'Dizziness and giddiness revised fixture', $system->logicalVersion()],
        ]);
        $secondHash = $this->sha256($secondPath);
        $second = $service->importXlsx(
            path: $secondPath,
            system: $system,
            expectedSha256: $secondHash,
            actorAssignment: $actor,
            requestKey: (string) Str::ulid(),
        );

        $this->assertSame(TerminologyReleaseStatus::Superseded, $first->refresh()->status);
        $this->assertSame(TerminologyReleaseStatus::Active, $second->status);
        $this->assertSame($first->getKey(), $second->supersedes_release_id);
        $this->assertDatabaseCount('terminology_releases', 2);
        $this->assertDatabaseCount('terminology_concepts', 3);
        $this->assertDomainFailure(
            fn () => $second->update(['source_filename' => 'forbidden.xlsx']),
            'immutable',
        );
        $second->refresh();
        $this->assertDomainFailure(fn () => $second->delete(), 'append-only');
        $concept = TerminologyConcept::query()
            ->where('terminology_release_id', $second->getKey())
            ->sole();
        $this->assertDomainFailure(fn () => $concept->update(['display' => 'forbidden']), 'immutable');
    }

    public function test_checksum_mismatch_and_duplicate_codes_are_rejected_without_partial_import(): void
    {
        $this->seedReferenceOutpatient();
        $actor = $this->terminologyManager();
        $system = TerminologySystem::Icd10;
        $service = app(TerminologyImportService::class);
        $validPath = $this->workbook($system, [
            ['R42', 'Dizziness and giddiness', $system->logicalVersion()],
        ]);

        $this->assertDomainFailure(
            fn () => $service->importXlsx(
                path: $validPath,
                system: $system,
                expectedSha256: str_repeat('0', 64),
                actorAssignment: $actor,
                requestKey: (string) Str::ulid(),
            ),
            'checksum',
        );
        $this->assertDatabaseCount('terminology_releases', 0);
        $this->assertDatabaseCount('terminology_concepts', 0);

        $duplicatePath = $this->workbook($system, [
            ['R42', 'Dizziness and giddiness', $system->logicalVersion()],
            ['R42', 'Duplicated display must be rejected', $system->logicalVersion()],
        ]);
        $this->assertDomainFailure(
            fn () => $service->importXlsx(
                path: $duplicatePath,
                system: $system,
                expectedSha256: $this->sha256($duplicatePath),
                actorAssignment: $actor,
                requestKey: (string) Str::ulid(),
            ),
            'duplicated',
        );
        $this->assertDatabaseCount('terminology_releases', 0);
        $this->assertDatabaseCount('terminology_concepts', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_icd9_cm_workbook_contract_imports_a_separate_procedure_release(): void
    {
        $this->seedReferenceOutpatient();
        $actor = $this->terminologyManager();
        $system = TerminologySystem::Icd9Cm;
        $path = $this->workbook($system, [
            ['47.0', 'Appendectomy', $system->logicalVersion()],
            ['99.15', 'Parenteral infusion of concentrated nutritional substances', $system->logicalVersion()],
        ]);
        $hash = $this->sha256($path);

        $release = app(TerminologyImportService::class)->importXlsx(
            path: $path,
            system: $system,
            expectedSha256: $hash,
            actorAssignment: $actor,
            requestKey: (string) Str::ulid(),
        );

        $this->assertSame(TerminologySystem::Icd9Cm, $release->classification_system);
        $this->assertSame('ICD9CM_2010', $release->logical_version);
        $this->assertSame($system->expectedSheetName(), $release->sheet_name);
        $this->assertSame(TerminologyReleaseStatus::Active, $release->status);
        $this->assertSame($hash, $release->source_sha256);
        $this->assertSame(2, $release->row_count);
        $this->assertDatabaseHas('terminology_concepts', [
            'terminology_release_id' => $release->getKey(),
            'code' => '47.0',
            'display' => 'Appendectomy',
        ]);
        $this->assertDatabaseMissing('terminology_releases', [
            'classification_system' => TerminologySystem::Icd10->value,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function terminologyManager(): Assignment
    {
        $user = User::query()
            ->where('email', 'fasilitator.simulasi@example.invalid')
            ->firstOrFail();

        return Assignment::query()->where('user_id', $user->getKey())->sole();
    }

    /**
     * @param  list<array{string, string, string}>  $rows
     */
    private function workbook(TerminologySystem $system, array $rows, int $blankRows = 0): string
    {
        $path = sys_get_temp_dir().'/simrs-terminology-'.Str::lower((string) Str::ulid()).'.xlsx';
        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened, 'A temporary XLSX archive could not be created.');
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($system));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->relationshipsXml());
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheetXml($rows, $blankRows));
        $this->assertTrue($zip->close(), 'The temporary XLSX archive could not be finalized.');
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function workbookXml(TerminologySystem $system): string
    {
        $sheet = $this->xml($system->expectedSheetName());

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="{$sheet}" sheetId="1" r:id="rId1"/></sheets></workbook>
XML;
    }

    private function relationshipsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>
XML;
    }

    private function sharedStringsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="0" uniqueCount="0"></sst>
XML;
    }

    /** @param list<array{string, string, string}> $rows */
    private function worksheetXml(array $rows, int $blankRows): string
    {
        $allRows = [['CODE', 'DISPLAY', 'VERSION'], ...$rows];
        $rowXml = '';

        foreach ($allRows as $index => $values) {
            $rowNumber = $index + 1;
            $rowXml .= '<row r="'.$rowNumber.'">';

            foreach (['A', 'B', 'C'] as $columnIndex => $column) {
                $value = $this->xml($values[$columnIndex]);
                $rowXml .= '<c r="'.$column.$rowNumber.'" t="inlineStr"><is><t>'.$value.'</t></is></c>';
            }

            $rowXml .= '</row>';
        }

        for ($index = 0; $index < $blankRows; $index++) {
            $rowNumber = count($allRows) + $index + 1;
            $rowXml .= '<row r="'.$rowNumber.'"></row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .$rowXml
            .'</sheetData></worksheet>';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function sha256(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if (! is_string($hash)) {
            throw new DomainException('The temporary workbook checksum could not be calculated.');
        }

        return $hash;
    }

    private function assertDomainFailure(callable $action, string $messageFragment): void
    {
        try {
            $action();
            $this->fail("Expected a domain failure containing {$messageFragment}.");
        } catch (DomainException $exception) {
            $this->assertStringContainsString($messageFragment, mb_strtolower($exception->getMessage()));
        }
    }
}
