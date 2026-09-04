<?php

namespace App\Support\Clinical;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OutpatientTerminologyService
{
    public function verifySelection(string $system, string $code, string $display): bool
    {
        return Cache::remember($this->verificationCacheKey($system, $code, $display), 86400, function () use ($system, $code, $display): bool {
            if ($system === 'ICD-10') {
                try {
                    $response = Http::acceptJson()->timeout(8)->retry(1, 100)->get('https://icd.who.int/browse10/2010/en/JsonGetConcept', ['ConceptId' => $code, 'useHtml' => 'false']);
                } catch (Throwable) {
                    throw new OutpatientLifecycleDenial('terminology_unavailable', 'Layanan ICD-10 WHO sementara tidak tersedia.', 503);
                }
                if (! $response->successful()) {
                    return false;
                }
                $canonicalCode = $response->json('ID');
                $label = $response->json('label');
                if (! is_string($canonicalCode) || ! is_string($label)) {
                    return false;
                }
                $canonicalDisplay = trim(preg_replace('/^'.preg_quote($canonicalCode, '/').'\s+/', '', $label) ?? $label);

                return $canonicalCode === $code && $canonicalDisplay === $display;
            }
            foreach ($this->search($system, $code)['options'] as $option) {
                if (strcasecmp($option['code'], $code) === 0 && $option['display'] === $display) {
                    return true;
                }
            }

            return false;
        });
    }

    /** @return array{options:list<array{code:string,display:string,system:string}>,source:array{authority:string,dataset:string}} */
    public function search(string $system, string $query): array
    {
        if ($system === 'ICD-10') {
            return $this->searchWhoIcd10($query);
        }
        [$dataset, $url, $params] = match ($system) {
            'ICD-9-CM' => ['ICD-9-CM Procedures', 'https://clinicaltables.nlm.nih.gov/api/icd9cm_sg/v3/search', ['sf' => 'code_dotted,long_name', 'df' => 'code_dotted,long_name']],
            default => throw new OutpatientLifecycleDenial('validation_failed', 'Sistem terminologi tidak didukung.'),
        };
        try {
            $response = Http::acceptJson()->timeout(5)->retry(1, 100)->get($url, [...$params, 'terms' => $query, 'maxList' => 20]);
        } catch (Throwable) {
            throw new OutpatientLifecycleDenial('terminology_unavailable', 'Layanan terminologi resmi sementara tidak tersedia.', 503);
        }
        if (! $response->successful()) {
            throw new OutpatientLifecycleDenial('terminology_unavailable', 'Layanan terminologi resmi sementara tidak tersedia.', 503);
        }
        $rows = $response->json('3');
        if (! is_array($rows)) {
            throw new OutpatientLifecycleDenial('terminology_invalid_response', 'Respons layanan terminologi tidak valid.', 503);
        }
        $options = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row[0] ?? null) && is_string($row[1] ?? null)) {
                $option = ['code' => trim($row[0]), 'display' => trim($row[1]), 'system' => $system];
                $options[] = $option;
                Cache::put($this->verificationCacheKey($system, $option['code'], $option['display']), true, 86400);
            }
        }

        return ['options' => $options, 'source' => ['authority' => 'U.S. National Library of Medicine Clinical Tables', 'dataset' => $dataset]];
    }

    /** @return array{options:list<array{code:string,display:string,system:string}>,source:array{authority:string,dataset:string}} */
    private function searchWhoIcd10(string $query): array
    {
        try {
            $response = Http::asForm()->timeout(8)->retry(1, 100)->post('https://icd.who.int/browse10/2010/en/ACSearch', ['q' => $query]);
        } catch (Throwable) {
            throw new OutpatientLifecycleDenial('terminology_unavailable', 'Layanan ICD-10 WHO sementara tidak tersedia.', 503);
        }
        if (! $response->successful()) {
            throw new OutpatientLifecycleDenial('terminology_unavailable', 'Layanan ICD-10 WHO sementara tidak tersedia.', 503);
        }
        $options = [];
        $dom = new \DOMDocument;
        $body = trim($response->body());
        if ($body === '' || @$dom->loadHTML($body) === false) {
            throw new OutpatientLifecycleDenial('terminology_invalid_response', 'Respons layanan ICD-10 WHO tidak valid.', 503);
        }
        $xpath = new \DOMXPath($dom);
        $entities = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' oneentity ')]");
        if ($entities === false) {
            throw new OutpatientLifecycleDenial('terminology_invalid_response', 'Respons layanan ICD-10 WHO tidak valid.', 503);
        }
        foreach ($entities as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }
            // The WHO 2010 browser emits `thecode` as a boolean HTML attribute,
            // while the visual size token is held in the class attribute.
            $codeNodes = $xpath->query('.//*[@thecode]', $node);
            $titleNodes = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' titlelabel ')]", $node);
            $codeNode = $codeNodes === false ? null : $codeNodes->item(0);
            $titleNode = $titleNodes === false ? null : $titleNodes->item(0);
            $code = $codeNode instanceof \DOMNode ? trim($codeNode->textContent) : '';
            $display = $titleNode instanceof \DOMNode ? (preg_replace('/\s+/', ' ', trim($titleNode->textContent)) ?? '') : '';
            if ($code !== '' && $display !== '') {
                $option = ['code' => $code, 'display' => $display, 'system' => 'ICD-10'];
                $options[] = $option;
                Cache::put($this->verificationCacheKey('ICD-10', $code, $display), true, 86400);
            }
            if (count($options) >= 20) {
                break;
            }
        }

        return ['options' => $options, 'source' => ['authority' => 'World Health Organization ICD-10 Browser', 'dataset' => 'ICD-10 2010']];
    }

    private function verificationCacheKey(string $system, string $code, string $display): string
    {
        return 'outpatient-terminology:'.hash('sha256', $system.'|'.$code.'|'.$display);
    }
}
