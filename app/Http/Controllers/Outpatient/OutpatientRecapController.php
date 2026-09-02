<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Encounter;
use App\Support\Authorization\Capability;
use App\Support\Http\InertiaPagination;
use App\Support\TeachingVocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class OutpatientRecapController extends Controller
{
    private const CSV_MAX_RANGE_DAYS = 31;

    public function index(Request $request): Response|HttpResponse|RedirectResponse
    {
        Gate::authorize(Capability::ENCOUNTER_LIST);

        $dateFrom = trim((string) $request->query('date_from', now()->toDateString()));
        $dateTo = trim((string) $request->query('date_to', now()->toDateString()));
        $clinic = trim((string) $request->query('clinic', ''));
        $payer = trim((string) $request->query('payer', ''));
        $origin = trim((string) $request->query('origin', ''));
        $careSetting = trim((string) $request->query('care_setting', Encounter::CARE_SETTING_OUTPATIENT));
        $status = trim((string) $request->query('status', ''));
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

        $controlQuery = clone $query;
        $statusValues = array_values(array_unique([
            ...Encounter::ACTIVE_STATUSES,
            ...Encounter::TERMINAL_STATUSES,
        ]));
        if ($status !== '' && in_array($status, $statusValues, true)) {
            $query->where('status', $status);
        } else {
            $status = '';
        }

        if ($isCsv) {
            return $this->csvResponse($query, $request);
        }

        $cancelledTotal = $controlQuery
            ->where('status', Encounter::STATUS_CANCELLED)
            ->count();

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
                'status' => $status,
            ],
            'rows' => $summaries,
            'pagination' => InertiaPagination::from($page),
            'totals' => [
                'all' => $page->total(),
                'online' => $onlineTotal,
                'walk_in' => $page->total() - $onlineTotal,
                'cancelled' => $cancelledTotal,
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
            'status_label' => match ($encounter->status) {
                Encounter::STATUS_REGISTERED => 'Terdaftar',
                Encounter::STATUS_IN_EXAMINATION => 'Dalam pemeriksaan',
                Encounter::STATUS_READY_FOR_RM => 'Siap RM',
                Encounter::STATUS_CLOSED => 'Selesai',
                Encounter::STATUS_CANCELLED => 'Dibatalkan',
                default => $encounter->status,
            },
            'patient' => [
                'medical_record_number' => $encounter->patient?->medical_record_number,
                'full_name' => $encounter->patient?->full_name,
            ],
        ];
    }

    /**
     * @param  Builder<Encounter>  $query
     */
    private function csvResponse(Builder $query, Request $request): HttpResponse|RedirectResponse
    {
        $userKey = hash('sha256', (string) $request->user()->public_id);
        $userAttempts = max(1, (int) config('simulation.recap_csv_user_attempts_per_minute', 3));
        $globalAttempts = max(1, (int) config('simulation.recap_csv_global_attempts_per_minute', 30));
        $maximumExecutionSeconds = min(300, max(1, (int) config('simulation.recap_csv_max_execution_seconds', 30)));
        // The lease must outlive every permitted synchronous build; download speed is outside the lease.
        $lockSeconds = max(
            $maximumExecutionSeconds + 5,
            max(1, (int) config('simulation.recap_csv_lock_seconds', 120)),
        );

        if (! RateLimiter::attempt('recap-csv:user:'.$userKey, $userAttempts, fn (): bool => true, 60)
            || ! RateLimiter::attempt('recap-csv:global', $globalAttempts, fn (): bool => true, 60)
        ) {
            return $this->rejectCsvExport(
                $request,
                'Batas permintaan ekspor CSV tercapai. Tunggu satu menit sebelum mencoba kembali.',
            );
        }

        $userLock = Cache::lock(
            'recap-csv:active:'.$userKey,
            $lockSeconds,
        );

        if (! $userLock->get()) {
            return $this->rejectCsvExport(
                $request,
                'Satu ekspor CSV untuk akun ini masih berjalan. Tunggu hingga selesai.',
            );
        }

        $globalLock = $this->acquireGlobalExportSlot(
            max(1, (int) config('simulation.recap_csv_max_active_exports', 2)),
            $lockSeconds,
        );
        if ($globalLock === null) {
            $this->releaseExportLock($userLock);

            return $this->rejectCsvExport(
                $request,
                'Batas ekspor CSV bersamaan tercapai. Tunggu hingga salah satu ekspor selesai.',
            );
        }

        try {
            $maximumRows = max(1, (int) config('simulation.recap_csv_max_rows', 5000));
            [$csv, $error] = $this->buildCsv(
                $query,
                $maximumRows,
                max(1, (int) config('simulation.recap_csv_max_bytes', 10_000_000)),
                microtime(true) + $maximumExecutionSeconds,
                $maximumExecutionSeconds,
            );
            if ($error !== null) {
                return $this->rejectCsvExport($request, $error);
            }
        } finally {
            $this->releaseExportLock($globalLock);
            $this->releaseExportLock($userLock);
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="rekap-pendaftaran.csv"',
            'Content-Length' => (string) strlen($csv),
        ]);
    }

    private function acquireGlobalExportSlot(int $maximumActiveExports, int $lockSeconds): ?Lock
    {
        for ($slot = 1; $slot <= $maximumActiveExports; $slot++) {
            $lock = Cache::lock('recap-csv:active:global:'.$slot, $lockSeconds);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    /**
     * @param  Builder<Encounter>  $query
     * @return array{string, ?string}
     */
    private function buildCsv(
        Builder $query,
        int $maximumRows,
        int $maximumBytes,
        float $deadline,
        int $maximumExecutionSeconds,
    ): array {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $this->buildCsvContents($query, $maximumRows, $maximumBytes, $deadline, $maximumExecutionSeconds);
        }

        try {
            return DB::transaction(function () use (
                $query,
                $maximumRows,
                $maximumBytes,
                $deadline,
                $maximumExecutionSeconds,
            ): array {
                DB::statement('SET LOCAL statement_timeout = '.$this->postgresStatementTimeoutMilliseconds($maximumExecutionSeconds));

                return $this->buildCsvContents(
                    $query,
                    $maximumRows,
                    $maximumBytes,
                    $deadline,
                    $maximumExecutionSeconds,
                );
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'statement timeout')) {
                return ['', $this->csvExecutionLimitMessage($maximumExecutionSeconds)];
            }

            throw $exception;
        }
    }

    /**
     * @param  Builder<Encounter>  $query
     * @return array{string, ?string}
     */
    private function buildCsvContents(
        Builder $query,
        int $maximumRows,
        int $maximumBytes,
        float $deadline,
        int $maximumExecutionSeconds,
    ): array {
        $exportCutoffId = (int) ((clone $query)->toBase()->max('id') ?? 0);
        if ($this->csvDeadlineExceeded($deadline)) {
            return ['', $this->csvExecutionLimitMessage($maximumExecutionSeconds)];
        }

        $exceedsMaximumRows = $exportCutoffId > 0
            && (clone $query)
                ->where('id', '<=', $exportCutoffId)
                ->reorder('id')
                ->offset($maximumRows)
                ->limit(1)
                ->exists();
        if ($exceedsMaximumRows) {
            return ['', sprintf(
                'Ekspor CSV sinkron dibatasi maksimal %s baris. Persempit filter sebelum mencoba kembali.',
                number_format($maximumRows, 0, ',', '.'),
            )];
        }
        if ($this->csvDeadlineExceeded($deadline)) {
            return ['', $this->csvExecutionLimitMessage($maximumExecutionSeconds)];
        }

        $handle = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('Keluaran CSV tidak dapat dibuka.');
        }

        try {
            $error = $this->writeCsvRow(
                $handle,
                ['Waktu', 'Antrian', 'No RM', 'Nama', 'Asal', 'Kode booking', 'Poli/unit', 'Dokter', 'Penjamin', 'Status kunjungan', 'Kode status kunjungan'],
                $maximumBytes,
                $deadline,
                $maximumExecutionSeconds,
            );
            if ($error !== null) {
                return ['', $error];
            }

            if ($exportCutoffId > 0) {
                $encounters = $query
                    ->where('id', '<=', $exportCutoffId)
                    ->lazyByIdDesc(500);

                foreach ($encounters as $encounter) {
                    $row = $this->encounterSummary($encounter);
                    $patient = is_array($row['patient'] ?? null) ? $row['patient'] : [];
                    $error = $this->writeCsvRow($handle, array_map($this->spreadsheetSafe(...), [
                        $row['registered_at'],
                        $row['queue_number'] ?? '',
                        $patient['medical_record_number'] ?? '',
                        $patient['full_name'] ?? '',
                        $row['origin_label'] ?? $row['origin'],
                        $row['booking_code'] ?? '',
                        $row['clinic_name'],
                        $row['doctor_name'] ?? '',
                        $row['payer_label'] ?? $row['payer_type'],
                        $row['status_label'] ?? $row['status'],
                        $row['status'],
                    ]), $maximumBytes, $deadline, $maximumExecutionSeconds);

                    if ($error !== null) {
                        return ['', $error];
                    }
                }
            }

            rewind($handle);
            $csv = stream_get_contents($handle);
            if ($csv === false) {
                throw new \RuntimeException('Keluaran CSV tidak dapat dibaca.');
            }
            if ($this->csvDeadlineExceeded($deadline)) {
                return ['', $this->csvExecutionLimitMessage($maximumExecutionSeconds)];
            }

            return [$csv, null];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $fields
     */
    private function writeCsvRow(
        mixed $handle,
        array $fields,
        int $maximumBytes,
        float $deadline,
        int $maximumExecutionSeconds,
    ): ?string {
        if ($this->csvDeadlineExceeded($deadline)) {
            return $this->csvExecutionLimitMessage($maximumExecutionSeconds);
        }
        if (fputcsv($handle, $fields) === false) {
            throw new \RuntimeException('Keluaran CSV tidak dapat ditulis.');
        }
        if (ftell($handle) > $maximumBytes) {
            return sprintf(
                'Ekspor CSV sinkron dibatasi maksimal %s byte. Persempit filter sebelum mencoba kembali.',
                number_format($maximumBytes, 0, ',', '.'),
            );
        }

        return $this->csvDeadlineExceeded($deadline)
            ? $this->csvExecutionLimitMessage($maximumExecutionSeconds)
            : null;
    }

    /**
     * @phpstan-impure Depends on the current wall-clock time.
     */
    private function csvDeadlineExceeded(float $deadline): bool
    {
        return microtime(true) >= $deadline;
    }

    private function csvExecutionLimitMessage(int $maximumExecutionSeconds): string
    {
        return sprintf(
            'Ekspor CSV sinkron melebihi batas waktu %s detik. Persempit filter sebelum mencoba kembali.',
            number_format($maximumExecutionSeconds, 0, ',', '.'),
        );
    }

    private function postgresStatementTimeoutMilliseconds(int $maximumExecutionSeconds): int
    {
        return max(1, ($maximumExecutionSeconds * 1000) - 250);
    }

    private function releaseExportLock(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (\Throwable) {
            // A bounded lease remains fail-safe if the cache backend becomes unavailable.
        }
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
            'status' => trim((string) $request->query('status', '')),
        ], fn (string $value): bool => $value !== '');

        return redirect()
            ->route('pendaftaran.rekap', $query)
            ->with('error', $message);
    }
}
