<?php

namespace App\Modules\Coding\Enums;

enum CodingConfidenceBand: string
{
    case Exact = 'EXACT';
    case StrongMatch = 'STRONG_MATCH';
    case ReviewRequired = 'REVIEW_REQUIRED';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Cocok tepat',
            self::StrongMatch => 'Kecocokan kuat',
            self::ReviewRequired => 'Perlu telaah',
        };
    }
}
