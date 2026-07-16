<?php

namespace App\Modules\Coding\Enums;

enum CodingSuggestionOutcome: string
{
    case Candidates = 'CANDIDATES';
    case NoReliableCandidate = 'NO_RELIABLE_CANDIDATE';

    public function label(): string
    {
        return match ($this) {
            self::Candidates => 'Kandidat tersedia',
            self::NoReliableCandidate => 'Tidak ada kandidat andal',
        };
    }
}
