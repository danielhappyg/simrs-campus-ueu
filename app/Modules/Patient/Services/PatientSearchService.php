<?php

namespace App\Modules\Patient\Services;

use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Models\SimulationSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PatientSearchService
{
    /**
     * @return Collection<int, SyntheticPatient>
     */
    public function search(SimulationSession $session, string $query, int $limit = 10): Collection
    {
        $normalized = $this->normalize($query);

        if (mb_strlen($normalized) < 2) {
            return collect();
        }

        return SyntheticPatient::query()
            ->where('session_id', $session->getKey())
            ->where(function (Builder $builder) use ($normalized): void {
                $builder
                    ->whereRaw('LOWER(full_name) LIKE ?', ['%'.$normalized.'%'])
                    ->orWhereHas('identifiers', function (Builder $identifierQuery) use ($normalized): void {
                        $identifierQuery->whereRaw('LOWER(value) LIKE ?', ['%'.$normalized.'%']);
                    });
            })
            ->with('identifiers')
            ->orderBy('full_name')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, SyntheticPatient>
     */
    public function possibleDuplicates(SimulationSession $session, string $fullName, string $birthDate): Collection
    {
        $normalizedName = $this->normalize($fullName);

        return SyntheticPatient::query()
            ->where('session_id', $session->getKey())
            ->where(function (Builder $builder) use ($normalizedName, $birthDate): void {
                $builder
                    ->whereRaw('LOWER(full_name) = ?', [$normalizedName])
                    ->orWhere('birth_date', $birthDate);
            })
            ->with('identifiers')
            ->lockForUpdate()
            ->get();
    }

    private function normalize(string $value): string
    {
        return Str::lower((string) preg_replace('/\s+/', ' ', trim($value)));
    }
}
