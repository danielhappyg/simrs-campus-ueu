<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientWard;
use App\Models\User;
use App\Support\Authorization\Capability;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @phpstan-type Filters array{q: string, ward_code: string, service_class: string, occupancy_state: string, master_state: string}
 * @phpstan-type BedProjection array{public_id: string, code: string, display_name: string, room_label: string, service_class: string, state: string, version: int, occupancy: array{state: string, occupant: array<string, mixed>|null}, actions: array{update_url: string|null, retire_url: string|null}}
 * @phpstan-type WardProjection array{public_id: string, code: string, display_name: string, state: string, version: int, summary: array{active_beds: int, occupied_beds: int, available_beds: int}, actions: array{update_url: string|null, retire_url: string|null, create_bed_url: string|null}, beds: list<BedProjection>}
 * @phpstan-type WardCandidate array{public_id: string, code: string, display_name: string, state: string, version: int, summary: array{active_beds: int, occupied_beds: int, available_beds: int}, actions: array{update_url: string|null, retire_url: string|null, create_bed_url: string|null}, beds: list<BedProjection>, _matches_query: bool}
 */
class InpatientOccupancyProjection
{
    public function __construct(private readonly InpatientMasterActorPolicy $actorPolicy) {}

    /**
     * @param  Filters  $filters
     * @return array<string, mixed>
     */
    public function forActor(User $actor, array $filters): array
    {
        return $this->consistentSnapshot(fn (): array => $this->project($actor, $filters));
    }

