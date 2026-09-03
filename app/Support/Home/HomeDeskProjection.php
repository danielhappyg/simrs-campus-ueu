<?php

namespace App\Support\Home;

use App\Models\Encounter;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Inpatient\InpatientMasterActorPolicy;
use App\Support\Inpatient\InpatientOccupancyProjection;
use Throwable;

final class HomeDeskProjection
{
    /**
     * @var list<array{
     *     id: string,
     *     label: string,
     *     hint: string,
     *     tone: 'navy'|'blue'|'teal'|'orange'|'slate',
     *     route: string,
     *     gate: array{type: 'capabilities', all: list<string>}|array{type: 'occupancy_view'},
     *     count: array{type: 'census', care_setting: string, status: string}|array{type: 'occupied_beds'}
     * }>
     */
    private const QUEUE_REGISTRY = [
        [
            'id' => 'queue.registered.rj',
            'label' => 'Terdaftar RJ',
            'hint' => 'Pasien poliklinik yang sudah didaftarkan hari ini.',
            'tone' => 'blue',
            'route' => 'pendaftaran.rawat-jalan.index',
            'gate' => [
                'type' => 'capabilities',
                'all' => [Capability::PATIENT_SEARCH, Capability::ENCOUNTER_LIST],
            ],
            'count' => [
                'type' => 'census',
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
                'status' => Encounter::STATUS_REGISTERED,
            ],
        ],
        [
            'id' => 'queue.in_exam.rj',
            'label' => 'Dalam pemeriksaan RJ',
            'hint' => 'Kunjungan poliklinik yang sedang dilayani.',
            'tone' => 'navy',
            'route' => 'pemeriksaan.rawat-jalan.index',
            'gate' => [
                'type' => 'capabilities',
                'all' => [Capability::ENCOUNTER_LIST],
            ],
            'count' => [
                'type' => 'census',
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
                'status' => Encounter::STATUS_IN_EXAMINATION,
            ],
        ],
        [
            'id' => 'queue.ready_rm.rj',
            'label' => 'Siap review RM',
            'hint' => 'Berkas rawat jalan siap ditinjau RMIK.',
            'tone' => 'orange',
            'route' => 'rm.rawat-jalan.index',
            'gate' => [
                'type' => 'capabilities',
                'all' => [Capability::ENCOUNTER_LIST, Capability::RMIK_REVIEW],
            ],
            'count' => [
                'type' => 'census',
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
                'status' => Encounter::STATUS_READY_FOR_RM,
            ],
        ],
        [
            'id' => 'queue.in_exam.igd',
            'label' => 'Pemeriksaan IGD',
            'hint' => 'Pasien IGD yang sedang diperiksa.',
            'tone' => 'navy',
            'route' => 'pemeriksaan.igd.index',
            'gate' => [
                'type' => 'capabilities',
                'all' => [Capability::ENCOUNTER_LIST],
            ],
            'count' => [
                'type' => 'census',
                'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
                'status' => Encounter::STATUS_IN_EXAMINATION,
            ],
        ],
        [
            'id' => 'queue.in_exam.ri',
            'label' => 'Pemeriksaan RI',
            'hint' => 'Pasien bangsal yang sedang dirawat.',
            'tone' => 'teal',
            'route' => 'pemeriksaan.rawat-inap.index',
            'gate' => [
                'type' => 'capabilities',
                'all' => [Capability::ENCOUNTER_LIST],
            ],
            'count' => [
                'type' => 'census',
                'care_setting' => Encounter::CARE_SETTING_INPATIENT,
                'status' => Encounter::STATUS_IN_EXAMINATION,
            ],
        ],
        [
            'id' => 'queue.ready_rm.ri',
            'label' => 'Siap review RM RI',
            'hint' => 'Episode rawat inap siap ditinjau RMIK.',
            'tone' => 'teal',
            'route' => 'rm.rawat-inap.index',
            'gate' => [
                'type' => 'capabilities',
                'all' => [Capability::ENCOUNTER_LIST, Capability::RMIK_REVIEW],
            ],
            'count' => [
                'type' => 'census',
                'care_setting' => Encounter::CARE_SETTING_INPATIENT,
                'status' => Encounter::STATUS_READY_FOR_RM,
            ],
        ],
        [
            'id' => 'queue.occupancy',
            'label' => 'Sensus tempat tidur',
            'hint' => 'Tempat tidur terisi pada bangsal terkelola.',
            'tone' => 'slate',
            'route' => 'manajemen-data.bangsal.index',
            'gate' => ['type' => 'occupancy_view'],
            'count' => ['type' => 'occupied_beds'],
        ],
    ];

    private const SETTING_KEYS = [
        'rawat_jalan' => Encounter::CARE_SETTING_OUTPATIENT,
        'igd' => Encounter::CARE_SETTING_EMERGENCY,
        'rawat_inap' => Encounter::CARE_SETTING_INPATIENT,
    ];

    public function __construct(
        private readonly InpatientOccupancyProjection $occupancyProjection,
        private readonly InpatientMasterActorPolicy $inpatientActorPolicy,
    ) {}

    /**
     * @return array{
     *     census: array{available: bool, read_error: string|null, by_setting: array<string, array{registered: int|null, in_examination: int|null, ready_for_rm: int|null, total_active: int|null}>},
     *     queues: list<array{id: string, label: string, hint: string, count: int|null, href: string, tone: string}>,
     *     occupancy: array{available: bool, totals: array{active_wards: int, active_beds: int, occupied_beds: int, available_beds: int}, read_error: string|null}|null,
     *     actions: list<array{setting: string, kind: string, label: string, href: string}>
     * }
     */
    public function forActor(User $actor): array
    {
        $matrix = $this->statusMatrix();
        $occupancy = $this->occupancyOverview($actor);

        return [
            'census' => $this->censusFromMatrix($matrix),
            'queues' => $this->queues($actor, $matrix, $occupancy),
            'occupancy' => $occupancy,
            'actions' => $this->actions($actor),
        ];
    }

