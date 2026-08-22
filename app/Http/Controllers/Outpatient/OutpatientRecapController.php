<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Encounter;
use App\Support\Authorization\Capability;
use App\Support\TeachingVocabulary;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OutpatientRecapController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        Gate::authorize(Capability::ENCOUNTER_LIST);

        $dateFrom = trim((string) $request->query('date_from', now()->toDateString()));
        $dateTo = trim((string) $request->query('date_to', now()->toDateString()));
        $clinic = trim((string) $request->query('clinic', ''));
        $payer = trim((string) $request->query('payer', ''));
        $origin = trim((string) $request->query('origin', ''));
        $careSetting = trim((string) $request->query('care_setting', Encounter::CARE_SETTING_OUTPATIENT));

        $query = Encounter::query()
            ->with('patient')
            ->whereHas('patient', fn ($patient) => $patient->where('is_synthetic', true));

        if ($careSetting !== '' && $careSetting !== 'ALL') {
            $query->where('care_setting', $careSetting);
        }

        if ($dateFrom !== '') {
            $query->whereDate('registered_at', '>=', $dateFrom);
        }

        if ($dateTo !== '') {
            $query->whereDate('registered_at', '<=', $dateTo);
        }

        if ($clinic !== '') {
            $query->where('clinic_name', $clinic);
        }

        if ($payer !== '') {
            $query->where('payer_type', $payer);
        }

        if ($origin === 'ONLINE') {
            $query->whereNotNull('booking_code')->where('booking_code', '!=', '');
        } elseif ($origin === 'WALK_IN') {
            $query->where(function ($inner): void {
                $inner->whereNull('booking_code')->orWhere('booking_code', '');
            });
        }

        $rows = $query
            ->orderByDesc('registered_at')
            ->limit(500)
            ->get();

        $summaries = array_values($rows->map(function (Encounter $encounter): array {
            $origin = filled($encounter->booking_code) ? 'ONLINE' : 'WALK_IN';

            return [
                'public_id' => $encounter->public_id,
                'registered_at' => $encounter->registered_at->toIso8601String(),
                'visit_date' => $encounter->visit_date?->toDateString(),
                'queue_number' => $encounter->queue_number,
                'care_setting' => $encounter->care_setting,
                'care_setting_label' => TeachingVocabulary::label(TeachingVocabulary::CARE_SETTING, $encounter->care_setting),
                'clinic_name' => $encounter->clinic_name,
                'doctor_name' => $encounter->doctor_name,
                'payer_type' => $encounter->payer_type,
                'payer_label' => TeachingVocabulary::label(TeachingVocabulary::PAYER, $encounter->payer_type),
                'booking_code' => $encounter->booking_code,
                'origin' => $origin,
                'origin_label' => TeachingVocabulary::label(TeachingVocabulary::ORIGIN, $origin),
                'status' => $encounter->status,
                'patient' => [
                    'medical_record_number' => $encounter->patient?->medical_record_number,
                    'full_name' => $encounter->patient?->full_name,
                ],
            ];
        })->all());

        if ($request->query('format') === 'csv') {
            $csv = $this->toCsv($summaries);

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="rekap-pendaftaran.csv"',
            ]);
        }

        $clinics = Clinic::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['name'])
            ->map(fn (Clinic $row): array => ['value' => $row->name, 'label' => $row->name])
            ->values()
            ->all();

        return Inertia::render('pendaftaran/rekap', [
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'clinic' => $clinic,
                'payer' => $payer,
                'origin' => $origin,
                'care_setting' => $careSetting,
            ],
            'rows' => $summaries,
            'totals' => [
                'all' => $rows->count(),
                'online' => $rows->filter(fn (Encounter $encounter): bool => filled($encounter->booking_code))->count(),
                'walk_in' => $rows->filter(fn (Encounter $encounter): bool => ! filled($encounter->booking_code))->count(),
            ],
            'clinicOptions' => $clinics,
            'payerOptions' => TeachingVocabulary::options(TeachingVocabulary::PAYER),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);
        fputcsv($handle, ['Waktu', 'Antrian', 'No RM', 'Nama', 'Asal', 'Kode booking', 'Poli/unit', 'Dokter', 'Penjamin', 'Status']);

        foreach ($rows as $row) {
            $patient = is_array($row['patient'] ?? null) ? $row['patient'] : [];
            fputcsv($handle, [
                (string) $row['registered_at'],
                (string) ($row['queue_number'] ?? ''),
                (string) ($patient['medical_record_number'] ?? ''),
                (string) ($patient['full_name'] ?? ''),
                (string) ($row['origin_label'] ?? $row['origin']),
                (string) ($row['booking_code'] ?? ''),
                (string) $row['clinic_name'],
                (string) ($row['doctor_name'] ?? ''),
                (string) ($row['payer_label'] ?? $row['payer_type']),
                (string) $row['status'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