    /**
     * @param  Filters  $filters
     * @return array<string, mixed>
     */
    private function project(User $actor, array $filters): array
    {
        $canManage = $this->actorPolicy->canManage($actor);
        $canSeePatient = $actor->canCapability(Capability::PATIENT_VIEW);
        $canOpen = $actor->canCapability(Capability::ENCOUNTER_OPEN);
        $claims = Encounter::query()
            ->syntheticOnly()
            ->with('patient')
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
            ->whereNotNull('inpatient_bed_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Encounter $encounter): int => (int) $encounter->inpatient_bed_id);

        if ($claims->contains(fn (Collection $bedClaims): bool => $bedClaims->count() > 1)) {
            throw new \RuntimeException('Duplicate active inpatient bed claims detected; census projection refused.');
        }
        $claims = $claims->map(fn (Collection $bedClaims): Encounter => $bedClaims->sole());

        $this->afterClaimsSnapshotRead();

        $masterWards = InpatientWard::query()
            ->with(['beds' => fn ($query) => $query->orderBy('code')])
            ->orderBy('code')
            ->get();

        $filterOptions = [
            'wards' => $masterWards
                ->map(static fn (InpatientWard $ward): array => [
                    'value' => $ward->code,
                    'label' => $ward->display_name.' ('.$ward->code.')',
                ])
                ->values()
                ->all(),
            'service_classes' => $masterWards
                ->flatMap(static fn (InpatientWard $ward): Collection => $ward->beds)
                ->pluck('service_class')
                ->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->map(static fn (string $value): string => trim($value))
                ->unique()
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->map(static fn (string $value): array => ['value' => $value, 'label' => $value])
                ->all(),
        ];

        $wards = $masterWards
            ->map(fn (InpatientWard $ward) => $this->ward($ward, $claims, $actor, $canManage, $canSeePatient, $canOpen, $filters))
            ->filter(fn ($ward): bool => $this->shouldIncludeWard($ward, $filters, $canManage))
            ->map(fn ($ward): array => $this->publishedWard($ward))
            ->values();

        $beds = $wards->flatMap(fn (array $ward): array => $ward['beds']);

        return [
            'generated_at' => now((string) config('app.timezone', 'Asia/Jakarta'))->toIso8601String(),
            'filters' => $filters,
            'totals' => [
                'active_wards' => $wards->where('state', InpatientWard::STATE_ACTIVE)->count(),
                'active_beds' => $beds->where('state', InpatientBed::STATE_ACTIVE)->count(),
                'occupied_beds' => $beds->where('occupancy.state', 'OCCUPIED')->count(),
                'available_beds' => $beds->where('occupancy.state', 'AVAILABLE')->count(),
            ],
            'wards' => $wards->all(),
            'permissions' => [
                'can_view_census' => $this->actorPolicy->canView($actor),
                'can_manage_master' => $canManage,
            ],
            'commands' => [
                'create_ward_url' => $canManage ? route('manajemen-data.bangsal.wards.store') : null,
            ],
            'reason_options' => array_map(
                static fn (string $code): array => ['value' => $code, 'label' => InpatientMasterService::REASON_LABELS[$code]],
                InpatientMasterService::REASON_CODES,
            ),
            'filter_options' => $filterOptions,
            'read_error' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function consistentSnapshot(callable $callback): array
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() > 0) {
            return $callback();
        }

        if ($connection->getDriverName() === 'mysql') {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }

        $connection->beginTransaction();
        try {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $result = $callback();
            $connection->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->rollBackSnapshot($connection);

            throw $exception;
        }
    }

    private function rollBackSnapshot(Connection $connection): void
    {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }

    /**
     * Deterministic seam for independent-process snapshot verification.
     * Production projection performs no work here.
     */
    protected function afterClaimsSnapshotRead(): void
    {
        // Intentionally empty.
    }

    /**
     * @param  Collection<string, Encounter>  $claims
     * @param  Filters  $filters
     * @return WardCandidate
     */
    private function ward(InpatientWard $ward, Collection $claims, User $actor, bool $canManage, bool $canSeePatient, bool $canOpen, array $filters): array
    {
        $q = mb_strtolower($filters['q']);
        $wardMatchesQuery = $q === '' || str_contains(
            mb_strtolower($ward->code.' '.$ward->display_name),
            $q,
        );
        $beds = $ward->beds
            ->map(fn (InpatientBed $bed): array => $this->bed($bed, $claims->get($bed->id), $actor, $canManage, $canSeePatient, $canOpen))
            ->filter(fn (array $bed): bool => $this->bedMatches($bed, $filters, $wardMatchesQuery))
            ->values();

        return [
            'public_id' => $ward->public_id,
            'code' => $ward->code,
            'display_name' => $ward->display_name,
            'state' => $ward->state,
            'version' => $ward->version,
            'summary' => [
                'active_beds' => $beds->where('state', InpatientBed::STATE_ACTIVE)->count(),
                'occupied_beds' => $beds->where('occupancy.state', 'OCCUPIED')->count(),
                'available_beds' => $beds->where('occupancy.state', 'AVAILABLE')->count(),
            ],
            'actions' => [
                'update_url' => $canManage && $ward->state === InpatientWard::STATE_ACTIVE ? route('manajemen-data.bangsal.wards.update', $ward) : null,
                'retire_url' => $canManage && $ward->state === InpatientWard::STATE_ACTIVE ? route('manajemen-data.bangsal.wards.retire', $ward) : null,
                'create_bed_url' => $canManage && $ward->state === InpatientWard::STATE_ACTIVE ? route('manajemen-data.bangsal.wards.beds.store', $ward) : null,
            ],
            'beds' => array_values($beds->all()),
            '_matches_query' => $wardMatchesQuery,
        ];
    }

    /** @return BedProjection */
    private function bed(InpatientBed $bed, ?Encounter $claim, User $actor, bool $canManage, bool $canSeePatient, bool $canOpen): array
    {
        $occupancy = $claim instanceof Encounter
            ? 'OCCUPIED'
            : ($bed->state === InpatientBed::STATE_RETIRED ? 'RETIRED' : 'AVAILABLE');
        $occupant = null;
        if ($claim instanceof Encounter && $canSeePatient) {
            $occupant = [
                'patient_name' => $claim->patient?->full_name,
                'medical_record_number' => $claim->patient?->medical_record_number,
                'encounter_public_id' => $claim->public_id,
                'open_url' => $canOpen ? route('pemeriksaan.rawat-inap.show', $claim) : null,
            ];
        }

        return [
            'public_id' => $bed->public_id,
            'code' => $bed->code,
            'display_name' => $bed->display_name,
            'room_label' => $bed->room_label,
            'service_class' => $bed->service_class,
            'state' => $bed->state,
            'version' => $bed->version,
            'occupancy' => ['state' => $occupancy, 'occupant' => $occupant],
            'actions' => [
                'update_url' => $canManage && $bed->state === InpatientBed::STATE_ACTIVE ? route('manajemen-data.bangsal.beds.update', $bed) : null,
                'retire_url' => $canManage && $bed->state === InpatientBed::STATE_ACTIVE ? route('manajemen-data.bangsal.beds.retire', $bed) : null,
            ],
        ];
    }

    /**
     * @param  BedProjection  $bed
     * @param  Filters  $filters
     */
    private function bedMatches(array $bed, array $filters, bool $wardMatchesQuery): bool
    {
        $q = mb_strtolower($filters['q']);
        if (! $wardMatchesQuery && $q !== '' && ! str_contains(mb_strtolower(implode(' ', [$bed['code'], $bed['display_name'], $bed['room_label'], $bed['service_class']])), $q)) {
            return false;
        }

        return ($filters['service_class'] === '' || $bed['service_class'] === $filters['service_class'])
            && ($filters['occupancy_state'] === '' || $bed['occupancy']['state'] === $filters['occupancy_state'])
            && ($filters['master_state'] === '' || $bed['state'] === $filters['master_state']);
    }

    /**
     * @param  WardCandidate  $ward
     * @param  Filters  $filters
     */
    private function wardMatches(array $ward, array $filters): bool
    {
        return ($filters['ward_code'] === '' || $ward['code'] === InpatientWard::normalizeCode($filters['ward_code']))
            && ($filters['master_state'] === '' || $ward['state'] === $filters['master_state'] || $ward['beds'] !== []);
    }

    /**
     * @param  WardCandidate  $ward
     * @param  Filters  $filters
     */
    private function shouldIncludeWard(array $ward, array $filters, bool $canManage): bool
    {
        if (! $this->wardMatches($ward, $filters)) {
            return false;
        }

        if ($ward['beds'] !== []) {
            return true;
        }

        return $canManage
            && $ward['_matches_query']
            && $filters['service_class'] === ''
            && $filters['occupancy_state'] === '';
    }

    /**
     * @param  WardCandidate  $ward
     * @return WardProjection
     */
    private function publishedWard(array $ward): array
    {
        return [
            'public_id' => $ward['public_id'],
            'code' => $ward['code'],
            'display_name' => $ward['display_name'],
            'state' => $ward['state'],
            'version' => $ward['version'],
            'summary' => $ward['summary'],
            'actions' => $ward['actions'],
            'beds' => $ward['beds'],
        ];
    }
}