    /**
     * @return array{available: bool, rows: array<string, array<string, int>>, read_error: string|null}
     */
    private function statusMatrix(): array
    {
        try {
            $todayStart = now((string) config('app.timezone', 'Asia/Jakarta'))->startOfDay();
            $tomorrowStart = $todayStart->copy()->addDay();

            $rows = Encounter::query()
                ->syntheticOnly()
                ->whereIn('status', Encounter::ACTIVE_STATUSES)
                ->where('registered_at', '>=', $todayStart)
                ->where('registered_at', '<', $tomorrowStart)
                ->selectRaw('care_setting, status, COUNT(*) AS aggregate')
                ->groupBy('care_setting', 'status')
                ->get();

            $matrix = [];
            foreach ($rows as $row) {
                $attributes = $row->getAttributes();
                $setting = (string) ($attributes['care_setting'] ?? '');
                $status = (string) ($attributes['status'] ?? '');
                $matrix[$setting][$status] = (int) ($attributes['aggregate'] ?? 0);
            }

            return [
                'available' => true,
                'rows' => $matrix,
                'read_error' => null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'available' => false,
                'rows' => [],
                'read_error' => 'Ringkasan kunjungan belum dapat dimuat. Silakan coba lagi.',
            ];
        }
    }

    /**
     * @param  array{available: bool, rows: array<string, array<string, int>>, read_error: string|null}  $matrix
     * @return array{available: bool, read_error: string|null, by_setting: array<string, array{registered: int|null, in_examination: int|null, ready_for_rm: int|null, total_active: int|null}>}
     */
    private function censusFromMatrix(array $matrix): array
    {
        $bySetting = [];
        foreach (self::SETTING_KEYS as $key => $careSetting) {
            if (! $matrix['available']) {
                $bySetting[$key] = [
                    'registered' => null,
                    'in_examination' => null,
                    'ready_for_rm' => null,
                    'total_active' => null,
                ];

                continue;
            }

            $registered = (int) ($matrix['rows'][$careSetting][Encounter::STATUS_REGISTERED] ?? 0);
            $inExamination = (int) ($matrix['rows'][$careSetting][Encounter::STATUS_IN_EXAMINATION] ?? 0);
            $readyForRm = (int) ($matrix['rows'][$careSetting][Encounter::STATUS_READY_FOR_RM] ?? 0);

            $bySetting[$key] = [
                'registered' => $registered,
                'in_examination' => $inExamination,
                'ready_for_rm' => $readyForRm,
                'total_active' => $registered + $inExamination + $readyForRm,
            ];
        }

        return [
            'available' => $matrix['available'],
            'read_error' => $matrix['read_error'],
            'by_setting' => $bySetting,
        ];
    }

    /**
     * @param  array{available: bool, rows: array<string, array<string, int>>, read_error: string|null}  $matrix
     * @param  array{available: bool, totals: array{active_wards: int, active_beds: int, occupied_beds: int, available_beds: int}, read_error: string|null}|null  $occupancy
     * @return list<array{id: string, label: string, hint: string, count: int|null, href: string, tone: string}>
     */
    private function queues(User $actor, array $matrix, ?array $occupancy): array
    {
        $queues = [];

        foreach (self::QUEUE_REGISTRY as $definition) {
            if (! $this->authorized($actor, $definition['gate'])) {
                continue;
            }

            $queues[] = [
                'id' => $definition['id'],
                'label' => $definition['label'],
                'hint' => $definition['hint'],
                'count' => $this->queueCount($definition['count'], $matrix, $occupancy),
                'href' => route($definition['route']),
                'tone' => $definition['tone'],
            ];
        }

        return $queues;
    }

    /**
     * @param  array{type: 'capabilities', all: list<string>}|array{type: 'occupancy_view'}  $gate
     */
    private function authorized(User $actor, array $gate): bool
    {
        return match ($gate['type']) {
            'capabilities' => $this->hasAllCapabilities($actor, $gate['all']),
            'occupancy_view' => $this->inpatientActorPolicy->canView($actor),
        };
    }

    /** @param list<string> $capabilities */
    private function hasAllCapabilities(User $actor, array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (! $actor->canCapability($capability)) {
                return false;
            }
        }

        return $capabilities !== [];
    }

    /**
     * @param  array{type: 'census', care_setting: string, status: string}|array{type: 'occupied_beds'}  $count
     * @param  array{available: bool, rows: array<string, array<string, int>>, read_error: string|null}  $matrix
     * @param  array{available: bool, totals: array{active_wards: int, active_beds: int, occupied_beds: int, available_beds: int}, read_error: string|null}|null  $occupancy
     */
    private function queueCount(array $count, array $matrix, ?array $occupancy): ?int
    {
        if ($count['type'] === 'census') {
            return $matrix['available']
                ? (int) ($matrix['rows'][$count['care_setting']][$count['status']] ?? 0)
                : null;
        }

        return $occupancy !== null && $occupancy['available']
            ? (int) $occupancy['totals']['occupied_beds']
            : null;
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
            $actions[] = $this->action('rawat_inap', 'medical_record', 'Buka review RM RI', 'rm.rawat-inap.index');
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
