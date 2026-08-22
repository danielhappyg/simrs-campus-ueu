<?php

namespace App\Http\Controllers\Emergency;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Support\Authorization\Capability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EmergencyTriageController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Capability::ENCOUNTER_LIST);

        $q = trim((string) $request->query('q', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $payer = trim((string) $request->query('payer', ''));

        $encounters = [];

        try {
            $query = Encounter::query()
                ->with('patient')
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
                ->map(fn (Encounter $encounter): array => [
                    'public_id' => $encounter->public_id,
                    'status' => $encounter->status,
                    'clinic_name' => $encounter->clinic_name,
                    'doctor_name' => $encounter->doctor_name,
                    'schedule_label' => $encounter->schedule_label,
                    'payer_type' => $encounter->payer_type,
                    'case_type' => $encounter->case_type,
                    'accident_type' => $encounter->accident_type,
                    'queue_number' => $encounter->queue_number,
                    'registered_at' => $encounter->registered_at->toIso8601String(),
                    'visit_date' => $encounter->visit_date?->toDateString(),
                    'chief_complaint' => $encounter->chief_complaint,
                    'patient' => [
                        'public_id' => $encounter->patient?->public_id,
                        'medical_record_number' => $encounter->patient?->medical_record_number,
                        'full_name' => $encounter->patient?->full_name,
                        'date_of_birth' => $encounter->patient?->date_of_birth->toDateString(),
                        'sex' => $encounter->patient?->sex,
                    ],
                ])
                ->all();
        } catch (\Throwable $e) {
            report($e);
        }

        return Inertia::render('pemeriksaan/rawat-jalan/index', [
            'variant' => 'triage',
            'indexPath' => '/pemeriksaan/triage',
            'showPathPrefix' => '/pemeriksaan/igd',
            'encounters' => $encounters,
            'clinics' => [],
            'payerOptions' => [
                ['value' => Encounter::PAYER_UMUM, 'label' => 'Umum'],
                ['value' => Encounter::PAYER_BPJS, 'label' => 'BPJS'],
                ['value' => Encounter::PAYER_LAINNYA, 'label' => 'Lainnya'],
            ],
            'filters' => [
                'q' => $q,
                'clinic' => '',
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'payer' => $payer,
            ],
            'canOpen' => $request->user()?->canCapability(Capability::ENCOUNTER_OPEN) ?? false,
        ]);
    }
}
