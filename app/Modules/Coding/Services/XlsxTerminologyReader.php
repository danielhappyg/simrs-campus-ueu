<?php

namespace App\Modules\Coding\Services;

use App\Modules\Coding\Enums\TerminologySystem;
use DomainException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use XMLReader;
use ZipArchive;

class XlsxTerminologyReader
{
    private const MAX_FILE_BYTES = 25_000_000;

    private const MAX_XML_ENTRY_BYTES = 15_000_000;

    /**
     * @return array{
     *   sheetName: string,
     *   headers: list<string>,
     *   rows: list<array{code: string, display: string, version: string, sourceRow: int}>,
     *   ignoredBlankRows: int
     * }
     */
    public function read(string $path, TerminologySystem $system): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new DomainException('The selected terminology workbook is not readable.');
        }

        $size = filesize($path);

        if (! is_int($size) || $size < 1 || $size > self::MAX_FILE_BYTES) {
            throw new DomainException('The terminology workbook exceeds the allowed file-size boundary.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new DomainException('The selected terminology file is not a readable XLSX archive.');
        }

        try {
            $workbookXml = $this->entry($zip, 'xl/workbook.xml');
            $relationshipsXml = $this->entry($zip, 'xl/_rels/workbook.xml.rels');
            [$sheetName, $sheetPath] = $this->resolveSheet(
                $workbookXml,
                $relationshipsXml,
                $system->expectedSheetName(),
            );
            $sharedStrings = $this->readSharedStrings($this->entry($zip, 'xl/sharedStrings.xml'));
            $result = $this->readSheet($this->entry($zip, $sheetPath), $sharedStrings, $system);

            return ['sheetName' => $sheetName, ...$result];
        } finally {
            $zip->close();
        }
    }

    private function entry(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);

        if (! is_array($stat) || $stat['size'] > self::MAX_XML_ENTRY_BYTES) {
            throw new DomainException("The XLSX entry {$name} is missing or exceeds the safe XML boundary.");
        }

        $contents = $zip->getFromName($name);

        if (! is_string($contents) || str_contains(strtoupper($contents), '<!DOCTYPE')) {
            throw new DomainException("The XLSX entry {$name} is unreadable or contains a forbidden document type declaration.");
        }

        return $contents;
    }

    /** @return array{string, string} */
    private function resolveSheet(string $workbookXml, string $relationshipsXml, string $expectedName): array
    {
        $workbook = $this->document($workbookXml);
        $xpath = new DOMXPath($workbook);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheetNodes = $xpath->query('/x:workbook/x:sheets/x:sheet');

        if ($sheetNodes === false) {
            throw new DomainException('The XLSX workbook sheet registry is invalid.');
        }

        $relationshipId = null;

        foreach ($sheetNodes as $sheet) {
            if ($sheet instanceof DOMElement && $sheet->getAttribute('name') === $expectedName) {
                $relationshipId = $sheet->getAttributeNS(
                    'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                    'id',
                );
                break;
            }
        }

        if (! is_string($relationshipId) || $relationshipId === '') {
            throw new DomainException("The workbook does not contain the expected sheet {$expectedName}.");
        }

        $relationships = $this->document($relationshipsXml);
        $relationshipXpath = new DOMXPath($relationships);
        $relationshipXpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relationshipNodes = $relationshipXpath->query("/r:Relationships/r:Relationship[@Id='{$relationshipId}']");

        if ($relationshipNodes === false) {
            throw new DomainException('The workbook relationship registry is invalid.');
        }

        $relationship = $relationshipNodes->item(0);

        if (! $relationship instanceof DOMElement) {
            throw new DomainException('The expected workbook sheet relationship is missing.');
        }

        $target = str_replace('\\', '/', $relationship->getAttribute('Target'));

        if ($target === '' || str_contains($target, '..')) {
            throw new DomainException('The workbook contains an unsafe sheet relationship target.');
        }

        $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.ltrim($target, '/');

        return [$expectedName, $path];
    }

    /** @return list<string> */
    private function readSharedStrings(string $xml): array
    {
        $reader = new XMLReader;

        if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new DomainException('The XLSX shared-string table cannot be parsed.');
        }

        $strings = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                continue;
            }

            $node = $reader->expand();

            if (! $node) {
                throw new DomainException('An XLSX shared string cannot be expanded safely.');
            }

            $strings[] = $node->textContent;
        }

        $reader->close();

        return $strings;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return array{
     *   headers: list<string>,
     *   rows: list<array{code: string, display: string, version: string, sourceRow: int}>,
     *   ignoredBlankRows: int
     * }
     */
    private function readSheet(string $xml, array $sharedStrings, TerminologySystem $system): array
    {
        $reader = new XMLReader;

        if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new DomainException('The expected XLSX worksheet cannot be parsed.');
        }

        $headers = [];
        $rows = [];
        $ignoredBlankRows = 0;
        $seenCodes = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $rowNode = $reader->expand();

            if (! $rowNode instanceof DOMElement) {
                throw new DomainException('An XLSX worksheet row cannot be expanded safely.');
            }

            $sourceRow = (int) $rowNode->getAttribute('r');
            $values = $this->rowValues($rowNode, $sharedStrings);
            $selected = [
                trim($values['A'] ?? ''),
                trim($values['B'] ?? ''),
                trim($values['C'] ?? ''),
            ];
            $extra = collect($values)
                ->except(['A', 'B', 'C'])
                ->contains(fn (string $value): bool => trim($value) !== '');

            if ($extra) {
                throw new DomainException("Unexpected populated columns were found on workbook row {$sourceRow}.");
            }

            if ($headers === []) {
                if ($selected !== ['CODE', 'DISPLAY', 'VERSION']) {
                    throw new DomainException('The workbook header must be exactly CODE, DISPLAY, VERSION.');
                }

                $headers = $selected;

                continue;
            }

            if ($selected === ['', '', '']) {
                $ignoredBlankRows++;

                continue;
            }

            if (in_array('', $selected, true)) {
                throw new DomainException("Workbook row {$sourceRow} contains a partially blank terminology record.");
            }

            [$code, $display, $version] = $selected;
            $this->assertCode($code, $system, $sourceRow);

            if ($version !== $system->logicalVersion()) {
                throw new DomainException("Workbook row {$sourceRow} contains unexpected version {$version}.");
            }

            if (isset($seenCodes[$code])) {
                throw new DomainException("Workbook code {$code} is duplicated on rows {$seenCodes[$code]} and {$sourceRow}.");
            }

            $seenCodes[$code] = $sourceRow;
            $rows[] = compact('code', 'display', 'version', 'sourceRow');
        }

        $reader->close();

        if ($headers === [] || $rows === []) {
            throw new DomainException('The workbook contains no valid terminology rows.');
        }

        return compact('headers', 'rows', 'ignoredBlankRows');
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return array<string, string>
     */
    private function rowValues(DOMElement $row, array $sharedStrings): array
    {
        $values = [];

        foreach ($row->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c') as $cell) {
            if ($cell->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'f')->length > 0) {
                throw new DomainException('Formula cells are not permitted in a terminology workbook.');
            }

            $reference = $cell->getAttribute('r');

            if (preg_match('/^([A-Z]+)\d+$/', $reference, $matches) !== 1) {
                throw new DomainException('An XLSX cell has an invalid coordinate.');
            }

            $column = $matches[1];
            $type = $cell->getAttribute('t');
            $raw = '';

            if ($type !== 'inlineStr') {
                $valueNodes = $cell->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'v');

                if ($valueNodes->length > 0) {
                    $raw = $valueNodes->item(0)->textContent;
                }
            }

            if ($type === 's') {
                if (! ctype_digit($raw) || ! array_key_exists((int) $raw, $sharedStrings)) {
                    throw new DomainException('An XLSX shared-string reference is invalid.');
                }

                $values[$column] = $sharedStrings[(int) $raw];
            } elseif ($type === 'inlineStr') {
                $inlineNodes = $cell->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'is');
                $values[$column] = $inlineNodes->length > 0
                    ? $inlineNodes->item(0)->textContent
                    : '';
            } else {
                $values[$column] = $raw;
            }
        }

        return $values;
    }

    private function assertCode(string $code, TerminologySystem $system, int $sourceRow): void
    {
        $valid = match ($system) {
            TerminologySystem::Icd10 => preg_match('/^[A-Z][0-9A-Z]{2}(?:\.[0-9A-Z]{1,5})?$/', $code) === 1,
            TerminologySystem::Icd9Cm => preg_match('/^[0-9]{2,3}(?:\.[0-9]{1,4})?$/', $code) === 1,
        };

        if (! $valid) {
            throw new DomainException("Workbook row {$sourceRow} contains invalid {$system->label()} code {$code}.");
        }
    }

    private function document(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new DomainException('An XLSX metadata document cannot be parsed.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }
}
