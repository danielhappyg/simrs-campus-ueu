<?php

namespace App\Modules\Interoperability\Support;

final class FhirPreviewValidator
{
    /**
     * @param  array<string, mixed>  $bundle
     * @param  list<array<string, string>>  $sourceIndex
     * @return array{status: string, readyForTransmission: false, structuralErrors: list<array{code: string, path: string, message: string}>, issues: list<array<string, mixed>>}
     */
    public function validate(array $bundle, array $sourceIndex = []): array
    {
        $errors = [];
        $entries = is_array($bundle['entry'] ?? null) ? array_values($bundle['entry']) : [];
        $fullUrls = [];

        if (($bundle['resourceType'] ?? null) !== 'Bundle') {
            $errors[] = $this->error('BUNDLE_RESOURCE_TYPE', 'bundle.resourceType', 'The preview root must be a FHIR Bundle.');
        }

        if (($bundle['type'] ?? null) !== 'collection') {
            $errors[] = $this->error('BUNDLE_TYPE', 'bundle.type', 'The local preview must remain a collection bundle.');
        }

        if (($entries[0]['resource']['resourceType'] ?? null) !== 'Composition') {
            $errors[] = $this->error('COMPOSITION_FIRST', 'bundle.entry.0', 'Composition must be the first preview resource.');
        }

        foreach ($entries as $index => $entry) {
            $fullUrl = $entry['fullUrl'] ?? null;
            $resourceId = $entry['resource']['id'] ?? null;

            if (! is_string($fullUrl) || ! str_starts_with($fullUrl, OutpatientPreviewUrl::BASE.'/')) {
                $errors[] = $this->error('FULL_URL_INVALID', "bundle.entry.{$index}.fullUrl", 'Every resource needs a non-resolving local preview fullUrl.');
            } elseif (in_array($fullUrl, $fullUrls, true)) {
                $errors[] = $this->error('FULL_URL_DUPLICATE', "bundle.entry.{$index}.fullUrl", 'Preview fullUrls must be unique.');
            } else {
                $fullUrls[] = $fullUrl;
            }

            if (! is_string($resourceId) || preg_match('/^[A-Za-z0-9\-.]{1,64}$/', $resourceId) !== 1) {
                $errors[] = $this->error('RESOURCE_ID_INVALID', "bundle.entry.{$index}.resource.id", 'Every resource needs a valid FHIR id.');
            }
        }

        foreach ($this->references($bundle) as $path => $reference) {
            if (str_starts_with($reference, OutpatientPreviewUrl::BASE.'/') && ! in_array($reference, $fullUrls, true)) {
                $errors[] = $this->error('REFERENCE_UNRESOLVED', $path, 'The local reference does not resolve inside this bundle.');
            }
        }

        return [
            'status' => 'PREVIEW_ONLY',
            'readyForTransmission' => false,
            'structuralErrors' => $errors,
            'issues' => [
                [
                    'code' => 'PROFILE_VALIDATION_NOT_RUN',
                    'severity' => 'warning',
                    'message' => 'SATUSEHAT profile validation has not been run; no conformance claim is made.',
                ],
                [
                    'code' => 'NATIONAL_IDENTIFIERS_ABSENT',
                    'severity' => 'warning',
                    'message' => 'Required national identifiers are intentionally absent from this synthetic local preview.',
                ],
                ...$this->textOnlyTerminologyIssues($entries, $sourceIndex),
            ],
        ];
    }

    /**
     * @param  list<mixed>  $entries
     * @param  list<array<string, string>>  $sourceIndex
     * @return list<array<string, mixed>>
     */
    private function textOnlyTerminologyIssues(array $entries, array $sourceIndex): array
    {
        $issues = [];

        foreach ($entries as $index => $entry) {
            if (! is_array($entry) || ! is_array($entry['resource'] ?? null)) {
                continue;
            }

            $resource = $entry['resource'];
            $field = match ($resource['resourceType'] ?? null) {
                'Condition', 'ServiceRequest', 'Observation', 'DiagnosticReport', 'Procedure' => 'code',
                'MedicationRequest', 'MedicationDispense' => 'medicationCodeableConcept',
                default => null,
            };

            if ($field === null) {
                continue;
            }

            $concept = $resource[$field] ?? null;
            $coding = is_array($concept) ? ($concept['coding'] ?? []) : [];

            if (! is_array($concept)
                || ! filled($concept['text'] ?? null)
                || ! is_array($coding)
                || $coding !== []) {
                continue;
            }

            $fullUrl = is_string($entry['fullUrl'] ?? null) ? $entry['fullUrl'] : '';
            $source = collect($sourceIndex)->firstWhere('fullUrl', $fullUrl);
            $issues[] = [
                'code' => 'LOCAL_TEXT_ONLY_TERMINOLOGY',
                'severity' => 'warning',
                'message' => 'The source concept remains local text because no approved external terminology mapping is available.',
                'sourcePath' => is_array($source) ? ($source['sourcePath'] ?? null) : null,
                'sourcePublicId' => is_array($source) ? ($source['sourcePublicId'] ?? null) : null,
                'bundlePath' => "bundle.entry.{$index}.resource.{$field}",
            ];
        }

        return $issues;
    }

    /** @return array{code: string, path: string, message: string} */
    private function error(string $code, string $path, string $message): array
    {
        return compact('code', 'path', 'message');
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, string>
     */
    private function references(array $value, string $path = 'bundle'): array
    {
        $references = [];

        foreach ($value as $key => $item) {
            $itemPath = $path.'.'.$key;

            if ($key === 'reference' && is_string($item)) {
                $references[$itemPath] = $item;
            } elseif (is_array($item)) {
                $references += $this->references($item, $itemPath);
            }
        }

        return $references;
    }
}
