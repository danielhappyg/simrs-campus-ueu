<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Encounter;
use App\Support\Authorization\Capability;
use App\Support\Http\InertiaPagination;
use App\Support\TeachingVocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OutpatientRecapController extends Controller
{
    private const CSV_MAX_RANGE_DAYS = 31;

    public function index(Request $request): Response|StreamedResponse|RedirectResponse
    {
        Gate::authorize(Capability::ENCOUNTER_LIST);

        $dateFrom = trim((string) $request->query('date_from', now()->toDateString()));
        $dateTo = trim((string) $request->query('date_to', now()->toDateString()));
        $clinic = trim((string) $request->query('clinic', ''));
        $payer = trim((string) $request->query('payer', ''));
        $origin = trim((string) $request->query('origin', ''));
        $careSetting = trim((string) $request->query('care_setting', Encounter::CARE_SETTING_OUTPATIENT));
        $isCsv = $request->query('format') === 'csv';

        if ($isCsv) {
            $csvDateFrom = trim((string) $request->query->get('date_from', ''));
            $csvDateTo = trim((string) $request->query->get('date_to', ''));
            $csvValidation = Validator::make([
                'date_from' => $csvDateFrom,
                'date_to' => $csvDateTo,
            ], [
                'date_from' => ['required', 'date_format:Y-m-d'],
                'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            ]);

            if ($csvValidation->fails()) {
                return $this->rejectCsvExport($request, 'Tanggal awal dan akhir yang valid wajib dipilih untuk ekspor CSV.');
            }

            $rangeDays = CarbonImmutable::parse($csvDateFrom)->startOfDay()
                ->diffInDays(CarbonImmutable::parse($csvDateTo)->startOfDay()) + 1;
            if ($rangeDays > self::CSV_MAX_RANGE_DAYS) {
                return $this->rejectCsvExport(
                    $request,
                    'Ekspor CSV sinkron sementara dibatasi maksimal 31 hari. Persempit rentang tanggal.',
                );
            }
        }

        $dateValidation = Validator::make([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ], [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        if ($dateValidation->fails()) {
            return $this->rejectCsvExport($request, 'Tanggal filter tidak valid. Rentang dikembalikan ke hari ini.');
        }

        $query = Encounter::query()
            ->syntheticOnly()
            ->with('patient');

        if ($careSetting !== '' && $careSetting !== 'ALL') {
            $query->where('care_setting', $careSetting);
        }

        if ($dateFrom !== '') {
            $query->where(
                'registered_at',
                '>=',
                CarbonImmutable::parse($dateFrom, config('app.timezone'))->startOfDay(),
            );
        }

        if ($dateTo !== '') {
            $query->where(
                'registered_at',
                '<',
                CarbonImmutable::parse($dateTo, config('app.timezone'))->startOfDay()->addDay(),
            );
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

        if ($isCsv) {
            return $this->csvResponse($query);
        }

        $onlineTotal = (clone $query)
            ->whereNotNull('booking_code')
            ->where('booking_code', '!=', '')
            ->count();

        $page = $query
            ->orderByDesc('registered_at')
            ->orderByDesc('id')
            ->paginate(500)
            ->withQueryString();
        if ($redirect = InertiaPagination::redirectIfOutOfRange($page, $request)) {
            return $redirect;
        }
        $summaries = collect($page->items())
            ->map(fn (Encounter $encounter): array => $this->encounterSummary($encounter))
            ->values()
            ->all();

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
            'pagination' => InertiaPagination::from($page),
            'totals' => [
                'all' => $page->total(),
                'online' => $onlineTotal,
                'walk_in' => $page->total() - $onlineTotal,
            ],
            'clinicOptions' => $clinics,
            'payerOptions' => TeachingVocabulary::options(TeachingVocabulary::PAYER),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function encounterSummary(Encounter $encounter): array
    {
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
    }

    /**
     * @param  Builder<Encounter>  $query
     */
    private function csvResponse(Builder $query): StreamedResponse
    {
        $maximumId = (clone $query)->max('id');
        $exportCutoffId = $maximumId === null ? null : (int) $maximumId;

        return response()->streamDownload(function () use ($query, $exportCutoffId): void {
            $handle = fopen('php://output', 'wb');
            assert($handle !== false);
            fputcsv($handle, ['Waktu', 'Antrian', 'No RM', 'Nama', 'Asal', 'Kode booking', 'Poli/unit', 'Dokter', 'Penjamin', 'Status']);

            if ($exportCutoffId === null) {
                fclose($handle);

                return;
            }

            $encounters = $query
                ->where('id', '<=', $exportCutoffId)
                ->lazyByIdDesc(500);

            foreach ($encounters as $encounter) {
                $row = $this->encounterSummary($encounter);
                $patient = is_array($row['patient'] ?? null) ? $row['patient'] : [];
                fputcsv($handle, array_map($this->spreadsheetSafe(...), [
                    $row['registered_at'],
                    $row['queue_number'] ?? '',
                    $patient['medical_record_number'] ?? '',
                    $patient['full_name'] ?? '',
                    $row['origin_label'] ?? $row['origin'],
                    $row['booking_code'] ?? '',
                    $row['clinic_name'],
                    $row['doctor_name'] ?? '',
                    $row['payer_label'] ?? $row['payer_type'],
                    $row['status'],
                ]));
            }

            fclose($handle);
        }, 'rekap-pendaftaran.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function spreadsheetSafe(mixed $value): string
    {
        $text = (string) $value;

        if (preg_match('/^(?:[=+\-@]|[\t\r\n]|[[:space:]]+[=+\-@])/u', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }

    private function rejectCsvExport(Request $request, string $message): RedirectResponse
    {
        $requestedDateFrom = trim((string) $request->query->get('date_from', ''));
        $requestedDateTo = trim((string) $request->query->get('date_to', ''));
        $dateValidation = Validator::make([
            'date_from' => $requestedDateFrom,
            'date_to' => $requestedDateTo,
        ], [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        if ($dateValidation->fails()) {
            $requestedDateFrom = now()->toDateString();
            $requestedDateTo = now()->toDateString();
        }

        $query = array_filter([
            'date_from' => $requestedDateFrom,
            'date_to' => $requestedDateTo,
            'clinic' => trim((string) $request->query('clinic', '')),
            'payer' => trim((string) $request->query('payer', '')),
            'origin' => trim((string) $request->query('origin', '')),
            'care_setting' => trim((string) $request->query('care_setting', Encounter::CARE_SETTING_OUTPATIENT)),
        ], fn (string $value): bool => $value !== '');

        return redirect()
            ->route('pendaftaran.rekap', $query)
            ->with('error', $message);
    }
}
