<?php

namespace App\Modules\Interoperability\Support;

final class FhirPreviewValidator
{
    /**
     * @param  array<string, mixed>  $bundle
     * @return array{status: string, readyForTransmission: false, structuralErrors: list<array{code: string, path: string, message: string}>, issues: list<array{code: string, severity: string, message: string}>}
     */
    public function validate(array $bundle): array
    {
        $errors = [];
        $entries = is_array($bundle['entry'] ?? null) ? $bundle['entry'] : [];
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
            ],
        ];
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
