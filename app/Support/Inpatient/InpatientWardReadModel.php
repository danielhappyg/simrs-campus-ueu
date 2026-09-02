<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientWard;

final class InpatientWardReadModel
{
    /**
     * @return list<array{
     *   public_id: string,
     *   code: string,
     *   display_name: string,
     *   beds: list<array{public_id: string, code: string, display_name: string, room_label: string, service_class: string}>
     * }>
     */
    public function registrationCatalogue(): array
    {
        $occupiedCodes = Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
            ->whereNotNull('bed_code')
            ->pluck('bed_code');

        $catalogue = [];
        $wards = InpatientWard::query()
            ->where('state', InpatientWard::STATE_ACTIVE)
            ->with(['beds' => fn ($query) => $query
                ->where('state', InpatientBed::STATE_ACTIVE)
                ->whereNotIn('code', $occupiedCodes)
                ->orderBy('code')])
            ->orderBy('code')
            ->get();

        foreach ($wards as $ward) {
            $beds = [];
            foreach ($ward->beds as $bed) {
                $beds[] = [
                    'public_id' => $bed->public_id,
                    'code' => $bed->code,
                    'display_name' => $bed->display_name,
                    'room_label' => $bed->room_label,
                    'service_class' => $bed->service_class,
                ];
            }
            $catalogue[] = [
                'public_id' => $ward->public_id,
                'code' => $ward->code,
                'display_name' => $ward->display_name,
                'beds' => $beds,
            ];
        }

        return $catalogue;
    }

    /** @return list<array{value: string, label: string}> */
    public function filterOptions(): array
    {
        $names = [];
        foreach (InpatientWard::query()->orderBy('code')->pluck('display_name') as $name) {
            if (is_string($name) && trim($name) !== '') {
                $names[trim($name)] = true;
            }
        }

        foreach (Encounter::query()
            ->syntheticOnly()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->whereNotNull('ward_name')
            ->distinct()
            ->pluck('ward_name') as $name) {
            if (is_string($name) && trim($name) !== '') {
                $names[trim($name)] = true;
            }
        }

        $values = array_keys($names);
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(static fn (string $name): array => ['value' => $name, 'label' => $name], $values);
    }
}
