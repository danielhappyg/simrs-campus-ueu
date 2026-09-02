<?php

namespace App\Http\Controllers\Rebuild;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Inpatient\InpatientMasterActorPolicy;
use App\Support\Inpatient\InpatientOccupancyProjection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RebuildHomeController extends Controller
{
    public function __construct(
        private readonly InpatientOccupancyProjection $occupancyProjection,
        private readonly InpatientMasterActorPolicy $inpatientActorPolicy,
    ) {}

    public function __invoke(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return Inertia::render('rebuild/home', [
            'encounters' => $this->encounterOverview(),
            'occupancy' => $this->occupancyOverview($actor),
            'actions' => $this->actions($actor),
        ]);
    }

    /** @return array{available: bool, totals: array{rawat_jalan: int|null, igd: int|null, rawat_inap: int|null}, read_error: string|null} */
    private function encounterOverview(): array
    {
        try {
            $todayStart = now((string) config('app.timezone', 'Asia/Jakarta'))->startOfDay();
            $tomorrowStart = $todayStart->copy()->addDay();
            $counts = Encounter::query()
                ->syntheticOnly()
                ->whereIn('status', Encounter::ACTIVE_STATUSES)
                ->where('registered_at', '>=', $todayStart)
                ->where('registered_at', '<', $tomorrowStart)
                ->selectRaw('care_setting, COUNT(*) AS aggregate')
                ->groupBy('care_setting')
                ->pluck('aggregate', 'care_setting');

            return [
                'available' => true,
                'totals' => [
                    'rawat_jalan' => (int) ($counts[Encounter::CARE_SETTING_OUTPATIENT] ?? 0),
                    'igd' => (int) ($counts[Encounter::CARE_SETTING_EMERGENCY] ?? 0),
                    'rawat_inap' => (int) ($counts[Encounter::CARE_SETTING_INPATIENT] ?? 0),
                ],
                'read_error' => null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'available' => false,
                'totals' => [
                    'rawat_jalan' => null,
                    'igd' => null,
                    'rawat_inap' => null,
                ],
                'read_error' => 'Ringkasan kunjungan belum dapat dimuat. Silakan coba lagi.',
            ];
        }
    }

    /**
     * @return array{available: bool, totals: array{active_wards: int, active_beds: int, occupied_beds: int, available_beds: int}, read_error: string|null}|null
     */
    private function occupancyOverview(User $actor): ?array
    {
        if (! $this->inpatientActorPolicy->canView($actor)) {
            return null;
        }

        try {
            $projection = $this->occupancyProjection->forActor($actor, [
                'q' => '',
                'ward_code' => '',
                'service_class' => '',
                'occupancy_state' => '',
                'master_state' => '',
            ]);

            return [
                'available' => true,
                'totals' => [
                    'active_wards' => (int) data_get($projection, 'totals.active_wards', 0),
                    'active_beds' => (int) data_get($projection, 'totals.active_beds', 0),
                    'occupied_beds' => (int) data_get($projection, 'totals.occupied_beds', 0),
                    'available_beds' => (int) data_get($projection, 'totals.available_beds', 0),
                ],
                'read_error' => null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'available' => false,
                'totals' => [
                    'active_wards' => 0,
                    'active_beds' => 0,
                    'occupied_beds' => 0,
                    'available_beds' => 0,
                ],
                'read_error' => 'Status hunian rawat inap belum dapat dimuat. Silakan coba lagi.',
            ];
        }
    }

    /** @return list<array{setting: string, kind: string, label: string, href: string}> */
    private function actions(User $actor): array
    {
        $actions = [];
        $canListEncounters = $actor->canCapability(Capability::ENCOUNTER_LIST);
        $canOpenRegistration = $canListEncounters
            && $actor->canCapability(Capability::PATIENT_SEARCH);

        if ($canOpenRegistration) {
            $actions[] = $this->action('rawat_jalan', 'registration', 'Buka pendaftaran', 'pendaftaran.rawat-jalan.index');
            $actions[] = $this->action('igd', 'registration', 'Buka pendaftaran', 'pendaftaran.igd.index');
            $actions[] = $this->action('rawat_inap', 'registration', 'Buka pendaftaran', 'pendaftaran.rawat-inap.index');
        }

        if ($canListEncounters) {
            $actions[] = $this->action('rawat_jalan', 'examination', 'Buka pemeriksaan', 'pemeriksaan.rawat-jalan.index');
            $actions[] = $this->action('igd', 'examination', 'Buka pemeriksaan', 'pemeriksaan.igd.index');
            $actions[] = $this->action('rawat_inap', 'examination', 'Buka pemeriksaan', 'pemeriksaan.rawat-inap.index');
        }

        if ($canListEncounters && $actor->canCapability(Capability::RMIK_REVIEW)) {
            $actions[] = $this->action('rawat_jalan', 'medical_record', 'Buka review RM', 'rm.rawat-jalan.index');
        }

        if ($this->inpatientActorPolicy->canView($actor)) {
            $actions[] = $this->action('occupancy', 'occupancy', 'Buka sensus tempat tidur', 'manajemen-data.bangsal.index');
        }

        return $actions;
    }

    /** @return array{setting: string, kind: string, label: string, href: string} */
    private function action(string $setting, string $kind, string $label, string $routeName): array
    {
        return [
            'setting' => $setting,
            'kind' => $kind,
            'label' => $label,
            'href' => route($routeName),
        ];
    }
}
