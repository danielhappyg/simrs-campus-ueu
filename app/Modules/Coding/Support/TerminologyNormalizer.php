<?php

namespace App\Modules\Coding\Support;

use Normalizer;

class TerminologyNormalizer
{
    public function text(string $value): string
    {
        $normalized = Normalizer::normalize(mb_strtolower(trim($value)), Normalizer::FORM_KD);
        $normalized = is_string($normalized) ? $normalized : mb_strtolower(trim($value));
        $normalized = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    }

    public function code(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', trim($value)) ?? trim($value));
    }

    /** @return list<string> */
    public function tokens(string $value): array
    {
        $tokens = preg_split('/\s+/u', $this->text($value), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($tokens)) {
            return [];
        }

        return array_values(collect($tokens)
            ->filter(fn (string $token): bool => mb_strlen($token) >= 2)
            ->unique()
            ->values()
            ->all());
    }
}
