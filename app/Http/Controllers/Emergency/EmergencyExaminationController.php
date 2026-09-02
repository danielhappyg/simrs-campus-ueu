<?php

namespace App\Http\Controllers\Emergency;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Clinical\LegacyLaboratoryCompatibilityProjection;
use App\Support\Emergency\EmergencyProjection;
use App\Support\Laboratory\LaboratoryProjection;
use App\Support\Pharmacy\PharmacyProjection;
use App\Support\Radiology\RadiologyProjection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EmergencyExaminationController extends Controller
{
    public function __construct(
        private readonly EmergencyProjection $emergencyProjection,
        private readonly RadiologyProjection $radiologyProjection,
        private readonly LaboratoryProjection $laboratoryProjection,
        private readonly PharmacyProjection $pharmacyProjection,
        private readonly LegacyLaboratoryCompatibilityProjection $legacyLaboratoryProjection,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Capability::ENCOUNTER_LIST);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $q = trim((string) $request->query('q', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $payer = trim((string) $request->query('payer', ''));

        $encounters = [];
        try {
            $query = Encounter::query()
                ->syntheticOnly()
                ->with(['patient', 'emergencyTriageAssessments' => fn ($triage) => $triage->orderBy('assessment_number')])
                ->where('care_setting', Encounter::CARE_SETTING_EMERGENCY)
                ->whereIn('status', Encounter::EXAMINATION_STATUSES);

            if ($dateFrom !== '') {
                $query->whereDate('registered_at', '>=', $dateFrom);
            }

            if ($dateTo !== '') {
                $query->whereDate('registered_at', '<=', $dateTo);
            }

            if ($payer !== '' && in_array($payer, Encounter::PAYER_VALUES, true)) {
                $query->where('payer_type', $payer);
            }

            if ($q !== '') {
                $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $query->whereHas('patient', function ($patientQuery) use ($q, $like): void {
                    $patientQuery->where('full_name', $like, '%'.$q.'%')
                        ->orWhere('medical_record_number', $like, '%'.$q.'%');
                });
            }

            $encounters = $query
                ->orderBy('registered_at')
                ->limit(100)
                ->get()
                ->map(fn (Encounter $encounter): array => $this->emergencyProjection->worklistEncounter($encounter, $actor))
                ->all();
        } catch (\Throwable $e) {
            report($e);
        }

        return Inertia::render('pemeriksaan/igd/index', [
            'indexPath' => '/pemeriksaan/igd',
            'showPathPrefix' => '/pemeriksaan/igd',
            'encounters' => $encounters,
            'payerOptions' => [
                ['value' => Encounter::PAYER_UMUM, 'label' => 'Umum'],
                ['value' => Encounter::PAYER_BPJS, 'label' => 'BPJS'],
                ['value' => Encounter::PAYER_LAINNYA, 'label' => 'Lainnya'],
            ],
            'filters' => [
                'q' => $q,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'payer' => $payer,
            ],
            'canOpen' => $request->user()?->canCapability(Capability::ENCOUNTER_OPEN) ?? false,
        ]);
    }

    public function show(Request $request, Encounter $encounter): Response
    {
        Gate::authorize(Capability::ENCOUNTER_OPEN);

        abort_unless(
            $encounter->care_setting === Encounter::CARE_SETTING_EMERGENCY,
            404,
        );

        $encounter->load('patient');

        $user = $request->user();
        assert($user !== null);

        $emergency = $this->emergencyProjection->encounter($encounter, $user);

        return Inertia::render('pemeriksaan/igd/show', [
            'encounter' => $this->emergencyProjection->worklistEncounter($encounter, $user),
            ...$emergency,
            'radiology' => $this->radiologyProjection->encounter($encounter, $user),
            'laboratory' => $this->laboratoryEncounter($encounter, $user),
            'pharmacy' => $this->pharmacyProjection->encounter($encounter, $user),
        ]);
    }

    /** @return array<string, mixed> */
    private function laboratoryEncounter(Encounter $encounter, User $actor): array
    {
        $projection = $this->laboratoryProjection->encounter($encounter, $actor);
        $projection['orders'] = [...$projection['orders'], ...$this->legacyLaboratoryProjection->encounter($encounter, $actor)];

        return $projection;
    }

    public function storeEntry(Request $request, Encounter $encounter): never
    {
        abort(410, 'Alur tulis catatan IGD lama telah ditutup. Gunakan dokumentasi IGD terstruktur.');
    }
}
